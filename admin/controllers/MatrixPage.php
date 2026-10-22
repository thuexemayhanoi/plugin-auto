<?php
/**
 * Page 2 — URL Inventory / SEO Matrix (server-side filters, sorting, pagination, export, import).
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Admin;

use SemanticSEO\Capabilities;
use SemanticSEO\Gsc\GscRepository;
use SemanticSEO\Language\LanguageResolver;
use SemanticSEO\Matrix\MatrixExporter;
use SemanticSEO\Matrix\MatrixImporter;
use SemanticSEO\Services\Api;

defined( 'ABSPATH' ) || exit;

/**
 * SEO Matrix.
 */
final class MatrixPage {

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public static function render() {
		Ui::header( __( 'URL Inventory / SEO Matrix', 'semantic-seo-engine-vi-en' ), __( 'Every row is a real WordPress object from your database. Filters, sorting and pagination run on the server.', 'semantic-seo-engine-vi-en' ) );
		$args = array(
			'locale'   => Ui::get( 'locale' ),
			'type'     => Ui::get( 'type' ),
			'status'   => Ui::get( 'status' ),
			'search'   => Ui::get( 's' ),
			'intent'   => Ui::get( 'intent' ),
			'role'     => Ui::get( 'role' ),
			'review'   => Ui::get( 'review' ),
			'orphan'   => '1' === Ui::get( 'orphan' ),
			'has_qa'   => '1' === Ui::get( 'has_qa' ),
			'has_risk' => '1' === Ui::get( 'has_risk' ),
			'sort'     => Ui::get( 'sort', 'id' ),
			'order'    => Ui::get( 'order', 'asc' ),
			'page'     => max( 1, (int) Ui::get( 'paged', '1' ) ),
			'limit'    => 25,
		);
		self::filters( $args );
		$res = Api::list_urls( $args );
		if ( is_wp_error( $res ) ) {
			Ui::error_state( $res );
			Ui::footer();
			return;
		}
		$rows = array();
		foreach ( $res['items'] as $r ) {
			$rows[] = array(
				'title'   => Ui::doc_link( $r['document_id'], $r['title'], $r['url'] ) . '<br><small>' . esc_html( $r['subtype'] . ' #' . $r['object_id'] ) . '</small>',
				'locale'  => esc_html( strtoupper( $r['locale'] ) ) . '<br><small>' . esc_html( $r['locale_source'] ) . '</small>',
				'intent'  => ( '' !== $r['editorial_intent'] ? Ui::badge( $r['editorial_intent'], 'intent' ) . ' <small>' . esc_html__( 'editorial', 'semantic-seo-engine-vi-en' ) . '</small>' : Ui::badge( $r['heuristic_intent'], 'intent' ) . ' <small>' . esc_html( Ui::term( $r['intent_confidence'] ) ) . '</small>' ),
				'cluster' => esc_html( $r['cluster'] ) . ( '' !== $r['cluster_role'] ? '<br>' . Ui::badge( $r['cluster_role'], 'role' ) : '' ) . ( $r['cluster_locked'] ? ' 🔒' : '' ),
				'links'   => esc_html( sprintf( '%d / %d', $r['inbound_links'], $r['outbound_links'] ) ) . ( $r['proposed_links'] > 0 ? '<br><small>' . esc_html( sprintf( /* translators: %d: suggestions */ __( '+%d suggested', 'semantic-seo-engine-vi-en' ), $r['proposed_links'] ) ) . '</small>' : '' ),
				'seo'     => esc_html( $r['robots'] ) . ( $r['indexable'] ? '' : ' ' . Ui::badge( 'warning', 'label' ) ) . ( '' !== $r['primary_keyword'] ? '<br><small>' . esc_html__( 'Focus:', 'semantic-seo-engine-vi-en' ) . ' ' . esc_html( $r['primary_keyword'] ) . '</small>' : '' ),
				'qa'      => $r['qa_issues'] > 0 ? '<a href="' . esc_url( Ui::url( 'sseo-qa', array( 'doc' => $r['document_id'] ) ) ) . '">' . esc_html( (string) $r['qa_issues'] ) . '</a>' : '0',
				'risk'    => null === $r['overlap_risk'] ? '—' : '<a href="' . esc_url( Ui::url( 'sseo-cannibalization', array( 'doc' => $r['document_id'] ) ) ) . '">' . esc_html( (string) $r['overlap_risk'] ) . '</a>',
				'review'  => Ui::badge( $r['review_state'], 'status' ) . ( $r['excluded'] ? ' ' . Ui::badge( 'excluded', 'status' ) : '' ),
				'updated' => esc_html( (string) $r['last_analyzed'] ),
			);
		}
		/* translators: %d: total */
		echo '<p class="sseo-count" aria-live="polite">' . esc_html( sprintf( _n( '%d URL', '%d URLs', $res['total'], 'semantic-seo-engine-vi-en' ), $res['total'] ) ) . '</p>';
		Ui::table(
			__( 'SEO Matrix', 'semantic-seo-engine-vi-en' ),
			array(
				'title'   => __( 'Page', 'semantic-seo-engine-vi-en' ),
				'locale'  => __( 'Locale', 'semantic-seo-engine-vi-en' ),
				'intent'  => __( 'Intent', 'semantic-seo-engine-vi-en' ),
				'cluster' => __( 'Cluster / role', 'semantic-seo-engine-vi-en' ),
				'links'   => __( 'Links in / out', 'semantic-seo-engine-vi-en' ),
				'seo'     => __( 'Robots / focus', 'semantic-seo-engine-vi-en' ),
				'qa'      => __( 'QA', 'semantic-seo-engine-vi-en' ),
				'risk'    => __( 'Overlap risk', 'semantic-seo-engine-vi-en' ),
				'review'  => __( 'Review', 'semantic-seo-engine-vi-en' ),
				'updated' => __( 'Last analyzed', 'semantic-seo-engine-vi-en' ),
			),
			$rows,
			__( 'No URLs match. Run an inventory from the Overview page first.', 'semantic-seo-engine-vi-en' )
		);
		$keep = array_filter(
			array(
				'locale'   => $args['locale'],
				'type'     => $args['type'],
				's'        => $args['search'],
				'intent'   => $args['intent'],
				'role'     => $args['role'],
				'orphan'   => $args['orphan'] ? '1' : '',
				'has_qa'   => $args['has_qa'] ? '1' : '',
				'has_risk' => $args['has_risk'] ? '1' : '',
				'sort'     => $args['sort'],
				'order'    => $args['order'],
			)
		);
		Ui::pagination( $res['total'], $res['page'], 25, 'sseo-matrix', $keep );
		self::export( $args );
		if ( current_user_can( Capabilities::REVIEW ) ) {
			self::import();
		}
		if ( current_user_can( Capabilities::MANAGE ) ) {
			self::gsc();
		}
		Ui::footer();
	}

	/**
	 * Filter bar.
	 *
	 * @param array $a Args.
	 * @return void
	 */
	private static function filters( array $a ) {
		$locales = array( '' => __( 'All', 'semantic-seo-engine-vi-en' ) );
		foreach ( LanguageResolver::languages() as $l ) {
			$locales[ $l ] = strtoupper( $l );
		}
		echo '<form method="get" class="sseo-filters" role="search"><input type="hidden" name="page" value="sseo-matrix">';
		echo '<label for="sseo-s">' . esc_html__( 'Search title or URL', 'semantic-seo-engine-vi-en' ) . '</label> <input id="sseo-s" type="search" name="s" value="' . esc_attr( $a['search'] ) . '"> ';
		echo Ui::select( 'locale', $locales, $a['locale'], __( 'Locale', 'semantic-seo-engine-vi-en' ) ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo Ui::select( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'intent',
			array(
				''              => __( 'All', 'semantic-seo-engine-vi-en' ),
				'informational' => Ui::term( 'informational' ),
				'transactional' => Ui::term( 'transactional' ),
				'commercial'    => Ui::term( 'commercial' ),
				'navigational'  => Ui::term( 'navigational' ),
				'unknown'       => Ui::term( 'unknown' ),
			),
			$a['intent'],
			__( 'Intent', 'semantic-seo-engine-vi-en' )
		) . ' ';
		echo Ui::select( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'role',
			array(
				''           => __( 'All', 'semantic-seo-engine-vi-en' ),
				'pillar'     => Ui::term( 'pillar' ),
				'hub'        => Ui::term( 'hub' ),
				'supporting' => Ui::term( 'supporting' ),
			),
			$a['role'],
			__( 'Role', 'semantic-seo-engine-vi-en' )
		) . ' ';
		echo Ui::select( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'sort',
			array(
				'id'          => __( 'Inventory order', 'semantic-seo-engine-vi-en' ),
				'title'       => __( 'Title', 'semantic-seo-engine-vi-en' ),
				'risk'        => __( 'Overlap risk', 'semantic-seo-engine-vi-en' ),
				'qa'          => __( 'QA issues', 'semantic-seo-engine-vi-en' ),
				'inbound'     => __( 'Inbound links', 'semantic-seo-engine-vi-en' ),
				'suggestions' => __( 'Link suggestions', 'semantic-seo-engine-vi-en' ),
				'words'       => __( 'Word count', 'semantic-seo-engine-vi-en' ),
				'modified'    => __( 'Modified', 'semantic-seo-engine-vi-en' ),
			),
			$a['sort'],
			__( 'Sort', 'semantic-seo-engine-vi-en' )
		) . ' ';
		echo Ui::select( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			'order',
			array(
				'asc'  => __( 'Ascending', 'semantic-seo-engine-vi-en' ),
				'desc' => __( 'Descending', 'semantic-seo-engine-vi-en' ),
			),
			$a['order'],
			__( 'Order', 'semantic-seo-engine-vi-en' )
		);
		echo ' <label><input type="checkbox" name="orphan" value="1"' . checked( $a['orphan'], true, false ) . '> ' . esc_html__( 'Orphan candidates', 'semantic-seo-engine-vi-en' ) . '</label>';
		echo ' <label><input type="checkbox" name="has_qa" value="1"' . checked( $a['has_qa'], true, false ) . '> ' . esc_html__( 'Has QA issues', 'semantic-seo-engine-vi-en' ) . '</label>';
		echo ' <label><input type="checkbox" name="has_risk" value="1"' . checked( $a['has_risk'], true, false ) . '> ' . esc_html__( 'Has overlap risk', 'semantic-seo-engine-vi-en' ) . '</label>';
		echo ' <button class="button">' . esc_html__( 'Filter', 'semantic-seo-engine-vi-en' ) . '</button></form>';
	}

	/**
	 * Export forms.
	 *
	 * @param array $a Args.
	 * @return void
	 */
	private static function export( array $a ) {
		echo '<section class="sseo-panel"><h2>' . esc_html__( 'Export', 'semantic-seo-engine-vi-en' ) . '</h2><p>' . esc_html__( 'CSV is UTF-8 with BOM (opens correctly in Excel). XLSX is a genuine Office Open XML workbook.', 'semantic-seo-engine-vi-en' ) . '</p>';
		foreach ( array( 'csv', 'xlsx' ) as $fmt ) {
			if ( 'xlsx' === $fmt && ! MatrixExporter::xlsx_available() ) {
				echo '<p class="description">' . esc_html__( 'XLSX needs the PHP zip extension, which is not available here.', 'semantic-seo-engine-vi-en' ) . '</p>';
				continue;
			}
			Ui::form_open( 'export', 'sseo-inline-form' );
			foreach ( array( 'locale', 'intent', 'role', 'search' ) as $k ) {
				echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( (string) $a[ $k ] ) . '">';
			}
			echo '<input type="hidden" name="format" value="' . esc_attr( $fmt ) . '"><button class="button">' . esc_html( 'csv' === $fmt ? __( 'Download CSV', 'semantic-seo-engine-vi-en' ) : __( 'Download XLSX', 'semantic-seo-engine-vi-en' ) ) . '</button></form> ';
		}
		echo '</section>';
	}

	/**
	 * Import preview/apply.
	 *
	 * @return void
	 */
	private static function import() {
		echo '<section class="sseo-panel"><h2>' . esc_html__( 'Import editorial fields', 'semantic-seo-engine-vi-en' ) . '</h2><p>' . esc_html__( 'Only this plugin’s editorial fields can be imported (editorial intent, review state, cluster role, cluster lock, exclusion). Empty cells mean “no change”. WordPress content, SEO plugin data and URLs are never touched.', 'semantic-seo-engine-vi-en' ) . '</p>';
		$view = get_transient( 'sseo_import_view_' . get_current_user_id() );
		if ( is_array( $view ) ) {
			echo '<h3>' . esc_html__( 'Preview (nothing written yet)', 'semantic-seo-engine-vi-en' ) . '</h3>';
			$rows = array();
			foreach ( $view['changes'] as $c ) {
				$parts = array();
				foreach ( $c['changes'] as $f => $d ) {
					$parts[] = esc_html( $f . ': "' . $d['from'] . '" → "' . $d['to'] . '"' );
				}
				$rows[] = array(
					'row'     => esc_html( (string) $c['row'] ),
					'doc'     => esc_html( '#' . $c['document'] . ' ' . $c['title'] ),
					'changes' => implode( '<br>', $parts ),
				);
			}
			Ui::table(
				__( 'Changes to apply', 'semantic-seo-engine-vi-en' ),
				array(
					'row'     => __( 'CSV row', 'semantic-seo-engine-vi-en' ),
					'doc'     => __( 'Document', 'semantic-seo-engine-vi-en' ),
					'changes' => __( 'Changes', 'semantic-seo-engine-vi-en' ),
				),
				$rows,
				__( 'No changes.', 'semantic-seo-engine-vi-en' )
			);
			if ( array() !== $view['conflicts'] ) {
				/* translators: %d: count */
				Ui::notice( 'warning', sprintf( __( '%d values were not changed because the field already has a value. Tick “Allow overwriting existing values” and preview again to replace them.', 'semantic-seo-engine-vi-en' ), count( $view['conflicts'] ) ) );
			}
			if ( array() !== $view['invalid'] ) {
				$inv = array();
				foreach ( $view['invalid'] as $i ) {
					$inv[] = array(
						'row'    => esc_html( (string) $i['row'] ),
						'reason' => esc_html( $i['reason'] ),
					);
				}
				Ui::table(
					__( 'Isolated invalid rows', 'semantic-seo-engine-vi-en' ),
					array(
						'row'    => __( 'CSV row', 'semantic-seo-engine-vi-en' ),
						'reason' => __( 'Reason', 'semantic-seo-engine-vi-en' ),
					),
					$inv
				);
			}
			if ( array() !== $view['changes'] ) {
				Ui::form_open( 'import_apply' );
				echo '<input type="hidden" name="token" value="' . esc_attr( $view['token'] ) . '"><button class="button button-primary">' . esc_html__( 'Apply these changes', 'semantic-seo-engine-vi-en' ) . '</button></form>';
			}
		}
		Ui::form_open( 'import_preview', '', true );
		echo '<p><label for="sseo-import-file">' . esc_html__( 'CSV file (exported from this matrix or with columns document_id or url plus fields)', 'semantic-seo-engine-vi-en' ) . '</label><br><input id="sseo-import-file" type="file" name="file" accept=".csv,text/csv" required></p>';
		echo '<p><label><input type="checkbox" name="overwrite" value="1"> ' . esc_html__( 'Allow overwriting existing values', 'semantic-seo-engine-vi-en' ) . '</label></p>';
		echo '<p class="description">' . esc_html__( 'Columns are mapped automatically by header name:', 'semantic-seo-engine-vi-en' ) . ' ' . esc_html( implode( ', ', array_merge( MatrixImporter::KEYS, array_keys( MatrixImporter::FIELDS ) ) ) ) . '</p>';
		echo '<button class="button">' . esc_html__( 'Upload and preview', 'semantic-seo-engine-vi-en' ) . '</button></form></section>';
	}

	/**
	 * Search Console import.
	 *
	 * @return void
	 */
	private static function gsc() {
		echo '<section class="sseo-panel"><h2>' . esc_html__( 'Search Console data (optional)', 'semantic-seo-engine-vi-en' ) . '</h2><p>' . esc_html__( 'Upload a genuine page × query export (columns: page, query, clicks, impressions, optional ctr, position). Metrics are shown only after an authorized import, always with the period and source. Without it, overlap reports stay hypotheses.', 'semantic-seo-engine-vi-en' ) . '</p>';
		Ui::form_open( 'gsc_import', '', true );
		echo '<p><label for="sseo-gsc-file">' . esc_html__( 'CSV file', 'semantic-seo-engine-vi-en' ) . '</label> <input id="sseo-gsc-file" type="file" name="file" accept=".csv,text/csv" required></p>';
		echo '<p><label for="sseo-gsc-start">' . esc_html__( 'Period start', 'semantic-seo-engine-vi-en' ) . '</label> <input id="sseo-gsc-start" type="date" name="period_start" required> <label for="sseo-gsc-end">' . esc_html__( 'Period end', 'semantic-seo-engine-vi-en' ) . '</label> <input id="sseo-gsc-end" type="date" name="period_end" required> <label for="sseo-gsc-source">' . esc_html__( 'Source', 'semantic-seo-engine-vi-en' ) . '</label> <input id="sseo-gsc-source" type="text" name="source" value="Search Console export" maxlength="60"></p>';
		echo '<p><label><input type="checkbox" name="commit" value="1"> ' . esc_html__( 'Import (leave unticked for a dry run)', 'semantic-seo-engine-vi-en' ) . '</label></p><button class="button">' . esc_html__( 'Upload', 'semantic-seo-engine-vi-en' ) . '</button></form>';
		$imports = GscRepository::imports();
		if ( array() !== $imports ) {
			$rows = array();
			foreach ( $imports as $i ) {
				$rows[] = array(
					'id'      => esc_html( $i['import_id'] ),
					'period'  => esc_html( $i['period_start'] . ' → ' . $i['period_end'] ),
					'source'  => esc_html( $i['source'] ),
					'rows'    => esc_html( $i['rows_count'] . ' / ' . $i['matched'] ),
					'actions' => Ui::action_button( 'gsc_delete', array( 'import_id' => $i['import_id'] ), __( 'Delete', 'semantic-seo-engine-vi-en' ), 'button-link-delete button-link', __( 'Delete this import?', 'semantic-seo-engine-vi-en' ) ),
				);
			}
			Ui::table(
				__( 'Imports', 'semantic-seo-engine-vi-en' ),
				array(
					'id'      => __( 'Import', 'semantic-seo-engine-vi-en' ),
					'period'  => __( 'Period', 'semantic-seo-engine-vi-en' ),
					'source'  => __( 'Source', 'semantic-seo-engine-vi-en' ),
					'rows'    => __( 'Rows / matched', 'semantic-seo-engine-vi-en' ),
					'actions' => __( 'Actions', 'semantic-seo-engine-vi-en' ),
				),
				$rows
			);
		}
		echo '</section>';
	}
}
