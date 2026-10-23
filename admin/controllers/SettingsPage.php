<?php
/**
 * Page 11 — Settings & Diagnostics.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Admin;

use SemanticSEO\Compatibility\Environment;
use SemanticSEO\Database\Migrator;
use SemanticSEO\Locks\LockService;
use SemanticSEO\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Basic settings, expert panel (tested defaults), locks, uninstall policy and diagnostics.
 */
final class SettingsPage {

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public static function render() {
		Ui::header( __( 'Settings & Diagnostics', 'semantic-seo-engine-vi-en' ) );
		$s = Settings::all();
		Ui::form_open( 'save_settings' );
		echo '<input type="hidden" name="form" value="settings">';
		echo '<section class="sseo-panel"><h2>' . esc_html__( 'Scope', 'semantic-seo-engine-vi-en' ) . '</h2>';
		echo '<p><label for="sseo-pt">' . esc_html__( 'Post types (comma separated)', 'semantic-seo-engine-vi-en' ) . '</label><br><input id="sseo-pt" type="text" name="sseo[post_types][]" value="' . esc_attr( implode( ',', $s['post_types'] ) ) . '" data-sseo-csv></p>';
		echo '<p><label for="sseo-tx">' . esc_html__( 'Taxonomies used as cluster backbone', 'semantic-seo-engine-vi-en' ) . '</label><br><input id="sseo-tx" type="text" name="sseo[taxonomies][]" value="' . esc_attr( implode( ',', $s['taxonomies'] ) ) . '" data-sseo-csv></p>';
		echo '<p><label><input type="checkbox" name="sseo[include_drafts]" value="1"' . checked( $s['include_drafts'], true, false ) . '> ' . esc_html__( 'Include drafts/private posts in analysis (never shown to agents or on the frontend)', 'semantic-seo-engine-vi-en' ) . '</label></p>';
		echo '<p><label for="sseo-dl">' . esc_html__( 'Default language (when no language plugin applies)', 'semantic-seo-engine-vi-en' ) . '</label> <input id="sseo-dl" type="text" name="sseo[default_locale]" size="3" maxlength="2" value="' . esc_attr( $s['default_locale'] ) . '"></p>';
		$prefix = array();
		foreach ( $s['url_prefix_locales'] as $code => $p ) {
			$prefix[] = $code . '=' . $p;
		}
		echo '<p><label for="sseo-pref">' . esc_html__( 'URL prefix rules (one per line, e.g. en=/en/) — used only when no language plugin (Polylang, WPML, Nguyen Ha VI-EN Core) is detected', 'semantic-seo-engine-vi-en' ) . '</label><br><textarea id="sseo-pref" name="sseo[url_prefix_locales]" rows="2" cols="30">' . esc_textarea( implode( "\n", $prefix ) ) . '</textarea></p>';
		echo '<p><label for="sseo-exid">' . esc_html__( 'Excluded post IDs', 'semantic-seo-engine-vi-en' ) . '</label><br><input id="sseo-exid" type="text" name="sseo[exclude_post_ids]" value="' . esc_attr( implode( ',', $s['exclude_post_ids'] ) ) . '" size="50"></p>';
		echo '<p><label for="sseo-expat">' . esc_html__( 'Excluded URL path fragments (one per line)', 'semantic-seo-engine-vi-en' ) . '</label><br><textarea id="sseo-expat" name="sseo[exclude_url_patterns]" rows="3" cols="50">' . esc_textarea( implode( "\n", $s['exclude_url_patterns'] ) ) . '</textarea></p></section>';

		echo '<section class="sseo-panel"><h2>' . esc_html__( 'Operations', 'semantic-seo-engine-vi-en' ) . '</h2>';
		self::number( 'batch_size', __( 'Batch size', 'semantic-seo-engine-vi-en' ), $s, 1, 200 );
		self::number( 'time_budget', __( 'Time budget per background tick (seconds)', 'semantic-seo-engine-vi-en' ), $s, 3, 50 );
		self::number( 'max_documents', __( 'Max documents', 'semantic-seo-engine-vi-en' ), $s, 10, 100000 );
		self::number( 'approval_ttl_hours', __( 'Approval validity (hours)', 'semantic-seo-engine-vi-en' ), $s, 1, 168 );
		self::number( 'rate_read', __( 'Rate limit: reads per minute per user', 'semantic-seo-engine-vi-en' ), $s, 10, 1000 );
		self::number( 'rate_analyze', __( 'Rate limit: job starts per minute', 'semantic-seo-engine-vi-en' ), $s, 1, 100 );
		self::number( 'rate_write', __( 'Rate limit: writes per minute', 'semantic-seo-engine-vi-en' ), $s, 1, 100 );
		self::number( 'audit_retention_days', __( 'Audit log retention (days)', 'semantic-seo-engine-vi-en' ), $s, 7, 3650 );
		echo '<p><label><input type="checkbox" name="sseo[auto_incremental]" value="1"' . checked( $s['auto_incremental'], true, false ) . '> ' . esc_html__( 'Re-index changed posts automatically (after the first inventory)', 'semantic-seo-engine-vi-en' ) . '</label></p>';
		echo '<p><label><input type="checkbox" name="sseo[allow_proposals]" value="1"' . checked( $s['allow_proposals'], true, false ) . '> ' . esc_html__( 'Allow change previews (approval is always required to apply)', 'semantic-seo-engine-vi-en' ) . '</label></p>';
		echo '<p><label><input type="checkbox" name="sseo[verify_http]" value="1"' . checked( $s['verify_http'], true, false ) . '> ' . esc_html__( 'Allow HTTP verification of internal link targets (same host only, no redirects followed)', 'semantic-seo-engine-vi-en' ) . '</label></p>';
		Ui::out(
			Ui::select(
				'sseo[ui_language]',
				array(
					'vi'   => 'Tiếng Việt',
					'en'   => 'English',
					'auto' => __( 'Follow WordPress profile language', 'semantic-seo-engine-vi-en' ),
				),
				$s['ui_language'],
				__( 'Plugin admin language', 'semantic-seo-engine-vi-en' )
			)
		);
		echo '</section>';

		echo '<details class="sseo-panel"><summary><strong>' . esc_html__( 'Expert panel (tested defaults — change only with evaluation data)', 'semantic-seo-engine-vi-en' ) . '</strong></summary>';
		foreach ( array(
			'w_lexical'                  => __( 'Weight: lexical', 'semantic-seo-engine-vi-en' ),
			'w_semantic'                 => __( 'Weight: semantic', 'semantic-seo-engine-vi-en' ),
			'w_anchor'                   => __( 'Weight: anchor quality', 'semantic-seo-engine-vi-en' ),
			'w_graph'                    => __( 'Weight: graph usefulness', 'semantic-seo-engine-vi-en' ),
			'link_min_score'             => __( 'Minimum link score', 'semantic-seo-engine-vi-en' ),
			'related_min_score'          => __( 'Minimum related score', 'semantic-seo-engine-vi-en' ),
			'cannibal_min_score'         => __( 'Overlap topic threshold', 'semantic-seo-engine-vi-en' ),
			'near_duplicate'             => __( 'Near-duplicate threshold (MinHash)', 'semantic-seo-engine-vi-en' ),
			'translation_min_similarity' => __( 'Possible-translation threshold (cross-language similarity)', 'semantic-seo-engine-vi-en' ),
		) as $k => $label ) {
			echo '<p><label for="sseo-' . esc_attr( $k ) . '">' . esc_html( $label ) . '</label> <input id="sseo-' . esc_attr( $k ) . '" type="number" step="0.01" min="0" max="1" name="sseo[' . esc_attr( $k ) . ']" value="' . esc_attr( (string) $s[ $k ] ) . '"></p>';
		}
		self::number( 'candidate_k', __( 'Candidates per page (BM25 top-k)', 'semantic-seo-engine-vi-en' ), $s, 5, 50 );
		self::number( 'link_max_per_source', __( 'Max link suggestions per page', 'semantic-seo-engine-vi-en' ), $s, 1, 10 );
		Ui::out(
			Ui::select(
				'sseo[model_dtype]',
				array(
					'q4f16' => 'q4f16 (≈567 MB)',
					'fp16'  => 'fp16 (≈1.2 GB)',
				),
				$s['model_dtype'],
				__( 'Model precision', 'semantic-seo-engine-vi-en' )
			)
		);
		Ui::out(
			' ' . Ui::select(
				'sseo[embedding_dimension]',
				array(
					'1024' => '1024',
					'768'  => '768 (MRL)',
					'512'  => '512 (MRL)',
					'256'  => '256 (MRL)',
				),
				(string) $s['embedding_dimension'],
				__( 'Vector dimension', 'semantic-seo-engine-vi-en' )
			)
		);
		self::number( 'embed_body_chunks', __( 'Body chunks embedded per page', 'semantic-seo-engine-vi-en' ), $s, 0, 8 );
		echo '<p class="description">' . esc_html__( 'Changing precision or dimension changes the model key: existing vectors are kept but ignored until you re-run embeddings.', 'semantic-seo-engine-vi-en' ) . '</p></details>';

		echo '<section class="sseo-panel"><h2>' . esc_html__( 'Uninstall policy', 'semantic-seo-engine-vi-en' ) . '</h2>';
		Ui::out(
			Ui::select(
				'sseo[uninstall_policy]',
				array(
					'preserve' => __( 'Preserve all plugin data (default)', 'semantic-seo-engine-vi-en' ),
					'delete'   => __( 'Delete all plugin data when the plugin is deleted', 'semantic-seo-engine-vi-en' ),
				),
				$s['uninstall_policy'],
				__( 'When the plugin is deleted', 'semantic-seo-engine-vi-en' )
			)
		);
		echo ' <label for="sseo-cd">' . esc_html__( 'Type DELETE to confirm the delete policy', 'semantic-seo-engine-vi-en' ) . '</label> <input id="sseo-cd" type="text" name="confirm_delete" size="8" autocomplete="off">';
		echo '<p class="description">' . esc_html__( 'Deleting plugin data also deletes the change log and rollback snapshots. WordPress posts are never deleted.', 'semantic-seo-engine-vi-en' ) . '</p></section>';
		echo '<p><button class="button button-primary">' . esc_html__( 'Save settings', 'semantic-seo-engine-vi-en' ) . '</button></p></form>';

		self::locks();
		self::diagnostics();
		Ui::footer();
	}

	/**
	 * Number input.
	 *
	 * @param string $key   Key.
	 * @param string $label Label.
	 * @param array  $s     Settings.
	 * @param int    $min   Min.
	 * @param int    $max   Max.
	 * @return void
	 */
	private static function number( $key, $label, array $s, $min, $max ) {
		echo '<p><label for="sseo-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label> <input id="sseo-' . esc_attr( $key ) . '" type="number" min="' . (int) $min . '" max="' . (int) $max . '" name="sseo[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) $s[ $key ] ) . '"></p>';
	}

	/**
	 * Locks list and URL-pattern lock form.
	 *
	 * @return void
	 */
	private static function locks() {
		echo '<section class="sseo-panel"><h2>' . esc_html__( 'Locks & exclusions', 'semantic-seo-engine-vi-en' ) . '</h2><p class="description">' . esc_html__( 'Enforced by the shared service layer for humans and agents alike.', 'semantic-seo-engine-vi-en' ) . '</p>';
		$rows = array();
		foreach ( LockService::all() as $l ) {
			$rows[] = array(
				'scope'   => esc_html( $l['scope_type'] . ': ' . $l['scope_value'] ),
				'type'    => esc_html( $l['lock_type'] ),
				'reason'  => esc_html( (string) $l['reason'] ),
				'actions' => Ui::action_button( 'unlock', array( 'lock_id' => $l['id'] ), __( 'Remove', 'semantic-seo-engine-vi-en' ), 'button-link button-link-delete' ),
			);
		}
		Ui::table(
			__( 'Locks', 'semantic-seo-engine-vi-en' ),
			array(
				'scope'   => __( 'Scope', 'semantic-seo-engine-vi-en' ),
				'type'    => __( 'Type', 'semantic-seo-engine-vi-en' ),
				'reason'  => __( 'Reason', 'semantic-seo-engine-vi-en' ),
				'actions' => __( 'Actions', 'semantic-seo-engine-vi-en' ),
			),
			$rows,
			__( 'No locks.', 'semantic-seo-engine-vi-en' )
		);
		Ui::form_open( 'lock' );
		echo '<input type="hidden" name="scope" value="url_pattern"><label for="sseo-lp">' . esc_html__( 'Lock a URL path fragment', 'semantic-seo-engine-vi-en' ) . '</label> <input id="sseo-lp" type="text" name="value" placeholder="/en/landing/" pattern="/.*"> ';
		Ui::out(
			Ui::select(
				'lock_type',
				array(
					'lock'    => __( 'Lock (no proposals)', 'semantic-seo-engine-vi-en' ),
					'exclude' => __( 'Exclude from suggestions', 'semantic-seo-engine-vi-en' ),
				),
				'lock',
				__( 'Type', 'semantic-seo-engine-vi-en' )
			)
		);
		echo ' <input type="text" name="reason" placeholder="' . esc_attr__( 'Reason', 'semantic-seo-engine-vi-en' ) . '" aria-label="' . esc_attr__( 'Reason', 'semantic-seo-engine-vi-en' ) . '"> <button class="button">' . esc_html__( 'Add', 'semantic-seo-engine-vi-en' ) . '</button></form></section>';
	}

	/**
	 * Diagnostics report.
	 *
	 * @return void
	 */
	private static function diagnostics() {
		$r = Environment::report();
		echo '<section class="sseo-panel"><h2>' . esc_html__( 'Diagnostics', 'semantic-seo-engine-vi-en' ) . '</h2><dl class="sseo-dl">';
		foreach ( $r as $k => $v ) {
			echo '<dt>' . esc_html( $k ) . '</dt><dd>' . esc_html( is_scalar( $v ) ? ( is_bool( $v ) ? ( $v ? 'yes' : 'no' ) : (string) $v ) : (string) wp_json_encode( $v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '</dd>';
		}
		echo '</dl><h3>' . esc_html__( 'Migration log', 'semantic-seo-engine-vi-en' ) . '</h3><pre class="sseo-json">' . esc_html( (string) wp_json_encode( get_option( Migrator::LOG_OPTION, array() ), JSON_PRETTY_PRINT ) ) . '</pre>';
		echo '<p><button type="button" class="button" data-sseo-copy="#sseo-diag-json">' . esc_html__( 'Copy diagnostics JSON', 'semantic-seo-engine-vi-en' ) . '</button></p><textarea id="sseo-diag-json" class="sseo-hidden-copy" readonly aria-label="' . esc_attr__( 'Diagnostics JSON', 'semantic-seo-engine-vi-en' ) . '">' . esc_textarea( (string) wp_json_encode( $r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '</textarea></section>';
	}
}
