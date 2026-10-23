<?php
/**
 * BM25F-lite inverted index stored in a custom table.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Lexical;

use SemanticSEO\Database\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Field-weighted term frequencies per document; BM25 scoring at query time. Terms are stored as
 * VARBINARY so database collations can never merge distinct Vietnamese syllables.
 */
final class LexicalIndex {

	const VERSION = 'bm25-1';
	const K1      = 1.2;
	const B       = 0.75;

	const FIELD_WEIGHTS = array(
		'title'       => 3.0,
		'focus'       => 2.5,
		'headings'    => 2.0,
		'description' => 1.5,
		'body'        => 1.0,
	);

	/**
	 * Request cache of corpus statistics.
	 *
	 * @var array
	 */
	private static $stats_cache = array();

	/**
	 * Clears cached statistics (after indexing, between tests).
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$stats_cache = array();
	}

	/**
	 * Builds weighted term frequencies for a document.
	 *
	 * @param array  $fields Field name => text.
	 * @param string $lang   Document language.
	 * @return array<string,float>
	 */
	public static function document_terms( array $fields, $lang = '' ) {
		$tf = array();
		foreach ( self::FIELD_WEIGHTS as $field => $weight ) {
			if ( ! empty( $fields[ $field ] ) ) {
				$tf = Tokenizer::term_frequencies( (string) $fields[ $field ], $weight, $tf, $lang );
			}
		}
		// Cap the number of distinct terms per document (bounded storage on very long pages).
		if ( count( $tf ) > 4000 ) {
			arsort( $tf );
			$tf = array_slice( $tf, 0, 4000, true );
		}
		return $tf;
	}

	/**
	 * Replaces the postings of a document.
	 *
	 * @param int   $doc_id Document ID.
	 * @param array $tf     Term => weighted tf.
	 * @return int Weighted document length.
	 */
	public static function index_document( $doc_id, array $tf ) {
		$doc_id = (int) $doc_id;
		Db::delete( 'postings', array( 'document_id' => $doc_id ) );
		$len   = 0.0;
		$batch = array();
		$args  = array();
		foreach ( $tf as $term => $w ) {
			$term = (string) $term;
			if ( '' === $term || strlen( $term ) > 96 ) {
				continue;
			}
			$len    += $w;
			$batch[] = '(%s,%d,%f)';
			$args[]  = $term;
			$args[]  = $doc_id;
			$args[]  = round( (float) $w, 3 );
			if ( count( $batch ) >= 300 ) {
				Db::query( 'INSERT INTO {postings} (term, document_id, tf) VALUES ' . implode( ',', $batch ), $args );
				$batch = array();
				$args  = array();
			}
		}
		if ( array() !== $batch ) {
			Db::query( 'INSERT INTO {postings} (term, document_id, tf) VALUES ' . implode( ',', $batch ), $args );
		}
		self::flush_cache();
		return (int) round( $len );
	}

	/**
	 * Corpus statistics for a locale (cached per request).
	 *
	 * @param string $locale Locale ('' = all).
	 * @return array{n:int,avgdl:float}
	 */
	public static function stats( $locale = '' ) {
		if ( isset( self::$stats_cache[ $locale ] ) ) {
			return self::$stats_cache[ $locale ];
		}
		if ( '' === $locale ) {
			$row = Db::row( "SELECT COUNT(*) AS n, AVG(doc_len) AS avgdl FROM {documents} WHERE status = 'publish' AND doc_len > 0" );
		} else {
			$row = Db::row( "SELECT COUNT(*) AS n, AVG(doc_len) AS avgdl FROM {documents} WHERE status = 'publish' AND doc_len > 0 AND locale = %s", array( $locale ) );
		}
		self::$stats_cache[ $locale ] = array(
			'n'     => (int) ( $row ? $row['n'] : 0 ),
			'avgdl' => max( 1.0, (float) ( $row ? $row['avgdl'] : 1 ) ),
		);
		return self::$stats_cache[ $locale ];
	}

	/**
	 * Document frequencies for terms within a locale.
	 *
	 * @param string[] $terms  Terms.
	 * @param string   $locale Locale.
	 * @return array<string,int>
	 */
	public static function df( array $terms, $locale = '' ) {
		$terms = array_values( array_unique( array_map( 'strval', $terms ) ) );
		$out   = array();
		foreach ( array_chunk( $terms, 400 ) as $chunk ) {
			if ( '' === $locale ) {
				$rows = Db::rows( "SELECT p.term, COUNT(*) AS df FROM {postings} p JOIN {documents} d ON d.id = p.document_id WHERE d.status = 'publish' AND p.term IN (" . Db::in( $chunk, '%s' ) . ') GROUP BY p.term', $chunk );
			} else {
				$args = array_merge( $chunk, array( $locale ) );
				$rows = Db::rows( "SELECT p.term, COUNT(*) AS df FROM {postings} p JOIN {documents} d ON d.id = p.document_id WHERE d.status = 'publish' AND p.term IN (" . Db::in( $chunk, '%s' ) . ') AND d.locale = %s GROUP BY p.term', $args );
			}
			foreach ( $rows as $r ) {
				$out[ (string) $r['term'] ] = (int) $r['df'];
			}
		}
		return $out;
	}

	/**
	 * BM25 inverse document frequency.
	 *
	 * @param int $df Document frequency.
	 * @param int $n  Corpus size.
	 * @return float
	 */
	public static function idf( $df, $n ) {
		return log( 1 + ( ( $n - $df + 0.5 ) / ( $df + 0.5 ) ) );
	}

	/**
	 * BM25 search.
	 *
	 * @param array<string,float> $query   Term => query weight.
	 * @param string              $locale  Restrict to locale ('' = all).
	 * @param int                 $limit   Max results.
	 * @param int[]               $exclude Document IDs to exclude.
	 * @return array<int,float> Document ID => score, descending.
	 */
	public static function search( array $query, $locale = '', $limit = 20, array $exclude = array() ) {
		if ( array() === $query ) {
			return array();
		}
		$stats = self::stats( $locale );
		if ( 0 === $stats['n'] ) {
			return array();
		}
		// Keep the most informative query terms to bound the work.
		$df = self::df( array_keys( $query ), $locale );
		// A term found in at most the excluded documents (typically only the source itself) cannot
		// retrieve anything; letting such rare terms win the top-40 cut starved candidate recall.
		$min_df   = array() === $exclude ? 1 : 2;
		$weighted = array();
		foreach ( $query as $term => $qw ) {
			if ( empty( $df[ $term ] ) || $df[ $term ] < $min_df ) {
				continue;
			}
			$weighted[ $term ] = $qw * self::idf( $df[ $term ], $stats['n'] );
		}
		arsort( $weighted );
		$weighted = array_slice( $weighted, 0, 40, true );
		if ( array() === $weighted ) {
			return array();
		}
		$terms = array_keys( $weighted );
		$args  = $terms;
		$sql   = "SELECT p.term, p.document_id, p.tf, d.doc_len FROM {postings} p JOIN {documents} d ON d.id = p.document_id WHERE d.status = 'publish' AND d.excluded = 0 AND p.term IN (" . Db::in( $terms, '%s' ) . ')';
		if ( '' !== $locale ) {
			$sql   .= ' AND d.locale = %s';
			$args[] = $locale;
		}
		$scores = array();
		$ex     = array_flip( array_map( 'intval', $exclude ) );
		foreach ( Db::rows( $sql, $args ) as $r ) {
			$doc = (int) $r['document_id'];
			if ( isset( $ex[ $doc ] ) ) {
				continue;
			}
			$tf             = (float) $r['tf'];
			$norm           = $tf * ( self::K1 + 1 ) / ( $tf + self::K1 * ( 1 - self::B + self::B * ( (float) $r['doc_len'] / $stats['avgdl'] ) ) );
			$idf            = self::idf( $df[ $r['term'] ], $stats['n'] );
			$scores[ $doc ] = ( isset( $scores[ $doc ] ) ? $scores[ $doc ] : 0.0 ) + $norm * $idf * ( isset( $query[ $r['term'] ] ) ? $query[ $r['term'] ] : 1.0 );
		}
		arsort( $scores );
		return array_slice( $scores, 0, max( 1, (int) $limit ), true );
	}

	/**
	 * TF-IDF vector (top terms) of a document for cosine similarity.
	 *
	 * @param int    $doc_id Document ID.
	 * @param string $locale Locale for IDF.
	 * @param int    $top    Number of terms.
	 * @return array<string,float> Normalized vector.
	 */
	public static function document_vector( $doc_id, $locale = '', $top = 200 ) {
		$rows = Db::rows( 'SELECT term, tf FROM {postings} WHERE document_id = %d', array( (int) $doc_id ) );
		if ( array() === $rows ) {
			return array();
		}
		$tf = array();
		foreach ( $rows as $r ) {
			$term = (string) $r['term'];
			// Accent-folded duplicates only help unaccented queries; they would double-count overlap.
			if ( '~' === substr( $term, 0, 1 ) ) {
				continue;
			}
			$tf[ $term ] = (float) $r['tf'];
		}
		$stats = self::stats( $locale );
		$df    = self::df( array_keys( $tf ), $locale );
		$vec   = array();
		foreach ( $tf as $term => $w ) {
			$d            = isset( $df[ $term ] ) ? $df[ $term ] : 1;
			$vec[ $term ] = ( 1 + log( $w ) ) * self::idf( $d, max( 1, $stats['n'] ) );
		}
		arsort( $vec );
		$vec = array_slice( $vec, 0, $top, true );
		return self::normalize( $vec );
	}

	/**
	 * L2-normalizes a sparse vector.
	 *
	 * @param array<string,float> $vec Vector.
	 * @return array<string,float>
	 */
	public static function normalize( array $vec ) {
		$sum = 0.0;
		foreach ( $vec as $v ) {
			$sum += $v * $v;
		}
		if ( $sum <= 0 ) {
			return array();
		}
		$n = sqrt( $sum );
		foreach ( $vec as $k => $v ) {
			$vec[ $k ] = round( $v / $n, 5 );
		}
		return $vec;
	}

	/**
	 * Cosine of two normalized sparse vectors.
	 *
	 * @param array<string,float> $a Vector.
	 * @param array<string,float> $b Vector.
	 * @return float
	 */
	public static function cosine( array $a, array $b ) {
		if ( count( $a ) > count( $b ) ) {
			$tmp = $a;
			$a   = $b;
			$b   = $tmp;
		}
		$dot = 0.0;
		foreach ( $a as $k => $v ) {
			if ( isset( $b[ $k ] ) ) {
				$dot += $v * $b[ $k ];
			}
		}
		return max( 0.0, min( 1.0, $dot ) );
	}

	/**
	 * Shared terms between two vectors, most important first (human-readable evidence).
	 *
	 * @param array<string,float> $a Vector.
	 * @param array<string,float> $b Vector.
	 * @param int                 $n Max terms.
	 * @return string[]
	 */
	public static function shared_terms( array $a, array $b, $n = 8 ) {
		$shared = array();
		foreach ( $a as $k => $v ) {
			if ( isset( $b[ $k ] ) && '~' !== substr( (string) $k, 0, 1 ) ) {
				$shared[ $k ] = $v * $b[ $k ];
			}
		}
		arsort( $shared );
		return array_map(
			static function ( $t ) {
				return str_replace( '_', ' ', (string) $t );
			},
			array_slice( array_keys( $shared ), 0, $n )
		);
	}

	/**
	 * Removes the postings of a document.
	 *
	 * @param int $doc_id Document ID.
	 * @return void
	 */
	public static function delete_document( $doc_id ) {
		Db::delete( 'postings', array( 'document_id' => (int) $doc_id ) );
	}
}
