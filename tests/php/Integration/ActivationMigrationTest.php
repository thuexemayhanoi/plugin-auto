<?php
/**
 * Integration: activation is read-only; migrations are versioned, repeatable and resumable.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Tests\Integration;

use SemanticSEO\Database\Migrator;
use SemanticSEO\Database\Schema;
use SemanticSEO\Plugin;
use SemanticSEO\Security\Mutex;

/**
 * @group activation
 */
final class ActivationMigrationTest extends TestCase {

	/**
	 * Checksums of WordPress-owned data.
	 *
	 * @return array
	 */
	private function wp_state() {
		global $wpdb;
		$opts = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name NOT LIKE 'sseo\\_%' AND option_name NOT LIKE '\\_transient%' AND option_name NOT LIKE '\\_site\\_transient%' AND option_name <> 'cron' ORDER BY option_name", ARRAY_A ); // phpcs:ignore WordPress.DB
		return array(
			'posts'    => md5( serialize( $wpdb->get_results( "SELECT ID, post_content, post_title, post_name, post_status, post_modified FROM {$wpdb->posts} ORDER BY ID", ARRAY_A ) ) ), // phpcs:ignore
			'postmeta' => md5( serialize( $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} ORDER BY meta_id", ARRAY_A ) ) ), // phpcs:ignore
			'terms'    => md5( serialize( $wpdb->get_results( "SELECT * FROM {$wpdb->term_relationships} ORDER BY object_id, term_taxonomy_id", ARRAY_A ) ) ), // phpcs:ignore
			'options'  => md5( serialize( $opts ) ), // phpcs:ignore
			'roles'    => md5( serialize( get_option( $wpdb->prefix . 'user_roles' ) ) ), // phpcs:ignore
		);
	}

	public function test_activation_is_read_only_for_wordpress_data() {
		$this->load_corpus();
		update_post_meta( $this->posts['vi-pillar'], '_yoast_wpseo_canonical', 'https://example.org/keep-me/' );
		$before = $this->wp_state();
		$cron   = _get_cron_array();
		Plugin::activate();
		$after = $this->wp_state();
		$this->assertSame( $before, $after, 'Posts, meta, term relationships, options and roles are untouched by activation.' );
		$this->assertSame( $cron, _get_cron_array(), 'Activation schedules nothing.' );
		$this->assertSame( 0, (int) \SemanticSEO\Database\Db::value( 'SELECT COUNT(*) FROM {jobs}' ), 'No scan is started on activation.' );
		$this->assertSame( array(), Migrator::missing_tables() );
		$settings = get_option( 'sseo_settings' );
		$this->assertFalse( $settings['related_enabled'], 'Frontend output disabled by default.' );
		$this->assertFalse( $settings['agents_may_apply'] );
		$this->assertFalse( $settings['mcp_enabled'] );
		$this->assertSame( array(), $settings['abilities_rest'] );
	}

	public function test_frontend_output_unchanged_by_default() {
		$this->load_corpus();
		$post = get_post( $this->posts['vi-pillar'] );
		$html = apply_filters( 'the_content', $post->post_content );
		$this->assertStringNotContainsString( 'sseo-related', $html );
		$this->assertSame( '', do_shortcode( '[sseo_related post_id="' . $post->ID . '"]' ), 'Shortcode renders nothing until enabled.' );
	}

	public function test_deactivation_keeps_data() {
		$this->load_corpus();
		$this->run_full_pipeline();
		$docs = (int) \SemanticSEO\Database\Db::value( 'SELECT COUNT(*) FROM {documents}' );
		Plugin::deactivate();
		$this->assertSame( $docs, (int) \SemanticSEO\Database\Db::value( 'SELECT COUNT(*) FROM {documents}' ) );
		$this->assertFalse( wp_next_scheduled( \SemanticSEO\Jobs\JobRunner::HOOK ) );
	}

	/**
	 * Disables the test suite's CREATE/DROP TEMPORARY TABLE rewriting for real DDL.
	 *
	 * @return void
	 */
	private function real_ddl() {
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	public function test_migration_is_repeatable_and_preserves_data() {
		$this->real_ddl();
		$this->load_corpus();
		$this->run_full_pipeline();
		$count = (int) \SemanticSEO\Database\Db::value( 'SELECT COUNT(*) FROM {documents}' );
		// Re-running the same version is a no-op; forcing it again is idempotent.
		$this->assertFalse( Migrator::maybe_upgrade()['ran'] );
		$res = Migrator::migrate();
		$this->assertSame( '', $res['error'] );
		$this->assertSame( $count, (int) \SemanticSEO\Database\Db::value( 'SELECT COUNT(*) FROM {documents}' ) );
	}

	public function test_upgrade_from_older_schema_restores_columns_without_data_loss() {
		$this->real_ddl();
		global $wpdb;
		$table = Schema::table( 'documents' );
		$wpdb->query( "INSERT INTO {$table} (object_type, object_id, url, url_hash, title, signals) VALUES ('post', 999001, 'https://example.org/legacy/', '', 'Legacy row', '{}')" ); // phpcs:ignore
		// Simulate a pre-release schema: a column is missing and the version is older.
		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN review_state" ); // phpcs:ignore
		update_option( Migrator::VERSION_OPTION, 0 );
		$this->assertTrue( Migrator::needs_upgrade() );
		$res = Migrator::maybe_upgrade();
		$this->assertTrue( $res['ran'] );
		$this->assertSame( '', $res['error'] );
		$this->assertSame( Schema::VERSION, Migrator::installed_version() );
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ); // phpcs:ignore
		$this->assertContains( 'review_state', $cols );
		$row = $wpdb->get_row( "SELECT * FROM {$table} WHERE object_id = 999001", ARRAY_A ); // phpcs:ignore
		$this->assertSame( 'Legacy row', $row['title'], 'Existing data preserved.' );
		$this->assertSame( 40, strlen( $row['url_hash'] ), 'Step 1 backfilled the URL hash.' );
		$log = get_option( Migrator::LOG_OPTION );
		$this->assertSame( 0, end( $log )['from'] );
	}

	public function test_upgrade_from_schema_1_adds_the_new_indexes() {
		$this->real_ddl();
		global $wpdb;
		$table = Schema::table( 'findings' );
		$docs  = Schema::table( 'documents' );
		// Schema 1: no target_id index, the narrow type_status index, no orphan/excluded indexes.
		$wpdb->query( "ALTER TABLE {$table} DROP INDEX target_id, DROP INDEX type_status_rank, ADD INDEX type_status (type,status)" ); // phpcs:ignore
		$wpdb->query( "ALTER TABLE {$docs} DROP INDEX orphans, DROP INDEX excluded" ); // phpcs:ignore
		update_option( Migrator::VERSION_OPTION, 1 );
		$res = Migrator::maybe_upgrade();
		$this->assertSame( '', $res['error'] );
		$this->assertSame( Schema::VERSION, Migrator::installed_version() );
		$keys = $wpdb->get_col( "SHOW INDEX FROM {$table}", 2 ); // phpcs:ignore
		$this->assertContains( 'target_id', $keys );
		$this->assertContains( 'type_status_rank', $keys );
		$this->assertNotContains( 'type_status', $keys, 'the replaced index is dropped' );
		$keys = $wpdb->get_col( "SHOW INDEX FROM {$docs}", 2 ); // phpcs:ignore
		$this->assertContains( 'orphans', $keys );
		$this->assertContains( 'excluded', $keys );
		// Running the step again is harmless.
		Migrator::step_3_drop_replaced_index();
	}

	public function test_interrupted_upgrade_is_resumed_after_stale_lock() {
		$this->real_ddl();
		update_option( Migrator::VERSION_OPTION, 0 );
		// A concurrent migration holds a fresh lock: this request must not run it twice.
		$token = Mutex::acquire( 'migration', Migrator::LOCK_TTL );
		$this->assertNotFalse( $token );
		$res = Migrator::maybe_upgrade();
		$this->assertSame( 'locked', $res['error'] );
		$this->assertSame( 0, Migrator::installed_version(), 'Version not advanced while locked.' );
		// The other process "crashed": make the lock stale, the next request takes over.
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = 'sseo_mutex_migration'", ( time() - Migrator::LOCK_TTL - 5 ) . ':' . $token ) ); // phpcs:ignore
		$res = Migrator::maybe_upgrade();
		$this->assertSame( '', $res['error'] );
		$this->assertSame( Schema::VERSION, Migrator::installed_version() );
		$this->assertFalse( Mutex::is_locked( 'migration', Migrator::LOCK_TTL ) );
	}

	public function test_mutex_is_exclusive() {
		$a = Mutex::acquire( 'object_post_1', 60 );
		$b = Mutex::acquire( 'object_post_1', 60 );
		$this->assertNotFalse( $a );
		$this->assertFalse( $b, 'Second acquire fails while held.' );
		$this->assertFalse( Mutex::release( 'object_post_1', 'wrongtoken' ) );
		$this->assertTrue( Mutex::release( 'object_post_1', $a ) );
		$this->assertNotFalse( Mutex::acquire( 'object_post_1', 60 ) );
	}
}
