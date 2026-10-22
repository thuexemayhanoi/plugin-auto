<?php
/**
 * Analysis job handler.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Jobs;

use SemanticSEO\Analysis\AnalysisRunner;

defined( 'ABSPATH' ) || exit;

/**
 * Delegates to the phased AnalysisRunner.
 */
final class AnalyzeJob {

	/**
	 * Initial state.
	 *
	 * @param array $job Job.
	 * @return array{state:array,total:int}
	 */
	public static function init( array $job ) {
		return AnalysisRunner::init( $job['params'] );
	}

	/**
	 * One batch.
	 *
	 * @param array $job   Job.
	 * @param int   $batch Batch size.
	 * @return array
	 */
	public static function step( array $job, $batch ) {
		return AnalysisRunner::step( $job, $batch );
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
