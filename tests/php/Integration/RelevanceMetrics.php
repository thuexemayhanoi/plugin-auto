<?php
/**
 * Relevance metrics on the LABELLED TEST FIXTURE corpus, shared by the lexical evaluation
 * (EvaluationTest) and the real-model hybrid evaluation (HybridEvaluationTest), so both report
 * exactly the same measurements.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Tests\Integration;

use SemanticSEO\Database\Db;
use SemanticSEO\Graph\LinkGraph;
use SemanticSEO\Recommendations\RelatedContent;

/**
 * Requires $this->posts (fixture key => post ID) and $this->doc( $key ) from TestCase.
 */
trait RelevanceMetrics {

	/**
	 * Fixture key by document ID.
	 *
	 * @var array<int,string>
	 */
	private $key_by_doc = array();

	/**
	 * Maps document IDs back to fixture keys (call after the inventory ran).
	 *
	 * @return void
	 */
	private function index_fixture_docs() {
		$this->key_by_doc = array();
		foreach ( $this->posts as $key => $post_id ) {
			$doc = $this->doc( $key );
			if ( $doc > 0 ) {
				$this->key_by_doc[ $doc ] = $key;
			}
		}
	}

	/**
	 * Ranked link targets (fixture keys) per source key.
	 *
	 * @param string $source Source key.
	 * @return string[]
	 */
	private function link_targets( $source ) {
		$rows = Db::rows( "SELECT target_id FROM {findings} WHERE type = 'link' AND status = 'open' AND document_id = %d ORDER BY score DESC, id ASC", array( $this->doc( $source ) ) );
		$out  = array();
		foreach ( $rows as $r ) {
			if ( isset( $this->key_by_doc[ (int) $r['target_id'] ] ) ) {
				$out[] = $this->key_by_doc[ (int) $r['target_id'] ];
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Related items (fixture keys) per source key.
	 *
	 * @param string $source Source key.
	 * @return string[]
	 */
	private function related( $source ) {
		$by_post = array_flip( $this->posts );
		$out     = array();
		foreach ( RelatedContent::for_post( $this->posts[ $source ], 5 ) as $item ) {
			if ( isset( $by_post[ $item['post_id'] ] ) ) {
				$out[] = $by_post[ $item['post_id'] ];
			}
		}
		return $out;
	}

	/**
	 * Fixture keys the source already links to.
	 *
	 * @param string $source Source key.
	 * @return string[]
	 */
	private function linked_keys( $source ) {
		$linked = array();
		foreach ( LinkGraph::linked_targets( $this->doc( $source ) ) as $doc_id ) {
			if ( isset( $this->key_by_doc[ (int) $doc_id ] ) ) {
				$linked[] = $this->key_by_doc[ (int) $doc_id ];
			}
		}
		return $linked;
	}

	/**
	 * Precision@k and recall@k for one ranked list.
	 *
	 * @param string[] $ranked   Ranked keys.
	 * @param string[] $relevant Relevant keys.
	 * @param int      $k        Cut-off.
	 * @return array{p: float, r: float, hits: int}
	 */
	private static function at_k( array $ranked, array $relevant, $k ) {
		$top  = array_slice( $ranked, 0, $k );
		$hits = count( array_intersect( $top, $relevant ) );
		return array(
			'p'    => $hits / $k,
			'r'    => array() === $relevant ? 0.0 : $hits / count( $relevant ),
			'hits' => $hits,
		);
	}

	/**
	 * Measures link suggestions, related content and overlap reports against the labels.
	 *
	 * @param array $labels Corpus labels.
	 * @return array{links: array, related: array, overlap: array, per_page: array}
	 */
	private function measure( array $labels ) {
		$report = array(
			'links'    => array(),
			'related'  => array(),
			'overlap'  => array(),
			'per_page' => array(),
		);
		foreach ( array( 'links', 'related' ) as $kind ) {
			$returned = 0;
			$hits_all = 0;
			$covered  = 0;
			$sources  = 0;
			$counted  = 0;
			foreach ( array( 3, 5 ) as $k ) {
				$p       = 0.0;
				$r       = 0.0;
				$counted = 0;
				foreach ( $labels['relevant'] as $source => $relevant ) {
					$ranked = 'links' === $kind ? $this->link_targets( $source ) : $this->related( $source );
					if ( 'links' === $kind ) {
						// A link suggestion can only be a NEW link: targets the source already links to are
						// correctly never suggested, so they are removed from the relevant set.
						$relevant = array_values( array_diff( $relevant, $this->linked_keys( $source ) ) );
						if ( array() === $relevant ) {
							continue;
						}
					}
					++$counted;
					$m  = self::at_k( $ranked, $relevant, $k );
					$p += $m['p'];
					$r += $m['r'];
					if ( 5 === $k ) {
						++$sources;
						$returned += min( 5, count( $ranked ) );
						$hits_all += $m['hits'];
						$covered  += array() === $ranked ? 0 : 1;
						$report['per_page'][ $kind ][ $source ] = array(
							'ranked'   => array_slice( $ranked, 0, 5 ),
							'relevant' => $relevant,
							'hits@5'   => $m['hits'],
						);
					}
				}
				$n                                    = max( 1, $counted );
				$report[ $kind ][ 'precision@' . $k ] = round( $p / $n, 3 );
				$report[ $kind ][ 'recall@' . $k ]    = round( $r / $n, 3 );
			}
			$report[ $kind ]['evaluated_sources']           = $sources;
			$report[ $kind ]['sources_with_output']         = $covered;
			$report[ $kind ]['precision_of_returned_items'] = $returned > 0 ? round( $hits_all / $returned, 3 ) : 0.0;
		}

		// Overlap reports: false positives on controls, recall on expected pairs, cross-locale pairs.
		$pairs = array();
		foreach ( Db::rows( "SELECT document_id, target_id, check_id FROM {findings} WHERE type = 'cannibalization' AND status = 'open'" ) as $f ) {
			$a                      = isset( $this->key_by_doc[ (int) $f['document_id'] ] ) ? $this->key_by_doc[ (int) $f['document_id'] ] : '';
			$b                      = isset( $this->key_by_doc[ (int) $f['target_id'] ] ) ? $this->key_by_doc[ (int) $f['target_id'] ] : '';
			$pairs[ $a . '|' . $b ] = $f['check_id'];
			$pairs[ $b . '|' . $a ] = $f['check_id'];
		}
		$fp = 0;
		foreach ( $labels['not_competition'] as $pair ) {
			$c = isset( $pairs[ $pair[0] . '|' . $pair[1] ] ) ? $pairs[ $pair[0] . '|' . $pair[1] ] : '';
			if ( in_array( $c, array( 'potential_competition', 'near_duplicate', 'exact_duplicate' ), true ) ) {
				++$fp;
			}
		}
		$found   = 0;
		$details = array();
		foreach ( $labels['expected_overlap'] as $exp ) {
			$c = isset( $pairs[ $exp[0] . '|' . $exp[1] ] ) ? $pairs[ $exp[0] . '|' . $exp[1] ] : '';
			if ( in_array( $c, $exp[2], true ) ) {
				++$found;
			}
			$details[] = $exp[0] . ' (' . Db::value( 'SELECT cluster_role FROM {documents} WHERE id = %d', array( $this->doc( $exp[0] ) ) ) . ') vs ' . $exp[1] . ' (' . Db::value( 'SELECT cluster_role FROM {documents} WHERE id = %d', array( $this->doc( $exp[1] ) ) ) . '): ' . ( '' === $c ? 'not reported' : $c ) . ' — expected ' . implode( '|', $exp[2] );
		}
		foreach ( $labels['not_competition'] as $pair ) {
			$c         = isset( $pairs[ $pair[0] . '|' . $pair[1] ] ) ? $pairs[ $pair[0] . '|' . $pair[1] ] : 'not reported';
			$details[] = 'control ' . $pair[0] . ' vs ' . $pair[1] . ': ' . $c;
		}
		$cross             = (int) Db::value( "SELECT COUNT(*) FROM {findings} f JOIN {documents} a ON a.id = f.document_id JOIN {documents} b ON b.id = f.target_id WHERE f.type = 'cannibalization' AND f.check_id <> 'translation_conflict' AND a.locale <> b.locale" );
		$total             = (int) Db::value( "SELECT COUNT(*) FROM {findings} WHERE type = 'cannibalization' AND status = 'open'" );
		$report['overlap'] = array(
			'reported_pairs'              => $total,
			'false_positives_on_controls' => $fp,
			'controls'                    => count( $labels['not_competition'] ),
			'expected_overlaps_found'     => $found,
			'expected_overlaps'           => count( $labels['expected_overlap'] ),
			'cross_locale_pairs_compared' => $cross,
			'warning_rate_per_document'   => round( $total / max( 1, count( $this->key_by_doc ) ), 3 ),
			'pairs'                       => $details,
		);
		return $report;
	}

	/**
	 * Writes a JSON artifact to tests/e2e/artifacts/.
	 *
	 * @param string $name   File name.
	 * @param array  $report Data.
	 * @return void
	 */
	private static function write_artifact( $name, array $report ) {
		$dir = dirname( __DIR__, 2 ) . '/e2e/artifacts';
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		}
		file_put_contents( $dir . '/' . $name, wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}
}
