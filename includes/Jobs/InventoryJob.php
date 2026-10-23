<?php
/**
 * Inventory job handler.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Jobs;

use SemanticSEO\Inventory\InventoryScanner;
use SemanticSEO\Support\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only scan of posts/pages/terms; optionally chains an analysis job.
 */
final class InventoryJob {

	/**
	 * Initial state.
	 *
	 * @param array $job Job.
	 * @return array{state:array,total:int}
	 */
	public static function init( array $job ) {
		$ids = isset( $job['params']['post_ids'] ) ? (array) $job['params']['post_ids'] : array();
		if ( array() !== $ids ) {
			return array(
				'state' => array(
					'phase'  => 'posts',
					'cursor' => 0,
				),
				'total' => count( $ids ),
			);
		}
		$counts = InventoryScanner::scope_counts();
		return array(
			'state' => array(
				'phase'  => 'posts',
				'cursor' => 0,
				'scope'  => $counts,
			),
			'total' => $counts['total'],
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
		$ids = isset( $job['params']['post_ids'] ) ? (array) $job['params']['post_ids'] : array();
		$res = InventoryScanner::step( $job['state'], $batch, $ids );
		return array(
			'state'    => $res['state'],
			'done'     => $res['processed'],
			'finished' => $res['done'],
			'errors'   => $res['errors'],
		);
	}

	/**
	 * Completion hook.
	 *
	 * @param array $job Job.
	 * @return void
	 */
	public static function finished( array $job ) {
		$ids = isset( $job['params']['post_ids'] ) ? (array) $job['params']['post_ids'] : array();
		if ( array() === $ids ) {
			$state                           = (array) get_option( 'sseo_state', array() );
			$state['inventory_completed_at'] = Util::now();
			$state['inventory_job']          = (int) $job['id'];
			update_option( 'sseo_state', $state, false );
		}
		if ( ! empty( $job['params']['then_analyze'] ) ) {
			$params = array();
			if ( array() !== $ids ) {
				$params['doc_ids'] = \SemanticSEO\Database\Db::col(
					"SELECT id FROM {documents} WHERE object_type = 'post' AND object_id IN (" . \SemanticSEO\Database\Db::in( $ids ) . ')',
					array_map( 'intval', $ids )
				);
				if ( array() === $params['doc_ids'] ) {
					// None of the requested pages is in the inventory (document limit reached, or
					// excluded): an empty scope must never turn into a site-wide analysis.
					JobRepository::fail_completed( (int) $job['id'], __( 'None of the requested pages could be added to the inventory (the maximum number of documents is reached, or they are excluded), so no analysis was started. Raise "Max documents" or check the excluded URLs in the analysis scope.', 'semantic-seo-engine-vi-en' ) );
					return;
				}
			}
			$params['source_job'] = (int) $job['id'];
			if ( ! empty( $job['params']['pilot'] ) ) {
				$params['pilot'] = true;
			}
			JobRepository::chain_analysis( $params, (int) $job['owner_id'] );
			JobRunner::schedule();
		}
	}
}
