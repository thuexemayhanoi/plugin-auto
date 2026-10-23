<?php
/**
 * History of completed scans: measured cost, results, read-only evidence and the difference
 * between two scans.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Analysis;

use SemanticSEO\Database\Db;
use SemanticSEO\Jobs\JobRepository;
use SemanticSEO\Security\ReadOnlyProof;
use SemanticSEO\Support\Util;

defined( 'ABSPATH' ) || exit;

/**
 * One entry per completed analysis (the inventory job that started it is folded in). Nothing here
 * is an SEO score: entries count open findings by type and label, and record what the scan cost.
 */
final class ScanHistory {

	const OPTION = 'sseo_scan_history';
	const MAX    = 20;

	/**
	 * Finding IDs kept per side (new / resolved) for the comparison of a site-wide scan.
	 */
	const DIFF_IDS = 100;

	/**
	 * Order of the listed findings: verified errors first.
	 */
	const DIFF_ORDER = "FIELD(f.label,'verified_error','warning','risk_hypothesis','suggestion','info'), f.score DESC, f.id";

	/**
	 * Records a completed analysis job.
	 *
	 * @param array $job Analysis job (decoded).
	 * @return array The stored entry.
	 */
	public static function record( array $job ) {
		$params = isset( $job['params'] ) && is_array( $job['params'] ) ? $job['params'] : array();
		$state  = isset( $job['state'] ) && is_array( $job['state'] ) ? $job['state'] : array();
		$source = ! empty( $params['source_job'] ) ? JobRepository::get( (int) $params['source_job'] ) : null;
		$inv    = null !== $source && is_array( $source['state'] ) ? $source['state'] : array();
		$scope  = ! empty( $params['pilot'] ) ? 'pilot' : ( ! empty( $params['doc_ids'] ) || ! empty( $params['locale'] ) ? 'partial' : 'site' );
		$docs   = ! empty( $params['doc_ids'] ) ? count( (array) $params['doc_ids'] ) : (int) Db::value( "SELECT COUNT(*) FROM {documents} WHERE status = 'publish' AND excluded = 0" );
		$start  = null !== $source ? (string) $source['created_at'] : (string) $job['created_at'];
		$end    = ! empty( $job['finished_at'] ) ? (string) $job['finished_at'] : Util::now();
		$work   = ( isset( $state['work_seconds'] ) ? (float) $state['work_seconds'] : 0.0 ) + ( isset( $inv['work_seconds'] ) ? (float) $inv['work_seconds'] : 0.0 );
		$entry  = array(
			'job_id'        => (int) $job['id'],
			'inventory_job' => null !== $source ? (int) $source['id'] : 0,
			'scope'         => $scope,
			'documents'     => $docs,
			'started_at'    => $start,
			'finished_at'   => $end,
			'work_seconds'  => round( $work, 2 ),
			'wall_seconds'  => max( 0, strtotime( $end . ' UTC' ) - strtotime( $start . ' UTC' ) ),
			'peak_memory'   => max( isset( $state['peak_memory'] ) ? (int) $state['peak_memory'] : 0, isset( $inv['peak_memory'] ) ? (int) $inv['peak_memory'] : 0 ),
			'memory_limit'  => (string) ini_get( 'memory_limit' ),
			'findings'      => FindingRepository::summary(),
			'findings_from' => isset( $state['findings_max_id'] ) ? (int) $state['findings_max_id'] : 0,
			'resolved'      => isset( $state['resolved'] ) ? (int) $state['resolved'] : 0,
			'priorities'    => PriorityQueue::counts(),
			'readonly'      => null,
		);
		if ( null !== $source && isset( $source['params']['readonly_snapshot'] ) && is_array( $source['params']['readonly_snapshot'] ) ) {
			$ids               = isset( $source['params']['post_ids'] ) ? (array) $source['params']['post_ids'] : array();
			$entry['readonly'] = ReadOnlyProof::compare( $source['params']['readonly_snapshot'], $ids );
		}
		if ( 'site' === $scope ) {
			// The comparison is fixed now: later trials or partial analyses must not change what
			// this scan found or resolved.
			$from                  = (int) $entry['findings_from'];
			$entry['new_count']    = (int) Db::value( "SELECT COUNT(*) FROM {findings} WHERE status = 'open' AND id > %d", array( $from ) );
			$entry['new_ids']      = array_map( 'intval', Db::col( "SELECT f.id FROM {findings} f WHERE f.status = 'open' AND f.id > %d ORDER BY " . self::DIFF_ORDER . ' LIMIT %d', array( $from, self::DIFF_IDS ) ) );
			$entry['resolved_ids'] = array_map( 'intval', Db::col( "SELECT f.id FROM {findings} f WHERE f.status = 'resolved' AND f.updated_at >= %s AND f.updated_at <= %s ORDER BY " . self::DIFF_ORDER . ' LIMIT %d', array( $start, $end, self::DIFF_IDS ) ) );
		}
		$all   = self::all();
		$all[] = $entry;
		update_option( self::OPTION, array_slice( $all, -self::MAX ), false );
		return $entry;
	}

	/**
	 * Every stored entry, oldest first.
	 *
	 * @return array[]
	 */
	public static function all() {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) ? array_values( $all ) : array();
	}

	/**
	 * The most recent entry (optionally of one scope).
	 *
	 * @param string $scope pilot|partial|site or '' for any.
	 * @return array|null
	 */
	public static function latest( $scope = '' ) {
		foreach ( array_reverse( self::all() ) as $e ) {
			if ( '' === $scope || $scope === $e['scope'] ) {
				return $e;
			}
		}
		return null;
	}

	/**
	 * Difference between the latest site-wide scan and the one before it.
	 *
	 * @param int  $limit       Items listed per side.
	 * @param bool $public_only List only findings of published pages (titles and URLs of drafts,
	 *                          private or pending pages are for people who can edit them).
	 * @return array|null Null until there is a site-wide scan.
	 */
	public static function diff( $limit = 20, $public_only = false ) {
		$site = array_values(
			array_filter(
				self::all(),
				static function ( $e ) {
					return 'site' === $e['scope'];
				}
			)
		);
		if ( array() === $site ) {
			return null;
		}
		$latest   = $site[ count( $site ) - 1 ];
		$previous = count( $site ) > 1 ? $site[ count( $site ) - 2 ] : null;
		$limit    = max( 1, min( self::DIFF_IDS, (int) $limit ) );
		$deltas   = array();
		foreach ( (array) $latest['priorities'] as $p => $n ) {
			$before       = null !== $previous && isset( $previous['priorities'][ $p ] ) ? (int) $previous['priorities'][ $p ] : 0;
			$deltas[ $p ] = array(
				'before' => null === $previous ? null : $before,
				'after'  => (int) $n,
			);
		}
		return array(
			'latest'         => $latest,
			'previous'       => $previous,
			'priorities'     => $deltas,
			'new_count'      => null === $previous || ! isset( $latest['new_count'] ) ? null : (int) $latest['new_count'],
			'resolved_count' => null === $previous ? null : (int) $latest['resolved'],
			'new'            => null === $previous || ! isset( $latest['new_ids'] ) ? array() : self::findings( (array) $latest['new_ids'], $limit, $public_only ),
			'resolved'       => null === $previous || ! isset( $latest['resolved_ids'] ) ? array() : self::findings( (array) $latest['resolved_ids'], $limit, $public_only ),
		);
	}

	/**
	 * Findings recorded by a scan, in their recorded order, with the page they concern.
	 *
	 * @param int[] $ids         Finding IDs.
	 * @param int   $limit       Maximum listed.
	 * @param bool  $public_only Only findings of published pages.
	 * @return array[]
	 */
	private static function findings( array $ids, $limit, $public_only ) {
		$ids = array_slice( array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) ), 0, self::DIFF_IDS );
		if ( array() === $ids ) {
			return array();
		}
		$public = $public_only ? " AND d.status = 'publish'" : '';
		$rows   = Db::rows( 'SELECT f.id, f.type, f.check_id, f.label, f.confidence, f.locale, f.document_id, f.target_id, d.title, d.url FROM {findings} f LEFT JOIN {documents} d ON d.id = f.document_id WHERE f.id IN (' . Db::in( $ids ) . ')' . $public, $ids );
		$pos    = array_flip( $ids );
		usort(
			$rows,
			static function ( $a, $b ) use ( $pos ) {
				return $pos[ (int) $a['id'] ] - $pos[ (int) $b['id'] ];
			}
		);
		return array_slice( $rows, 0, (int) $limit );
	}

	/**
	 * Estimated cost of scanning a number of documents, from the last measured scan.
	 *
	 * Analysis compares every page with its most similar candidates, so the time per page grows
	 * with the size of the site: a measurement on a small pilot is a lower bound for the whole site.
	 *
	 * @param int $documents Documents to scan.
	 * @return array|null Null until a scan was measured.
	 */
	public static function estimate( $documents ) {
		$documents = max( 0, (int) $documents );
		$basis     = null;
		foreach ( array_reverse( self::all() ) as $e ) {
			if ( (int) $e['documents'] > 0 && (float) $e['work_seconds'] > 0 ) {
				$basis = $e;
				break;
			}
		}
		if ( null === $basis ) {
			return null;
		}
		$per = (float) $basis['work_seconds'] / (int) $basis['documents'];
		return array(
			'documents'            => $documents,
			'basis_scope'          => (string) $basis['scope'],
			'basis_documents'      => (int) $basis['documents'],
			'basis_job'            => (int) $basis['job_id'],
			'seconds_per_document' => round( $per, 3 ),
			'estimated_seconds'    => (int) ceil( $per * $documents ),
			'lower_bound'          => (int) $basis['documents'] < $documents,
			'peak_memory'          => (int) $basis['peak_memory'],
			'memory_limit'         => (string) ini_get( 'memory_limit' ),
			'memory_limit_bytes'   => self::bytes( (string) ini_get( 'memory_limit' ) ),
		);
	}

	/**
	 * Bytes of a php.ini size value (-1 = unlimited → 0).
	 *
	 * @param string $value Value such as 256M.
	 * @return int
	 */
	public static function bytes( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value || '-1' === $value ) {
			return 0;
		}
		$n    = (int) $value;
		$unit = strtolower( substr( $value, -1 ) );
		$mult = array(
			'g' => 1073741824,
			'm' => 1048576,
			'k' => 1024,
		);
		return isset( $mult[ $unit ] ) ? $n * $mult[ $unit ] : $n;
	}
}
