<?php
/**
 * Admin menu, assets and page routing (Human Mode).
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Admin;

use SemanticSEO\Capabilities;
use SemanticSEO\Compatibility\Environment;
use SemanticSEO\Semantic\ModelProfile;
use SemanticSEO\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the 12 admin pages. Assets load only on these pages.
 */
final class Menu {

	/**
	 * Page definitions: slug => [title callback key, capability, class].
	 *
	 * @return array
	 */
	public static function pages() {
		return array(
			'sseo'                 => array( __( 'Overview & Setup', 'semantic-seo-engine-vi-en' ), Capabilities::READ, OverviewPage::class ),
			'sseo-matrix'          => array( __( 'URL Inventory / SEO Matrix', 'semantic-seo-engine-vi-en' ), Capabilities::READ, MatrixPage::class ),
			'sseo-semantic'        => array( __( 'Semantic Analysis', 'semantic-seo-engine-vi-en' ), Capabilities::READ, SemanticPage::class ),
			'sseo-links'           => array( __( 'Link Suggestions & Graph', 'semantic-seo-engine-vi-en' ), Capabilities::READ, LinksPage::class ),
			'sseo-cannibalization' => array( __( 'Cannibalization Review', 'semantic-seo-engine-vi-en' ), Capabilities::READ, CannibalizationPage::class ),
			'sseo-clusters'        => array( __( 'Pillar–Hub–Silo Clusters', 'semantic-seo-engine-vi-en' ), Capabilities::READ, ClustersPage::class ),
			'sseo-qa'              => array( __( 'Content QA', 'semantic-seo-engine-vi-en' ), Capabilities::READ, QaPage::class ),
			'sseo-related'         => array( __( 'Related Content Output', 'semantic-seo-engine-vi-en' ), Capabilities::READ, RelatedPage::class ),
			'sseo-jobs'            => array( __( 'Jobs & Inference Device', 'semantic-seo-engine-vi-en' ), Capabilities::READ, JobsPage::class ),
			'sseo-agents'          => array( __( 'Agent Connections / Permissions', 'semantic-seo-engine-vi-en' ), Capabilities::MANAGE, AgentsPage::class ),
			'sseo-settings'        => array( __( 'Settings & Diagnostics', 'semantic-seo-engine-vi-en' ), Capabilities::MANAGE, SettingsPage::class ),
			'sseo-audit'           => array( __( 'Audit / Undo / Rollback', 'semantic-seo-engine-vi-en' ), Capabilities::READ, AuditPage::class ),
		);
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'environment_notice' ) );
		Actions::register_hooks();
	}

	/**
	 * Adds the menu.
	 *
	 * @return void
	 */
	public static function menu() {
		add_menu_page(
			__( 'Semantic SEO', 'semantic-seo-engine-vi-en' ),
			__( 'Semantic SEO', 'semantic-seo-engine-vi-en' ),
			Capabilities::READ,
			'sseo',
			array( __CLASS__, 'render' ),
			'dashicons-networking',
			81
		);
		foreach ( self::pages() as $slug => $def ) {
			add_submenu_page( 'sseo', $def[0], $def[0], $def[1], $slug, array( __CLASS__, 'render' ) );
		}
	}

	/**
	 * Renders the current page.
	 *
	 * @return void
	 */
	public static function render() {
		$slug  = Ui::get( 'page', 'sseo' );
		$pages = self::pages();
		if ( ! isset( $pages[ $slug ] ) || ! current_user_can( $pages[ $slug ][1] ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'semantic-seo-engine-vi-en' ), 403 );
		}
		call_user_func( array( $pages[ $slug ][2], 'render' ) );
	}

	/**
	 * Enqueues CSS/JS on plugin pages only.
	 *
	 * @param string $hook Hook suffix.
	 * @return void
	 */
	public static function assets( $hook ) {
		$slug = Ui::get( 'page' );
		if ( ! isset( self::pages()[ $slug ] ) ) {
			return;
		}
		unset( $hook );
		wp_enqueue_style( 'sseo-admin', SSEO_URL . 'admin/assets/css/admin.css', array(), SSEO_VERSION );
		wp_enqueue_script( 'sseo-admin', SSEO_URL . 'admin/assets/js/admin.js', array(), SSEO_VERSION, true );
		wp_localize_script(
			'sseo-admin',
			'SSEO',
			array(
				'rest'    => esc_url_raw( rest_url( 'semantic-seo/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'cronOff' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
				'i18n'    => array(
					'confirm'      => __( 'Are you sure?', 'semantic-seo-engine-vi-en' ),
					'processing'   => __( 'Processing…', 'semantic-seo-engine-vi-en' ),
					'completed'    => __( 'Completed', 'semantic-seo-engine-vi-en' ),
					'failed'       => __( 'Failed', 'semantic-seo-engine-vi-en' ),
					'reload'       => __( 'Job finished — reload to see results.', 'semantic-seo-engine-vi-en' ),
					'copied'       => __( 'Copied to clipboard.', 'semantic-seo-engine-vi-en' ),
					'networkError' => __( 'Network error; will retry.', 'semantic-seo-engine-vi-en' ),
				),
			)
		);
		if ( 'sseo-jobs' === $slug ) {
			wp_enqueue_script( 'sseo-inference', SSEO_URL . 'admin/assets/js/inference.js', array( 'sseo-admin' ), SSEO_VERSION, true );
			$profile = ModelProfile::active();
			wp_localize_script(
				'sseo-inference',
				'SSEO_MODEL',
				array(
					'workerUrl'   => esc_url_raw( SSEO_URL . 'assets/js/ai-worker/worker.js' ),
					'vendorUrl'   => esc_url_raw( SSEO_URL . 'assets/vendor/transformers/' ),
					'vendorReady' => file_exists( SSEO_DIR . 'assets/vendor/transformers/transformers.min.js' ),
					'profile'     => \SemanticSEO\Semantic\EmbeddingQueue::public_profile( $profile ),
					'reference'   => $profile['reference'],
					'canEmbed'    => current_user_can( Capabilities::EMBED ),
					'i18n'        => array(
						'unsupported'    => __( 'Unsupported on this device: neither WebGPU nor WebAssembly is usable here. The lexical tier keeps working.', 'semantic-seo-engine-vi-en' ),
						'consent'        => __( 'Please confirm the download first.', 'semantic-seo-engine-vi-en' ),
						'loading'        => __( 'Downloading and verifying model files…', 'semantic-seo-engine-vi-en' ),
						'loaded'         => __( 'Model loaded', 'semantic-seo-engine-vi-en' ),
						'selftestOk'     => __( 'Self-test passed: similarities match the model card reference within tolerance.', 'semantic-seo-engine-vi-en' ),
						'selftestFail'   => __( 'Self-test failed: results differ from the reference; vectors will not be uploaded.', 'semantic-seo-engine-vi-en' ),
						'serverChecked'  => __( 'checked by the server, max Δ', 'semantic-seo-engine-vi-en' ),
						'idle'           => __( 'Queue empty — nothing to embed.', 'semantic-seo-engine-vi-en' ),
						'tabWarning'     => __( 'Keep this tab open and the device awake; closing the tab or locking an iPhone interrupts processing (claimed items are returned to the queue automatically).', 'semantic-seo-engine-vi-en' ),
						'noVendor'       => __( 'The browser runtime files are not bundled in this build; browser inference is unavailable.', 'semantic-seo-engine-vi-en' ),
						'yes'            => __( 'yes', 'semantic-seo-engine-vi-en' ),
						'no'             => __( 'no', 'semantic-seo-engine-vi-en' ),
						'noF16'          => __( 'no shader-f16', 'semantic-seo-engine-vi-en' ),
						'threads'        => __( 'Threads', 'semantic-seo-engine-vi-en' ),
						'oneThread'      => __( '1 (page is not cross-origin isolated)', 'semantic-seo-engine-vi-en' ),
						'memory'         => __( 'Device memory', 'semantic-seo-engine-vi-en' ),
						'iosNote'        => __( 'yes — may be too slow or run out of memory; keep the screen on', 'semantic-seo-engine-vi-en' ),
						'wasmSlower'     => __( 'WebAssembly (CPU) — slower', 'semantic-seo-engine-vi-en' ),
						/* translators: %s: precision such as q4f16 */
						'f16OnWasm'      => __( 'No WebGPU with 16-bit shaders on this device: %s runs on the CPU (WebAssembly), which is slower. For many pages, a desktop browser with WebGPU or the private worker is faster.', 'semantic-seo-engine-vi-en' ),
						'workerError'    => __( 'Worker error:', 'semantic-seo-engine-vi-en' ),
						'profileChanged' => __( 'The model profile changed on the server; reload this page.', 'semantic-seo-engine-vi-en' ),
						/* translators: 1: stored, 2: rejected, 3: milliseconds, 4: running total */
						'batchDone'      => __( '%1$d stored, %2$d rejected (%3$d ms) — total %4$d', 'semantic-seo-engine-vi-en' ),
						'cached'         => __( 'from cache', 'semantic-seo-engine-vi-en' ),
						/* translators: 1: free browser storage, 2: model download size */
						'storageLow'     => __( 'Browser storage has %1$s free but the model needs %2$s: it will work, but may be downloaded again next time.', 'semantic-seo-engine-vi-en' ),
						'stopped'        => __( 'Stopped; claimed items were returned to the queue.', 'semantic-seo-engine-vi-en' ),
					),
				)
			);
		}
	}

	/**
	 * Informative (non-fatal) notice about optional capabilities, shown on plugin pages only.
	 *
	 * @return void
	 */
	public static function environment_notice() {
		if ( ! isset( self::pages()[ Ui::get( 'page' ) ] ) || ! current_user_can( Capabilities::READ ) ) {
			return;
		}
		$msgs = array();
		if ( ! Environment::abilities_available() ) {
			$msgs[] = __( 'Agent-native WordPress Abilities are unavailable on this WordPress version (6.9+ required). The REST API and all modules still work.', 'semantic-seo-engine-vi-en' );
		} elseif ( Settings::get( 'mcp_enabled', false ) && ! Environment::mcp_adapter_available() ) {
			$msgs[] = __( 'MCP exposure is enabled but the official MCP Adapter plugin is not active, so no MCP client can connect.', 'semantic-seo-engine-vi-en' );
		}
		foreach ( $msgs as $m ) {
			echo '<div class="notice notice-info"><p>' . esc_html( $m ) . '</p></div>';
		}
	}
}
