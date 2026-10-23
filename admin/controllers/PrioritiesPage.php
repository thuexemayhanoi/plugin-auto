<?php
/**
 * Page — SEO priority queue: what to do first, with filters and export.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Admin;

use SemanticSEO\Analysis\PriorityQueue;
use SemanticSEO\Services\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Every item names the page, the problem, the evidence, the confidence, the expected benefit and
 * the recommended action, and links to the full finding.
 */
final class PrioritiesPage {

	const PER_PAGE = 25;

	/**
	 * Current filters from the query string.
	 *
	 * @return array
	 */
	public static function filters() {
		return array(
			'locale'   => sanitize_key( Ui::get( 'locale' ) ),
			'category' => sanitize_key( Ui::get( 'category' ) ),
			'priority' => (int) Ui::get( 'priority', '0' ),
			'search'   => Ui::get( 'search' ),
		);
	}

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public static function render() {
		Ui::header( __( 'What to do first', 'semantic-seo-engine-vi-en' ), __( 'Verified errors first, then likely problems, linking work and editorial improvements. Nothing is changed automatically: each item says what to check and why.', 'semantic-seo-engine-vi-en' ) );
		$f    = self::filters();
		$page = max( 1, (int) Ui::get( 'paged', '1' ) );
		$res  = Api::priorities(
			array_merge(
				$f,
				array(
					'limit'  => self::PER_PAGE,
					'offset' => ( $page - 1 ) * self::PER_PAGE,
				)
			)
		);
		if ( is_wp_error( $res ) ) {
			Ui::error_state( $res );
			Ui::footer();
			return;
		}
		echo '<form method="get" class="sseo-filters" role="search"><input type="hidden" name="page" value="sseo-priorities">';
		$locales = array( '' => __( 'All languages', 'semantic-seo-engine-vi-en' ) );
		foreach ( \SemanticSEO\Database\Db::col( "SELECT DISTINCT locale FROM {documents} WHERE locale <> '' ORDER BY locale" ) as $l ) {
			$locales[ $l ] = strtoupper( (string) $l );
		}
		Ui::out( Ui::select( 'locale', $locales, $f['locale'], __( 'Language', 'semantic-seo-engine-vi-en' ) ) );
		$cats = array( '' => __( 'All types', 'semantic-seo-engine-vi-en' ) );
		foreach ( PriorityQueue::CATEGORIES as $c ) {
			$cats[ $c ] = PriorityQueue::category_label( $c );
		}
		Ui::out( Ui::select( 'category', $cats, $f['category'], __( 'Type of issue', 'semantic-seo-engine-vi-en' ) ) );
		$prios = array( '0' => __( 'All priorities', 'semantic-seo-engine-vi-en' ) );
		foreach ( array( 1, 2, 3, 4 ) as $p ) {
			$prios[ (string) $p ] = PriorityQueue::priority_label( $p ) . ' (' . number_format_i18n( (int) $res['counts'][ $p ] ) . ')';
		}
		Ui::out( Ui::select( 'priority', $prios, (string) $f['priority'], __( 'Priority', 'semantic-seo-engine-vi-en' ) ) );
		echo '<label for="sseo-search">' . esc_html__( 'URL or title contains', 'semantic-seo-engine-vi-en' ) . '</label> <input id="sseo-search" type="search" name="search" value="' . esc_attr( $f['search'] ) . '"> <button class="button">' . esc_html__( 'Filter', 'semantic-seo-engine-vi-en' ) . '</button></form>';

		/* translators: %s: number of items */
		echo '<p class="sseo-total" data-total="' . (int) $res['total'] . '"><strong>' . esc_html( sprintf( __( '%s items', 'semantic-seo-engine-vi-en' ), number_format_i18n( (int) $res['total'] ) ) ) . '</strong> — ' . esc_html( $res['notice'] ) . '</p>';
		self::table( $res['items'] );
		Ui::pagination( (int) $res['total'], $page, self::PER_PAGE, 'sseo-priorities', array_filter( array_map( 'strval', $f ) ) );
		Ui::out( Ui::action_button( 'export_priorities', array_map( 'strval', $f ), __( 'Download CSV (Excel)', 'semantic-seo-engine-vi-en' ) ) );
		echo '<p class="description">' . esc_html__( 'The CSV contains every item matching the filters, with the evidence, the confidence, the expected benefit and the action. AI agents read the same list through the REST API (GET /wp-json/semantic-seo/v1/priorities).', 'semantic-seo-engine-vi-en' ) . '</p>';
		Ui::footer();
	}

	/**
	 * Item table (also used on the dashboard).
	 *
	 * @param array[] $items Items.
	 * @return void
	 */
	public static function table( array $items ) {
		if ( array() === $items ) {
			Ui::empty_state( __( 'Nothing to show for these filters.', 'semantic-seo-engine-vi-en' ) );
			return;
		}
		echo '<table class="widefat striped sseo-table sseo-priorities"><thead><tr><th scope="col">' . esc_html__( 'Priority', 'semantic-seo-engine-vi-en' ) . '</th><th scope="col">' . esc_html__( 'Problem and page', 'semantic-seo-engine-vi-en' ) . '</th><th scope="col">' . esc_html__( 'Evidence', 'semantic-seo-engine-vi-en' ) . '</th><th scope="col">' . esc_html__( 'Confidence', 'semantic-seo-engine-vi-en' ) . '</th><th scope="col">' . esc_html__( 'What to do and why', 'semantic-seo-engine-vi-en' ) . '</th></tr></thead><tbody>';
		$kinds = array(
			'verified'  => __( 'Verified error', 'semantic-seo-engine-vi-en' ),
			'heuristic' => __( 'Warning to check', 'semantic-seo-engine-vi-en' ),
			'editorial' => __( 'Suggestion', 'semantic-seo-engine-vi-en' ),
		);
		foreach ( $items as $i ) {
			$detail = isset( $i['detail'] ) ? Ui::url( $i['detail']['page'], $i['detail']['args'] ) : ( isset( $i['detail_url'] ) ? $i['detail_url'] : '' );
			echo '<tr>';
			echo '<td data-label="' . esc_attr__( 'Priority', 'semantic-seo-engine-vi-en' ) . '"><span class="sseo-prio sseo-prio--' . (int) $i['priority'] . '">' . esc_html( $i['priority_label'] ) . '</span><br><small>' . esc_html( $i['category_label'] ) . '</small></td>';
			echo '<td data-label="' . esc_attr__( 'Problem and page', 'semantic-seo-engine-vi-en' ) . '"><strong>' . esc_html( $i['problem'] ) . '</strong> <small>(' . esc_html( $kinds[ $i['finding_kind'] ] ) . ')</small>';
			if ( '' !== $i['url'] ) {
				echo '<br><a href="' . esc_url( $i['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( '' !== $i['page_title'] ? $i['page_title'] : $i['url'] ) . '</a> <small class="sseo-url">' . esc_html( (string) wp_parse_url( $i['url'], PHP_URL_PATH ) ) . '</small>';
			}
			if ( '' !== $i['target_url'] ) {
				echo '<br>→ <a href="' . esc_url( $i['target_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( '' !== $i['target_title'] ? $i['target_title'] : $i['target_url'] ) . '</a>';
			}
			echo ' <span class="sseo-lang">' . esc_html( strtoupper( $i['locale'] ) ) . '</span></td>';
			echo '<td data-label="' . esc_attr__( 'Evidence', 'semantic-seo-engine-vi-en' ) . '">' . esc_html( $i['evidence'] ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'Confidence', 'semantic-seo-engine-vi-en' ) . '">' . esc_html( $i['confidence_label'] ) . '</td>';
			echo '<td data-label="' . esc_attr__( 'What to do and why', 'semantic-seo-engine-vi-en' ) . '">' . esc_html( $i['action'] ) . ( '' !== $i['benefit'] ? '<br><small>' . esc_html__( 'Expected benefit:', 'semantic-seo-engine-vi-en' ) . ' ' . esc_html( $i['benefit'] ) . '</small>' : '' ) . ( '' !== $detail ? '<br><a href="' . esc_url( $detail ) . '">' . esc_html__( 'Open the finding', 'semantic-seo-engine-vi-en' ) . '</a>' : '' ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}
}
