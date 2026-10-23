<?php
/**
 * Integration: the 12 admin pages render (empty and populated), capability gating, and the
 * admin-post actions used by Human Mode (approve/apply/rollback/settings) incl. negative cases.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Tests\Integration;

use SemanticSEO\Admin\Actions;
use SemanticSEO\Admin\Menu;
use SemanticSEO\Capabilities;
use SemanticSEO\Database\Db;
use SemanticSEO\Services\Api;
use SemanticSEO\Settings;

/**
 * Thrown instead of redirecting so the request can be inspected.
 */
final class RedirectIntercepted extends \Exception {

	/**
	 * Location.
	 *
	 * @var string
	 */
	public $location;

	/**
	 * Constructor.
	 *
	 * @param string $location Location.
	 */
	public function __construct( $location ) {
		parent::__construct( 'redirect' );
		$this->location = $location;
	}
}

/**
 * @group admin
 */
final class AdminUiTest extends TestCase {

	/**
	 * Intercepts redirects.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		add_filter( 'wp_redirect', array( $this, 'intercept' ), 1 );
	}

	/**
	 * Cleans globals.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_filter( 'wp_redirect', array( $this, 'intercept' ), 1 );
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		$GLOBALS['current_screen'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		parent::tear_down();
	}

	/**
	 * Redirect interceptor.
	 *
	 * @param string $location Location.
	 * @throws RedirectIntercepted Always.
	 */
	public function intercept( $location ) {
		throw new RedirectIntercepted( $location );
	}

	/**
	 * Renders an admin page and returns the HTML.
	 *
	 * @param string $slug Page slug.
	 * @param array  $get  Extra query args.
	 * @return string
	 */
	private function render_page( $slug, array $get = array() ) {
		$this->as_admin_screen();
		$_GET = array_merge( array( 'page' => $slug ), $get );
		ob_start();
		try {
			Menu::render();
		} finally {
			$html = ob_get_clean();
		}
		return $html;
	}

	/**
	 * Posts an admin action and returns [notice type, message, redirect].
	 *
	 * @param string $action Action without prefix.
	 * @param array  $fields Fields.
	 * @return array
	 */
	private function post_action( $action, array $fields ) {
		$this->as_admin_screen();
		$_POST    = array_merge(
			array(
				'action'     => 'sseo_' . $action,
				'_wpnonce'   => wp_create_nonce( 'sseo_' . $action ),
				'_sseo_back' => admin_url( 'admin.php?page=sseo-audit' ),
			),
			$fields
		);
		$_REQUEST = $_POST;
		$location = '';
		try {
			Actions::dispatch();
		} catch ( RedirectIntercepted $r ) {
			$location = $r->location;
		}
		$notice = get_transient( 'sseo_notice_' . get_current_user_id() );
		delete_transient( 'sseo_notice_' . get_current_user_id() );
		$first = is_array( $notice ) && isset( $notice[0] ) ? $notice[0] : array( '', '' );
		return array( isset( $first['type'] ) ? $first['type'] : $first[0], isset( $first['message'] ) ? $first['message'] : $first[1], $location );
	}

	public function test_all_pages_render_on_empty_install() {
		foreach ( array_keys( Menu::pages() ) as $slug ) {
			$html = $this->render_page( $slug );
			$this->assertStringContainsString( 'sseo-wrap', $html, $slug );
			$this->assertStringNotContainsString( 'Fatal error', $html, $slug );
			$this->assertStringNotContainsString( 'Warning:', $html, $slug );
		}
		$overview = $this->render_page( 'sseo' );
		$this->assertMatchesRegularExpression( '/wizard|Wizard|Setup/i', $overview );
	}

	public function test_all_pages_render_with_analysis_data() {
		$this->load_corpus();
		$this->run_full_pipeline();
		$doc  = $this->doc( 'vi-pillar' );
		$seen = array();
		foreach ( array_keys( Menu::pages() ) as $slug ) {
			$html          = $this->render_page( $slug );
			$seen[ $slug ] = strlen( $html );
			$this->assertStringContainsString( 'sseo-wrap', $html, $slug );
		}
		$this->assertStringContainsString( 'data-label', $this->render_page( 'sseo-matrix' ), 'Responsive tables carry data-label.' );
		$this->assertStringContainsString( 'id="sseo-inference"', $this->render_page( 'sseo-jobs' ) );
		$detail = $this->render_page( 'sseo-semantic', array( 'doc' => $doc ) );
		$this->assertStringContainsString( 'Thuê xe máy', $detail );
		foreach ( array( 'proposals', 'changes', 'approvals', 'log' ) as $tab ) {
			$this->assertStringContainsString( 'sseo-wrap', $this->render_page( 'sseo-audit', array( 'tab' => $tab ) ) );
		}
		$this->assertGreaterThan( 1000, $seen['sseo-cannibalization'] );
	}

	public function test_management_pages_are_gated() {
		Capabilities::ensure_agent_roles();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'sseo_agent_analyst' ) ) );
		$this->assertStringContainsString( 'sseo-wrap', $this->render_page( 'sseo-matrix' ) );
		foreach ( array( 'sseo-settings', 'sseo-agents' ) as $slug ) {
			try {
				$this->render_page( $slug );
				$this->fail( $slug . ' must be refused to agents.' );
			} catch ( \WPDieException $e ) {
				$this->assertStringContainsString( 'permission', $e->getMessage() );
			}
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->expectException( \WPDieException::class );
		$this->render_page( 'sseo' );
	}

	public function test_actions_require_valid_nonce() {
		$this->as_admin_screen();
		$_POST    = array(
			'action'   => 'sseo_save_settings',
			'_wpnonce' => 'forged',
			'form'     => 'settings',
		);
		$_REQUEST = $_POST;
		$this->expectException( \WPDieException::class );
		Actions::dispatch();
	}

	public function test_human_approve_apply_rollback_through_admin_forms() {
		$this->load_corpus();
		$this->run_full_pipeline();
		$f = Db::row( "SELECT * FROM {findings} WHERE type = 'link' AND status = 'open' AND evidence LIKE %s ORDER BY score DESC LIMIT 1", array( '%"offset"%' ) );
		$this->assertNotNull( $f );
		list( $type, $msg ) = $this->post_action( 'create_preview', array( 'finding_id' => $f['id'] ) );
		$this->assertSame( 'success', $type, $msg );
		$proposal = Db::row( 'SELECT * FROM {proposals} ORDER BY id DESC LIMIT 1' );
		$post_id  = (int) $proposal['object_id'];
		$before   = get_post_field( 'post_content', $post_id, 'raw' );

		// Approval without the explicit confirmation is refused.
		list( $type ) = $this->post_action( 'approve', array( 'proposal_id' => $proposal['id'] ) );
		$this->assertSame( 'error', $type );
		$this->assertSame( 0, (int) Db::value( 'SELECT COUNT(*) FROM {approvals}' ) );

		list( $type, $msg, $loc ) = $this->post_action(
			'approve',
			array(
				'proposal_id' => $proposal['id'],
				'confirm'     => '1',
			)
		);
		$this->assertSame( 'success', $type, $msg );
		$this->assertStringContainsString( 'page=sseo-audit', $loc );
		$approval = Db::row( 'SELECT * FROM {approvals} ORDER BY id DESC LIMIT 1' );
		$this->assertSame( 'active', $approval['status'] );

		list( $type, $msg ) = $this->post_action(
			'apply',
			array(
				'proposal_id'     => $proposal['id'],
				'approval_id'     => $approval['id'],
				'idempotency_key' => 'ui-test-apply-1',
			)
		);
		$this->assertSame( 'success', $type, $msg );
		$this->assertNotSame( $before, get_post_field( 'post_content', $post_id, 'raw' ) );

		$change = Db::row( 'SELECT * FROM {changes} ORDER BY id DESC LIMIT 1' );
		list( $type ) = $this->post_action( 'rollback', array( 'change_id' => $change['id'] ) );
		$this->assertSame( 'error', $type, 'Rollback needs confirmation.' );
		list( $type, $msg ) = $this->post_action(
			'rollback',
			array(
				'change_id' => $change['id'],
				'confirm'   => '1',
			)
		);
		$this->assertSame( 'success', $type, $msg );
		$this->assertSame( $before, get_post_field( 'post_content', $post_id, 'raw' ) );
	}

	public function test_agent_cannot_approve_even_through_admin_forms() {
		$this->load_corpus();
		$this->run_full_pipeline();
		$f = Db::row( "SELECT * FROM {findings} WHERE type = 'link' AND status = 'open' AND evidence LIKE %s LIMIT 1", array( '%"offset"%' ) );
		$p = Api::create_change_preview( array( 'finding_ids' => array( (int) $f['id'] ) ) );
		$this->assertArrayHasKey( 'proposal', $p['previews'][0] );
		Capabilities::ensure_agent_roles();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'sseo_agent_operator' ) ) );
		list( $type ) = $this->post_action(
			'approve',
			array(
				'proposal_id' => $p['previews'][0]['proposal']['id'],
				'confirm'     => '1',
			)
		);
		$this->assertSame( 'error', $type );
		$this->assertSame( 0, (int) Db::value( 'SELECT COUNT(*) FROM {approvals}' ) );
	}

	public function test_settings_form_sanitizes_and_requires_typed_delete() {
		list( $type, $msg ) = $this->post_action(
			'save_settings',
			array(
				'form' => 'settings',
				'sseo' => array(
					'post_types'      => 'post, page, ../evil',
					'batch_size'      => '99999',
					'link_min_score'  => '7',
					'uninstall_policy' => 'delete',
				),
			)
		);
		$this->assertSame( 'error', $type, 'Typed confirmation required for delete-on-uninstall.' );
		$this->assertSame( 'preserve', Settings::get( 'uninstall_policy' ) );

		list( $type, $msg ) = $this->post_action(
			'save_settings',
			array(
				'form' => 'settings',
				'sseo' => array(
					'post_types' => 'post, page',
					'batch_size' => '99999',
				),
			)
		);
		$this->assertSame( 'success', $type, $msg );
		$this->assertLessThanOrEqual( 200, (int) Settings::get( 'batch_size' ) );

		// Agents may not change settings, whatever the form says.
		Capabilities::ensure_agent_roles();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'sseo_agent_operator' ) ) );
		list( $type ) = $this->post_action(
			'save_settings',
			array(
				'form' => 'agents',
				'sseo' => array( 'agents_may_apply' => '1' ),
			)
		);
		$this->assertSame( 'error', $type );
		$this->assertFalse( (bool) Settings::get( 'agents_may_apply' ) );
	}

	public function test_back_redirect_cannot_leave_admin() {
		list( , , $loc ) = $this->post_action( 'job_control', array( 'job_id' => 999, 'op' => 'tick', '_sseo_back' => 'https://evil.example/phish' ) );
		$this->assertStringStartsWith( admin_url(), $loc );
	}

	public function test_vietnamese_admin_translation_is_loaded_by_default_and_english_on_request() {
		$this->as_admin_screen();
		unload_textdomain( 'semantic-seo-engine-vi-en' );
		\SemanticSEO\Plugin::load_textdomain();
		$this->assertSame( 'Đã lưu cài đặt.', __( 'Settings saved.', 'semantic-seo-engine-vi-en' ) );
		$this->assertSame( 'Nhật ký / Hoàn tác', __( 'Audit / Undo / Rollback', 'semantic-seo-engine-vi-en' ) );
		$html = $this->render_page( 'sseo-audit' );
		$this->assertStringContainsString( 'Nhật ký / Hoàn tác', $html );

		Settings::update( array( 'ui_language' => 'en' ) );
		unload_textdomain( 'semantic-seo-engine-vi-en' );
		\SemanticSEO\Plugin::load_textdomain();
		$this->assertSame( 'Settings saved.', __( 'Settings saved.', 'semantic-seo-engine-vi-en' ) );
		unload_textdomain( 'semantic-seo-engine-vi-en' );
	}

	/**
	 * Runs every queued scan job to completion (inventory, then the chained analysis).
	 *
	 * @return void
	 */
	private function run_scan_jobs() {
		for ( $i = 0; $i < 6; $i++ ) {
			$next = \SemanticSEO\Jobs\JobRepository::next_runnable();
			if ( $next <= 0 ) {
				return;
			}
			\SemanticSEO\Jobs\JobRunner::run_to_completion( $next );
		}
	}

	public function test_dashboard_trial_on_20_pages_is_read_only_and_shows_measured_results() {
		$this->load_corpus();
		$before = \SemanticSEO\Security\ReadOnlyProof::snapshot();
		list( $type, $msg ) = $this->post_action( 'scan', array( 'scope' => 'pilot' ) );
		$this->assertSame( 'success', $type, $msg );
		$inv = \SemanticSEO\Jobs\JobRepository::find( array( 'type' => 'inventory', 'limit' => 1 ) )[0];
		$this->assertTrue( $inv['params']['pilot'] );
		$this->assertCount( \SemanticSEO\Admin\OverviewPage::PILOT_SIZE, $inv['params']['post_ids'] );
		$this->assertArrayHasKey( 'hash', $inv['params']['readonly_snapshot'] );

		// While it runs: progress and controls on the dashboard.
		$html = $this->render_page( 'sseo' );
		$this->assertStringContainsString( 'data-sseo-job="' . $inv['id'] . '"', $html );
		$this->assertStringContainsString( 'name="op" value="pause"', $html );
		$this->assertStringContainsString( 'name="op" value="cancel"', $html );

		$this->run_scan_jobs();
		$pilot = \SemanticSEO\Analysis\ScanHistory::latest( 'pilot' );
		$this->assertNotNull( $pilot );
		$this->assertTrue( $pilot['readonly']['unchanged'], 'the trial changed nothing' );
		$this->assertSame( array(), $pilot['readonly']['changed_post_ids'] );
		$this->assertGreaterThan( 0, $pilot['work_seconds'] );
		$this->assertGreaterThan( 0, $pilot['peak_memory'] );
		$this->assertSame( $before['hash'], \SemanticSEO\Security\ReadOnlyProof::snapshot()['hash'], 'posts, meta and terms of the whole site unchanged' );

		$html = $this->render_page( 'sseo' );
		$this->assertStringContainsString( 'Read-only verified', $html );
		$this->assertStringContainsString( 'Estimated processing time', $html, 'the whole-site estimate comes from the measured trial' );
		$this->assertStringContainsString( 'value="site"', $html, 'step 3 is offered' );
		$est = \SemanticSEO\Services\HealthReport::overview()['estimate'];
		$this->assertSame( $pilot['job_id'], $est['basis_job'] );
		$this->assertSame( (int) ceil( $est['seconds_per_document'] * $est['documents'] ), $est['estimated_seconds'] );
	}

	public function test_dashboard_full_scan_can_be_paused_resumed_and_compared() {
		$this->load_corpus();
		list( $type ) = $this->post_action( 'scan', array( 'scope' => 'site' ) );
		$this->assertSame( 'success', $type );
		$inv = \SemanticSEO\Jobs\JobRepository::find( array( 'type' => 'inventory', 'limit' => 1 ) )[0];
		list( $type ) = $this->post_action( 'job_control', array( 'job_id' => $inv['id'], 'op' => 'pause' ) );
		$this->assertSame( 'paused', \SemanticSEO\Jobs\JobRepository::get( (int) $inv['id'] )['status'] );
		$this->assertStringContainsString( 'name="op" value="resume"', $this->render_page( 'sseo' ) );
		$this->post_action( 'job_control', array( 'job_id' => $inv['id'], 'op' => 'resume' ) );
		$this->run_scan_jobs();
		$this->assertSame( 'site', \SemanticSEO\Analysis\ScanHistory::latest()['scope'] );

		$html = $this->render_page( 'sseo' );
		$this->assertStringContainsString( 'SEO health', $html );
		$this->assertStringContainsString( 'What to do first', $html );
		$this->assertStringContainsString( 'This is the first full scan', $html );

		// A second scan is compared with the first one.
		$this->post_action( 'scan', array( 'scope' => 'site' ) );
		$this->run_scan_jobs();
		$diff = \SemanticSEO\Analysis\ScanHistory::diff();
		$this->assertNotNull( $diff['previous'] );
		$this->assertSame( 0, $diff['new_count'], 'nothing changed between the scans' );
		$this->assertSame( 0, $diff['resolved_count'] );
		$this->assertStringContainsString( '0 new findings, 0 resolved', $this->render_page( 'sseo' ) );
	}

	public function test_dashboard_repairs_an_incomplete_database() {
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		global $wpdb;
		$table = \SemanticSEO\Database\Schema::table( 'embeddings' );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $table ); // phpcs:ignore WordPress.DB
		$read = array();
		$spy  = static function ( $q ) use ( $table, &$read ) {
			if ( false !== stripos( $q, 'FROM ' . $table ) ) {
				$read[] = $q;
			}
			return $q;
		};
		add_filter( 'query', $spy );
		$html = $this->render_page( 'sseo' );
		remove_filter( 'query', $spy );
		$this->assertSame( array(), $read, 'the dashboard never reads the missing table (no SQL error on an incomplete database)' );
		$this->assertStringContainsString( 'sseo-health--error', $html );
		$this->assertStringContainsString( 'Missing: embeddings', $html );
		$this->assertStringContainsString( 'value="sseo_repair_db"', $html );
		$this->assertStringNotContainsString( 'value="pilot"', $html, 'no scan button while the database is incomplete' );

		list( $type, $msg ) = $this->post_action( 'repair_db', array() );
		$this->assertSame( 'success', $type, $msg );
		$this->assertStringContainsString( 'Database ready', $msg );
		$this->assertTrue( \SemanticSEO\Compatibility\Environment::db_schema()['ready'] );
		$this->assertStringContainsString( 'sseo-health--ok', $this->render_page( 'sseo' ) );
	}

	public function test_only_managers_can_repair_the_database() {
		Capabilities::ensure_agent_roles();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'sseo_agent_analyst' ) ) );
		list( $type ) = $this->post_action( 'repair_db', array() );
		$this->assertSame( 'error', $type );
	}

	public function test_priorities_page_filters() {
		$this->load_corpus();
		$this->run_full_pipeline();
		$all = $this->render_page( 'sseo-priorities' );
		$this->assertStringContainsString( 'sseo-priorities', $all );
		$this->assertStringContainsString( 'Expected benefit:', $all );
		$this->assertStringContainsString( 'value="sseo_export_priorities"', $all );
		$links = $this->render_page( 'sseo-priorities', array( 'category' => 'links' ) );
		$this->assertStringNotContainsString( 'Two pages with nearly the same content', $links );
		$this->assertStringContainsString( 'Internal links', $links );
		$none = $this->render_page( 'sseo-priorities', array( 'search' => 'zzz-no-such-page' ) );
		$this->assertStringContainsString( 'Nothing to show for these filters.', $none );
	}
}
