<?php
/**
 * Matrix import with preview, column mapping, invalid-row isolation and no silent overwrites.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Matrix;

use SemanticSEO\Audit\AuditLog;
use SemanticSEO\Database\Db;
use SemanticSEO\Inventory\DocumentRepository;
use SemanticSEO\Locks\LockService;
use SemanticSEO\Support\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Only the plugin's own editorial fields can be imported. WordPress content, SEO plugin data,
 * categories and URLs are never written by an import. Empty cells mean "no change".
 */
final class MatrixImporter {

	const FIELDS = array(
		'editorial_intent' => array( '', 'informational', 'transactional', 'commercial', 'navigational' ),
		'review_state'     => array( 'unreviewed', 'needs_review', 'reviewed', 'approved_plan' ),
		'cluster_role'     => array( 'pillar', 'hub', 'silo', 'supporting' ),
		'cluster_locked'   => array( '0', '1' ),
		'excluded'         => array( '0', '1' ),
	);

	const KEYS = array( 'document_id', 'url' );

	/**
	 * Suggests a mapping from CSV headers to fields.
	 *
	 * @param string[] $header Header.
	 * @return array<string,int> field => column index.
	 */
	public static function auto_map( array $header ) {
		$map = array();
		foreach ( $header as $i => $h ) {
			$key = strtolower( trim( preg_replace( '/[^a-z0-9]+/i', '_', (string) $h ), '_' ) );
			if ( in_array( $key, self::KEYS, true ) || isset( self::FIELDS[ $key ] ) ) {
				$map[ $key ] = (int) $i;
			}
		}
		return $map;
	}

	/**
	 * Builds a preview. Nothing is written.
	 *
	 * @param array $csv     Parsed CSV (header, rows).
	 * @param array $mapping field => column index.
	 * @param bool  $overwrite Whether non-empty existing values may be replaced.
	 * @return array|\WP_Error
	 */
	public static function preview( array $csv, array $mapping, $overwrite = false ) {
		$mapping = array_filter(
			$mapping,
			static function ( $v, $k ) {
				return ( in_array( $k, self::KEYS, true ) || isset( self::FIELDS[ $k ] ) ) && is_numeric( $v ) && (int) $v >= 0;
			},
			ARRAY_FILTER_USE_BOTH
		);
		if ( ! isset( $mapping['document_id'] ) && ! isset( $mapping['url'] ) ) {
			return new \WP_Error( 'sseo_import_key', __( 'Map a document_id or url column to identify rows.', 'semantic-seo-engine-vi-en' ), array( 'status' => 400 ) );
		}
		$fields = array_values( array_intersect( array_keys( self::FIELDS ), array_keys( $mapping ) ) );
		if ( array() === $fields ) {
			return new \WP_Error( 'sseo_import_fields', __( 'Map at least one importable field.', 'semantic-seo-engine-vi-en' ), array( 'status' => 400 ) );
		}
		$changes   = array();
		$invalid   = array();
		$conflicts = array();
		$unchanged = 0;
		$seen      = array();
		foreach ( $csv['rows'] as $n => $row ) {
			$line = $n + 2;
			$doc  = null;
			if ( isset( $mapping['document_id'] ) && isset( $row[ $mapping['document_id'] ] ) && ctype_digit( trim( (string) $row[ $mapping['document_id'] ] ) ) ) {
				$doc = DocumentRepository::get( (int) $row[ $mapping['document_id'] ] );
			} elseif ( isset( $mapping['url'] ) && isset( $row[ $mapping['url'] ] ) && '' !== trim( (string) $row[ $mapping['url'] ] ) ) {
				$doc = DocumentRepository::by_url( trim( (string) $row[ $mapping['url'] ] ) );
			}
			if ( null === $doc ) {
				$invalid[] = array(
					'row'    => $line,
					'reason' => 'no matching inventoried document',
				);
				continue;
			}
			$id = (int) $doc['id'];
			if ( isset( $seen[ $id ] ) ) {
				$invalid[] = array(
					'row'    => $line,
					'reason' => 'duplicate row for document ' . $id . ' (first seen on row ' . $seen[ $id ] . ')',
				);
				continue;
			}
			$seen[ $id ] = $line;
			$row_changes = array();
			$row_invalid = array();
			foreach ( $fields as $field ) {
				$cell = isset( $row[ $mapping[ $field ] ] ) ? trim( (string) $row[ $mapping[ $field ] ] ) : '';
				if ( '' === $cell ) {
					continue; // Empty cell = no change (never a deletion).
				}
				$value = strtolower( $cell );
				if ( ! in_array( $value, self::FIELDS[ $field ], true ) ) {
					$row_invalid[] = $field . '="' . Util::truncate( $cell, 40 ) . '" is not one of: ' . implode( ', ', array_filter( self::FIELDS[ $field ], 'strlen' ) );
					continue;
				}
				$current = (string) $doc[ $field ];
				if ( $current === $value ) {
					continue;
				}
				if ( 'cluster_role' === $field && LockService::is_locked( $doc['object_type'], (int) $doc['object_id'], $doc['url'] ) ) {
					$row_invalid[] = 'document is locked';
					continue;
				}
				$is_default = in_array( $current, array( '', '0', 'unreviewed' ), true );
				if ( ! $is_default && ! $overwrite ) {
					$conflicts[] = array(
						'row'      => $line,
						'document' => $id,
						'field'    => $field,
						'current'  => $current,
						'new'      => $value,
					);
					continue;
				}
				$row_changes[ $field ] = array(
					'from' => $current,
					'to'   => $value,
				);
			}
			if ( array() !== $row_invalid ) {
				$invalid[] = array(
					'row'    => $line,
					'reason' => implode( '; ', $row_invalid ),
				);
				continue; // Isolate the whole row.
			}
			if ( array() === $row_changes ) {
				++$unchanged;
				continue;
			}
			$changes[] = array(
				'row'      => $line,
				'document' => $id,
				'title'    => $doc['title'],
				'changes'  => $row_changes,
			);
		}
		$token = Util::token( 16 );
		set_transient(
			'sseo_import_' . get_current_user_id() . '_' . $token,
			array(
				'changes' => $changes,
				'hash'    => Util::sha256( Util::canonical_json( $changes ) ),
			),
			30 * MINUTE_IN_SECONDS
		);
		return array(
			'token'     => $token,
			'changes'   => $changes,
			'invalid'   => $invalid,
			'conflicts' => $conflicts,
			'unchanged' => $unchanged,
			'rows'      => count( $csv['rows'] ),
			'overwrite' => (bool) $overwrite,
			'fields'    => $fields,
		);
	}

	/**
	 * Applies a previously previewed import (same user, within 30 minutes).
	 *
	 * @param string $token Preview token.
	 * @return array|\WP_Error
	 */
	public static function apply( $token ) {
		$key  = 'sseo_import_' . get_current_user_id() . '_' . preg_replace( '/[^a-f0-9]/', '', (string) $token );
		$data = get_transient( $key );
		if ( ! is_array( $data ) || ! isset( $data['changes'] ) ) {
			return new \WP_Error( 'sseo_import_expired', __( 'The import preview expired or does not belong to you. Upload the file again.', 'semantic-seo-engine-vi-en' ), array( 'status' => 410 ) );
		}
		delete_transient( $key );
		$applied = 0;
		foreach ( $data['changes'] as $c ) {
			$update = array();
			foreach ( $c['changes'] as $field => $diff ) {
				if ( 'excluded' === $field ) {
					$doc = DocumentRepository::get( (int) $c['document'] );
					if ( null !== $doc ) {
						$value = $doc['object_type'] . ':' . (int) $doc['object_id'];
						if ( '1' === $diff['to'] ) {
							LockService::add( 'document', $value, 'exclude', 'matrix import' );
						} else {
							Db::query( "DELETE FROM {locks} WHERE scope_type = 'document' AND scope_value = %s AND lock_type = 'exclude'", array( $value ) );
							LockService::flush();
						}
					}
				}
				$update[ $field ] = 'cluster_locked' === $field || 'excluded' === $field ? (int) $diff['to'] : $diff['to'];
			}
			DocumentRepository::update( (int) $c['document'], $update );
			++$applied;
		}
		AuditLog::record(
			'matrix.import_applied',
			'matrix',
			0,
			'ok',
			array(
				'documents' => $applied,
				'hash'      => $data['hash'],
			)
		);
		return array(
			'applied' => $applied,
			'hash'    => $data['hash'],
		);
	}
}
