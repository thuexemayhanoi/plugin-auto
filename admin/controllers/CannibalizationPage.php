<?php
/**
 * Page 5 — Cannibalization Review.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Admin;

use SemanticSEO\Capabilities;
use SemanticSEO\Services\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Same-locale overlap hypotheses grouped for review. No canonical/301/noindex/merge is ever applied.
 */
final class CannibalizationPage {

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public static function render() {
		Ui::header( __( 'Cannibalization Review', 'semantic-seo-engine-vi-en' ), __( 'Risk hypotheses between pages of the same language. Translations are never compared. Without imported Search Console data nothing here proves ranking competition.', 'semantic-seo-engine-vi-en' ) );
		$cats = array(
			''                             => __( 'All categories', 'semantic-seo-engine-vi-en' ),
			'potential_competition'        => Ui::term( 'potential_competition' ),
			'near_duplicate'               => Ui::term( 'near_duplicate' ),
			'exact_duplicate'              => Ui::term( 'exact_duplicate' ),
			'manual_review'                => Ui::term( 'manual_review' ),
			'distinct_facet'               => Ui::term( 'distinct_facet' ),
			'same_topic_different_intent'  => Ui::term( 'same_topic_different_intent' ),
			'pillar_supporting'            => Ui::term( 'pillar_supporting' ),
			'translation_mapping_conflict' => Ui::term( 'translation_mapping_conflict' ),
		);
		$args = array(
			'category' => Ui::get( 'category' ),
			'locale'   => Ui::get( 'locale' ),
			'status'   => Ui::get( 'status', 'open' ),
			'limit'    => 20,
			'cursor'   => Ui::get( 'cursor' ),
		);
		if ( '' !== Ui::get( 'doc' ) ) {
			$args['document_id'] = (int) Ui::get( 'doc' );
		}
		echo '<form method="get" class="sseo-filters"><input type="hidden" name="page" value="sseo-cannibalization">';
		echo Ui::select( 'category', $cats, $args['category'], __( 'Category', 'semantic-seo-engine-vi-en' ) ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo Ui::select( 'locale', array( '' => __( 'All', 'semantic-seo-engine-vi-en' ), 'vi' => 'VI', 'en' => 'EN' ), $args['locale'], __( 'Locale', 'semantic-seo-engine-vi-en' ) ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo Ui::select( 'status', array( 'open' => Ui::term( 'open' ), 'accepted' => Ui::term( 'accepted' ), 'dismissed' => Ui::term( 'dismissed' ) ), $args['status'], __( 'Status', 'semantic-seo-engine-vi-en' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo ' <button class="button">' . esc_html__( 'Filter', 'semantic-seo-engine-vi-en' ) . '</button></form>';
		$res = Api::cannibalization( $args );
		if ( is_wp_error( $res ) ) {
			Ui::error_state( $res );
			Ui::footer();
			return;
		}
		if ( array() === $res['items'] ) {
			Ui::empty_state( __( 'No overlap hypotheses for these filters.', 'semantic-seo-engine-vi-en' ) );
		}
		echo '<div class="sseo-pairs">';
		foreach ( $res['items'] as $f ) {
			$e     = $f['evidence'];
			$pages = (array) $e['pages'];
			echo '<article class="sseo-pair sseo-panel"><header><h3>' . Ui::badge( $f['check_id'], 'category' ) . ' ' . Ui::badge( $f['confidence'], 'confidence' ) . ' ' . Ui::badge( $f['label'], 'label' ) . '</h3>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			if ( 0 === strpos( (string) $f['group_key'], 'cg-' ) ) {
				echo '<p class="description">' . esc_html( sprintf( /* translators: %s: group */ __( 'Overlap group %s', 'semantic-seo-engine-vi-en' ), $f['group_key'] ) ) . '</p>';
			}
			echo '</header><div class="sseo-pair__pages">';
			foreach ( $pages as $id => $p ) {
				echo '<div>' . Ui::doc_link( (int) $id, $p['title'], $p['url'] ) . ( '' !== $p['role'] ? ' ' . Ui::badge( $p['role'], 'role' ) : '' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</div><ul>';
			foreach ( (array) $e['notes'] as $n ) {
				echo '<li>' . esc_html( $n ) . '</li>';
			}
			echo '</ul><div class="sseo-meters">';
			echo Ui::meter( $e['topic'], __( 'Topic', 'semantic-seo-engine-vi-en' ) ) . Ui::meter( $e['lexical'], __( 'Lexical', 'semantic-seo-engine-vi-en' ) ) . Ui::meter( $e['semantic'], __( 'Semantic', 'semantic-seo-engine-vi-en' ) ) . Ui::meter( $e['title_overlap'], __( 'Title overlap', 'semantic-seo-engine-vi-en' ) ) . Ui::meter( $e['near_duplicate'], __( 'Shingle overlap', 'semantic-seo-engine-vi-en' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</div><p><small>' . esc_html( sprintf( /* translators: 1: intents, 2: shared terms */ __( 'Intents: %1$s · Shared terms: %2$s', 'semantic-seo-engine-vi-en' ), implode( ' / ', array_map( array( Ui::class, 'term' ), (array) $e['intents'] ) ), implode( ', ', (array) $e['shared_terms'] ) ) ) . '</small></p>';
			echo '<p class="description">' . esc_html( $e['never_automatic'] ) . '</p>';
			if ( current_user_can( Capabilities::REVIEW ) && 'open' === $f['status'] ) {
				echo Ui::action_button( 'review_finding', array( 'finding_id' => $f['id'], 'status' => 'accepted' ), __( 'Confirm as issue to fix editorially', 'semantic-seo-engine-vi-en' ) ) . ' ' . Ui::action_button( 'review_finding', array( 'finding_id' => $f['id'], 'status' => 'dismissed' ), __( 'Not a problem', 'semantic-seo-engine-vi-en' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</article>';
		}
		echo '</div>';
		if ( ! empty( $res['next_cursor'] ) ) {
			echo '<p><a class="button" href="' . esc_url( Ui::url( 'sseo-cannibalization', array_filter( array( 'category' => $args['category'], 'locale' => $args['locale'], 'status' => $args['status'], 'cursor' => $res['next_cursor'] ) ) ) ) . '">' . esc_html__( 'More ›', 'semantic-seo-engine-vi-en' ) . '</a></p>';
		}
		Ui::footer();
	}
}
