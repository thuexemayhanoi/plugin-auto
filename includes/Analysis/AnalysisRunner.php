<?php
/**
 * Phased, resumable analysis pipeline (M1–M6).
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Analysis;

use SemanticSEO\Cannibalization\CannibalizationAnalyzer;
use SemanticSEO\Clustering\ClusterBuilder;
use SemanticSEO\Clustering\ClusterTree;
use SemanticSEO\Database\Db;
use SemanticSEO\Graph\LinkGraph;
use SemanticSEO\Gsc\GscRepository;
use SemanticSEO\Inventory\DocumentRepository;
use SemanticSEO\Lexical\LexicalIndex;
use SemanticSEO\Links\LinkSuggester;
use SemanticSEO\Quality\QaChecker;
use SemanticSEO\Recommendations\RelatedContent;
use SemanticSEO\Semantic\CrossLanguage;
use SemanticSEO\Semantic\EmbeddingRepository;
use SemanticSEO\Semantic\ModelProfile;
use SemanticSEO\Settings;
use SemanticSEO\Support\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Phases: graph → vectors → pairs → clusters → qa → finalize. Each step processes a bounded
 * batch and returns a cursor so the job can resume after a timeout or crash.
 */
final class AnalysisRunner {

	const VERSION = 'analysis-2';
	const PHASES  = array( 'graph', 'vectors', 'clusters', 'pairs', 'qa', 'finalize' );

	/**
	 * Initial state.
	 *
	 * @param array $params Job params: doc_ids (bounded), locale.
	 * @return array{state:array,total:int}
	 */
	public static function init( array $params ) {
		$scope = self::scope_ids( $params );
		$n     = count( $scope );
		return array(
			'state' => array(
				'phase'  => 'graph',
				'cursor' => 0,
				'scoped' => ! empty( $params['doc_ids'] ),
				'counts' => array(),
			),
			'total' => 3 * $n + 3,
		);
	}

	/**
	 * Document IDs in scope (all published, or a bounded explicit list).
	 *
	 * @param array $params Params.
	 * @return int[]
	 */
	public static function scope_ids( array $params ) {
		if ( ! empty( $params['doc_ids'] ) ) {
			$ids = array_slice( array_values( array_unique( array_map( 'intval', (array) $params['doc_ids'] ) ) ), 0, 500 );
			return array_map(
				'intval',
				Db::col( "SELECT id FROM {documents} WHERE status = 'publish' AND id IN (" . Db::in( $ids ) . ') ORDER BY id ASC', $ids )
			);
		}
		$locale = isset( $params['locale'] ) ? (string) $params['locale'] : '';
		return DocumentRepository::analyzable_ids( $locale );
	}

	/**
	 * One step.
	 *
	 * @param array $job   Job row (state, params, id).
	 * @param int   $batch Batch size.
	 * @return array{state:array,done:int,finished:bool,errors:array}
	 */
	public static function step( array $job, $batch ) {
		$state  = $job['state'];
		$params = $job['params'];
		$run_id = (int) $job['id'];
		$phase  = isset( $state['phase'] ) ? $state['phase'] : 'graph';
		$cursor = isset( $state['cursor'] ) ? (int) $state['cursor'] : 0;
		$done   = 0;
		$errors = array();
		$batch  = max( 1, (int) $batch );

		switch ( $phase ) {
			case 'graph':
				$res = LinkGraph::resolve_batch( $cursor, $batch * 20 );
				if ( $res['processed'] < $batch * 20 ) {
					LinkGraph::recount();
					$phase  = 'vectors';
					$cursor = 0;
					$done   = 1;
				} else {
					$cursor = $res['last_id'];
				}
				break;

			case 'vectors':
				$ids = self::next_ids( $params, $cursor, $batch * 4 );
				foreach ( $ids as $id ) {
					$cursor = $id;
					$row    = DocumentRepository::get( $id );
					if ( null === $row ) {
						continue;
					}
					$signals           = DocumentRepository::signals( $row );
					$signals['lexvec'] = LexicalIndex::document_vector( $id, (string) $row['locale'], 200 );
					DocumentRepository::update( $id, array( 'signals' => Util::json( $signals ) ) );
					++$done;
				}
				if ( count( $ids ) < $batch * 4 ) {
					$phase  = 'clusters';
					$cursor = 0;
				}
				break;

			case 'pairs':
				$ids = self::next_ids( $params, $cursor, $batch );
				foreach ( $ids as $id ) {
					$cursor = $id;
					try {
						$counts = self::analyze_document( $id, $run_id );
						foreach ( $counts as $k => $v ) {
							$state['counts'][ $k ] = ( isset( $state['counts'][ $k ] ) ? $state['counts'][ $k ] : 0 ) + $v;
						}
					} catch ( \Throwable $e ) {
						$errors[] = array(
							'document_id' => $id,
							'error'       => Util::truncate( $e->getMessage() . ' @' . basename( $e->getFile() ) . ':' . $e->getLine(), 240 ),
						);
					}
					++$done;
				}
				if ( count( $ids ) < $batch ) {
					$phase  = 'qa';
					$cursor = 0;
				}
				break;

			case 'clusters':
				$state['counts']['clusters'] = ClusterBuilder::build( $run_id );
				$phase                       = 'pairs';
				$cursor                      = 0;
				$done                        = 1;
				break;

			case 'qa':
				if ( ! isset( $state['qa_ctx_ready'] ) ) {
					$state['qa_ctx_ready'] = true;
				}
				$ctx = QaChecker::context();
				$ids = self::next_ids( $params, $cursor, $batch * 2, true );
				foreach ( $ids as $id ) {
					$cursor = $id;
					$row    = DocumentRepository::get( $id );
					if ( null === $row ) {
						continue;
					}
					foreach ( QaChecker::check( Scorer::profile( $row ), $row, $ctx ) as $f ) {
						FindingRepository::upsert( $f, $run_id );
						$state['counts']['qa'] = ( isset( $state['counts']['qa'] ) ? $state['counts']['qa'] : 0 ) + 1;
					}
					++$done;
				}
				if ( count( $ids ) < $batch * 2 ) {
					$phase  = 'finalize';
					$cursor = 0;
				}
				break;

			case 'finalize':
				$scope = ! empty( $params['doc_ids'] ) ? self::scope_ids( $params ) : array();
				if ( array() === $scope ) {
					// Site-wide only: cross-language pairs need every page of both languages.
					foreach ( CrossLanguage::findings( ModelProfile::active()['model_key'] ) as $f ) {
						FindingRepository::upsert( $f, $run_id );
						$state['counts']['qa'] = ( isset( $state['counts']['qa'] ) ? $state['counts']['qa'] : 0 ) + 1;
					}
				}
				FindingRepository::resolve_stale( array( 'link', 'cannibalization', 'qa', 'classification' ), $run_id, $scope );
				self::group_cannibalization();
				if ( array() === $scope ) {
					Db::query( "UPDATE {documents} SET analyzed_at = %s WHERE status = 'publish'", array( Util::now() ) );
				} else {
					Db::query( 'UPDATE {documents} SET analyzed_at = %s WHERE id IN (' . Db::in( $scope ) . ')', array_merge( array( Util::now() ), $scope ) );
				}
				$state['summary'] = array(
					'findings'  => FindingRepository::summary(),
					'counts'    => isset( $state['counts'] ) ? $state['counts'] : array(),
					'coverage'  => LinkGraph::coverage(),
					'semantic'  => EmbeddingRepository::stats( ModelProfile::active()['model_key'] ),
					'algorithm' => array(
						'analysis' => self::VERSION,
						'scorer'   => Scorer::VERSION,
						'links'    => LinkSuggester::VERSION,
						'cannibal' => CannibalizationAnalyzer::VERSION,
						'clusters' => ClusterBuilder::VERSION,
						'qa'       => QaChecker::VERSION,
						'related'  => RelatedContent::VERSION,
					),
				);
				update_option(
					'sseo_state',
					array_merge(
						(array) get_option( 'sseo_state', array() ),
						array(
							'last_analysis_at'  => Util::now(),
							'last_analysis_job' => $run_id,
						)
					),
					false
				);
				$phase = 'done';
				$done  = 1;
				break;
		}
		$state['phase']  = $phase;
		$state['cursor'] = $cursor;
		return array(
			'state'    => $state,
			'done'     => $done,
			'finished' => 'done' === $phase,
			'errors'   => $errors,
		);
	}

	/**
	 * Next document IDs in scope after a cursor.
	 *
	 * @param array $params    Params.
	 * @param int   $cursor    Cursor.
	 * @param int   $limit     Limit.
	 * @param bool  $all_types Include term documents (QA covers category archives too).
	 * @return int[]
	 */
	private static function next_ids( array $params, $cursor, $limit, $all_types = true ) {
		unset( $all_types );
		if ( ! empty( $params['doc_ids'] ) ) {
			$ids = array_filter(
				self::scope_ids( $params ),
				static function ( $id ) use ( $cursor ) {
					return $id > $cursor;
				}
			);
			return array_slice( array_values( $ids ), 0, $limit );
		}
		$locale = isset( $params['locale'] ) ? (string) $params['locale'] : '';
		return DocumentRepository::analyzable_ids( $locale, $cursor, $limit );
	}

	/**
	 * Candidate generation + per-task scoring for one source document.
	 *
	 * @param int $doc_id Document ID.
	 * @param int $run_id Run ID.
	 * @return array<string,int> Counts.
	 */
	public static function analyze_document( $doc_id, $run_id ) {
		$row = DocumentRepository::get( $doc_id );
		if ( null === $row || 'publish' !== $row['status'] ) {
			return array();
		}
		$source = Scorer::profile( $row );
		$k      = (int) Settings::get( 'candidate_k', 15 );

		// Candidate set: BM25 neighbours (same locale) + same-cluster pages.
		$query = array();
		foreach ( $source['lexvec'] as $term => $w ) {
			$query[ $term ] = (float) $w;
		}
		$hits = LexicalIndex::search( $query, $source['locale'], $k + 1, array( $source['id'] ) );
		$ids  = array_keys( $hits );
		if ( $source['cluster_id'] > 0 ) {
			$ids = array_merge( $ids, array_map( 'intval', Db::col( "SELECT id FROM {documents} WHERE cluster_id = %d AND status = 'publish' AND id <> %d LIMIT 20", array( $source['cluster_id'], $source['id'] ) ) ) );
			$ids = array_merge( $ids, ClusterTree::ancestor_pillars( $source['cluster_id'] ) );
		}
		$ids       = array_values( array_diff( array_unique( $ids ), array( $source['id'] ) ) );
		$rows      = DocumentRepository::many( $ids );
		$model_key = ModelProfile::active()['model_key'];
		$vectors   = EmbeddingRepository::summary_vectors( array_merge( array( $source['id'] ), array_keys( $rows ) ), $model_key );
		$gsc       = GscRepository::overlaps( $source['id'], array_keys( $rows ) );

		$candidates = array();
		foreach ( $rows as $id => $r ) {
			$t            = Scorer::profile( $r );
			$f            = Scorer::features(
				$source,
				$t,
				isset( $vectors[ $source['id'] ] ) ? $vectors[ $source['id'] ] : null,
				isset( $vectors[ $id ] ) ? $vectors[ $id ] : null,
				isset( $gsc['overlap'][ $id ] ) ? $gsc['overlap'][ $id ] : 0,
				$gsc['available'] && in_array( $id, $gsc['with_data'], true )
			);
			$f['bm25']    = isset( $hits[ $id ] ) ? round( $hits[ $id ], 3 ) : null;
			$candidates[] = array( $t, $f );
		}
		usort(
			$candidates,
			static function ( $x, $y ) {
				return $y[1]['topic'] <=> $x[1]['topic'];
			}
		);

		$counts = array(
			'link'            => 0,
			'cannibalization' => 0,
			'related'         => 0,
		);
		if ( 'post' === $source['object_type'] ) {
			$content = (string) get_post_field( 'post_content', $source['object_id'], 'raw' );
			foreach ( LinkSuggester::suggest( $source, $candidates, $content ) as $f ) {
				FindingRepository::upsert( $f, $run_id );
				++$counts['link'];
			}
			foreach ( $candidates as $pair ) {
				$finding = CannibalizationAnalyzer::classify( $source, $pair[0], $pair[1] );
				if ( null !== $finding ) {
					FindingRepository::upsert( $finding, $run_id );
					++$counts['cannibalization'];
				}
			}
			$counts['related'] = count( RelatedContent::compute( $source, $candidates ) );
		}
		return $counts;
	}

	/**
	 * Groups risky cannibalization pairs into connected components.
	 *
	 * @return void
	 */
	private static function group_cannibalization() {
		$pairs = array();
		$rows  = Db::rows( "SELECT id, document_id, target_id FROM {findings} WHERE type = 'cannibalization' AND status = 'open' AND check_id IN ('exact_duplicate','near_duplicate','potential_competition')" );
		foreach ( $rows as $r ) {
			$pairs[] = array( (int) $r['document_id'], (int) $r['target_id'] );
		}
		$groups = CannibalizationAnalyzer::components( $pairs );
		foreach ( $rows as $r ) {
			$g = isset( $groups[ (int) $r['document_id'] ] ) ? $groups[ (int) $r['document_id'] ] : '';
			if ( '' !== $g ) {
				Db::update( 'findings', array( 'group_key' => $g ), array( 'id' => (int) $r['id'] ) );
			}
		}
	}
}
