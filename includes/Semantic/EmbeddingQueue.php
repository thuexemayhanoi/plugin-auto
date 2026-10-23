<?php
/**
 * Leased embedding work queue for the browser worker (Tier 1) and private worker (Tier 2).
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Semantic;

use SemanticSEO\Database\Db;
use SemanticSEO\Settings;
use SemanticSEO\Support\Util;

defined( 'ABSPATH' ) || exit;

/**
 * PHP never runs the model. Workers claim small batches with a single-use lease token (only its
 * SHA-256 is stored), upload vectors before the lease expires, and every vector is validated
 * against the pinned profile and the current chunk hash before it is stored.
 */
final class EmbeddingQueue {

	const MAX_CLAIM   = 16;
	const LEASE_TTL   = 180;
	const MAX_ATTEMPT = 5;
	const MAX_TEXT    = 6000;

	/**
	 * Enqueues chunks needing a vector for the active profile (documents after a cursor).
	 *
	 * @param int   $job_id  Job ID.
	 * @param int   $after   Document cursor.
	 * @param int   $batch   Documents per batch.
	 * @param array $doc_ids Optional bounded scope.
	 * @return array{cursor:int,documents:int,enqueued:int}
	 */
	public static function enqueue_batch( $job_id, $after, $batch, array $doc_ids = array() ) {
		$profile = ModelProfile::active();
		$chunks  = (int) Settings::get( 'embed_body_chunks', 2 );
		// MySQL evaluates ON DUPLICATE KEY assignments left to right: attempts and status are
		// computed from the old row before content_hash is overwritten.
		$sql  = "SELECT id, locale FROM {documents} WHERE status = 'publish' AND excluded = 0 AND id > %d";
		$args = array( (int) $after );
		if ( array() !== $doc_ids ) {
			$doc_ids = array_slice( array_map( 'intval', $doc_ids ), 0, 500 );
			$sql    .= ' AND id IN (' . Db::in( $doc_ids ) . ')';
			$args    = array_merge( $args, $doc_ids );
		}
		$sql   .= ' ORDER BY id ASC LIMIT %d';
		$args[] = (int) $batch;
		$docs   = Db::rows( $sql, $args );
		$count  = 0;
		$cursor = array() === $docs ? (int) $after : (int) end( $docs )['id'];
		if ( array() === $docs ) {
			return array(
				'cursor'    => $cursor,
				'documents' => 0,
				'enqueued'  => 0,
			);
		}
		// Three queries per batch (chunks, current vectors, queue insert) instead of two per chunk.
		$ids   = array_map( 'intval', array_column( $docs, 'id' ) );
		$chunk = Db::rows( 'SELECT document_id, chunk_index, content_hash FROM {chunks} WHERE document_id IN (' . Db::in( $ids ) . ') AND chunk_index <= %d ORDER BY document_id ASC, chunk_index ASC', array_merge( $ids, array( $chunks ) ) );
		$have  = array();
		foreach ( Db::rows( 'SELECT document_id, chunk_id, content_hash FROM {embeddings} WHERE model_key = %s AND document_id IN (' . Db::in( $ids ) . ')', array_merge( array( $profile['model_key'] ), $ids ) ) as $e ) {
			$have[ $e['document_id'] . ':' . $e['chunk_id'] . ':' . $e['content_hash'] ] = true;
		}
		$values = array();
		$vargs  = array();
		$now    = Util::now();
		foreach ( $chunk as $c ) {
			if ( isset( $have[ $c['document_id'] . ':' . $c['chunk_index'] . ':' . $c['content_hash'] ] ) ) {
				continue;
			}
			$values[] = "(%d, %d, %d, %s, %s, 'pending', 0, %s)";
			array_push( $vargs, (int) $job_id, (int) $c['document_id'], (int) $c['chunk_index'], $profile['model_key'], $c['content_hash'], $now );
			++$count;
		}
		if ( array() !== $values ) {
			Db::query(
				'INSERT INTO {queue} (job_id, document_id, chunk_id, model_key, content_hash, status, attempts, updated_at) VALUES ' . implode( ', ', $values ) . "
				ON DUPLICATE KEY UPDATE attempts = IF(status = 'done' OR status = 'failed', 0, attempts), status = IF(status = 'leased' AND content_hash = VALUES(content_hash), status, 'pending'), job_id = VALUES(job_id), content_hash = VALUES(content_hash), last_error = '', updated_at = VALUES(updated_at)",
				$vargs
			);
		}
		return array(
			'cursor'    => $cursor,
			'documents' => count( $docs ),
			'enqueued'  => $count,
		);
	}

	/**
	 * Returns expired leases to the pending state.
	 *
	 * @return int
	 */
	public static function expire_leases() {
		return max(
			0,
			Db::query(
				"UPDATE {queue} SET status = IF(attempts >= %d, 'failed', 'pending'), lease_hash = '', lease_owner = 0, last_error = IF(attempts >= %d, 'lease expired too many times', 'lease expired'), updated_at = %s WHERE status = 'leased' AND lease_expires < %s",
				array( self::MAX_ATTEMPT, self::MAX_ATTEMPT, Util::now(), Util::now() )
			)
		);
	}

	/**
	 * Claims a batch.
	 *
	 * @param int $user_id Worker user ID.
	 * @param int $limit   Items.
	 * @return array{lease_token:string,expires_at:string,profile:array,items:array}
	 */
	public static function claim( $user_id, $limit ) {
		self::expire_leases();
		$profile = ModelProfile::active();
		$limit   = Util::clamp_int( $limit, 1, self::MAX_CLAIM );
		$token   = Util::token( 24 );
		$hash    = Util::sha256( $token );
		$expires = Util::now( self::LEASE_TTL );
		Db::query(
			"UPDATE {queue} SET status = 'leased', lease_hash = %s, lease_owner = %d, lease_expires = %s, attempts = attempts + 1, updated_at = %s WHERE status = 'pending' AND model_key = %s AND attempts < %d ORDER BY id ASC LIMIT %d",
			array( $hash, (int) $user_id, $expires, Util::now(), $profile['model_key'], self::MAX_ATTEMPT, $limit )
		);
		$rows  = Db::rows(
			'SELECT q.id, q.document_id, q.chunk_id, q.content_hash, c.kind, c.text, d.locale FROM {queue} q JOIN {chunks} c ON c.document_id = q.document_id AND c.chunk_index = q.chunk_id JOIN {documents} d ON d.id = q.document_id WHERE q.lease_hash = %s',
			array( $hash )
		);
		$items = array();
		foreach ( $rows as $r ) {
			$items[] = array(
				'queue_id'     => (int) $r['id'],
				'document_id'  => (int) $r['document_id'],
				'chunk_index'  => (int) $r['chunk_id'],
				'kind'         => (string) $r['kind'],
				'locale'       => (string) $r['locale'],
				'content_hash' => (string) $r['content_hash'],
				'text'         => Util::truncate( (string) $r['text'], self::MAX_TEXT ),
				'role'         => 'document',
			);
		}
		return array(
			'lease_token' => array() === $items ? '' : $token,
			'expires_at'  => $expires,
			'profile'     => self::public_profile( $profile ),
			'items'       => $items,
		);
	}

	/**
	 * Accepts results for a lease.
	 *
	 * @param int    $user_id   Worker user ID.
	 * @param string $token     Lease token.
	 * @param string $model_key Model key the worker used.
	 * @param array  $results   [{queue_id, vector: float[]|base64}].
	 * @param string $source    browser|worker.
	 * @return array|\WP_Error Per-item outcome.
	 */
	public static function complete( $user_id, $token, $model_key, array $results, $source ) {
		$profile = ModelProfile::active();
		if ( ! hash_equals( $profile['model_key'], (string) $model_key ) ) {
			return new \WP_Error( 'sseo_model_mismatch', __( 'Vectors were produced with a different model profile and were rejected.', 'semantic-seo-engine-vi-en' ), array( 'status' => 409 ) );
		}
		// The device's own "self-test passed" is not accepted as proof: the server must have checked it.
		if ( ! DeviceVerification::is_verified( $user_id, $profile['model_key'] ) ) {
			return new \WP_Error( 'sseo_selftest_required', __( 'This device has no valid server-checked self-test for the current model profile; send the reference vectors to /embeddings/selftest first.', 'semantic-seo-engine-vi-en' ), array( 'status' => 428 ) );
		}
		$hash = Util::sha256( (string) $token );
		$out  = array(
			'stored'   => 0,
			'rejected' => array(),
		);
		foreach ( array_slice( $results, 0, self::MAX_CLAIM ) as $res ) {
			$qid = isset( $res['queue_id'] ) ? (int) $res['queue_id'] : 0;
			$row = Db::row( "SELECT * FROM {queue} WHERE id = %d AND lease_hash = %s AND lease_owner = %d AND status = 'leased'", array( $qid, $hash, (int) $user_id ) );
			if ( null === $row || strtotime( $row['lease_expires'] . ' UTC' ) < time() ) {
				$out['rejected'][] = array(
					'queue_id' => $qid,
					'reason'   => 'lease invalid, expired or already used',
				);
				continue;
			}
			$vector = self::decode_vector( isset( $res['vector'] ) ? $res['vector'] : null );
			$valid  = VectorMath::validate( $vector, (int) $profile['dimension'] );
			if ( true !== $valid ) {
				self::release_one( $qid, 'invalid vector: ' . $valid );
				$out['rejected'][] = array(
					'queue_id' => $qid,
					'reason'   => $valid,
				);
				continue;
			}
			$current = Db::value( 'SELECT content_hash FROM {chunks} WHERE document_id = %d AND chunk_index = %d', array( (int) $row['document_id'], (int) $row['chunk_id'] ) );
			if ( $current !== $row['content_hash'] ) {
				self::release_one( $qid, 'content changed while embedding' );
				$out['rejected'][] = array(
					'queue_id' => $qid,
					'reason'   => 'stale: content changed',
				);
				continue;
			}
			$locale = (string) Db::value( 'SELECT locale FROM {documents} WHERE id = %d', array( (int) $row['document_id'] ) );
			EmbeddingRepository::save(
				array(
					'document_id'  => (int) $row['document_id'],
					'chunk_id'     => (int) $row['chunk_id'],
					'content_hash' => $row['content_hash'],
					'locale'       => $locale,
				),
				$vector,
				$profile,
				$source
			);
			Db::query( "UPDATE {queue} SET status = 'done', lease_hash = '', lease_expires = NULL, updated_at = %s WHERE id = %d", array( Util::now(), $qid ) );
			// Let the waiting embedding job refresh its progress on the next tick.
			Db::query( "UPDATE {jobs} SET next_run_at = NULL WHERE id = %d AND status = 'running'", array( (int) $row['job_id'] ) );
			++$out['stored'];
		}
		return $out;
	}

	/**
	 * Releases every lease held by a user (a browser tab closing mid-request cannot know whether a
	 * claim or an upload was still in flight). Only the caller's own leases are touched.
	 *
	 * @param int    $user_id Worker user.
	 * @param string $error   Reason.
	 * @return int Released items.
	 */
	public static function release_all_for_user( $user_id, $error = '' ) {
		if ( (int) $user_id <= 0 ) {
			return 0;
		}
		return max(
			0,
			Db::query(
				"UPDATE {queue} SET status = IF(attempts >= %d, 'failed', 'pending'), lease_hash = '', lease_owner = 0, lease_expires = NULL, last_error = %s, updated_at = %s WHERE lease_owner = %d AND status = 'leased'",
				array( self::MAX_ATTEMPT, Util::truncate( (string) $error, 250 ), Util::now(), (int) $user_id )
			)
		);
	}

	/**
	 * Releases items of a lease (worker stopped, paused or failed).
	 *
	 * @param int    $user_id Worker user.
	 * @param string $token   Lease token.
	 * @param string $error   Reason.
	 * @return int Released items.
	 */
	public static function release( $user_id, $token, $error = '' ) {
		return max(
			0,
			Db::query(
				"UPDATE {queue} SET status = IF(attempts >= %d, 'failed', 'pending'), lease_hash = '', lease_owner = 0, lease_expires = NULL, last_error = %s, updated_at = %s WHERE lease_hash = %s AND lease_owner = %d AND status = 'leased'",
				array( self::MAX_ATTEMPT, Util::truncate( (string) $error, 250 ), Util::now(), Util::sha256( (string) $token ), (int) $user_id )
			)
		);
	}

	/**
	 * Releases one item.
	 *
	 * @param int    $qid   Queue ID.
	 * @param string $error Reason.
	 * @return void
	 */
	private static function release_one( $qid, $error ) {
		Db::query(
			"UPDATE {queue} SET status = IF(attempts >= %d, 'failed', 'pending'), lease_hash = '', lease_owner = 0, lease_expires = NULL, last_error = %s, updated_at = %s WHERE id = %d",
			array( self::MAX_ATTEMPT, Util::truncate( $error, 250 ), Util::now(), (int) $qid )
		);
	}

	/**
	 * Decodes a vector from a float array or base64 little-endian float32.
	 *
	 * @param mixed $v Input.
	 * @return array|null
	 */
	public static function decode_vector( $v ) {
		if ( is_array( $v ) ) {
			return array_map(
				static function ( $x ) {
					return is_numeric( $x ) ? (float) $x : NAN;
				},
				array_values( $v )
			);
		}
		if ( is_string( $v ) && strlen( $v ) <= 16384 ) {
			$bin = base64_decode( $v, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			return false === $bin ? null : VectorMath::unpack( $bin );
		}
		return null;
	}

	/**
	 * Queue counts (optionally for one job).
	 *
	 * @param int $job_id Job ID or 0.
	 * @return array<string,int>
	 */
	public static function counts( $job_id = 0 ) {
		$out  = array(
			'pending' => 0,
			'leased'  => 0,
			'done'    => 0,
			'failed'  => 0,
		);
		$rows = $job_id > 0
			? Db::rows( 'SELECT status, COUNT(*) AS n FROM {queue} WHERE job_id = %d GROUP BY status', array( (int) $job_id ) )
			: Db::rows( 'SELECT status, COUNT(*) AS n FROM {queue} WHERE model_key = %s GROUP BY status', array( ModelProfile::active()['model_key'] ) );
		foreach ( $rows as $r ) {
			$out[ $r['status'] ] = (int) $r['n'];
		}
		return $out;
	}

	/**
	 * Profile fields a worker needs (never includes secrets).
	 *
	 * @param array $p Profile.
	 * @return array
	 */
	public static function public_profile( array $p ) {
		return array(
			'model_key'          => $p['model_key'],
			'model_id'           => $p['model_id'],
			'revision'           => $p['revision'],
			'dtype'              => $p['dtype'],
			'dimension'          => $p['dimension'],
			'pooling'            => $p['pooling'],
			'normalize'          => true,
			'preprocess_version' => $p['preprocess_version'],
			'max_tokens'         => $p['max_tokens'],
			'host'               => $p['host'],
			'files'              => $p['files'],
			'query_task'         => $p['query_task'],
		);
	}
}
