<?php
/**
 * Database compatibility regressions (MariaDB 11.7+ VECTOR keyword).
 *
 * Production report: on MariaDB 11.8.9 the plugin created 15 of its 16 tables. The embeddings
 * definition used a column named `vector`, which MariaDB 11.7+ parses as the VECTOR data type
 * (error 1064, SQLSTATE 42000), so the table was never created. The version never advanced and
 * the failed migration re-ran on every request.
 *
 * Run this file against MariaDB 11.8 (CI job "Integration (MariaDB 11.8)") and against an older
 * server: every test must pass on both.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Tests\Integration;

use SemanticSEO\Compatibility\Environment;
use SemanticSEO\Database\Db;
use SemanticSEO\Database\Migrator;
use SemanticSEO\Database\Schema;
use SemanticSEO\Semantic\EmbeddingRepository;
use SemanticSEO\Semantic\ModelProfile;
use SemanticSEO\Semantic\VectorMath;

/**
 * @group activation
 */
final class SchemaCompatibilityTest extends TestCase {

	/**
	 * DDL statements seen while a filter is active.
	 *
	 * @var string[]
	 */
	private $ddl = array();

	/**
	 * Disables the test suite's CREATE/DROP TEMPORARY TABLE rewriting for real DDL.
	 *
	 * @return void
	 */
	private function real_ddl() {
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	/**
	 * Restores a complete schema after a test that broke it (DDL is not rolled back).
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_filter( 'query', array( $this, 'break_embeddings_create' ) );
		remove_filter( 'query', array( $this, 'record_ddl' ) );
		delete_option( Migrator::RETRY_OPTION );
		Migrator::migrate();
		parent::tear_down();
	}

	/**
	 * Server version facts.
	 *
	 * @return array{mariadb:bool,version:string}
	 */
	private function server() {
		global $wpdb;
		$v = (string) $wpdb->get_var( 'SELECT VERSION()' );
		return array(
			'mariadb' => false !== stripos( $v, 'mariadb' ),
			'version' => preg_replace( '/[^0-9.].*$/', '', $v ),
		);
	}

	public function test_root_cause_vector_is_a_keyword_on_mariadb_11_7_and_later() {
		$server = $this->server();
		if ( ! $server['mariadb'] || version_compare( $server['version'], '11.7', '<' ) ) {
			$this->markTestSkipped( 'Only MariaDB 11.7+ treats VECTOR as a data type keyword (server ' . $server['version'] . ').' );
		}
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		$wpdb->query( 'CREATE TEMPORARY TABLE sseo_vector_probe (id int, vector longblob NOT NULL)' ); // phpcs:ignore WordPress.DB
		$errno = (int) mysqli_errno( $wpdb->dbh );
		$state = (string) mysqli_sqlstate( $wpdb->dbh );
		$wpdb->suppress_errors( $suppress );
		$this->assertSame( 1064, $errno, 'the 1.0.0-beta.3 column definition is a syntax error here' );
		$this->assertSame( '42000', $state );
	}

	public function test_every_table_and_column_exists_after_activation() {
		$this->real_ddl();
		$res = Migrator::migrate();
		$this->assertSame( '', $res['error'], wp_json_encode( $res ) );
		$verify = Migrator::verify();
		$this->assertSame( array(), $verify['missing'] );
		$this->assertSame( array(), $verify['missing_columns'] );
		$this->assertContains( 'vector_data', Migrator::expected_columns()['embeddings'] );
		$this->assertNotContains( 'vector', Migrator::expected_columns()['embeddings'], 'no keyword column names' );

		$schema = Environment::db_schema();
		$this->assertTrue( $schema['ready'] );
		$this->assertSame( Schema::VERSION, $schema['installed'] );
		$this->assertSame( $schema['expected'], $schema['installed'] );
		$this->assertSame( 16, $schema['tables_present'] );
		$this->assertSame( 16, $schema['tables_expected'] );
	}

	public function test_vectors_are_stored_and_read_back() {
		$this->load_corpus();
		$this->run_full_pipeline();
		$profile = ModelProfile::active();
		$doc     = (int) Db::value( "SELECT id FROM {documents} WHERE object_type = 'post' LIMIT 1" );
		$chunk   = Db::row( "SELECT chunk_index, content_hash FROM {chunks} WHERE document_id = %d AND kind = 'summary'", array( $doc ) );
		$this->assertNotNull( $chunk );
		$vec = VectorMath::normalize( array_map( 'floatval', range( 1, (int) $profile['dimension'] ) ) );
		$this->assertTrue(
			EmbeddingRepository::save(
				array(
					'document_id'  => $doc,
					'chunk_id'     => (int) $chunk['chunk_index'],
					'content_hash' => $chunk['content_hash'],
				),
				$vec,
				$profile,
				'browser'
			),
			Db::last_error()
		);
		$read = EmbeddingRepository::summary_vectors( array( $doc ), $profile['model_key'] );
		$this->assertArrayHasKey( $doc, $read );
		$this->assertEqualsWithDelta( $vec[0], $read[ $doc ][0], 1e-6 );
		$this->assertSame( 1, EmbeddingRepository::stats( $profile['model_key'] )['documents_with_vector'] );
	}

	public function test_a_partial_install_with_15_of_16_tables_is_completed() {
		$this->real_ddl();
		global $wpdb;
		$this->load_corpus();
		$this->run_full_pipeline();
		$docs = (int) Db::value( 'SELECT COUNT(*) FROM {documents}' );
		$this->assertGreaterThan( 0, $docs );
		// The production state: every table but embeddings, version never recorded.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Schema::table( 'embeddings' ) ); // phpcs:ignore WordPress.DB
		update_option( Migrator::VERSION_OPTION, 0 );
		$this->assertSame( array( 'embeddings' ), Migrator::missing_tables() );
		$this->assertFalse( Environment::db_schema()['ready'] );

		$res = Migrator::maybe_upgrade();
		$this->assertTrue( $res['ran'] );
		$this->assertSame( '', $res['error'], wp_json_encode( $res ) );
		$this->assertSame( Schema::VERSION, Migrator::installed_version() );
		$this->assertSame( array(), Migrator::missing_tables() );
		$this->assertSame( $docs, (int) Db::value( 'SELECT COUNT(*) FROM {documents}' ), 'existing data kept' );
		$this->assertTrue( Environment::db_schema()['ready'] );
	}

	public function test_legacy_vector_column_is_renamed_without_losing_vectors() {
		$this->real_ddl();
		global $wpdb;
		$table = Schema::table( 'embeddings' );
		// The beta.3 table as MySQL / MariaDB <= 11.6 created it (the old name quoted, so this
		// setup also runs on MariaDB 11.8).
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
		$create = str_replace( 'vector_data longblob', '`vector` longblob', Schema::definitions()['embeddings'] );
		$this->assertNotSame( Schema::definitions()['embeddings'], $create );
		$wpdb->query( $create ); // phpcs:ignore WordPress.DB
		$blob = VectorMath::pack( array( 0.6, 0.8 ) );
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (document_id, chunk_id, model_key, model_id, revision, dtype, dimension, preprocess_version, content_hash, `vector`, norm, created_at) VALUES (7, 0, %s, 'm', 'r', 'fp32', 2, 'p2', %s, %s, 1, NOW())", str_repeat( 'k', 40 ), str_repeat( 'h', 64 ), $blob ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( '', (string) $wpdb->last_error );
		update_option( Migrator::VERSION_OPTION, 3 );

		$res = Migrator::maybe_upgrade();
		$this->assertSame( '', $res['error'], wp_json_encode( $res ) );
		$this->assertSame( 4, Migrator::installed_version() );
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ); // phpcs:ignore WordPress.DB
		$this->assertContains( 'vector_data', $cols );
		$this->assertNotContains( 'vector', $cols );
		$this->assertSame( $blob, $wpdb->get_var( "SELECT vector_data FROM {$table} WHERE document_id = 7" ), 'vector kept byte for byte' ); // phpcs:ignore WordPress.DB

		// Idempotent: running the step and the migration again changes nothing.
		Migrator::step_4_rename_vector_column();
		$this->assertSame( '', Migrator::migrate()['error'] );
		$this->assertSame( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) ); // phpcs:ignore WordPress.DB
	}

	public function test_a_failed_rename_stops_the_migration_and_keeps_the_vectors() {
		$this->real_ddl();
		global $wpdb;
		$table = Schema::table( 'embeddings' );
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
		$wpdb->query( str_replace( 'vector_data longblob', '`vector` longblob', Schema::definitions()['embeddings'] ) ); // phpcs:ignore WordPress.DB
		$blob = VectorMath::pack( array( 0.6, 0.8 ) );
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (document_id, chunk_id, model_key, model_id, revision, dtype, dimension, preprocess_version, content_hash, `vector`, norm, created_at) VALUES (9, 0, %s, 'm', 'r', 'fp32', 2, 'p2', %s, %s, 1, NOW())", str_repeat( 'k', 40 ), str_repeat( 'h', 64 ), $blob ) ); // phpcs:ignore WordPress.DB
		update_option( Migrator::VERSION_OPTION, 3 );
		delete_option( Migrator::RETRY_OPTION );

		// The rename fails (as a lock wait timeout would): nothing may continue to dbDelta(), which
		// would add an empty vector_data column and let the migration look complete.
		$break = static function ( $sql ) {
			return false !== strpos( $sql, 'CHANGE COLUMN `vector`' ) ? str_replace( 'CHANGE COLUMN', 'CHANGE COLUMNX', $sql ) : $sql;
		};
		add_filter( 'query', $break );
		$res = Migrator::migrate();
		remove_filter( 'query', $break );
		$this->assertStringContainsString( 'could not rename', $res['error'] );
		$this->assertArrayHasKey( 'embeddings', $res['failures'] );
		$this->assertGreaterThan( 0, $res['failures']['embeddings']['errno'], 'MySQL error number recorded' );
		$this->assertStringContainsString( 'CHANGE COLUMN', $res['failures']['embeddings']['statement'] );
		$this->assertSame( 3, Migrator::installed_version(), 'not reported as migrated' );
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ); // phpcs:ignore WordPress.DB
		$this->assertContains( 'vector', $cols );
		$this->assertNotContains( 'vector_data', $cols, 'no empty vector_data column added next to the old one' );
		$this->assertFalse( Environment::db_schema()['ready'] );
		$this->assertTrue( Migrator::backing_off(), 'retried later, not on every request' );

		// The next attempt completes it with the vector intact. (The retry marker is removed first:
		// the rename's DDL commits the test transaction, which would otherwise keep it for later tests.)
		delete_option( Migrator::RETRY_OPTION );
		$this->assertSame( '', Migrator::migrate()['error'] );
		$this->assertSame( Schema::VERSION, Migrator::installed_version() );
		$this->assertSame( $blob, $wpdb->get_var( "SELECT vector_data FROM {$table} WHERE document_id = 9" ) ); // phpcs:ignore WordPress.DB
	}

	public function test_a_table_with_both_columns_is_repaired_without_losing_vectors() {
		$this->real_ddl();
		global $wpdb;
		$table = Schema::table( 'embeddings' );
		$wpdb->query( "ALTER TABLE {$table} ADD COLUMN `vector` longblob NULL" ); // phpcs:ignore WordPress.DB
		$blob = VectorMath::pack( array( 1.0 ) );
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (document_id, chunk_id, model_key, model_id, revision, dtype, dimension, preprocess_version, content_hash, vector_data, `vector`, created_at) VALUES (8, 0, %s, 'm', 'r', 'fp32', 1, 'p2', %s, '', %s, NOW())", str_repeat( 'k', 40 ), str_repeat( 'h', 64 ), $blob ) ); // phpcs:ignore WordPress.DB
		$this->assertContains( 'embeddings.vector (old column, not yet moved to vector_data)', Migrator::verify()['missing_columns'], 'a leftover old column is not a complete schema' );
		Migrator::step_4_rename_vector_column();
		$this->assertNotContains( 'vector', $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( $blob, $wpdb->get_var( "SELECT vector_data FROM {$table} WHERE document_id = 8" ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Makes the embeddings CREATE TABLE fail with a syntax error on any server.
	 *
	 * @param string $sql SQL.
	 * @return string
	 */
	public function break_embeddings_create( $sql ) {
		if ( 0 === strpos( ltrim( $sql ), 'CREATE TABLE ' . Schema::table( 'embeddings' ) . ' ' ) ) {
			return str_replace( 'vector_data longblob NOT NULL,', 'vector_data longblob NOT NULL NOT,', $sql );
		}
		return $sql;
	}

	/**
	 * Records DDL statements.
	 *
	 * @param string $sql SQL.
	 * @return string
	 */
	public function record_ddl( $sql ) {
		if ( preg_match( '/^\s*(CREATE|ALTER|DESCRIBE)\b/i', $sql ) ) {
			$this->ddl[] = $sql;
		}
		return $sql;
	}

	public function test_a_failed_migration_reports_the_sql_error_and_is_not_retried_on_every_request() {
		$this->real_ddl();
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Schema::table( 'embeddings' ) ); // phpcs:ignore WordPress.DB
		update_option( Migrator::VERSION_OPTION, 0 );
		add_filter( 'query', array( $this, 'break_embeddings_create' ) );

		$res = Migrator::maybe_upgrade();
		$this->assertTrue( $res['ran'] );
		$this->assertSame( 'missing tables: embeddings', $res['error'] );
		$this->assertSame( 0, Migrator::installed_version(), 'never reported as migrated while a table is missing' );
		$this->assertSame( 1064, $res['failures']['embeddings']['errno'], wp_json_encode( $res['failures'] ) );
		$this->assertSame( '42000', $res['failures']['embeddings']['sqlstate'] );
		$this->assertStringContainsString( 'CREATE TABLE', $res['failures']['embeddings']['statement'] );
		$last = Migrator::last_log();
		$this->assertSame( 1064, $last['sql_errors']['embeddings']['errno'], 'the log keeps the SQL error' );

		$schema = Environment::db_schema();
		$this->assertFalse( $schema['ready'] );
		$this->assertSame( 15, $schema['tables_present'] );
		$this->assertSame( array( 'embeddings' ), $schema['missing'] );
		$this->assertSame( '42000', $schema['sql_errors']['embeddings']['sqlstate'] );
		$this->assertTrue( $schema['retry_scheduled'] );

		// The next requests do not run the migration again (no DDL at all).
		add_filter( 'query', array( $this, 'record_ddl' ) );
		for ( $i = 0; $i < 3; $i++ ) {
			$again = Migrator::maybe_upgrade();
			$this->assertFalse( $again['ran'] );
			$this->assertSame( 'retry_later', $again['error'] );
		}
		$this->assertSame( array(), $this->ddl, 'no dbDelta on ordinary requests while backing off' );
		$this->assertCount( 1, array_filter( get_option( Migrator::LOG_OPTION ), static function ( $e ) { return 0 === $e['from'] && '' !== $e['error']; } ) ); // phpcs:ignore

		// A plugin update retries at once; once the cause is gone the schema is complete.
		remove_filter( 'query', array( $this, 'break_embeddings_create' ) );
		update_option(
			Migrator::RETRY_OPTION,
			array(
				'at'     => time(),
				'plugin' => '0.0.0-older',
			)
		);
		$this->assertFalse( Migrator::backing_off() );
		$fixed = Migrator::maybe_upgrade();
		$this->assertSame( '', $fixed['error'] );
		$this->assertSame( Schema::VERSION, Migrator::installed_version() );
		$this->assertFalse( get_option( Migrator::RETRY_OPTION, false ) );
		$this->assertTrue( Environment::db_schema()['ready'] );
	}

	public function test_a_missing_column_is_never_reported_as_migrated() {
		$this->real_ddl();
		global $wpdb;
		update_option( Migrator::VERSION_OPTION, 0 );
		// A column dbDelta() cannot add (the ALTER fails): simulate with a filter.
		$block = static function ( $sql ) {
			return preg_match( '/^ALTER TABLE \S+sseo_jobs ADD COLUMN `?heartbeat_at/i', ltrim( $sql ) ) ? 'ALTER TABLE broken syntax (' : $sql;
		};
		$wpdb->query( 'ALTER TABLE ' . Schema::table( 'jobs' ) . ' DROP COLUMN heartbeat_at' ); // phpcs:ignore WordPress.DB
		add_filter( 'query', $block );
		$res = Migrator::migrate();
		remove_filter( 'query', $block );
		$this->assertSame( 'missing columns: jobs.heartbeat_at', $res['error'] );
		$this->assertSame( 0, Migrator::installed_version() );
		$this->assertSame( array( 'jobs.heartbeat_at' ), Environment::db_schema()['missing_columns'] );
	}
}
