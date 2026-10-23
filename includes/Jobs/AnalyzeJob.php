<?php
/**
 * Analysis job handler.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Jobs;

use SemanticSEO\Analysis\AnalysisRunner;
use SemanticSEO\Analysis\ScanHistory;

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
		$init = AnalysisRunner::init( $job['params'] );
		// Findings created by this run have a higher ID: the scan comparison counts them as new.
		$init['state']['findings_max_id'] = (int) \SemanticSEO\Database\Db::value( 'SELECT COALESCE(MAX(id), 0) FROM {findings}' );
		return $init;
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
		ScanHistory::record( $job );
		// An analysis requested while this one was active (e.g. a full scan after a pilot).
		if ( JobRepository::start_pending_analysis() > 0 ) {
			JobRunner::schedule();
		}
	}
}
