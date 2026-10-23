<?php
/**
 * Server-side check that a device really reproduces the pinned model before its vectors are kept.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Semantic;

use SemanticSEO\Audit\AuditLog;
use SemanticSEO\Support\Util;

defined( 'ABSPATH' ) || exit;

/**
 * A browser or private worker runs the model-card self-test locally, but a device saying "passed"
 * is only a claim. Before any vector is accepted, the device sends the vectors it computed for the
 * reference queries and documents; the server validates them (dimension, finite values, unit norm),
 * computes the query × document similarity matrix itself and compares it with the matrix measured
 * for the active precision. Only a pass, valid for this user and model profile for a limited time,
 * lets EmbeddingQueue::complete() store vectors. It proves the device's pipeline (model, precision,
 * tokenizer, instruction, pooling, normalization) reproduces the reference; it cannot prove a
 * dishonest authorized account computed every later vector the same way, which is why only a
 * dedicated, least-privilege worker account may upload at all.
 */
final class DeviceVerification {

	const META = 'sseo_embed_verification';

	/**
	 * How long a verification stays valid.
	 */
	const TTL = 86400;

	/**
	 * Verifies reference vectors and records the result.
	 *
	 * @param int    $user_id   User.
	 * @param string $model_key Model key the device used.
	 * @param array  $queries   Vectors of the reference queries (float arrays or base64).
	 * @param array  $documents Vectors of the reference documents.
	 * @param string $source    browser|worker.
	 * @return array|\WP_Error {verified, max_abs_diff, tolerance, matrix, expected, valid_until}
	 */
	public static function verify( $user_id, $model_key, array $queries, array $documents, $source ) {
		$profile = ModelProfile::active();
		if ( ! hash_equals( $profile['model_key'], (string) $model_key ) ) {
			return new \WP_Error( 'sseo_model_mismatch', __( 'The self-test was computed with a different model profile.', 'semantic-seo-engine-vi-en' ), array( 'status' => 409 ) );
		}
		$ref      = $profile['reference'];
		$expected = isset( $ref['scores_by_dtype'][ $profile['dtype'] ] ) ? $ref['scores_by_dtype'][ $profile['dtype'] ] : $ref['scores'];
		$tol      = isset( $ref['tolerance'][ $profile['dtype'] ] ) ? (float) $ref['tolerance'][ $profile['dtype'] ] : 0.03;
		if ( count( $queries ) !== count( $expected ) || count( $documents ) !== count( $expected[0] ) ) {
			return new \WP_Error( 'sseo_selftest_shape', __( 'Send one vector per reference query and per reference document.', 'semantic-seo-engine-vi-en' ), array( 'status' => 400 ) );
		}
		$q = array();
		$d = array();
		foreach ( array(
			'queries'   => $queries,
			'documents' => $documents,
		) as $kind => $list ) {
			foreach ( array_values( $list ) as $i => $raw ) {
				// Full model vectors (the reference was measured before any MRL truncation).
				$vec   = EmbeddingQueue::decode_vector( $raw );
				$dim   = is_array( $vec ) ? count( $vec ) : 0;
				$valid = $dim < (int) $profile['dimension'] || $dim > ModelProfile::MAX_DIMENSION ? 'dimension mismatch' : VectorMath::validate( $vec, $dim );
				if ( true !== $valid ) {
					/* translators: 1: queries/documents, 2: index, 3: reason. */
					return new \WP_Error( 'sseo_selftest_vector', sprintf( __( 'Invalid self-test vector (%1$s %2$d): %3$s', 'semantic-seo-engine-vi-en' ), $kind, $i, $valid ), array( 'status' => 400 ) );
				}
				if ( 'queries' === $kind ) {
					$q[] = $vec;
				} else {
					$d[] = $vec;
				}
			}
		}
		$matrix = array();
		$max    = 0.0;
		$rank   = true;
		foreach ( $q as $i => $qv ) {
			foreach ( $d as $j => $dv ) {
				$matrix[ $i ][ $j ] = round( VectorMath::dot( $qv, $dv ), 5 );
				$max                = max( $max, abs( $matrix[ $i ][ $j ] - (float) $expected[ $i ][ $j ] ) );
			}
			// Each query must also rank its own document first, as in the reference.
			$rank = $rank && array_search( max( $matrix[ $i ] ), $matrix[ $i ], true ) === array_search( max( $expected[ $i ] ), $expected[ $i ], true );
		}
		$ok     = $max <= $tol && $rank;
		$until  = time() + self::TTL;
		$result = array(
			'verified'     => $ok,
			'max_abs_diff' => round( $max, 5 ),
			'tolerance'    => $tol,
			'matrix'       => $matrix,
			'expected'     => $expected,
			'dtype'        => $profile['dtype'],
			'valid_until'  => $ok ? gmdate( 'Y-m-d H:i:s', $until ) : null,
		);
		$stored = get_user_meta( (int) $user_id, self::META, true );
		$stored = is_array( $stored ) ? $stored : array();
		if ( $ok ) {
			$stored[ $profile['model_key'] ] = array(
				'until'        => $until,
				'max_abs_diff' => $result['max_abs_diff'],
				'source'       => 'worker' === $source ? 'worker' : 'browser',
				'at'           => Util::now(),
			);
		} else {
			unset( $stored[ $profile['model_key'] ] );
		}
		update_user_meta( (int) $user_id, self::META, $stored );
		AuditLog::record(
			$ok ? 'embedding.selftest_verified' : 'embedding.selftest_failed',
			'user',
			(int) $user_id,
			$ok ? 'ok' : 'denied',
			array(
				'max_abs_diff' => $result['max_abs_diff'],
				'tolerance'    => $tol,
				'dtype'        => $profile['dtype'],
				'source'       => $source,
			)
		);
		return $result;
	}

	/**
	 * Whether the user has a valid server-side verification for a model profile.
	 *
	 * @param int    $user_id   User.
	 * @param string $model_key Model key.
	 * @return bool
	 */
	public static function is_verified( $user_id, $model_key ) {
		$stored = get_user_meta( (int) $user_id, self::META, true );
		return is_array( $stored ) && isset( $stored[ $model_key ]['until'] ) && (int) $stored[ $model_key ]['until'] > time();
	}

	/**
	 * Current verification of a user for the active profile (for the profile endpoint).
	 *
	 * @param int    $user_id   User.
	 * @param string $model_key Model key.
	 * @return array
	 */
	public static function status( $user_id, $model_key ) {
		$stored = get_user_meta( (int) $user_id, self::META, true );
		$row    = is_array( $stored ) && isset( $stored[ $model_key ] ) ? $stored[ $model_key ] : null;
		return array(
			'required'    => true,
			'endpoint'    => '/embeddings/selftest',
			'verified'    => null !== $row && (int) $row['until'] > time(),
			'valid_until' => null !== $row ? gmdate( 'Y-m-d H:i:s', (int) $row['until'] ) : null,
		);
	}
}
