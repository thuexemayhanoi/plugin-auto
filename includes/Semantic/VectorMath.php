<?php
/**
 * Dense vector helpers (packing, normalization, MRL truncation, cosine).
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Semantic;

defined( 'ABSPATH' ) || exit;

/**
 * Pure functions; unit-tested without WordPress.
 */
final class VectorMath {

	/**
	 * Packs floats as little-endian float32.
	 *
	 * @param float[] $vec Vector.
	 * @return string
	 */
	public static function pack( array $vec ) {
		return pack( 'g*', ...array_map( 'floatval', array_values( $vec ) ) );
	}

	/**
	 * Unpacks little-endian float32.
	 *
	 * @param string $bin Binary.
	 * @return float[]
	 */
	public static function unpack( $bin ) {
		if ( ! is_string( $bin ) || '' === $bin || 0 !== strlen( $bin ) % 4 ) {
			return array();
		}
		$out = unpack( 'g*', $bin );
		return false === $out ? array() : array_values( $out );
	}

	/**
	 * L2 norm.
	 *
	 * @param float[] $vec Vector.
	 * @return float
	 */
	public static function norm( array $vec ) {
		$s = 0.0;
		foreach ( $vec as $v ) {
			$s += $v * $v;
		}
		return sqrt( $s );
	}

	/**
	 * L2 normalization.
	 *
	 * @param float[] $vec Vector.
	 * @return float[]
	 */
	public static function normalize( array $vec ) {
		$n = self::norm( $vec );
		if ( $n <= 0.0 ) {
			return $vec;
		}
		return array_map(
			static function ( $v ) use ( $n ) {
				return $v / $n;
			},
			$vec
		);
	}

	/**
	 * Matryoshka (MRL) truncation followed by re-normalization.
	 *
	 * @param float[] $vec Vector.
	 * @param int     $dim Target dimension.
	 * @return float[]
	 */
	public static function truncate( array $vec, $dim ) {
		return self::normalize( array_slice( array_values( $vec ), 0, max( 1, (int) $dim ) ) );
	}

	/**
	 * Dot product (= cosine for normalized vectors).
	 *
	 * @param float[] $a Vector.
	 * @param float[] $b Vector.
	 * @return float
	 */
	public static function dot( array $a, array $b ) {
		$n = min( count( $a ), count( $b ) );
		$s = 0.0;
		for ( $i = 0; $i < $n; $i++ ) {
			$s += $a[ $i ] * $b[ $i ];
		}
		return $s;
	}

	/**
	 * Cosine similarity.
	 *
	 * @param float[] $a Vector.
	 * @param float[] $b Vector.
	 * @return float
	 */
	public static function cosine( array $a, array $b ) {
		$na = self::norm( $a );
		$nb = self::norm( $b );
		if ( $na <= 0.0 || $nb <= 0.0 ) {
			return 0.0;
		}
		return self::dot( $a, $b ) / ( $na * $nb );
	}

	/**
	 * Validates an uploaded vector: finite numbers, expected dimension, unit norm within tolerance.
	 *
	 * @param mixed $vec       Candidate.
	 * @param int   $dimension Expected dimension.
	 * @return true|string True or reason.
	 */
	public static function validate( $vec, $dimension ) {
		if ( ! is_array( $vec ) ) {
			return 'not an array';
		}
		if ( count( $vec ) !== (int) $dimension ) {
			return 'dimension mismatch';
		}
		foreach ( $vec as $v ) {
			if ( ! is_float( $v ) && ! is_int( $v ) ) {
				return 'non-numeric value';
			}
			if ( ! is_finite( (float) $v ) ) {
				return 'non-finite value';
			}
		}
		$n = self::norm( $vec );
		if ( abs( $n - 1.0 ) > 0.01 ) {
			return 'vector is not L2-normalized';
		}
		return true;
	}
}
