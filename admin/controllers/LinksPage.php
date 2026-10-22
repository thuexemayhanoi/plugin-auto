<?php
/**
 * Page 4 — Link Suggestions & Graph.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Admin;

use SemanticSEO\Capabilities;
use SemanticSEO\Database\Db;
use SemanticSEO\Services\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Suggestions (with exact anchors), orphan candidates and graph statistics.
 */
final class LinksPage {

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public static function render() {
		Ui::header( __( 'Link Suggestions & Graph', 'semantic-seo-engine-vi-en' ), __( 'Suggestions only: no link is inserted until you create a preview, review the exact diff and approve it.', 'semantic-seo-engine-vi-en' ) );
		$args = array(
			'locale'     => Ui::get( 'locale' ),
			'confidence' => Ui::get( 'confidence' ),
			'status'     => Ui::get( 'status', 'open' ),
			'limit'      => 20,
			'cursor'     => Ui::get( 'cursor' ),
		);
		if ( '' !== Ui::get( 'doc' ) ) {
			$args['document_id'] = (int) Ui::get( 'doc' );
		}
		echo '<form method="get" class="sseo-filters"><input type="hidden" name="page" value="sseo-links">';
		echo Ui::select( 'locale', array( '' => __( 'All', 'semantic-seo-engine-vi-en' ), 'vi' => 'VI', 'en' => 'EN' ), $args['locale'], __( 'Locale', 'semantic-seo-engine-vi-en' ) ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo Ui::select( 'confidence', array( '' => __( 'All', 'semantic-seo-engine-vi-en' ), 'high' => Ui::term( 'high' ), 'medium' => Ui::term( 'medium' ), 'low' => Ui::term( 'low' ) ), $args['confidence'], __( 'Confidence', 'semantic-seo-engine-vi-en' ) ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo Ui::select( 'status', array( 'open' => Ui::term( 'open' ), 'proposed' => Ui::term( 'proposed' ), 'accepted' => Ui::term( 'accepted' ), 'dismissed' => Ui::term( 'dismissed' ) ), $args['status'], __( 'Status', 'semantic-seo-engine-vi-en' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo ' <button class="button">' . esc_html__( 'Filter', 'semantic-seo-engine-vi-en' ) . '</button></form>';

		$res = Api::link_suggestions( $args );
		if ( is_wp_error( $res ) ) {
			Ui::error_state( $res );
			Ui::footer();
			return;
		}
		$docs = array();
		foreach ( $res['items'] as $f ) {
			$docs[] = (int) $f['document_id'];
			$docs[] = (int) $f['target_id'];
		}
		$meta = \SemanticSEO\Inventory\DocumentRepository::many( $docs );
		$rows = array();
		foreach ( $res['items'] as $f ) {
			$e   = $f['evidence'];
			$src = isset( $meta[ (int) $f['document_id'] ] ) ? $meta[ (int) $f['document_id'] ] : array( 'title' => '?', 'url' => '' );
			$tgt = isset( $meta[ (int) $f['target_id'] ] ) ? $meta[ (int) $f['target_id'] ] : array( 'title' => '?', 'url' => '' );
			$anchor = is_array( $e['anchor'] )
				? '<code class="sseo-snippet">' . esc_html( $e['anchor']['snippet'] ) . '</code><br><small>' . esc_html( sprintf( /* translators: 1: anchor source, 2: byte offset */ __( 'anchor from %1$s, byte offset %2$d', 'semantic-seo-engine-vi-en' ), $e['anchor']['phrase_source'], $e['anchor']['offset'] ) ) . '</small>'
				: '<em>' . esc_html__( 'No natural anchor — editorial insertion needed', 'semantic-seo-engine-vi-en' ) . '</em>';
			$c       = $e['components'];
			$actions = '';
			if ( 'open' === $f['status'] && is_array( $e['anchor'] ) && current_user_can( Capabilities::ANALYZE ) ) {
				$actions .= Ui::action_button( 'create_preview', array( 'finding_id' => $f['id'] ), __( 'Create preview', 'semantic-seo-engine-vi-en' ), 'button button-primary' );
			}
			if ( 'open' === $f['status'] && current_user_can( Capabilities::REVIEW ) ) {
				$actions .= Ui::action_button( 'review_finding', array( 'finding_id' => $f['id'], 'status' => 'dismissed' ), __( 'Dismiss', 'semantic-seo-engine-vi-en' ) );
			}
			$rows[] = array(
				'pair'     => Ui::doc_link( $f['document_id'], $src['title'], $src['url'] ) . '<div class="sseo-arrow" aria-hidden="true">↓</div>' . Ui::doc_link( $f['target_id'], $tgt['title'], $tgt['url'] ),
				'anchor'   => $anchor,
				'why'      => esc_html( $e['reason'] ) . ( ! empty( $e['notes'] ) ? '<br><small>' . esc_html( implode( '; ', (array) $e['notes'] ) ) . '</small>' : '' ),
				'evidence' => Ui::badge( $f['confidence'], 'confidence' ) . '<br>' . Ui::meter( $c['lexical'], __( 'Lexical', 'semantic-seo-engine-vi-en' ) ) . Ui::meter( $c['semantic'], __( 'Semantic', 'semantic-seo-engine-vi-en' ) ) . Ui::meter( $c['anchor'], __( 'Anchor', 'semantic-seo-engine-vi-en' ) ) . Ui::meter( $c['graph'], __( 'Graph', 'semantic-seo-engine-vi-en' ) ),
				'actions'  => $actions,
			);
		}
		Ui::table(
			__( 'Internal link suggestions', 'semantic-seo-engine-vi-en' ),
			array(
				'pair'     => __( 'Source → target', 'semantic-seo-engine-vi-en' ),
				'anchor'   => __( 'Anchor in source', 'semantic-seo-engine-vi-en' ),
				'why'      => __( 'Reason', 'semantic-seo-engine-vi-en' ),
				'evidence' => __( 'Evidence', 'semantic-seo-engine-vi-en' ),
				'actions'  => __( 'Actions', 'semantic-seo-engine-vi-en' ),
			),
			$rows,
			__( 'No suggestions match these filters.', 'semantic-seo-engine-vi-en' )
		);
		if ( ! empty( $res['next_cursor'] ) ) {
			echo '<p><a class="button" href="' . esc_url( Ui::url( 'sseo-links', array_filter( array_merge( $args, array( 'cursor' => $res['next_cursor'], 'limit' => null ) ) ) ) ) . '">' . esc_html__( 'More suggestions ›', 'semantic-seo-engine-vi-en' ) . '</a></p>';
		}

		// Orphans.
		$cov = $res['coverage'];
		echo '<h2>' . esc_html__( 'Orphan candidates', 'semantic-seo-engine-vi-en' ) . '</h2><p class="description">' . esc_html( sprintf( /* translators: 1: docs, 2: not observed */ __( 'Based on links observed by the scanner in %1$d documents. Not observed: %2$s.', 'semantic-seo-engine-vi-en' ), $cov['documents_scanned'], $cov['not_observed'] ) ) . '</p>';
		$rows = array();
		foreach ( $res['orphans'] as $o ) {
			$rows[] = array(
				'page'   => Ui::doc_link( $o['id'], $o['title'], $o['url'] ),
				'locale' => esc_html( strtoupper( $o['locale'] ) ),
				'words'  => esc_html( (string) $o['word_count'] ),
			);
		}
		Ui::table(
			__( 'Orphan candidates', 'semantic-seo-engine-vi-en' ),
			array(
				'page'   => __( 'Page', 'semantic-seo-engine-vi-en' ),
				'locale' => __( 'Locale', 'semantic-seo-engine-vi-en' ),
				'words'  => __( 'Words', 'semantic-seo-engine-vi-en' ),
			),
			$rows,
			__( 'No orphan candidates among scanned documents.', 'semantic-seo-engine-vi-en' )
		);

		// Graph statistics.
		echo '<h2>' . esc_html__( 'Graph', 'semantic-seo-engine-vi-en' ) . '</h2>';
		$hubs = Db::rows( "SELECT id, title, url, inbound_count, outbound_count FROM {documents} WHERE status = 'publish' ORDER BY inbound_count DESC, id ASC LIMIT 10" );
		$rows = array();
		foreach ( $hubs as $h ) {
			$rows[] = array(
				'page' => Ui::doc_link( $h['id'], $h['title'], $h['url'] ),
				'in'   => esc_html( (string) $h['inbound_count'] ),
				'out'  => esc_html( (string) $h['outbound_count'] ),
			);
		}
		echo '<p>' . esc_html( sprintf( /* translators: 1: links, 2: resolved, 3: unresolved */ __( '%1$d internal links observed, %2$d resolved to inventoried pages, %3$d unresolved or broken.', 'semantic-seo-engine-vi-en' ), $cov['internal_links'], $cov['resolved_links'], $cov['unresolved_links'] ) ) . '</p>';
		Ui::table(
			__( 'Most linked pages', 'semantic-seo-engine-vi-en' ),
			array(
				'page' => __( 'Page', 'semantic-seo-engine-vi-en' ),
				'in'   => __( 'Inbound', 'semantic-seo-engine-vi-en' ),
				'out'  => __( 'Outbound', 'semantic-seo-engine-vi-en' ),
			),
			$rows
		);
		Ui::footer();
	}
}
