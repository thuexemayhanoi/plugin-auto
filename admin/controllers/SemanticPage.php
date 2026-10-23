<?php
/**
 * Page 3 — Semantic Analysis of one URL: facts separated from inferences, with provenance.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Admin;

use SemanticSEO\Capabilities;
use SemanticSEO\Services\Api;

defined( 'ABSPATH' ) || exit;

/**
 * URL analysis screen.
 */
final class SemanticPage {

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public static function render() {
		Ui::header( __( 'Semantic Analysis', 'semantic-seo-engine-vi-en' ), __( 'Facts are measured from the page; inferences are heuristics or model signals with their evidence. They are shown separately on purpose.', 'semantic-seo-engine-vi-en' ) );
		$q   = Ui::get( 'q' );
		$doc = (int) Ui::get( 'doc', '0' );
		echo '<form method="get" class="sseo-filters" role="search"><input type="hidden" name="page" value="sseo-semantic"><label for="sseo-q">' . esc_html__( 'Find a page (title or URL)', 'semantic-seo-engine-vi-en' ) . '</label> <input id="sseo-q" type="search" name="q" value="' . esc_attr( $q ) . '"> <button class="button">' . esc_html__( 'Search', 'semantic-seo-engine-vi-en' ) . '</button></form>';
		if ( '' !== $q && 0 === $doc ) {
			$res = Api::list_urls(
				array(
					'search' => $q,
					'limit'  => 20,
				)
			);
			if ( is_wp_error( $res ) ) {
				Ui::error_state( $res );
			} else {
				$rows = array();
				foreach ( $res['items'] as $r ) {
					$rows[] = array(
						'page'   => Ui::doc_link( $r['document_id'], $r['title'], $r['url'] ),
						'locale' => esc_html( strtoupper( $r['locale'] ) ),
					);
				}
				Ui::table(
					__( 'Search results', 'semantic-seo-engine-vi-en' ),
					array(
						'page'   => __( 'Page', 'semantic-seo-engine-vi-en' ),
						'locale' => __( 'Locale', 'semantic-seo-engine-vi-en' ),
					),
					$rows,
					__( 'No matching page in the inventory.', 'semantic-seo-engine-vi-en' )
				);
			}
		}
		if ( $doc > 0 ) {
			$a = Api::url_analysis( array( 'document_id' => $doc ) );
			if ( is_wp_error( $a ) ) {
				Ui::error_state( $a );
			} else {
				self::analysis( $a );
			}
		} elseif ( '' === $q ) {
			Ui::empty_state( __( 'Search for a page above, or open one from the SEO Matrix.', 'semantic-seo-engine-vi-en' ) );
		}
		Ui::footer();
	}

	/**
	 * Renders one analysis.
	 *
	 * @param array $a Analysis.
	 * @return void
	 */
	private static function analysis( array $a ) {
		$d = $a['document'];
		echo '<h2>' . esc_html( $d['title'] ) . '</h2><p><a href="' . esc_url( $d['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $d['url'] ) . '</a></p>';
		echo '<div class="sseo-cols">';

		// Facts.
		echo '<section class="sseo-panel"><h3>' . esc_html__( 'Facts (measured)', 'semantic-seo-engine-vi-en' ) . '</h3><dl class="sseo-dl">';
		$facts = array(
			__( 'Status', 'semantic-seo-engine-vi-en' )    => Ui::term( $d['status'] ),
			__( 'Locale', 'semantic-seo-engine-vi-en' )    => strtoupper( $d['locale'] ) . ' (' . $d['locale_source'] . ')',
			__( 'Indexable', 'semantic-seo-engine-vi-en' ) => $d['indexable'] ? __( 'yes', 'semantic-seo-engine-vi-en' ) : __( 'no', 'semantic-seo-engine-vi-en' ) . ' — ' . implode( ', ', (array) $d['indexability_notes'] ),
			__( 'Robots', 'semantic-seo-engine-vi-en' )    => $d['robots'],
			__( 'Canonical', 'semantic-seo-engine-vi-en' ) => (string) $d['canonical'],
			__( 'SEO metadata source', 'semantic-seo-engine-vi-en' ) => $d['seo_source'],
			__( 'Focus keyword', 'semantic-seo-engine-vi-en' ) => '' !== $d['focus_keyword']['value'] ? $d['focus_keyword']['value'] . ' (' . $d['focus_keyword']['source'] . ')' : __( 'none set', 'semantic-seo-engine-vi-en' ),
			__( 'Words', 'semantic-seo-engine-vi-en' )     => (string) $a['facts']['word_count'],
			__( 'Internal links in / out', 'semantic-seo-engine-vi-en' ) => $a['facts']['inbound_internal'] . ' / ' . $a['facts']['outbound_internal'],
			__( 'Images without alt', 'semantic-seo-engine-vi-en' ) => $a['facts']['images_missing_alt'] . ' / ' . $a['facts']['images'],
		);
		foreach ( $facts as $k => $v ) {
			echo '<dt>' . esc_html( $k ) . '</dt><dd>' . esc_html( $v ) . '</dd>';
		}
		echo '</dl>';
		if ( ! empty( $d['translations'] ) ) {
			echo '<h4>' . esc_html__( 'Translations (from the language plugin)', 'semantic-seo-engine-vi-en' ) . '</h4><ul>';
			foreach ( (array) $d['translations'] as $lang => $pid ) {
				echo '<li>' . esc_html( strtoupper( (string) $lang ) ) . ': <a href="' . esc_url( (string) get_permalink( (int) $pid ) ) . '">' . esc_html( get_the_title( (int) $pid ) ) . '</a></li>';
			}
			echo '</ul>';
		}
		echo '<h4>' . esc_html__( 'Heading outline', 'semantic-seo-engine-vi-en' ) . '</h4><ul class="sseo-outline">';
		foreach ( array_slice( (array) $a['facts']['headings'], 0, 40 ) as $h ) {
			echo '<li class="sseo-h' . (int) $h['level'] . '"><span class="sseo-hl">H' . (int) $h['level'] . '</span> ' . esc_html( $h['text'] ) . '</li>';
		}
		echo '</ul><p class="description">' . esc_html( $a['facts']['link_coverage_note'] ) . '</p></section>';

		// Inferences.
		$i = $a['inferences'];
		echo '<section class="sseo-panel"><h3>' . esc_html__( 'Inferences (heuristic or model signals)', 'semantic-seo-engine-vi-en' ) . '</h3>';
		echo '<p><strong>' . esc_html__( 'Search intent:', 'semantic-seo-engine-vi-en' ) . '</strong> ' . Ui::badge( $i['intent']['label'], 'intent' ) . ' ' . Ui::badge( $i['intent']['confidence'], 'confidence' ) . ' <small>' . esc_html( $i['intent']['source'] ) . '</small></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( ! empty( $i['intent']['evidence']['evidence'] ) ) {
			echo '<ul class="sseo-evidence">';
			foreach ( (array) $i['intent']['evidence']['evidence'] as $e ) {
				echo '<li>' . esc_html( sprintf( /* translators: 1: cue, 2: field, 3: intent */ __( '“%1$s” in %2$s → %3$s', 'semantic-seo-engine-vi-en' ), $e['cue'], $e['field'], Ui::term( $e['intent'] ) ) ) . '</li>';
			}
			echo '</ul>';
		}
		echo '<p><strong>' . esc_html__( 'Topic terms:', 'semantic-seo-engine-vi-en' ) . '</strong> ' . esc_html( implode( ', ', array_slice( (array) $i['topics'], 0, 15 ) ) ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Candidate entities:', 'semantic-seo-engine-vi-en' ) . '</strong> ' . esc_html(
			implode(
				', ',
				array_map(
					static function ( $e ) {
						return $e['text'] . ' ×' . $e['count'];
					},
					(array) $i['entities']['items']
				)
			)
		) . ' <small>(' . esc_html( $i['entities']['method'] ) . ')</small></p>';
		$det = (array) $i['detected_language'];
		if ( isset( $det['lang'] ) ) {
			echo '<p><strong>' . esc_html__( 'Detected text language:', 'semantic-seo-engine-vi-en' ) . '</strong> ' . esc_html( strtoupper( $det['lang'] ) . ' (' . Ui::term( $det['confidence'] ) . ')' ) . '</p>';
		}
		echo '<p><strong>' . esc_html__( 'Semantic vector:', 'semantic-seo-engine-vi-en' ) . '</strong> ' . esc_html( $a['semantic']['note'] ) . '</p>';
		if ( $a['cluster'] ) {
			echo '<p><strong>' . esc_html__( 'Cluster:', 'semantic-seo-engine-vi-en' ) . '</strong> ' . esc_html( $a['cluster']['label'] ) . ' ' . Ui::badge( $a['cluster_role'], 'role' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		if ( current_user_can( Capabilities::REVIEW ) ) {
			Ui::form_open( 'editorial' );
			echo '<input type="hidden" name="doc_id" value="' . esc_attr( (string) $d['id'] ) . '"><fieldset><legend>' . esc_html__( 'Editorial override (plugin data only)', 'semantic-seo-engine-vi-en' ) . '</legend>';
			Ui::out(
				Ui::select(
					'editorial_intent',
					array(
						''              => __( '(use heuristic)', 'semantic-seo-engine-vi-en' ),
						'informational' => Ui::term( 'informational' ),
						'transactional' => Ui::term( 'transactional' ),
						'commercial'    => Ui::term( 'commercial' ),
						'navigational'  => Ui::term( 'navigational' ),
					),
					'editorial override' === $i['intent']['source'] ? $i['intent']['label'] : '',
					__( 'Intent', 'semantic-seo-engine-vi-en' )
				)
			);
			Ui::out(
				' ' . Ui::select(
					'review_state',
					array(
						'unreviewed'    => Ui::term( 'unreviewed' ),
						'needs_review'  => Ui::term( 'needs_review' ),
						'reviewed'      => Ui::term( 'reviewed' ),
						'approved_plan' => Ui::term( 'approved_plan' ),
					),
					$d['review_state'],
					__( 'Review state', 'semantic-seo-engine-vi-en' )
				)
			);
			echo ' <button class="button">' . esc_html__( 'Save', 'semantic-seo-engine-vi-en' ) . '</button></fieldset></form>';
			Ui::out(
				'<p>' . Ui::action_button(
					'lock',
					array(
						'scope'     => 'document',
						'value'     => $d['object_type'] . ':' . $d['object_id'],
						'lock_type' => 'lock',
						'reason'    => 'priority URL',
					),
					__( 'Lock this URL (no proposals)', 'semantic-seo-engine-vi-en' )
				) . ' ' . Ui::action_button(
					'lock',
					array(
						'scope'     => 'document',
						'value'     => $d['object_type'] . ':' . $d['object_id'],
						'lock_type' => 'exclude',
						'reason'    => 'excluded from analysis',
					),
					__( 'Exclude from suggestions', 'semantic-seo-engine-vi-en' )
				) . '</p>'
			);
		}
		echo '</section></div>';

		// Findings.
		self::findings_table( __( 'QA issues', 'semantic-seo-engine-vi-en' ), $a['qa_issues'] );
		self::findings_table( __( 'Link suggestions from this page', 'semantic-seo-engine-vi-en' ), $a['link_suggestions'] );
		self::findings_table( __( 'Overlap risks', 'semantic-seo-engine-vi-en' ), $a['overlap_risks'] );
		$rows = array();
		foreach ( $a['related'] as $r ) {
			$rows[] = array(
				'rank'  => esc_html( (string) $r['rank_pos'] ),
				'page'  => Ui::doc_link( $r['related_id'], $r['title'], $r['url'] ),
				'score' => esc_html( (string) round( (float) $r['score'], 3 ) ),
			);
		}
		echo '<h3>' . esc_html__( 'Precomputed related content', 'semantic-seo-engine-vi-en' ) . '</h3>';
		Ui::table(
			__( 'Related', 'semantic-seo-engine-vi-en' ),
			array(
				'rank'  => '#',
				'page'  => __( 'Page', 'semantic-seo-engine-vi-en' ),
				'score' => __( 'Score', 'semantic-seo-engine-vi-en' ),
			),
			$rows
		);
		echo '<details class="sseo-panel"><summary>' . esc_html__( 'Provenance (raw)', 'semantic-seo-engine-vi-en' ) . '</summary><pre class="sseo-json">' . esc_html( (string) wp_json_encode( $a['provenance'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '</pre></details>';
	}

	/**
	 * Compact findings table.
	 *
	 * @param string $title Title.
	 * @param array  $items Findings.
	 * @return void
	 */
	private static function findings_table( $title, array $items ) {
		echo '<h3>' . esc_html( $title ) . '</h3>';
		$rows = array();
		foreach ( $items as $f ) {
			$e      = (array) $f['evidence'];
			$detail = isset( $e['check'] ) ? $e['check'] : ( isset( $e['reason'] ) ? $e['reason'] : ( isset( $e['notes'] ) ? implode( ' ', (array) $e['notes'] ) : '' ) );
			$rows[] = array(
				'label'  => Ui::badge( $f['label'], 'label' ) . ' ' . Ui::badge( $f['confidence'], 'confidence' ),
				'check'  => esc_html( Ui::term( $f['check_id'] ) ),
				'detail' => esc_html( $detail ),
				'status' => Ui::badge( $f['status'], 'status' ),
			);
		}
		Ui::table(
			$title,
			array(
				'label'  => __( 'Label', 'semantic-seo-engine-vi-en' ),
				'check'  => __( 'Check', 'semantic-seo-engine-vi-en' ),
				'detail' => __( 'Evidence', 'semantic-seo-engine-vi-en' ),
				'status' => __( 'Status', 'semantic-seo-engine-vi-en' ),
			),
			$rows,
			__( 'None.', 'semantic-seo-engine-vi-en' )
		);
	}
}
