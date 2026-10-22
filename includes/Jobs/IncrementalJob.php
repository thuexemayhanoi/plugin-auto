<?php
/**
 * Incremental re-index of changed objects, followed by a scoped analysis.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Jobs;

use SemanticSEO\Database\Db;
use SemanticSEO\Inventory\InventoryScanner;
use SemanticSEO\Support\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Only dirty documents and new in-scope posts are processed; plugin-generated data never marks
 * documents dirty, so this cannot loop.
 */
final class IncrementalJob {

	const MAX_OBJECTS = 2000;

	/**
	 * Initial state.
	 *
	 * @param array $job Job.
	 * @return array{state:array,total:int}
	 */
	public static function init( array $job ) {
		unset( $job );
		$objects = array();
		foreach ( Db::rows( 'SELECT object_type, object_id FROM {documents} WHERE dirty = 1 ORDER BY id ASC LIMIT %d', array( self::MAX_OBJECTS ) ) as $r ) {
			$objects[] = $r['object_type'] . ':' . (int) $r['object_id'];
		}
		$types    = InventoryScanner::post_types();
		$statuses = InventoryScanner::statuses();
		if ( array() !== $types && count( $objects ) < self::MAX_OBJECTS ) {
			$new = Db::col(
				"SELECT p.ID FROM {posts} p LEFT JOIN {documents} d ON d.object_type = 'post' AND d.object_id = p.ID WHERE d.id IS NULL AND p.post_type IN (" . Db::in( $types, '%s' ) . ') AND p.post_status IN (' . Db::in( $statuses, '%s' ) . ') ORDER BY p.ID ASC LIMIT %d',
				array_merge( $types, $statuses, array( self::MAX_OBJECTS - count( $objects ) ) )
			);
			foreach ( $new as $id ) {
				$objects[] = 'post:' . (int) $id;
			}
		}
		return array(
			'state' => array(
				'phase'   => 'index',
				'objects' => array_values( array_unique( $objects ) ),
				'i'       => 0,
				'touched' => array(),
			),
			'total' => count( $objects ),
		);
	}

	/**
	 * One batch.
	 *
	 * @param array $job   Job.
	 * @param int   $batch Batch size.
	 * @return array
	 */
	public static function step( array $job, $batch ) {
		$state  = $job['state'];
		$slice  = array_slice( $state['objects'], (int) $state['i'], max( 1, (int) $batch ) );
		$errors = array();
		foreach ( $slice as $ref ) {
			list( $type, $id ) = explode( ':', $ref );
			$id                = (int) $id;
			try {
				if ( 'post' === $type ) {
					$post = get_post( $id );
					if ( $post instanceof \WP_Post && InventoryScanner::object_in_scope( 'post', $id ) ) {
						$state['touched'][] = InventoryScanner::index_post( $post, true );
					} else {
						$doc = Db::value( "SELECT id FROM {documents} WHERE object_type = 'post' AND object_id = %d", array( $id ) );
						if ( null !== $doc ) {
							\SemanticSEO\Inventory\DocumentRepository::purge( (int) $doc );
						}
					}
				} else {
					$term = get_term( $id );
					if ( $term instanceof \WP_Term && InventoryScanner::object_in_scope( 'term', $id ) ) {
						$state['touched'][] = InventoryScanner::index_term( $term, true );
					}
				}
			} catch ( \Throwable $e ) {
				$errors[] = array(
					'object' => $ref,
					'error'  => Util::truncate( $e->getMessage() . ' @' . basename( $e->getFile() ) . ':' . $e->getLine(), 240 ),
				);
			}
		}
		$state['i']      += count( $slice );
		$state['touched'] = array_slice( array_values( array_unique( array_map( 'intval', $state['touched'] ) ) ), 0, 500 );
		return array(
			'state'    => $state,
			'done'     => count( $slice ),
			'finished' => $state['i'] >= count( $state['objects'] ),
			'errors'   => $errors,
		);
	}

	/**
	 * Completion hook: scoped analysis of touched documents.
	 *
	 * @param array $job Job.
	 * @return void
	 */
	public static function finished( array $job ) {
		$touched = isset( $job['state']['touched'] ) ? (array) $job['state']['touched'] : array();
		if ( array() !== $touched ) {
			JobRepository::create( 'analyze', array( 'doc_ids' => $touched ), (int) $job['owner_id'], 'chain' );
			JobRunner::schedule();
		}
	}
}
