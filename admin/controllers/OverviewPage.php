<?php
/**
 * Page 1 — Overview & quick-start wizard.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Admin;

use SemanticSEO\Capabilities;
use SemanticSEO\Compatibility\Environment;
use SemanticSEO\Inventory\InventoryScanner;
use SemanticSEO\Jobs\JobRepository;
use SemanticSEO\Language\LanguageResolver;
use SemanticSEO\Services\Api;
use SemanticSEO\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Status dashboard; first-run wizard until completed.
 */
final class OverviewPage {

	/**
	 * Overall state label (never optimistic).
	 *
	 * @param array $status Status from Api::status().
	 * @return array{0:string,1:string} [state key, explanation]
	 */
	public static function state( array $status ) {
		$active = JobRepository::find(
			array(
				'status' => 'running',
				'limit'  => 1,
			)
		);
		$queued = JobRepository::find(
			array(
				'status' => 'queued',
				'limit'  => 1,
			)
		);
		$paused = JobRepository::find(
			array(
				'status' => 'paused',
				'limit'  => 1,
			)
		);
		$last   = JobRepository::find( array( 'limit' => 1 ) );
		if ( 0 === (int) $status['index']['total'] ) {
			return array( 'not_configured', __( 'Not configured: no inventory yet. Run the read-only pilot below.', 'semantic-seo-engine-vi-en' ) );
		}
		if ( array() !== $active || array() !== $queued ) {
			return array( 'processing', __( 'Processing: a job is queued or running.', 'semantic-seo-engine-vi-en' ) );
		}
		if ( array() !== $paused ) {
			return array( 'paused', __( 'Paused: a job is waiting to be resumed.', 'semantic-seo-engine-vi-en' ) );
		}
		if ( array() !== $last && 'failed' === $last[0]['status'] ) {
			return array( 'failed', __( 'Failed: the last job failed; see Jobs for the error.', 'semantic-seo-engine-vi-en' ) );
		}
		$open = 0;
		foreach ( (array) $status['findings'] as $labels ) {
			$open += array_sum( (array) $labels );
		}
		if ( $open > 0 ) {
			return array( 'needs_review', __( 'Needs review: findings are waiting for a human decision.', 'semantic-seo-engine-vi-en' ) );
		}
		return array( 'ready', __( 'Ready (lexical): analysis is up to date.', 'semantic-seo-engine-vi-en' ) );
	}

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public static function render() {
		Ui::header( __( 'Semantic SEO Engine VI–EN', 'semantic-seo-engine-vi-en' ), __( 'Read-only by default: the plugin analyzes and proposes; nothing on your site changes without an exact diff that a human approves.', 'semantic-seo-engine-vi-en' ) );
		$status = Api::status();
		if ( is_wp_error( $status ) ) {
			Ui::error_state( $status );
			Ui::footer();
			return;
		}
		list( $state, $explain ) = self::state( $status );
		echo '<section class="sseo-state sseo-state--' . esc_attr( $state ) . '" aria-live="polite"><h2>' . esc_html__( 'Current state', 'semantic-seo-engine-vi-en' ) . '</h2><p><strong>' . esc_html( $explain ) . '</strong></p></section>';

		if ( ! Settings::get( 'wizard_completed', false ) && current_user_can( Capabilities::MANAGE ) ) {
			self::wizard( $status );
		}
		self::cards( $status );
		Ui::footer();
	}

	/**
	 * Status cards.
	 *
	 * @param array $s Status.
	 * @return void
	 */
	private static function cards( array $s ) {
		$f = (array) $s['findings'];
		echo '<div class="sseo-cards">';
		echo '<section class="sseo-card"><h2>' . esc_html__( 'Inventory', 'semantic-seo-engine-vi-en' ) . '</h2><p class="sseo-big">' . esc_html( number_format_i18n( (int) $s['index']['published'] ) ) . '</p><p>' . esc_html__( 'published URLs analyzed (posts, pages, category archives)', 'semantic-seo-engine-vi-en' ) . '</p><ul>';
		foreach ( (array) $s['index']['by_locale'] as $loc => $n ) {
			echo '<li>' . esc_html( strtoupper( (string) $loc ) ) . ': ' . esc_html( number_format_i18n( (int) $n ) ) . '</li>';
		}
		echo '</ul><p><a href="' . esc_url( Ui::url( 'sseo-matrix' ) ) . '">' . esc_html__( 'Open SEO Matrix', 'semantic-seo-engine-vi-en' ) . '</a></p></section>';

		$qa = isset( $f['qa'] ) ? (array) $f['qa'] : array();
		echo '<section class="sseo-card"><h2>' . esc_html__( 'Content QA', 'semantic-seo-engine-vi-en' ) . '</h2><ul class="sseo-counts">';
		foreach ( array( 'verified_error', 'warning', 'suggestion' ) as $l ) {
			echo '<li>' . Ui::badge( $l, 'label' ) . ' ' . esc_html( number_format_i18n( isset( $qa[ $l ] ) ? (int) $qa[ $l ] : 0 ) ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes.
		}
		echo '</ul><p><a href="' . esc_url( Ui::url( 'sseo-qa' ) ) . '">' . esc_html__( 'Review QA issues', 'semantic-seo-engine-vi-en' ) . '</a></p></section>';

		$links = isset( $f['link']['suggestion'] ) ? (int) $f['link']['suggestion'] : 0;
		$risk  = isset( $f['cannibalization']['risk_hypothesis'] ) ? (int) $f['cannibalization']['risk_hypothesis'] : 0;
		echo '<section class="sseo-card"><h2>' . esc_html__( 'Links & overlap', 'semantic-seo-engine-vi-en' ) . '</h2><p>' . esc_html( sprintf( /* translators: %s: number */ __( '%s open internal-link suggestions', 'semantic-seo-engine-vi-en' ), number_format_i18n( $links ) ) ) . '</p><p>' . esc_html( sprintf( /* translators: %s: number */ __( '%s same-locale overlap hypotheses', 'semantic-seo-engine-vi-en' ), number_format_i18n( $risk ) ) ) . '</p>';
		echo '<p class="description">' . esc_html( sprintf( /* translators: 1: scanned docs, 2: links */ __( 'Coverage: %1$d documents scanned, %2$d internal links observed in content (menus/widgets/templates not observed).', 'semantic-seo-engine-vi-en' ), (int) $s['link_coverage']['documents_scanned'], (int) $s['link_coverage']['internal_links'] ) ) . '</p>';
		echo '<p><a href="' . esc_url( Ui::url( 'sseo-links' ) ) . '">' . esc_html__( 'Link suggestions', 'semantic-seo-engine-vi-en' ) . '</a> · <a href="' . esc_url( Ui::url( 'sseo-cannibalization' ) ) . '">' . esc_html__( 'Overlap review', 'semantic-seo-engine-vi-en' ) . '</a></p></section>';

		echo '<section class="sseo-card"><h2>' . esc_html__( 'Compute tiers', 'semantic-seo-engine-vi-en' ) . '</h2><ul>';
		echo '<li><strong>' . esc_html__( 'Tier 0 — lexical:', 'semantic-seo-engine-vi-en' ) . '</strong> ' . esc_html__( 'Ready (works on this server, no model).', 'semantic-seo-engine-vi-en' ) . '</li>';
		echo '<li><strong>' . esc_html__( 'Tier 1 — browser model:', 'semantic-seo-engine-vi-en' ) . '</strong> ' . esc_html( sprintf( /* translators: %d: vectors */ __( 'optional, started by an administrator in a supported browser; %d vectors stored for the pinned model.', 'semantic-seo-engine-vi-en' ), (int) $s['semantic']['vectors_current_model'] ) ) . '</li>';
		echo '<li><strong>' . esc_html__( 'Tier 2 — private worker:', 'semantic-seo-engine-vi-en' ) . '</strong> ' . esc_html__( 'optional, not configured unless you set one up.', 'semantic-seo-engine-vi-en' ) . '</li></ul>';
		echo '<p><a href="' . esc_url( Ui::url( 'sseo-jobs' ) ) . '">' . esc_html__( 'Jobs & Inference Device', 'semantic-seo-engine-vi-en' ) . '</a></p></section>';
		echo '</div>';

		if ( current_user_can( Capabilities::ANALYZE ) ) {
			echo '<section class="sseo-panel"><h2>' . esc_html__( 'Run analysis', 'semantic-seo-engine-vi-en' ) . '</h2><p>' . esc_html__( 'Inventory and analysis are read-only and run in small background batches. Large sites take several minutes.', 'semantic-seo-engine-vi-en' ) . '</p>';
			Ui::out(
				Ui::action_button(
					'start_job',
					array(
						'type'         => 'inventory',
						'then_analyze' => '1',
					),
					__( 'Full inventory + analysis', 'semantic-seo-engine-vi-en' ),
					'button button-primary'
				)
			); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with escaping.
			echo ' ' . Ui::action_button( 'start_job', array( 'type' => 'analyze' ), __( 'Re-run analysis only', 'semantic-seo-engine-vi-en' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</section>';
		}
	}

	/**
	 * First-run wizard.
	 *
	 * @param array $status Status.
	 * @return void
	 */
	private static function wizard( array $status ) {
		$env    = Environment::report();
		$scope  = InventoryScanner::scope_counts();
		$req_ok = version_compare( PHP_VERSION, SSEO_MIN_PHP, '>=' ) && version_compare( $env['wordpress'], SSEO_MIN_WP, '>=' );
		echo '<section class="sseo-wizard" aria-labelledby="sseo-wizard-title"><h2 id="sseo-wizard-title">' . esc_html__( 'Quick-start wizard', 'semantic-seo-engine-vi-en' ) . '</h2><ol class="sseo-steps">';

		// Step 1: compatibility.
		echo '<li><h3>' . esc_html__( '1. Compatibility check', 'semantic-seo-engine-vi-en' ) . '</h3><ul class="sseo-checks">';
		$checks = array(
			array( $req_ok, sprintf( /* translators: 1: WP, 2: PHP */ __( 'WordPress %1$s, PHP %2$s', 'semantic-seo-engine-vi-en' ), $env['wordpress'], $env['php'] ) ),
			array( array() === $env['db_schema']['missing'], __( 'Plugin database tables', 'semantic-seo-engine-vi-en' ) ),
			array( $env['intl'], $env['intl'] ? __( 'intl extension (Unicode normalization)', 'semantic-seo-engine-vi-en' ) : __( 'intl missing — the built-in Vietnamese normalizer is used', 'semantic-seo-engine-vi-en' ) ),
			array( $env['abilities_api'], $env['abilities_api'] ? __( 'WordPress Abilities API available', 'semantic-seo-engine-vi-en' ) : __( 'Abilities API unavailable (WordPress 6.9+); REST still works', 'semantic-seo-engine-vi-en' ) ),
			array( $env['translation_mapping'], sprintf( /* translators: %s: framework */ __( 'Language framework: %s', 'semantic-seo-engine-vi-en' ), LanguageResolver::framework() ) ),
			array( true, sprintf( /* translators: %s: plugin */ __( 'SEO metadata source: %s (read-only)', 'semantic-seo-engine-vi-en' ), $env['seo_primary'] ) ),
			array( ! $env['wp_cron_disabled'], $env['wp_cron_disabled'] ? __( 'WP-Cron disabled — jobs advance while the Jobs page is open, or via WP-CLI', 'semantic-seo-engine-vi-en' ) : __( 'WP-Cron available for background batches', 'semantic-seo-engine-vi-en' ) ),
		);
		foreach ( $checks as $c ) {
			echo '<li class="' . ( $c[0] ? 'sseo-ok' : 'sseo-warn' ) . '"><span aria-hidden="true">' . ( $c[0] ? '✓' : '!' ) . '</span> ' . esc_html( $c[1] ) . '</li>';
		}
		echo '</ul></li>';

		// Step 2: tiers.
		echo '<li><h3>' . esc_html__( '2. Compute tiers', 'semantic-seo-engine-vi-en' ) . '</h3><p>' . esc_html__( 'Tier 0 (lexical) is available now on this hosting. Tier 1 runs the Qwen3 embedding model in your browser only when you start it; Tier 2 is an optional private worker. Site visitors never load a model.', 'semantic-seo-engine-vi-en' ) . '</p></li>';

		// Step 3: scope.
		echo '<li><h3>' . esc_html__( '3. Scope', 'semantic-seo-engine-vi-en' ) . '</h3>';
		Ui::form_open( 'wizard' );
		echo '<input type="hidden" name="step" value="scope"><input type="hidden" name="form" value="scope">';
		$types = get_post_types( array( 'public' => true ), 'objects' );
		echo '<fieldset><legend>' . esc_html__( 'Content types', 'semantic-seo-engine-vi-en' ) . '</legend>';
		foreach ( $types as $t ) {
			if ( 'attachment' === $t->name ) {
				continue;
			}
			echo '<label><input type="checkbox" name="sseo[post_types][]" value="' . esc_attr( $t->name ) . '"' . checked( in_array( $t->name, (array) Settings::get( 'post_types' ), true ), true, false ) . '> ' . esc_html( $t->labels->name ) . '</label> ';
		}
		echo '</fieldset><p><label for="sseo-ex">' . esc_html__( 'Exclude URL path fragments (one per line)', 'semantic-seo-engine-vi-en' ) . '</label><br><textarea id="sseo-ex" name="sseo[exclude_url_patterns]" rows="3" cols="50">' . esc_textarea( implode( "\n", (array) Settings::get( 'exclude_url_patterns' ) ) ) . '</textarea></p>';
		echo '<p><label for="sseo-bs">' . esc_html__( 'Batch size', 'semantic-seo-engine-vi-en' ) . '</label> <input id="sseo-bs" type="number" min="1" max="200" name="sseo[batch_size]" value="' . esc_attr( (string) Settings::get( 'batch_size' ) ) . '"> <label for="sseo-mx">' . esc_html__( 'Max documents', 'semantic-seo-engine-vi-en' ) . '</label> <input id="sseo-mx" type="number" min="10" name="sseo[max_documents]" value="' . esc_attr( (string) Settings::get( 'max_documents' ) ) . '"></p>';
		echo '<p class="description">' . esc_html( sprintf( /* translators: 1: posts, 2: terms */ __( 'In scope now: %1$d posts/pages and %2$d category archives (real database counts).', 'semantic-seo-engine-vi-en' ), $scope['posts'], $scope['terms'] ) ) . '</p>';
		echo '<button class="button">' . esc_html__( 'Save scope', 'semantic-seo-engine-vi-en' ) . '</button></form></li>';

		// Step 4: pilot.
		echo '<li><h3>' . esc_html__( '4. Read-only pilot', 'semantic-seo-engine-vi-en' ) . '</h3><p>' . esc_html__( 'Analyze a small sample first and check the counts and coverage before running the whole site.', 'semantic-seo-engine-vi-en' ) . '</p>';
		Ui::form_open( 'wizard' );
		echo '<input type="hidden" name="step" value="pilot"><label for="sseo-pilot">' . esc_html__( 'Pilot size', 'semantic-seo-engine-vi-en' ) . '</label> <input id="sseo-pilot" type="number" min="5" max="50" name="pilot_size" value="20"> <button class="button button-primary">' . esc_html__( 'Start pilot', 'semantic-seo-engine-vi-en' ) . '</button></form>';
		if ( (int) $status['index']['total'] > 0 ) {
			echo '<p>' . esc_html( sprintf( /* translators: 1: documents, 2: links */ __( 'Pilot result so far: %1$d documents, %2$d internal links observed.', 'semantic-seo-engine-vi-en' ), (int) $status['index']['total'], (int) $status['link_coverage']['internal_links'] ) ) . '</p>';
		}
		echo '</li>';

		// Step 5: finish.
		echo '<li><h3>' . esc_html__( '5. Finish', 'semantic-seo-engine-vi-en' ) . '</h3>';
		Ui::form_open( 'wizard' );
		echo '<input type="hidden" name="step" value="finish"><button class="button">' . esc_html__( 'Finish setup', 'semantic-seo-engine-vi-en' ) . '</button></form></li>';
		echo '</ol></section>';
	}
}
