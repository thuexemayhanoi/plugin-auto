<?php
/**
 * Thin, prepared-statement-only data access helper.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Database;

defined( 'ABSPATH' ) || exit;

/**
 * All values go through $wpdb->prepare / $wpdb->insert / $wpdb->update. Table names come from
 * Schema::table() only (never from input) and column names are validated against a pattern.
 */
final class Db {

	/**
	 * Table name helper.
	 *
	 * @param string $name Logical table.
	 * @return string
	 */
	public static function t( $name ) {
		return Schema::table( $name );
	}

	/**
	 * Inserts a row.
	 *
	 * @param string $table Logical table.
	 * @param array  $data  Column => value.
	 * @return int Insert ID (0 on failure).
	 */
	public static function insert( $table, array $data ) {
		global $wpdb;
		self::assert_columns( array_keys( $data ) );
		$ok = $wpdb->insert( self::t( $table ), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return false === $ok ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Updates rows.
	 *
	 * @param string $table Logical table.
	 * @param array  $data  Values.
	 * @param array  $where Conditions (equality).
	 * @return int Affected rows (-1 on error).
	 */
	public static function update( $table, array $data, array $where ) {
		global $wpdb;
		self::assert_columns( array_merge( array_keys( $data ), array_keys( $where ) ) );
		$res = $wpdb->update( self::t( $table ), $data, $where ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return false === $res ? -1 : (int) $res;
	}

	/**
	 * Deletes rows.
	 *
	 * @param string $table Logical table.
	 * @param array  $where Conditions.
	 * @return int
	 */
	public static function delete( $table, array $where ) {
		global $wpdb;
		self::assert_columns( array_keys( $where ) );
		$res = $wpdb->delete( self::t( $table ), $where ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return false === $res ? -1 : (int) $res;
	}

	/**
	 * Runs a prepared SELECT returning rows. "{table}" placeholders are replaced by real names.
	 *
	 * @param string $sql  SQL with %s/%d/%f placeholders and {table} tokens.
	 * @param array  $args Values.
	 * @return array<int,array>
	 */
	public static function rows( $sql, array $args = array() ) {
		global $wpdb;
		$sql = self::expand( $sql );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$res = $wpdb->get_results( array() === $args ? $sql : $wpdb->prepare( $sql, $args ), ARRAY_A );
		return is_array( $res ) ? $res : array();
	}

	/**
	 * Single row.
	 *
	 * @param string $sql  SQL.
	 * @param array  $args Values.
	 * @return array|null
	 */
	public static function row( $sql, array $args = array() ) {
		global $wpdb;
		$sql = self::expand( $sql );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$res = $wpdb->get_row( array() === $args ? $sql : $wpdb->prepare( $sql, $args ), ARRAY_A );
		return is_array( $res ) ? $res : null;
	}

	/**
	 * Single value.
	 *
	 * @param string $sql  SQL.
	 * @param array  $args Values.
	 * @return string|null
	 */
	public static function value( $sql, array $args = array() ) {
		global $wpdb;
		$sql = self::expand( $sql );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$res = $wpdb->get_var( array() === $args ? $sql : $wpdb->prepare( $sql, $args ) );
		return null === $res ? null : (string) $res;
	}

	/**
	 * Column of values.
	 *
	 * @param string $sql  SQL.
	 * @param array  $args Values.
	 * @return array
	 */
	public static function col( $sql, array $args = array() ) {
		global $wpdb;
		$sql = self::expand( $sql );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$res = $wpdb->get_col( array() === $args ? $sql : $wpdb->prepare( $sql, $args ) );
		return is_array( $res ) ? $res : array();
	}

	/**
	 * Executes a prepared write statement.
	 *
	 * @param string $sql  SQL.
	 * @param array  $args Values.
	 * @return int Affected rows (-1 on error).
	 */
	public static function query( $sql, array $args = array() ) {
		global $wpdb;
		$sql = self::expand( $sql );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$res = $wpdb->query( array() === $args ? $sql : $wpdb->prepare( $sql, $args ) );
		return false === $res ? -1 : (int) $res;
	}

	/**
	 * Builds an IN() placeholder list.
	 *
	 * @param array  $values Values.
	 * @param string $type   %d or %s.
	 * @return string
	 */
	public static function in( array $values, $type = '%d' ) {
		return implode( ',', array_fill( 0, max( 1, count( $values ) ), '%d' === $type ? '%d' : '%s' ) );
	}

	/**
	 * LIKE pattern matching a literal prefix.
	 *
	 * @param string $prefix Prefix.
	 * @return string
	 */
	public static function esc_like_prefix( $prefix ) {
		global $wpdb;
		return $wpdb->esc_like( (string) $prefix ) . '%';
	}

	/**
	 * Last database error (diagnostics/tests).
	 *
	 * @return string
	 */
	public static function last_error() {
		global $wpdb;
		return (string) $wpdb->last_error;
	}

	/**
	 * Replaces {table} tokens with real table names.
	 *
	 * @param string $sql SQL.
	 * @return string
	 */
	private static function expand( $sql ) {
		return (string) preg_replace_callback(
			'/\{([a-z_]+)\}/',
			static function ( $m ) {
				if ( 'posts' === $m[1] || 'term_taxonomy' === $m[1] || 'terms' === $m[1] || 'options' === $m[1] ) {
					global $wpdb;
					return $wpdb->{$m[1]};
				}
				if ( ! in_array( $m[1], Schema::TABLES, true ) ) {
					return $m[0];
				}
				return Schema::table( $m[1] );
			},
			$sql
		);
	}

	/**
	 * Guards against column names coming from input.
	 *
	 * @param string[] $columns Columns.
	 * @return void
	 * @throws \InvalidArgumentException On invalid name.
	 */
	private static function assert_columns( array $columns ) {
		foreach ( $columns as $c ) {
			if ( ! preg_match( '/^[a-z_][a-z0-9_]{0,63}$/', (string) $c ) ) {
				throw new \InvalidArgumentException( 'Invalid column name.' );
			}
		}
	}
}
