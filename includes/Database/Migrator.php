<?php
/**
 * Versioned, idempotent, lock-protected migrations.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Database;

use SemanticSEO\Inventory\DocumentRepository;
use SemanticSEO\Security\Mutex;
use SemanticSEO\Support\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Runs dbDelta plus explicit idempotent steps. The stored version only advances after every
 * step succeeded, so an interrupted upgrade is simply re-run on the next request.
 */
final class Migrator {

	const VERSION_OPTION = 'sseo_db_version';
	const LOG_OPTION     = 'sseo_migration_log';
	const LOCK_TTL       = 300;

	/**
	 * Installed schema version (0 when never installed).
	 *
	 * @return int
	 */
	public static function installed_version() {
		return (int) get_option( self::VERSION_OPTION, 0 );
	}

	/**
	 * Whether an upgrade is pending.
	 *
	 * @return bool
	 */
	public static function needs_upgrade() {
		return self::installed_version() < Schema::VERSION;
	}

	/**
	 * Runs the migration when needed. Safe to call on every request (cheap option read).
	 *
	 * @return array{ran:bool,from:int,to:int,error:string}
	 */
	public static function maybe_upgrade() {
		$from = self::installed_version();
		if ( $from >= Schema::VERSION ) {
			return array(
				'ran'   => false,
				'from'  => $from,
				'to'    => $from,
				'error' => '',
			);
		}
		return self::migrate();
	}

	/**
	 * Performs the migration under a lock.
	 *
	 * @return array{ran:bool,from:int,to:int,error:string}
	 */
	public static function migrate() {
		global $wpdb;
		$from = self::installed_version();
		if ( ! self::acquire_lock() ) {
			return array(
				'ran'   => false,
				'from'  => $from,
				'to'    => $from,
				'error' => 'locked',
			);
		}
		$error = '';
		try {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			$suppress = $wpdb->suppress_errors( true );
			foreach ( Schema::definitions() as $sql ) {
				dbDelta( $sql );
			}
			$wpdb->suppress_errors( $suppress );
			foreach ( self::steps() as $version => $step ) {
				if ( $version > $from && $version <= Schema::VERSION ) {
					call_user_func( $step );
				}
			}
			$missing = self::missing_tables();
			if ( array() !== $missing ) {
				$error = 'missing tables: ' . implode( ',', $missing );
			}
		} catch ( \Throwable $e ) {
			$error = $e->getMessage();
		}
		if ( '' === $error ) {
			update_option( self::VERSION_OPTION, Schema::VERSION, true );
		}
		self::log( $from, '' === $error ? Schema::VERSION : $from, $error );
		self::release_lock();
		return array(
			'ran'   => true,
			'from'  => $from,
			'to'    => '' === $error ? Schema::VERSION : $from,
			'error' => $error,
		);
	}

	/**
	 * Explicit, idempotent steps keyed by the version they introduce.
	 *
	 * @return array<int,callable>
	 */
	private static function steps() {
		// Version 2 only adds the findings.target_id index, which dbDelta() creates: no data step.
		return array(
			1 => array( __CLASS__, 'step_1_backfill_hashes' ),
			3 => array( __CLASS__, 'step_3_drop_replaced_index' ),
		);
	}

	/**
	 * Step 3: findings.type_status is covered by the wider type_status_rank index that dbDelta()
	 * just added; dbDelta() never drops indexes, so the old one is removed here (idempotent).
	 *
	 * @return void
	 */
	public static function step_3_drop_replaced_index() {
		global $wpdb;
		$table = Schema::table( 'findings' );
		$found = $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", 'type_status' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( null !== $found ) {
			$wpdb->query( "ALTER TABLE {$table} DROP INDEX type_status" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
		}
	}

	/**
	 * Step 1: guarantees url_hash is populated (idempotent; no-op on fresh installs).
	 *
	 * @return void
	 */
	public static function step_1_backfill_hashes() {
		$rows = Db::rows( "SELECT id, url FROM {documents} WHERE url_hash = '' LIMIT 5000" );
		foreach ( $rows as $row ) {
			Db::update( 'documents', array( 'url_hash' => DocumentRepository::url_hash( $row['url'] ) ), array( 'id' => (int) $row['id'] ) );
		}
	}

	/**
	 * Lists expected tables that do not exist.
	 *
	 * @return string[]
	 */
	public static function missing_tables() {
		global $wpdb;
		$missing = array();
		foreach ( Schema::TABLES as $name ) {
			$table = Schema::table( $name );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( $found !== $table ) {
				$missing[] = $name;
			}
		}
		return $missing;
	}

	/**
	 * Atomic lock (stale locks from a crashed migration are taken over after LOCK_TTL).
	 *
	 * @var string|false
	 */
	private static $lock_token = false;

	/**
	 * Acquires the migration lock.
	 *
	 * @return bool
	 */
	private static function acquire_lock() {
		self::$lock_token = Mutex::acquire( 'migration', self::LOCK_TTL );
		return false !== self::$lock_token;
	}

	/**
	 * Releases the migration lock.
	 *
	 * @return void
	 */
	private static function release_lock() {
		if ( false !== self::$lock_token ) {
			Mutex::release( 'migration', self::$lock_token );
			self::$lock_token = false;
		}
	}

	/**
	 * Appends to the bounded migration log.
	 *
	 * @param int    $from  From version.
	 * @param int    $to    To version.
	 * @param string $error Error or empty.
	 * @return void
	 */
	private static function log( $from, $to, $error ) {
		$log   = get_option( self::LOG_OPTION, array() );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array(
			'at'    => Util::now(),
			'from'  => (int) $from,
			'to'    => (int) $to,
			'error' => (string) $error,
			'php'   => PHP_VERSION,
		);
		update_option( self::LOG_OPTION, array_slice( $log, -20 ), false );
	}

	/**
	 * Drops every plugin table and option. Only called by uninstall.php with the delete policy.
	 *
	 * @return void
	 */
	public static function drop_everything() {
		global $wpdb;
		foreach ( Schema::TABLES as $name ) {
			$table = Schema::table( $name );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
		foreach ( array( self::VERSION_OPTION, self::LOG_OPTION, 'sseo_settings', 'sseo_state', 'sseo_similarity_baseline', 'sseo_mutex_migration' ) as $opt ) {
			delete_option( $opt );
		}
	}
}
