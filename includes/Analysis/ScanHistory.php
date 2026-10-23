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
		$scope  = ! empty( $params['pilot'] ) ? 'pilot' : ( ! empty( $params['doc_ids'] ) ? 'partial' : 'site' );
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
	 * @param int $limit Items listed per side.
	 * @return array|null Null until there is a site-wide scan.
	 */
	public static function diff( $limit = 20 ) {
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
		$since    = (string) $latest['started_at'];
		$from     = isset( $latest['findings_from'] ) ? (int) $latest['findings_from'] : 0;
		$limit    = max( 1, min( 100, (int) $limit ) );
		$cols     = 'f.id, f.type, f.check_id, f.label, f.confidence, f.locale, f.document_id, f.target_id, d.title, d.url';
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
			'new_count'      => null === $previous ? null : (int) Db::value( "SELECT COUNT(*) FROM {findings} WHERE status = 'open' AND id > %d", array( $from ) ),
			'resolved_count' => null === $previous ? null : (int) $latest['resolved'],
			'new'            => null === $previous ? array() : Db::rows( "SELECT {$cols} FROM {findings} f LEFT JOIN {documents} d ON d.id = f.document_id WHERE f.status = 'open' AND f.id > %d ORDER BY FIELD(f.label,'verified_error','warning','risk_hypothesis','suggestion','info'), f.score DESC, f.id LIMIT %d", array( $from, $limit ) ),
			'resolved'       => null === $previous ? array() : Db::rows( "SELECT {$cols} FROM {findings} f LEFT JOIN {documents} d ON d.id = f.document_id WHERE f.status = 'resolved' AND f.updated_at >= %s ORDER BY FIELD(f.label,'verified_error','warning','risk_hypothesis','suggestion','info'), f.score DESC, f.id LIMIT %d", array( $since, $limit ) ),
		);
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
