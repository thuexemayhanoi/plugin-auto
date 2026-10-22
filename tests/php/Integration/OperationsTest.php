<?php
/**
 * Integration: job reliability, SEO Matrix export/import, related-content output, uninstall.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Tests\Integration;

use SemanticSEO\Database\Db;
use SemanticSEO\Database\Migrator;
use SemanticSEO\Jobs\JobRepository;
use SemanticSEO\Jobs\JobRunner;
use SemanticSEO\Matrix\CsvReader;
use SemanticSEO\Matrix\MatrixExporter;
use SemanticSEO\Matrix\MatrixImporter;
use SemanticSEO\Matrix\MatrixQuery;
use SemanticSEO\Settings;
use SemanticSEO\Support\Util;

/**
 * Handler that always throws (retry/backoff test).
 */
final class FailingJob {
	public static function init( array $job ) {
		unset( $job );
		return array(
			'state' => array(),
			'total' => 1,
		);
	}
	public static function step( array $job, $batch ) {
		unset( $job, $batch );
		throw new \RuntimeException( 'simulated database timeout' );
	}
	public static function finished( array $job ) {
		unset( $job );
	}
}

/**
 * Handler that needs exactly five ticks (one batch per tick).
 */
final class FiveStepJob {
	public static function init( array $job ) {
		unset( $job );
		return array(
			'state' => array( 'n' => 0 ),
			'total' => 5,
		);
	}
	public static function step( array $job, $batch ) {
		unset( $batch );
		$state      = $job['state'];
		$state['n'] = (int) $state['n'] + 1;
		return array(
			'state'    => $state,
			'done'     => 1,
			'finished' => $state['n'] >= 5,
			'yield'    => true,
		);
	}
	public static function finished( array $job ) {
		unset( $job );
	}
}

/**
 * @group operations
 */
final class OperationsTest extends TestCase {

	public function test_cancel_pause_resume_and_progress() {
		add_filter(
			'sseo_job_handlers',
			static function ( $h ) {
				$h['verify_links'] = FiveStepJob::class;
				return $h;
			}
		);
		$job = JobRepository::create( 'verify_links', array(), $this->admin, 'test' );
		$this->assertTrue( JobRepository::pause( (int) $job['id'] ) );
		$this->assertSame( 'paused', JobRunner::tick( 5, (int) $job['id'] )['status'], 'Paused jobs are not claimable.' );
		$this->assertTrue( JobRepository::resume( (int) $job['id'] ) );
		JobRunner::tick( 5, (int) $job['id'] );
		JobRunner::tick( 5, (int) $job['id'] );
		$mid = JobRepository::get( (int) $job['id'] );
		$this->assertSame( 2, (int) $mid['progress_done'], 'Honest progress: two of five batches.' );
		$this->assertSame( 5, (int) $mid['progress_total'] );
		$this->assertSame( 2, (int) $mid['state']['n'], 'State persisted between ticks (resumable cursor).' );
		$this->assertTrue( JobRepository::cancel( (int) $job['id'] ) );
		$this->assertSame( 'cancelled', JobRunner::tick( 5, (int) $job['id'] )['status'] );
		$this->assertSame( 2, (int) JobRepository::get( (int) $job['id'] )['state']['n'], 'No work after cancellation.' );
		$this->assertFalse( JobRepository::resume( (int) $job['id'] ), 'Cancelled jobs cannot be resumed.' );
	}

	public function test_resume_after_crash_via_expired_lease() {
		$this->load_corpus();
		Settings::update( array( 'batch_size' => 3 ) );
		$job = JobRepository::create( 'inventory', array(), $this->admin, 'test' );
		$this->assertTrue( JobRepository::claim( (int) $job['id'], 'crashed-worker', 90 ) );
		$this->assertSame( 0, JobRunner::tick( 5, (int) $job['id'] )['batches'], 'A live lease blocks a second runner (no overlap).' );
		Db::query( 'UPDATE {jobs} SET lease_expires = %s WHERE id = %d', array( Util::now( -5 ), (int) $job['id'] ) );
		$res = JobRunner::run_to_completion( (int) $job['id'] );
		$this->assertSame( 'completed', $res['status'], 'Another runner took over the expired lease and resumed from the cursor.' );
	}

	public function test_failures_back_off_then_fail() {
		add_filter(
			'sseo_job_handlers',
			static function ( $h ) {
				$h['analyze'] = FailingJob::class;
				return $h;
			}
		);
		$job = JobRepository::create( 'analyze', array(), $this->admin, 'test' );
		$res = JobRunner::tick( 5, (int) $job['id'] );
		$this->assertSame( 'queued', $res['status'] );
		$row = JobRepository::get( (int) $job['id'] );
		$this->assertSame( 1, (int) $row['attempts'] );
		$this->assertStringContainsString( 'simulated database timeout', $row['last_error'] );
		$this->assertGreaterThan( Util::now(), $row['next_run_at'], 'Exponential backoff scheduled.' );
		$this->assertSame( 'queued', JobRunner::tick( 5, (int) $job['id'] )['status'], 'Not retried before backoff.' );
		for ( $i = 0; $i < 6; $i++ ) {
			Db::query( 'UPDATE {jobs} SET next_run_at = NULL WHERE id = %d', array( (int) $job['id'] ) );
			JobRunner::tick( 5, (int) $job['id'] );
		}
		$this->assertSame( 'failed', JobRepository::get( (int) $job['id'] )['status'] );
	}

	public function test_post_deleted_or_updated_mid_job_is_handled() {
		$this->load_corpus();
		$ids   = $this->posts;
		$state = array();
		// First batch only.
		$res   = \SemanticSEO\Inventory\InventoryScanner::step( $state, 4 );
		$state = $res['state'];
		$done  = $state['cursor'];
		// Delete one already-processed post and one not yet processed.
		$processed = min( array_filter( $ids, static function ( $id ) use ( $done ) { return $id <= $done; } ) );
		$pending   = max( $ids );
		wp_delete_post( $processed, true );
		wp_delete_post( $pending, true );
		do {
			$res   = \SemanticSEO\Inventory\InventoryScanner::step( $state, 4 );
			$state = $res['state'];
		} while ( ! $res['done'] );
		$a = \SemanticSEO\Inventory\DocumentRepository::by_object( 'post', $processed );
		$this->assertSame( 'deleted', $a['status'], 'Cleanup phase purged the post deleted after processing.' );
		$this->assertNull( \SemanticSEO\Inventory\DocumentRepository::by_object( 'post', $pending ), 'Never inventoried.' );
	}

	public function test_matrix_query_export_and_import_preview() {
		$this->load_corpus();
		$this->run_full_pipeline();
		$q = MatrixQuery::query(
			array(
				'locale'   => 'vi',
				'search'   => 'Hà Nội',
				'per_page' => 3,
				'sort'     => 'inbound',
				'order'    => 'desc',
			)
		);
		$this->assertCount( 3, $q['items'] );
		foreach ( $q['items'] as $r ) {
			$this->assertSame( 'vi', $r['locale'] );
		}
		$orph = MatrixQuery::query( array( 'orphan' => 1 ) );
		$this->assertGreaterThan( 0, $orph['total'] );

		// CSV: UTF-8 BOM, header, formula injection neutralized.
		wp_update_post(
			array(
				'ID'         => $this->posts['vi-lien-he'],
				'post_title' => '=HYPERLINK("http://evil.example","x")',
			)
		);
		\SemanticSEO\Inventory\InventoryScanner::index_post( get_post( $this->posts['vi-lien-he'] ), true );
		$fh = fopen( 'php://memory', 'w+' );
		$n  = MatrixExporter::csv( $fh, array() );
		rewind( $fh );
		$csv = stream_get_contents( $fh );
		fclose( $fh );
		$this->assertSame( "\xEF\xBB\xBF", substr( $csv, 0, 3 ) );
		$this->assertGreaterThan( 20, $n );
		$this->assertStringContainsString( "'=HYPERLINK", $csv );
		$this->assertStringContainsString( 'Thuê xe máy ở Hà Nội', $csv, 'Vietnamese preserved.' );

		// XLSX is a genuine zip package with SpreadsheetML parts.
		$path = wp_tempnam( 'matrix' ) . '.xlsx';
		$rows = MatrixExporter::xlsx( array(), $path );
		$zip  = new \ZipArchive();
		$this->assertTrue( $zip->open( $path ) );
		$this->assertNotFalse( $zip->locateName( 'xl/worksheets/sheet1.xml' ) );
		$sheet = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
		$zip->close();
		$this->assertNotFalse( simplexml_load_string( $sheet ), 'Sheet XML is well-formed.' );
		$this->assertSame( $n, $rows );

		// Import preview: empty cells are no-ops, invalid rows isolated, conflicts not overwritten.
		$doc_a = $this->doc( 'vi-pillar' );
		$doc_b = $this->doc( 'vi-ngay' );
		\SemanticSEO\Inventory\DocumentRepository::update( $doc_b, array( 'editorial_intent' => 'informational' ) );
		$parsed = CsvReader::parse( "document_id,editorial_intent,review_state\n{$doc_a},transactional,\n{$doc_b},commercial,reviewed\n999999,transactional,\n{$doc_a},bogus,\n" );
		$map    = MatrixImporter::auto_map( $parsed['header'] );
		$prev   = MatrixImporter::preview( $parsed, $map, false );
		$this->assertCount( 2, $prev['changes'] );
		$this->assertSame( 'transactional', $prev['changes'][0]['changes']['editorial_intent']['to'] );
		$this->assertArrayNotHasKey( 'review_state', $prev['changes'][0]['changes'], 'Empty cell = no change.' );
		$this->assertCount( 1, $prev['conflicts'], 'Existing non-default value not overwritten without consent.' );
		$this->assertCount( 2, $prev['invalid'], 'Unknown document and duplicate row isolated.' );
		$before = Db::row( 'SELECT editorial_intent FROM {documents} WHERE id = %d', array( $doc_a ) );
		$this->assertSame( '', $before['editorial_intent'], 'Preview writes nothing.' );
		$applied = MatrixImporter::apply( $prev['token'] );
		$this->assertSame( 2, $applied['applied'] );
		$this->assertSame( 'transactional', Db::value( 'SELECT editorial_intent FROM {documents} WHERE id = %d', array( $doc_a ) ) );
		$this->assertSame( 'informational', Db::value( 'SELECT editorial_intent FROM {documents} WHERE id = %d', array( $doc_b ) ) );
		$this->assertWPError( MatrixImporter::apply( $prev['token'] ), 'Preview token is single-use.' );
		$this->assertSame( $this->posts['vi-pillar'], (int) Db::value( 'SELECT object_id FROM {documents} WHERE id = %d', array( $doc_a ) ) );
	}

	public function test_related_content_output_when_enabled_is_escaped_and_locale_safe() {
		$this->load_corpus();
		wp_update_post(
			array(
				'ID'         => $this->posts['vi-thang'],
				'post_title' => 'Thuê xe máy theo tháng <img src=x onerror=alert(1)>',
			)
		);
		$this->run_full_pipeline();
		Settings::update( array( 'related_enabled' => true ) );
		$html = do_shortcode( '[sseo_related post_id="' . $this->posts['vi-ngay'] . '"]' );
		$this->assertStringContainsString( '<nav class="sseo-related"', $html );
		$this->assertStringNotContainsString( '<img', $html, 'Titles are escaped.' );
		$items = sseo_get_related_posts( $this->posts['vi-ngay'] );
		$this->assertNotEmpty( $items );
		foreach ( $items as $it ) {
			$doc = \SemanticSEO\Inventory\DocumentRepository::by_object( 'post', $it['post_id'] );
			$this->assertSame( 'vi', $doc['locale'] );
			$this->assertSame( 'publish', get_post_status( $it['post_id'] ) );
			$this->assertNotSame( $this->posts['vi-ngay-gia-tot'], $it['post_id'], 'Near-duplicate never recommended.' );
		}
		$this->assertStringNotContainsString( 'transformers', $html );
		$this->assertStringNotContainsString( 'onnx', $html );
		$this->assertFalse( wp_script_is( 'sseo-ai-worker', 'enqueued' ), 'No model code on the frontend.' );
	}

	public function test_uninstall_preserves_data_by_default_and_deletes_on_explicit_policy() {
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$this->load_corpus();
		$this->run_full_pipeline();
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'semantic-seo-engine-vi-en/semantic-seo-engine-vi-en.php' );
		}
		$docs = (int) Db::value( 'SELECT COUNT(*) FROM {documents}' );
		include dirname( __DIR__, 3 ) . '/uninstall.php';
		$this->assertSame( array(), Migrator::missing_tables() );
		$this->assertSame( $docs, (int) Db::value( 'SELECT COUNT(*) FROM {documents}' ) );

		Settings::update( array( 'uninstall_policy' => 'delete' ) );
		include dirname( __DIR__, 3 ) . '/uninstall.php';
		$this->assertCount( count( \SemanticSEO\Database\Schema::TABLES ), Migrator::missing_tables() );
		$this->assertFalse( get_option( 'sseo_settings' ) );
		// Restore for the rest of the suite.
		Migrator::migrate();
		$this->assertSame( array(), Migrator::missing_tables() );
	}
}
