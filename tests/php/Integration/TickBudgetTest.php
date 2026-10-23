<?php
/**
 * A background tick stays within its time budget when items are slow (large sites): batches are
 * sized from the measured time per item, so a host's request time limit does not kill the tick
 * before its progress is saved.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Tests\Integration;

use SemanticSEO\Jobs\JobRepository;
use SemanticSEO\Jobs\JobRunner;
use SemanticSEO\Settings;

/**
 * A job whose items take 0.2 s each (as analysing one document of a 20,000-post site can).
 */
final class SlowItemsJobFixture {

	const TOTAL    = 200;
	const PER_ITEM = 200000;

	public static function init( array $job ) {
		unset( $job );
		return array(
			'state' => array( 'cursor' => 0 ),
			'total' => self::TOTAL,
		);
	}

	public static function step( array $job, $batch ) {
		$cursor = (int) $job['state']['cursor'];
		$n      = min( (int) $batch, self::TOTAL - $cursor );
		usleep( self::PER_ITEM * $n );
		return array(
			'state'    => array( 'cursor' => $cursor + $n ) + $job['state'],
			'done'     => $n,
			'finished' => $cursor + $n >= self::TOTAL,
		);
	}

	public static function finished( $job ) {
		unset( $job );
	}
}

/**
 * @group jobs
 */
final class TickBudgetTest extends TestCase {

	public function handlers( $handlers ) {
		$handlers['verify_links'] = SlowItemsJobFixture::class;
		return $handlers;
	}

	public function test_slow_items_do_not_make_a_tick_overrun_its_budget() {
		add_filter( 'sseo_job_handlers', array( $this, 'handlers' ) );
		Settings::update( array( 'batch_size' => 25 ) );
		$job = JobRepository::create( 'verify_links', array(), $this->admin, 'test' );

		$ticks = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$t0      = microtime( true );
			$res     = JobRunner::tick( 1, (int) $job['id'] );
			$ticks[] = microtime( true ) - $t0;
			$this->assertSame( 'running', $res['status'], wp_json_encode( $res ) );
		}
		remove_filter( 'sseo_job_handlers', array( $this, 'handlers' ) );

		// Budget 1 s; one batch of 25 slow items would take 5 s. Allowed: the budget plus one item
		// and some overhead.
		foreach ( $ticks as $n => $seconds ) {
			$this->assertLessThan( 1.6, $seconds, sprintf( 'tick %d took %.2f s for a 1 s budget', $n + 1, $seconds ) );
		}
		$j = JobRepository::get( (int) $job['id'] );
		$this->assertGreaterThan( 0, (int) $j['progress_done'], 'progress is saved' );
		$this->assertArrayHasKey( 'seconds_per_item', $j['state'], 'the measured speed is kept for the next tick' );
	}
}
