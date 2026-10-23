<?php
/**
 * Embedding job handler: enqueues work and waits for external workers.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Jobs;

use SemanticSEO\Semantic\EmbeddingQueue;

defined( 'ABSPATH' ) || exit;

/**
 * Inference happens only in the administrator's browser (Tier 1) or a private worker (Tier 2).
 * The server side only enqueues items, expires leases and reports honest progress.
 */
final class EmbedJob {

	/**
	 * Initial state.
	 *
	 * @param array $job Job.
	 * @return array{state:array,total:int}
	 */
	public static function init( array $job ) {
		unset( $job );
		return array(
			'state' => array(
				'phase'    => 'enqueue',
				'cursor'   => 0,
				'reported' => 0,
			),
			'total' => 0,
		);
	}

	/**
	 * One step.
	 *
	 * @param array $job   Job.
	 * @param int   $batch Batch size.
	 * @return array
	 */
	public static function step( array $job, $batch ) {
		$state = $job['state'];
		$ids   = isset( $job['params']['doc_ids'] ) ? (array) $job['params']['doc_ids'] : array();
		if ( 'enqueue' === $state['phase'] ) {
			$res             = EmbeddingQueue::enqueue_batch( (int) $job['id'], (int) $state['cursor'], max( 10, $batch * 2 ), $ids );
			$state['cursor'] = $res['cursor'];
			if ( $res['documents'] < max( 10, $batch * 2 ) ) {
				$state['phase'] = 'wait';
			}
			$counts = EmbeddingQueue::counts( (int) $job['id'] );
			return array(
				'state'    => $state,
				'done'     => 0,
				'total'    => array_sum( $counts ),
				'finished' => false,
			);
		}
		EmbeddingQueue::expire_leases();
		$counts            = EmbeddingQueue::counts( (int) $job['id'] );
		$total             = array_sum( $counts );
		$finished_items    = $counts['done'] + $counts['failed'];
		$delta             = max( 0, $finished_items - (int) $state['reported'] );
		$state['reported'] = $finished_items;
		$state['queue']    = $counts;
		$open              = $counts['pending'] + $counts['leased'];
		return array(
			'state'    => $state,
			'done'     => $delta,
			'total'    => $total,
			'finished' => 0 === $open,
			'yield'    => true,
			'delay'    => 60,
		);
	}

	/**
	 * Completion hook.
	 *
	 * @param array $job Job.
	 * @return void
	 */
	public static function finished( array $job ) {
		unset( $job );
	}
}
