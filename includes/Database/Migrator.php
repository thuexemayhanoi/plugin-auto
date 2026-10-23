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
 * table and column exists, so an interrupted upgrade is re-run later. A failed upgrade is retried
 * on ordinary requests at most once per RETRY_DELAY (or at once after a plugin update), never on
 * every page view.
 */
final class Migrator {

	const VERSION_OPTION = 'sseo_db_version';
	const LOG_OPTION     = 'sseo_migration_log';
	const RETRY_OPTION   = 'sseo_migration_retry';
	const LOCK_TTL       = 300;
	const RETRY_DELAY    = 1800;

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
		if ( $from >= Schema::VERSION || self::backing_off() ) {
			return array(
				'ran'   => false,
				'from'  => $from,
				'to'    => $from,
				'error' => $from >= Schema::VERSION ? '' : 'retry_later',
			);
		}
		return self::migrate();
	}

	/**
	 * Whether the last migration failed recently for this plugin version (no retry yet).
	 *
	 * @return bool
	 */
	public static function backing_off() {
		$retry = get_option( self::RETRY_OPTION, array() );
		if ( ! is_array( $retry ) || ! isset( $retry['at'], $retry['plugin'] ) ) {
			return false;
		}
		return SSEO_VERSION === $retry['plugin'] && time() < (int) $retry['at'] + self::RETRY_DELAY;
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
		$error    = '';
		$failures = array();
		$suppress = $wpdb->suppress_errors( true );
		try {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			// Renames must run before dbDelta(), which would otherwise add the new column next to
			// the old one.
			self::step_4_rename_vector_column();
			foreach ( Schema::definitions() as $name => $sql ) {
				dbDelta( $sql );
				if ( '' !== (string) $wpdb->last_error ) {
					$failures[ $name ] = self::sql_error( $sql );
				}
			}
			foreach ( self::steps() as $version => $step ) {
				if ( $version > $from && $version <= Schema::VERSION ) {
					call_user_func( $step );
				}
			}
			$problems = self::verify();
			if ( array() !== $problems['missing'] ) {
				$error = 'missing tables: ' . implode( ',', $problems['missing'] );
			} elseif ( array() !== $problems['missing_columns'] ) {
				$error = 'missing columns: ' . implode( ',', $problems['missing_columns'] );
			}
		} catch ( \Throwable $e ) {
			$error = $e->getMessage();
		}
		$wpdb->suppress_errors( $suppress );
		// Only the SQL errors of objects that are still missing explain the failure.
		$failures = '' === $error ? array() : $failures;
		if ( '' === $error ) {
			update_option( self::VERSION_OPTION, Schema::VERSION, true );
			delete_option( self::RETRY_OPTION );
		} else {
			update_option(
				self::RETRY_OPTION,
				array(
					'at'     => time(),
					'plugin' => SSEO_VERSION,
				),
				false
			);
		}
		self::log( $from, '' === $error ? Schema::VERSION : $from, $error, $failures );
		self::release_lock();
		return array(
			'ran'      => true,
			'from'     => $from,
			'to'       => '' === $error ? Schema::VERSION : $from,
			'error'    => $error,
			'failures' => $failures,
		);
	}

	/**
	 * The database error of the last statement, with its MySQL error number and SQLSTATE.
	 *
	 * @param string $sql Statement that was run (its first line is kept for the log).
	 * @return array{errno:int,sqlstate:string,message:string,statement:string}
	 */
	private static function sql_error( $sql ) {
		global $wpdb;
		$errno    = 0;
		$sqlstate = '';
		// wpdb exposes only the message; the error number and SQLSTATE of its own connection are
		// read for the log (no query is run).
		if ( $wpdb->dbh instanceof \mysqli ) {
			$errno    = (int) mysqli_errno( $wpdb->dbh ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_errno
			$sqlstate = (string) mysqli_sqlstate( $wpdb->dbh ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_sqlstate
		}
		$lines = preg_split( '/\R/', trim( (string) $sql ) );
		return array(
			'errno'     => $errno,
			'sqlstate'  => $sqlstate,
			'message'   => substr( (string) $wpdb->last_error, 0, 500 ),
			'statement' => substr( (string) $lines[0], 0, 200 ),
		);
	}

	/**
	 * Explicit, idempotent steps keyed by the version they introduce.
	 *
	 * @return array<int,callable>
	 */
	private static function steps() {
		// Version 2 only adds the findings.target_id index, which dbDelta() creates: no data step.
		// Version 4 (embeddings.vector -> vector_data) runs before dbDelta() in migrate().
		return array(
			1 => array( __CLASS__, 'step_1_backfill_hashes' ),
			3 => array( __CLASS__, 'step_3_drop_replaced_index' ),
		);
	}

	/**
	 * Step 4: embeddings.vector becomes vector_data, keeping every stored vector. Runs on every
	 * migration because it is cheap and idempotent: no-op when the table does not exist yet (fresh
	 * install, or MariaDB 11.7+ where the old definition never ran) or is already renamed. The old
	 * name is always quoted because it is a keyword on MariaDB 11.7+.
	 *
	 * @return void
	 */
	public static function step_4_rename_vector_column() {
		global $wpdb;
		$table = Schema::table( 'embeddings' );
		$cols  = self::columns( $table );
		if ( ! in_array( 'vector', $cols, true ) ) {
			return;
		}
		if ( ! in_array( 'vector_data', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE `{$table}` CHANGE COLUMN `vector` `vector_data` longblob NOT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
			return;
		}
		// Both columns (an interrupted manual repair): copy, check, then drop the old column only
		// when no vector would be lost.
		$wpdb->query( "UPDATE `{$table}` SET `vector_data` = `vector` WHERE LENGTH(`vector_data`) = 0 AND LENGTH(`vector`) > 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE LENGTH(`vector_data`) = 0 AND LENGTH(`vector`) > 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( 0 === $left && '' === (string) $wpdb->last_error ) {
			$wpdb->query( "ALTER TABLE `{$table}` DROP COLUMN `vector`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
		}
	}

	/**
	 * Column names of a table (empty when it does not exist).
	 *
	 * @param string $table Full table name.
	 * @return string[]
	 */
	private static function columns( $table ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( $found !== $table ) {
			return array();
		}
		return array_map( 'strval', (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Columns each table must have, read from the schema definitions.
	 *
	 * @return array<string,string[]>
	 */
	public static function expected_columns() {
		$out = array();
		foreach ( Schema::definitions() as $name => $sql ) {
			// Column lines start with a lowercase name; CREATE/PRIMARY/UNIQUE/KEY lines are uppercase.
			preg_match_all( '/^([a-z_][a-z0-9_]*) [a-z]/m', $sql, $m );
			$out[ $name ] = $m[1];
		}
		return $out;
	}

	/**
	 * Missing tables and missing columns (as table.column).
	 *
	 * @return array{missing:string[],missing_columns:string[]}
	 */
	public static function verify() {
		$missing = array();
		$columns = array();
		foreach ( self::expected_columns() as $name => $expected ) {
			$have = self::columns( Schema::table( $name ) );
			if ( array() === $have ) {
				$missing[] = $name;
				continue;
			}
			foreach ( array_diff( $expected, $have ) as $col ) {
				$columns[] = $name . '.' . $col;
			}
		}
		return array(
			'missing'         => $missing,
			'missing_columns' => $columns,
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
	 * @param int    $from     From version.
	 * @param int    $to       To version.
	 * @param string $error    Error or empty.
	 * @param array  $failures SQL errors per table.
	 * @return void
	 */
	private static function log( $from, $to, $error, array $failures = array() ) {
		global $wpdb;
		$log   = get_option( self::LOG_OPTION, array() );
		$log   = is_array( $log ) ? $log : array();
		$entry = array(
			'at'     => Util::now(),
			'from'   => (int) $from,
			'to'     => (int) $to,
			'error'  => (string) $error,
			'php'    => PHP_VERSION,
			'db'     => (string) $wpdb->db_server_info(),
			'plugin' => SSEO_VERSION,
		);
		if ( array() !== $failures ) {
			$entry['sql_errors'] = $failures;
		}
		$log[] = $entry;
		update_option( self::LOG_OPTION, array_slice( $log, -20 ), false );
	}

	/**
	 * The most recent migration log entry (empty array when none).
	 *
	 * @return array
	 */
	public static function last_log() {
		$log = get_option( self::LOG_OPTION, array() );
		return is_array( $log ) && array() !== $log ? (array) end( $log ) : array();
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
		foreach ( array( self::VERSION_OPTION, self::LOG_OPTION, self::RETRY_OPTION, 'sseo_scan_history', 'sseo_pending_analysis', 'sseo_settings', 'sseo_state', 'sseo_similarity_baseline', 'sseo_mutex_migration' ) as $opt ) {
			delete_option( $opt );
		}
	}
}
