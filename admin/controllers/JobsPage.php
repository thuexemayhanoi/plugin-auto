<?php
/**
 * Page 9 — Jobs & Inference Device.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Admin;

use SemanticSEO\Capabilities;
use SemanticSEO\Semantic\EmbeddingQueue;
use SemanticSEO\Semantic\ModelProfile;
use SemanticSEO\Services\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Job monitor with controls, and the opt-in browser inference panel.
 */
final class JobsPage {

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public static function render() {
		Ui::header( __( 'Jobs & Inference Device', 'semantic-seo-engine-vi-en' ), __( 'Background work runs in small resumable batches. The embedding model never runs on the server or for visitors — only in this browser when you start it.', 'semantic-seo-engine-vi-en' ) );
		$jobs = Api::jobs( array( 'limit' => 20 ) );
		if ( is_wp_error( $jobs ) ) {
			Ui::error_state( $jobs );
			Ui::footer();
			return;
		}
		if ( current_user_can( Capabilities::ANALYZE ) ) {
			echo '<section class="sseo-panel"><h2>' . esc_html__( 'Start a job', 'semantic-seo-engine-vi-en' ) . '</h2><p>';
			Ui::out(
				Ui::action_button(
					'start_job',
					array(
						'type'         => 'inventory',
						'then_analyze' => '1',
					),
					__( 'Inventory + analysis', 'semantic-seo-engine-vi-en' ),
					'button button-primary'
				) . ' '
			); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			Ui::out( Ui::action_button( 'start_job', array( 'type' => 'analyze' ), __( 'Analysis only', 'semantic-seo-engine-vi-en' ) ) . ' ' );
			Ui::out( Ui::action_button( 'start_job', array( 'type' => 'embed' ), __( 'Queue embeddings', 'semantic-seo-engine-vi-en' ) ) . ' ' );
			Ui::out( Ui::action_button( 'start_job', array( 'type' => 'verify_links' ), __( 'Verify internal link HTTP status', 'semantic-seo-engine-vi-en' ) ) );
			echo '</p>';
			Ui::form_open( 'start_job' );
			echo '<input type="hidden" name="type" value="inventory"><input type="hidden" name="then_analyze" value="1"><label for="sseo-ids">' . esc_html__( 'Targeted run: post IDs (max 200, comma separated)', 'semantic-seo-engine-vi-en' ) . '</label> <input id="sseo-ids" type="text" name="post_ids" size="40" pattern="[0-9,\s]*"> <button class="button">' . esc_html__( 'Analyze these posts', 'semantic-seo-engine-vi-en' ) . '</button></form>';
			if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
				echo '<p class="description">' . esc_html__( 'WP-Cron is disabled on this site: running jobs advance while this page stays open (or run `wp sseo run`).', 'semantic-seo-engine-vi-en' ) . '</p>';
			}
			echo '</section>';
		}

		$rows = array();
		foreach ( $jobs['items'] as $j ) {
			$pct      = null === $j['progress_pct'] ? 0 : (float) $j['progress_pct'];
			$controls = '';
			if ( current_user_can( Capabilities::ANALYZE ) ) {
				if ( in_array( $j['status'], array( 'queued', 'running' ), true ) ) {
					$controls .= Ui::action_button(
						'job_control',
						array(
							'job_id' => $j['id'],
							'op'     => 'tick',
						),
						__( 'Advance now', 'semantic-seo-engine-vi-en' )
					);
					$controls .= Ui::action_button(
						'job_control',
						array(
							'job_id' => $j['id'],
							'op'     => 'pause',
						),
						__( 'Pause', 'semantic-seo-engine-vi-en' )
					);
				}
				if ( 'paused' === $j['status'] ) {
					$controls .= Ui::action_button(
						'job_control',
						array(
							'job_id' => $j['id'],
							'op'     => 'resume',
						),
						__( 'Resume', 'semantic-seo-engine-vi-en' )
					);
				}
				if ( in_array( $j['status'], array( 'queued', 'running', 'paused' ), true ) ) {
					$controls .= Ui::action_button(
						'job_control',
						array(
							'job_id' => $j['id'],
							'op'     => 'cancel',
						),
						__( 'Cancel', 'semantic-seo-engine-vi-en' ),
						'button button-link-delete',
						__( 'Cancel this job?', 'semantic-seo-engine-vi-en' )
					);
				}
			}
			$errors = '';
			if ( '' !== $j['last_error'] ) {
				$errors .= '<br><small class="sseo-error">' . esc_html( $j['last_error'] ) . '</small>';
			}
			if ( ! empty( $j['errors'] ) ) {
				/* translators: %d: count */
				$errors .= '<br><small>' . esc_html( sprintf( __( '%d object-level errors (see summary)', 'semantic-seo-engine-vi-en' ), count( $j['errors'] ) ) ) . '</small>';
			}
			$rows[] = array(
				'job'      => esc_html( '#' . $j['id'] . ' ' . Ui::term( $j['type'] ) ) . '<br><small>' . esc_html( $j['created_at'] . ' · ' . $j['via'] ) . '</small>',
				'status'   => '<span data-sseo-job="' . esc_attr( (string) $j['id'] ) . '" data-sseo-status="' . esc_attr( $j['status'] ) . '">' . Ui::badge( $j['status'], 'status' ) . '</span>' . ( '' !== $j['phase'] ? '<br><small>' . esc_html( $j['phase'] ) . '</small>' : '' ) . $errors,
				'progress' => '<progress class="sseo-progress" max="100" value="' . esc_attr( (string) $pct ) . '" aria-label="' . esc_attr__( 'Progress', 'semantic-seo-engine-vi-en' ) . '"></progress> <span data-sseo-progress="' . esc_attr( (string) $j['id'] ) . '">' . esc_html( $j['progress_done'] . ' / ' . $j['progress_total'] ) . '</span>',
				'controls' => $controls,
			);
		}
		echo '<h2>' . esc_html__( 'Jobs', 'semantic-seo-engine-vi-en' ) . '</h2><div aria-live="polite">';
		Ui::table(
			__( 'Jobs', 'semantic-seo-engine-vi-en' ),
			array(
				'job'      => __( 'Job', 'semantic-seo-engine-vi-en' ),
				'status'   => __( 'Status', 'semantic-seo-engine-vi-en' ),
				'progress' => __( 'Progress', 'semantic-seo-engine-vi-en' ),
				'controls' => __( 'Controls', 'semantic-seo-engine-vi-en' ),
			),
			$rows,
			__( 'No jobs yet.', 'semantic-seo-engine-vi-en' )
		);
		echo '</div>';
		self::inference();
		Ui::footer();
	}

	/**
	 * Inference device panel (the worker UI is driven by inference.js).
	 *
	 * @return void
	 */
	private static function inference() {
		$p     = ModelProfile::active();
		$queue = EmbeddingQueue::counts();
		echo '<section class="sseo-panel" id="sseo-inference" aria-labelledby="sseo-inf-title"><h2 id="sseo-inf-title">' . esc_html__( 'Inference device (Tier 1 — browser)', 'semantic-seo-engine-vi-en' ) . '</h2>';
		echo '<dl class="sseo-dl">';
		$facts = array(
			__( 'Model', 'semantic-seo-engine-vi-en' )   => $p['model_id'] . ' @ ' . substr( $p['revision'], 0, 12 ) . ' (' . __( 'base', 'semantic-seo-engine-vi-en' ) . ' ' . $p['base_model'] . ', ' . $p['license'] . ')',
			__( 'Precision / dimension', 'semantic-seo-engine-vi-en' ) => $p['dtype'] . ' / ' . $p['dimension'] . ' · ' . __( 'pooling', 'semantic-seo-engine-vi-en' ) . ' ' . $p['pooling'] . ' + L2',
			__( 'Initial download', 'semantic-seo-engine-vi-en' ) => size_format( $p['download_bytes'], 1 ) . ' ' . __( 'from huggingface.co (pinned revision, SHA-256 verified, cached by this browser)', 'semantic-seo-engine-vi-en' ),
			__( 'Privacy', 'semantic-seo-engine-vi-en' ) => __( 'Only model files are downloaded from Hugging Face. Your post text is processed inside this browser and the vectors are sent only to this WordPress site.', 'semantic-seo-engine-vi-en' ),
			__( 'Devices', 'semantic-seo-engine-vi-en' ) => __( 'Desktop Chrome/Edge with WebGPU is fastest; WASM works but is slower; many phones (including iPhones) may be unsupported or too slow — the lexical tier keeps working.', 'semantic-seo-engine-vi-en' ),
			__( 'Queue', 'semantic-seo-engine-vi-en' )   => sprintf( /* translators: 1: pending, 2: leased, 3: done, 4: failed */ __( '%1$d pending · %2$d in progress · %3$d done · %4$d failed', 'semantic-seo-engine-vi-en' ), $queue['pending'], $queue['leased'], $queue['done'], $queue['failed'] ),
		);
		foreach ( $facts as $k => $v ) {
			echo '<dt>' . esc_html( $k ) . '</dt><dd>' . esc_html( $v ) . '</dd>';
		}
		echo '</dl>';
		echo '<div class="sseo-device" data-sseo-device><p><strong>' . esc_html__( 'This device:', 'semantic-seo-engine-vi-en' ) . '</strong> <span data-sseo-device-status>' . esc_html__( 'Not tested', 'semantic-seo-engine-vi-en' ) . '</span></p><ul data-sseo-device-facts></ul></div>';
		if ( current_user_can( Capabilities::EMBED ) ) {
			echo '<p><label><input type="checkbox" data-sseo-consent> ' . esc_html( sprintf( /* translators: %s: size */ __( 'I understand about %s will be downloaded to this browser from huggingface.co.', 'semantic-seo-engine-vi-en' ), size_format( $p['download_bytes'], 1 ) ) ) . '</label></p>';
			echo '<p><button type="button" class="button" data-sseo-action="detect">' . esc_html__( 'Check this device', 'semantic-seo-engine-vi-en' ) . '</button> <button type="button" class="button" data-sseo-action="load">' . esc_html__( 'Load model & run self-test', 'semantic-seo-engine-vi-en' ) . '</button> <button type="button" class="button button-primary" data-sseo-action="start" disabled>' . esc_html__( 'Process embedding queue', 'semantic-seo-engine-vi-en' ) . '</button> <button type="button" class="button" data-sseo-action="pause" disabled>' . esc_html__( 'Pause', 'semantic-seo-engine-vi-en' ) . '</button> <button type="button" class="button button-link-delete" data-sseo-action="cancel" disabled>' . esc_html__( 'Stop & release', 'semantic-seo-engine-vi-en' ) . '</button></p>';
			echo '<p class="description">' . esc_html__( 'Keep this tab open and the device awake; closing the tab or locking an iPhone interrupts processing (claimed items return to the queue automatically).', 'semantic-seo-engine-vi-en' ) . '</p>';
		} else {
			echo '<p class="description">' . esc_html__( 'You do not have the capability to process embeddings.', 'semantic-seo-engine-vi-en' ) . '</p>';
		}
		echo '<div class="sseo-log" role="log" aria-live="polite" data-sseo-log></div></section>';
	}
}
