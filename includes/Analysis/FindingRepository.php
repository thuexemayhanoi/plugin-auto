<?php
/**
 * Findings (analyses/recommendations) with stable fingerprints so human decisions persist.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Analysis;

use SemanticSEO\Database\Db;
use SemanticSEO\Support\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Types: link, cannibalization, qa, classification. Labels: verified_error, warning, suggestion,
 * risk_hypothesis, info. Confidence categories: high, medium, low, manual_review.
 */
final class FindingRepository {

	const TYPES       = array( 'link', 'cannibalization', 'qa', 'classification' );
	const LABELS      = array( 'verified_error', 'warning', 'suggestion', 'risk_hypothesis', 'info' );
	const STATUSES    = array( 'open', 'dismissed', 'accepted', 'proposed', 'resolved' );
	const CONFIDENCES = array( 'high', 'medium', 'low', 'manual_review' );

	/**
	 * Stable fingerprint.
	 *
	 * @param string $type     Type.
	 * @param string $check_id Check.
	 * @param int    $doc      Document ID.
	 * @param int    $target   Target ID.
	 * @return string
	 */
	public static function fingerprint( $type, $check_id, $doc, $target ) {
		return sha1( $type . '|' . $check_id . '|' . (int) $doc . '|' . (int) $target );
	}

	/**
	 * Inserts or refreshes a finding. Dismissed/accepted/proposed statuses are preserved; a
	 * previously resolved finding that is detected again is reopened.
	 *
	 * @param array $f      Finding.
	 * @param int   $run_id Analysis job ID.
	 * @return int Finding ID.
	 */
	public static function upsert( array $f, $run_id ) {
		$type   = (string) $f['type'];
		$check  = isset( $f['check_id'] ) ? (string) $f['check_id'] : '';
		$doc    = isset( $f['document_id'] ) ? (int) $f['document_id'] : 0;
		$target = isset( $f['target_id'] ) ? (int) $f['target_id'] : 0;
		$fp     = self::fingerprint( $type, $check, $doc, $target );
		$now    = Util::now();
		$data   = array(
			'type'              => $type,
			'check_id'          => substr( $check, 0, 64 ),
			'document_id'       => $doc,
			'target_id'         => $target,
			'group_key'         => isset( $f['group_key'] ) ? substr( (string) $f['group_key'], 0, 64 ) : '',
			'locale'            => isset( $f['locale'] ) ? (string) $f['locale'] : '',
			'label'             => in_array( $f['label'], self::LABELS, true ) ? $f['label'] : 'suggestion',
			'severity'          => isset( $f['severity'] ) ? (string) $f['severity'] : 'info',
			'confidence'        => in_array( $f['confidence'], self::CONFIDENCES, true ) ? $f['confidence'] : 'low',
			'score'             => isset( $f['score'] ) ? round( (float) $f['score'], 4 ) : 0,
			'evidence'          => Util::json( isset( $f['evidence'] ) ? $f['evidence'] : array() ),
			'algorithm_version' => isset( $f['algorithm_version'] ) ? (string) $f['algorithm_version'] : '',
			'run_id'            => (int) $run_id,
			'updated_at'        => $now,
		);
		$existing = Db::row( 'SELECT id, status FROM {findings} WHERE fingerprint = %s', array( $fp ) );
		if ( null !== $existing ) {
			if ( 'resolved' === $existing['status'] ) {
				$data['status'] = 'open';
			}
			Db::update( 'findings', $data, array( 'id' => (int) $existing['id'] ) );
			return (int) $existing['id'];
		}
		$data['fingerprint'] = $fp;
		$data['status']      = 'open';
		$data['created_at']  = $now;
		return Db::insert( 'findings', $data );
	}

	/**
	 * Marks open findings not refreshed by the current run as resolved.
	 *
	 * @param string[] $types   Types.
	 * @param int      $run_id  Current run.
	 * @param int[]    $doc_ids Restrict to documents (empty = all).
	 * @return int
	 */
	public static function resolve_stale( array $types, $run_id, array $doc_ids = array() ) {
		$types = array_values( array_intersect( self::TYPES, $types ) );
		if ( array() === $types ) {
			return 0;
		}
		$sql  = "UPDATE {findings} SET status = 'resolved', updated_at = %s WHERE status = 'open' AND run_id < %d AND type IN (" . Db::in( $types, '%s' ) . ')';
		$args = array_merge( array( Util::now(), (int) $run_id ), $types );
		if ( array() !== $doc_ids ) {
			$doc_ids = array_map( 'intval', $doc_ids );
			$sql    .= ' AND (document_id IN (' . Db::in( $doc_ids ) . ') OR target_id IN (' . Db::in( $doc_ids ) . '))';
			$args    = array_merge( $args, $doc_ids, $doc_ids );
		}
		return max( 0, Db::query( $sql, $args ) );
	}

	/**
	 * Fetches one finding.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		$row = Db::row( 'SELECT * FROM {findings} WHERE id = %d', array( (int) $id ) );
		return null === $row ? null : self::decode( $row );
	}

	/**
	 * Paginated query (keyset by score/id).
	 *
	 * @param array $f Filters: type, status, label, severity, confidence, locale, document_id, target_id, group_key, check_id, limit, offset.
	 * @return array{items:array,total:int}
	 */
	public static function query( array $f ) {
		$where = array( '1=1' );
		$args  = array();
		foreach ( array( 'type', 'status', 'label', 'severity', 'confidence', 'locale', 'group_key', 'check_id' ) as $k ) {
			if ( isset( $f[ $k ] ) && '' !== (string) $f[ $k ] ) {
				$where[] = "{$k} = %s";
				$args[]  = (string) $f[ $k ];
			}
		}
		if ( ! empty( $f['document_id'] ) ) {
			$where[] = '(document_id = %d OR target_id = %d)';
			$args[]  = (int) $f['document_id'];
			$args[]  = (int) $f['document_id'];
		}
		if ( ! empty( $f['source_only'] ) && ! empty( $f['document_id'] ) ) {
			$where[] = 'document_id = %d';
			$args[]  = (int) $f['document_id'];
		}
		if ( isset( $f['min_score'] ) && '' !== (string) $f['min_score'] ) {
			$where[] = 'score >= %f';
			$args[]  = (float) $f['min_score'];
		}
		$w      = implode( ' AND ', $where );
		$total  = (int) Db::value( "SELECT COUNT(*) FROM {findings} WHERE {$w}", $args );
		$limit  = Util::clamp_int( isset( $f['limit'] ) ? $f['limit'] : 20, 1, 100 );
		$offset = max( 0, isset( $f['offset'] ) ? (int) $f['offset'] : 0 );
		$order  = isset( $f['order'] ) && 'severity' === $f['order'] ? "FIELD(severity,'error','warning','info'), score DESC, id ASC" : 'score DESC, id ASC';
		$rows   = Db::rows( "SELECT * FROM {findings} WHERE {$w} ORDER BY {$order} LIMIT %d OFFSET %d", array_merge( $args, array( $limit, $offset ) ) );
		return array(
			'items' => array_map( array( __CLASS__, 'decode' ), $rows ),
			'total' => $total,
		);
	}

	/**
	 * Updates the review status of a finding.
	 *
	 * @param int    $id     ID.
	 * @param string $status Status.
	 * @return bool
	 */
	public static function set_status( $id, $status ) {
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return false;
		}
		return Db::update(
			'findings',
			array(
				'status'     => $status,
				'updated_at' => Util::now(),
			),
			array( 'id' => (int) $id )
		) >= 0;
	}

	/**
	 * Counts open findings by type and label.
	 *
	 * @return array
	 */
	public static function summary() {
		$out = array();
		foreach ( Db::rows( "SELECT type, label, COUNT(*) AS n FROM {findings} WHERE status = 'open' GROUP BY type, label" ) as $r ) {
			$out[ $r['type'] ][ $r['label'] ] = (int) $r['n'];
		}
		return $out;
	}

	/**
	 * Decodes evidence.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public static function decode( array $row ) {
		$row['evidence'] = Util::json_array( isset( $row['evidence'] ) ? $row['evidence'] : '' );
		$row['id']       = (int) $row['id'];
		return $row;
	}
}
