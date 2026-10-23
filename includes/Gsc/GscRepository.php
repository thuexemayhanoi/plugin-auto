<?php
/**
 * Imported Google Search Console data (only from a genuine export the administrator uploads).
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Gsc;

use SemanticSEO\Database\Db;
use SemanticSEO\Inventory\DocumentRepository;
use SemanticSEO\Matrix\CsvReader;
use SemanticSEO\Support\Util;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin never fabricates or estimates search metrics. Rows carry the import ID, period,
 * declared source and importing user. Without an import, every GSC field stays empty.
 */
final class GscRepository {

	const REQUIRED = array( 'page', 'query', 'clicks', 'impressions' );

	/**
	 * Imports a page×query CSV (e.g. Search Console API / BigQuery bulk export).
	 *
	 * @param string $path         Uploaded file path.
	 * @param string $period_start Y-m-d.
	 * @param string $period_end   Y-m-d.
	 * @param string $source       Declared source label.
	 * @param bool   $dry_run      Preview only.
	 * @return array|\WP_Error Report.
	 */
	public static function import( $path, $period_start, $period_end, $source, $dry_run = true ) {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $period_start ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $period_end ) || $period_start > $period_end ) {
			return new \WP_Error( 'sseo_gsc_period', __( 'A valid reporting period (start ≤ end) is required.', 'semantic-seo-engine-vi-en' ), array( 'status' => 400 ) );
		}
		$csv = CsvReader::read( $path, 50000 );
		if ( is_wp_error( $csv ) ) {
			return $csv;
		}
		$map = array();
		foreach ( $csv['header'] as $i => $h ) {
			$key         = strtolower( trim( preg_replace( '/[^a-z]+/i', '_', (string) $h ), '_' ) );
			$alias       = array(
				'top_pages'    => 'page',
				'landing_page' => 'page',
				'url'          => 'page',
				'top_queries'  => 'query',
				'queries'      => 'query',
				'search_query' => 'query',
			);
			$key         = isset( $alias[ $key ] ) ? $alias[ $key ] : $key;
			$map[ $key ] = $i;
		}
		foreach ( self::REQUIRED as $col ) {
			if ( ! isset( $map[ $col ] ) ) {
				return new \WP_Error( 'sseo_gsc_columns', sprintf( /* translators: %s: column list */ __( 'Missing required columns. Required: %s.', 'semantic-seo-engine-vi-en' ), implode( ', ', self::REQUIRED ) ), array( 'status' => 400 ) );
			}
		}
		$import_id = 'gsc-' . gmdate( 'YmdHis' ) . '-' . substr( Util::token( 4 ), 0, 6 );
		$valid     = 0;
		$invalid   = array();
		$matched   = 0;
		foreach ( $csv['rows'] as $n => $r ) {
			$page  = isset( $r[ $map['page'] ] ) ? trim( (string) $r[ $map['page'] ] ) : '';
			$query = isset( $r[ $map['query'] ] ) ? trim( (string) $r[ $map['query'] ] ) : '';
			$clk   = isset( $r[ $map['clicks'] ] ) ? str_replace( array( ',', ' ' ), '', (string) $r[ $map['clicks'] ] ) : '';
			$imp   = isset( $r[ $map['impressions'] ] ) ? str_replace( array( ',', ' ' ), '', (string) $r[ $map['impressions'] ] ) : '';
			if ( '' === $page || '' === $query || ! ctype_digit( $clk ) || ! ctype_digit( $imp ) || ! filter_var( $page, FILTER_VALIDATE_URL ) ) {
				if ( count( $invalid ) < 50 ) {
					$invalid[] = array(
						'row'    => $n + 2,
						'reason' => 'page URL, query, clicks and impressions must be present and numeric',
					);
				}
				continue;
			}
			++$valid;
			$doc = DocumentRepository::by_url( $page );
			if ( null !== $doc ) {
				++$matched;
			}
			if ( $dry_run ) {
				continue;
			}
			Db::insert(
				'gsc',
				array(
					'import_id'    => $import_id,
					'page_url'     => Util::truncate( $page, 2000 ),
					'page_hash'    => DocumentRepository::url_hash( $page ),
					'document_id'  => null === $doc ? 0 : (int) $doc['id'],
					'query'        => Util::truncate( $query, 250 ),
					'clicks'       => (int) $clk,
					'impressions'  => (int) $imp,
					'ctr'          => isset( $map['ctr'], $r[ $map['ctr'] ] ) ? (float) str_replace( '%', '', (string) $r[ $map['ctr'] ] ) : 0,
					'position'     => isset( $map['position'], $r[ $map['position'] ] ) ? (float) $r[ $map['position'] ] : 0,
					'period_start' => $period_start,
					'period_end'   => $period_end,
					'source'       => Util::truncate( sanitize_text_field( (string) $source ), 60 ),
					'imported_at'  => Util::now(),
					'imported_by'  => get_current_user_id(),
				)
			);
		}
		return array(
			'import_id'            => $dry_run ? null : $import_id,
			'dry_run'              => (bool) $dry_run,
			'rows_total'           => count( $csv['rows'] ),
			'rows_valid'           => $valid,
			'rows_invalid'         => count( $csv['rows'] ) - $valid,
			'invalid_samples'      => $invalid,
			'matched_to_inventory' => $matched,
			'period'               => array( $period_start, $period_end ),
		);
	}

	/**
	 * Shared-query overlap between a source and candidate documents (same import period).
	 *
	 * @param int   $source_id  Source document ID.
	 * @param int[] $candidates Candidate IDs.
	 * @return array{available:bool,with_data:int[],overlap:array<int,int>}
	 */
	public static function overlaps( $source_id, array $candidates ) {
		$has_any = null !== Db::value( 'SELECT id FROM {gsc} WHERE document_id > 0 LIMIT 1' );
		$empty   = array(
			'available' => false,
			'with_data' => array(),
			'overlap'   => array(),
		);
		if ( ! $has_any || array() === $candidates ) {
			return $empty;
		}
		$src = (int) Db::value( 'SELECT COUNT(*) FROM {gsc} WHERE document_id = %d AND impressions > 0', array( (int) $source_id ) );
		if ( 0 === $src ) {
			return $empty;
		}
		$candidates = array_map( 'intval', $candidates );
		$with       = array_map( 'intval', Db::col( 'SELECT DISTINCT document_id FROM {gsc} WHERE impressions > 0 AND document_id IN (' . Db::in( $candidates ) . ')', $candidates ) );
		$overlap    = array();
		if ( array() !== $with ) {
			$rows = Db::rows(
				'SELECT b.document_id, COUNT(DISTINCT a.query) AS n FROM {gsc} a JOIN {gsc} b ON a.query = b.query AND a.import_id = b.import_id AND b.impressions > 0 WHERE a.document_id = %d AND a.impressions > 0 AND b.document_id IN (' . Db::in( $with ) . ') GROUP BY b.document_id',
				array_merge( array( (int) $source_id ), $with )
			);
			foreach ( $rows as $r ) {
				$overlap[ (int) $r['document_id'] ] = (int) $r['n'];
			}
		}
		return array(
			'available' => true,
			'with_data' => $with,
			'overlap'   => $overlap,
		);
	}

	/**
	 * Imports overview.
	 *
	 * @return array
	 */
	public static function imports() {
		return Db::rows( 'SELECT import_id, source, period_start, period_end, MIN(imported_at) AS imported_at, imported_by, COUNT(*) AS rows_count, SUM(document_id > 0) AS matched FROM {gsc} GROUP BY import_id, source, period_start, period_end, imported_by ORDER BY imported_at DESC LIMIT 20' );
	}

	/**
	 * Deletes an import.
	 *
	 * @param string $import_id Import ID.
	 * @return int
	 */
	public static function delete_import( $import_id ) {
		return Db::delete( 'gsc', array( 'import_id' => (string) $import_id ) );
	}

	/**
	 * Metrics of one document (sum per import) for the matrix.
	 *
	 * @param int $doc_id Document ID.
	 * @return array
	 */
	public static function document_metrics( $doc_id ) {
		return Db::rows( 'SELECT import_id, source, period_start, period_end, SUM(clicks) AS clicks, SUM(impressions) AS impressions, COUNT(DISTINCT query) AS queries FROM {gsc} WHERE document_id = %d GROUP BY import_id, source, period_start, period_end', array( (int) $doc_id ) );
	}
}
