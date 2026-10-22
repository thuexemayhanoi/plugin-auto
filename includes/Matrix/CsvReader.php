<?php
/**
 * Safe CSV reader (BOM, delimiter detection, size and row limits).
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Matrix;

defined( 'ABSPATH' ) || exit;

/**
 * Reads small administrator-uploaded CSV files. Never executes or evaluates content.
 */
final class CsvReader {

	const MAX_BYTES = 5242880;

	/**
	 * Reads a CSV file.
	 *
	 * @param string $path     File path.
	 * @param int    $max_rows Max data rows.
	 * @return array{header:string[],rows:array,delimiter:string}|\WP_Error
	 */
	public static function read( $path, $max_rows = 5000 ) {
		if ( ! is_string( $path ) || ! is_readable( $path ) || ! is_file( $path ) ) {
			return new \WP_Error( 'sseo_csv_unreadable', __( 'The uploaded file could not be read.', 'semantic-seo-engine-vi-en' ), array( 'status' => 400 ) );
		}
		$size = filesize( $path );
		if ( false === $size || $size > self::MAX_BYTES ) {
			return new \WP_Error( 'sseo_csv_too_large', __( 'The file is larger than 5 MB.', 'semantic-seo-engine-vi-en' ), array( 'status' => 413 ) );
		}
		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $raw ) {
			return new \WP_Error( 'sseo_csv_unreadable', __( 'The uploaded file could not be read.', 'semantic-seo-engine-vi-en' ), array( 'status' => 400 ) );
		}
		return self::parse( $raw, $max_rows );
	}

	/**
	 * Parses CSV text.
	 *
	 * @param string $raw      CSV text.
	 * @param int    $max_rows Max rows.
	 * @return array{header:string[],rows:array,delimiter:string}|\WP_Error
	 */
	public static function parse( $raw, $max_rows = 5000 ) {
		if ( 0 === strpos( $raw, "\xEF\xBB\xBF" ) ) {
			$raw = substr( $raw, 3 );
		}
		if ( ! mb_check_encoding( $raw, 'UTF-8' ) ) {
			return new \WP_Error( 'sseo_csv_encoding', __( 'The file must be UTF-8 encoded.', 'semantic-seo-engine-vi-en' ), array( 'status' => 400 ) );
		}
		$first     = strtok( $raw, "\n" );
		$delimiter = ',';
		$best      = 0;
		foreach ( array( ',', ';', "\t" ) as $d ) {
			$n = substr_count( (string) $first, $d );
			if ( $n > $best ) {
				$best      = $n;
				$delimiter = $d;
			}
		}
		$fh = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $fh, $raw ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		rewind( $fh );
		$header = fgetcsv( $fh, 0, $delimiter, '"', '\\' );
		if ( ! is_array( $header ) || array() === array_filter( $header ) ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new \WP_Error( 'sseo_csv_header', __( 'The file has no header row.', 'semantic-seo-engine-vi-en' ), array( 'status' => 400 ) );
		}
		$header = array_map(
			static function ( $h ) {
				return trim( (string) $h );
			},
			$header
		);
		$rows = array();
		while ( false !== ( $row = fgetcsv( $fh, 0, $delimiter, '"', '\\' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			if ( array( null ) === $row ) {
				continue;
			}
			$rows[] = $row;
			if ( count( $rows ) >= $max_rows ) {
				break;
			}
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return array(
			'header'    => $header,
			'rows'      => $rows,
			'delimiter' => $delimiter,
		);
	}

	/**
	 * Neutralizes spreadsheet formula injection in exported cells.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function safe_cell( $value ) {
		$value = is_scalar( $value ) || null === $value ? (string) $value : wp_json_encode( $value );
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $value;
		}
		return $value;
	}
}
