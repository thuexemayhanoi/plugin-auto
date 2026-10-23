<?php
/**
 * Matrix exports: CSV (UTF-8 with BOM for Excel) and genuine XLSX (Office Open XML via ZipArchive).
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Matrix;

defined( 'ABSPATH' ) || exit;

/**
 * Exports stream rows in batches. XLSX is a real SpreadsheetML workbook, never a renamed CSV.
 */
final class MatrixExporter {

	/**
	 * Writes CSV to a stream.
	 *
	 * @param resource $out     Output stream.
	 * @param array    $filters Matrix filters.
	 * @return int Rows written.
	 */
	public static function csv( $out, array $filters ) {
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fputcsv( $out, MatrixQuery::COLUMNS, ',', '"', '\\' );
		return MatrixQuery::each(
			$filters,
			static function ( $row ) use ( $out ) {
				$line = array();
				foreach ( MatrixQuery::COLUMNS as $col ) {
					$line[] = CsvReader::safe_cell( isset( $row[ $col ] ) ? $row[ $col ] : '' );
				}
				fputcsv( $out, $line, ',', '"', '\\' );
			}
		);
	}

	/**
	 * Whether XLSX export is possible on this server.
	 *
	 * @return bool
	 */
	public static function xlsx_available() {
		return class_exists( '\ZipArchive' );
	}

	/**
	 * Builds an XLSX file and returns its path.
	 *
	 * @param array  $filters Filters.
	 * @param string $path    Destination path.
	 * @return int|\WP_Error Rows written.
	 */
	public static function xlsx( array $filters, $path ) {
		if ( ! self::xlsx_available() ) {
			return new \WP_Error( 'sseo_xlsx_unavailable', __( 'XLSX export needs the PHP zip extension; use CSV instead.', 'semantic-seo-engine-vi-en' ), array( 'status' => 501 ) );
		}
		$tmp = wp_tempnam( 'sseo-sheet' );
		$fh  = fopen( $tmp, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $fh, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $fh, self::row_xml( 1, MatrixQuery::COLUMNS ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		$r     = 1;
		$count = MatrixQuery::each(
			$filters,
			static function ( $row ) use ( $fh, &$r ) {
				++$r;
				$cells = array();
				foreach ( MatrixQuery::COLUMNS as $col ) {
					$cells[] = isset( $row[ $col ] ) ? $row[ $col ] : '';
				}
				fwrite( $fh, self::row_xml( $r, $cells ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			}
		);
		fwrite( $fh, '</sheetData></worksheet>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'sseo_xlsx_write', __( 'Could not create the XLSX file.', 'semantic-seo-engine-vi-en' ), array( 'status' => 500 ) );
		}
		$zip->addFromString( '[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>' );
		$zip->addFromString( '_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>' );
		$zip->addFromString( 'xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="SEO Matrix" sheetId="1" r:id="rId1"/></sheets></workbook>' );
		$zip->addFromString( 'xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>' );
		$zip->addFile( $tmp, 'xl/worksheets/sheet1.xml' );
		$zip->close();
		wp_delete_file( $tmp );
		return $count;
	}

	/**
	 * One row as inline-string cells (numbers as numeric cells).
	 *
	 * @param int   $r     Row number.
	 * @param array $cells Values.
	 * @return string
	 */
	private static function row_xml( $r, array $cells ) {
		$xml = '<row r="' . (int) $r . '">';
		foreach ( array_values( $cells ) as $i => $v ) {
			$ref = self::col_letter( $i ) . (int) $r;
			if ( is_int( $v ) || is_float( $v ) ) {
				$xml .= '<c r="' . $ref . '"><v>' . ( is_float( $v ) ? rtrim( rtrim( sprintf( '%.6F', $v ), '0' ), '.' ) : (int) $v ) . '</v></c>';
				continue;
			}
			$s    = null === $v ? '' : ( is_scalar( $v ) ? (string) $v : wp_json_encode( $v ) );
			$s    = preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $s );
			$xml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . htmlspecialchars( (string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . '</t></is></c>';
		}
		return $xml . '</row>';
	}

	/**
	 * Column index → letters (0 → A).
	 *
	 * @param int $i Index.
	 * @return string
	 */
	private static function col_letter( $i ) {
		$s = '';
		++$i;
		while ( $i > 0 ) {
			$m = ( $i - 1 ) % 26;
			$s = chr( 65 + $m ) . $s;
			$i = (int) ( ( $i - $m ) / 26 );
		}
		return $s;
	}
}
