<?php
/**
 * How similar the pages of this site usually are to each other (per language).
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Semantic;

use SemanticSEO\Database\Db;
use SemanticSEO\Support\Util;

defined( 'ABSPATH' ) || exit;

/**
 * On a site about one subject every page is "about the same thing": the real-model pilot on a copy
 * of a one-topic blog showed most same-language pairs far above the fixed calibration floor, so the
 * semantic part alone pushed thousands of ordinary pairs into the overlap report. The baseline is
 * the median cosine of same-language page pairs of this site; the overlap gate counts only the
 * similarity above it (Scorer::calibrate). It is measured on a deterministic sample of pairs at
 * every site-wide analysis and stored with the model key it was measured with.
 */
final class SimilarityBaseline {

	const VERSION = 'baseline-1';
	const OPTION  = 'sseo_similarity_baseline';

	/**
	 * Pairs sampled per language (bounds the analysis step; ±0.01 on the median is enough).
	 */
	const MAX_PAIRS = 20000;

	/**
	 * Fewer pages than this in a language: no baseline (the fixed floor applies).
	 */
	const MIN_DOCS = 10;

	/**
	 * Request cache.
	 *
	 * @var array|null
	 */
	private static $memo = null;

	/**
	 * Measures and stores the baseline of every language.
	 *
	 * @param string $model_key Active model key.
	 * @return array Stored baseline.
	 */
	public static function compute( $model_key ) {
		$by_locale = array();
		foreach ( Db::rows( "SELECT id, locale FROM {documents} WHERE object_type = 'post' AND status = 'publish' AND indexable = 1 AND excluded = 0 AND locale <> ''" ) as $r ) {
			$by_locale[ $r['locale'] ][] = (int) $r['id'];
		}
		$locales = array();
		foreach ( $by_locale as $locale => $ids ) {
			$vectors = array_values( EmbeddingRepository::summary_vectors( $ids, $model_key ) );
			if ( count( $vectors ) < self::MIN_DOCS ) {
				continue;
			}
			$sims = self::sample( $vectors );
			sort( $sims );
			$locales[ $locale ] = array(
				'documents' => count( $vectors ),
				'pairs'     => count( $sims ),
				'p10'       => round( self::quantile( $sims, 0.10 ), 4 ),
				'p50'       => round( self::quantile( $sims, 0.50 ), 4 ),
				'p90'       => round( self::quantile( $sims, 0.90 ), 4 ),
				'p99'       => round( self::quantile( $sims, 0.99 ), 4 ),
			);
		}
		$baseline = array(
			'version'     => self::VERSION,
			'model_key'   => (string) $model_key,
			'locales'     => $locales,
			'measured_at' => Util::now(),
		);
		update_option( self::OPTION, $baseline, false );
		self::$memo = null;
		return $baseline;
	}

	/**
	 * Cosine of sampled pairs (all pairs when there are few; otherwise a fixed pseudo-random sample,
	 * so two runs on the same vectors give the same baseline).
	 *
	 * @param float[][] $vectors Normalized vectors.
	 * @return float[]
	 */
	public static function sample( array $vectors ) {
		$n     = count( $vectors );
		$total = $n * ( $n - 1 ) / 2;
		$out   = array();
		if ( $total <= self::MAX_PAIRS ) {
			for ( $i = 0; $i < $n; $i++ ) {
				for ( $j = $i + 1; $j < $n; $j++ ) {
					$out[] = VectorMath::dot( $vectors[ $i ], $vectors[ $j ] );
				}
			}
			return $out;
		}
		// Park–Miller generator with a fixed seed: deterministic, independent of mt_rand state.
		$state = 12345;
		$taken = 0;
		while ( $taken < self::MAX_PAIRS ) {
			$state = ( 48271 * $state ) % 2147483647;
			$i     = $state % $n;
			$state = ( 48271 * $state ) % 2147483647;
			$j     = $state % $n;
			if ( $i !== $j ) {
				$out[] = VectorMath::dot( $vectors[ $i ], $vectors[ $j ] );
				++$taken;
			}
		}
		return $out;
	}

	/**
	 * Quantile of a sorted list (linear interpolation).
	 *
	 * @param float[] $sorted Sorted values.
	 * @param float   $q      0..1.
	 * @return float
	 */
	public static function quantile( array $sorted, $q ) {
		$n = count( $sorted );
		if ( 0 === $n ) {
			return 0.0;
		}
		$pos = ( $n - 1 ) * $q;
		$lo  = (int) floor( $pos );
		$hi  = (int) ceil( $pos );
		return (float) $sorted[ $lo ] + ( $pos - $lo ) * ( (float) $sorted[ $hi ] - (float) $sorted[ $lo ] );
	}

	/**
	 * Stored baseline, if it was measured with the active model.
	 *
	 * @param string $model_key Active model key.
	 * @return array|null
	 */
	public static function get( $model_key ) {
		if ( null === self::$memo ) {
			$stored     = get_option( self::OPTION, array() );
			self::$memo = is_array( $stored ) ? $stored : array();
		}
		if ( ! isset( self::$memo['model_key'], self::$memo['locales'] ) || self::$memo['model_key'] !== $model_key ) {
			return null;
		}
		return self::$memo;
	}

	/**
	 * Site median cosine of a language, or null (no baseline, other model, or disabled).
	 *
	 * @param string $locale    Locale.
	 * @param string $model_key Active model key.
	 * @return float|null
	 */
	public static function median( $locale, $model_key ) {
		/**
		 * Filters whether the overlap gate uses the site baseline (false = fixed calibration only).
		 *
		 * @param bool $enabled Default true.
		 */
		if ( ! apply_filters( 'sseo_similarity_baseline_enabled', true ) ) {
			return null;
		}
		$b = self::get( $model_key );
		return null !== $b && isset( $b['locales'][ $locale ]['p50'] ) ? (float) $b['locales'][ $locale ]['p50'] : null;
	}

	/**
	 * Forgets the request cache (tests, after settings changes).
	 *
	 * @return void
	 */
	public static function reset() {
		self::$memo = null;
	}
}
