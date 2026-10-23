<?php
/**
 * Recovery of background work after a worker dies or content changes mid-way: a job whose ticks
 * keep crashing ends as "failed" instead of blocking its type; WP-CLI waits for a worker holding
 * the lease instead of spinning; an embedding item re-queued with new content stays claimable and
 * an item out of attempts never keeps the embedding job open.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Tests\Integration;

use SemanticSEO\Database\Db;
use SemanticSEO\Jobs\JobRepository;
use SemanticSEO\Jobs\JobRunner;
use SemanticSEO\Semantic\EmbeddingQueue;
use SemanticSEO\Semantic\ModelProfile;
use SemanticSEO\Support\Util;

/**
 * @group jobs
 */
final class JobRecoveryTest extends TestCase {

	public function test_a_job_whose_ticks_keep_crashing_fails_after_max_attempts() {
		$job = JobRepository::create( 'verify_links', array(), $this->admin, 'test' );
		$id  = (int) $job['id'];
		$max = (int) JobRepository::get( $id )['max_attempts'];
		for ( $i = 0; $i < $max + 3; $i++ ) {
			if ( ! JobRepository::claim( $id, 'dead' . $i, 90 ) ) {
				break;
			}
			// The process dies (fatal error) without heartbeat or fail(): only the lease expires.
			Db::query( 'UPDATE {jobs} SET lease_expires = %s WHERE id = %d', array( Util::now( -1 ), $id ) );
		}
		JobRepository::claim( $id, 'next', 90 );
		$j = JobRepository::get( $id );
		$this->assertSame( 'failed', $j['status'], 'not retried forever' );
		$this->assertSame( $max, (int) $j['attempts'] );
		$this->assertSame( JobRepository::CRASHED, $j['last_error'] );
		$this->assertIsArray( JobRepository::create( 'verify_links', array(), $this->admin, 'test' ), 'the job type is no longer blocked' );
	}

	public function test_a_lease_taken_over_once_is_counted_and_the_job_still_runs() {
		$job = JobRepository::create( 'verify_links', array(), $this->admin, 'test' );
		$id  = (int) $job['id'];
		$this->assertTrue( JobRepository::claim( $id, 'dead', 90 ) );
		Db::query( 'UPDATE {jobs} SET lease_expires = %s WHERE id = %d', array( Util::now( -1 ), $id ) );
		$res = JobRunner::run_to_completion( $id );
		$this->assertSame( 'completed', $res['status'] );
		$this->assertSame( 1, (int) JobRepository::get( $id )['attempts'] );
	}

	public function test_cli_waits_for_a_worker_holding_the_lease_instead_of_spinning() {
		$job = JobRepository::create( 'verify_links', array(), $this->admin, 'test' );
		$id  = (int) $job['id'];
		$this->assertTrue( JobRepository::claim( $id, 'cron', 90 ) );
		$t0  = microtime( true );
		$res = JobRunner::run_to_completion( $id, 3 );
		$this->assertGreaterThanOrEqual( 1.9, microtime( true ) - $t0, 'waited between attempts' );
		$this->assertSame( 'running', $res['status'] );
		$this->assertSame( 'cron', JobRepository::get( $id )['lease_owner'], 'the other worker keeps its lease' );
	}

	public function test_an_item_requeued_with_new_content_while_leased_is_claimable_again() {
		$this->load_corpus();
		$this->run_full_pipeline();
		$doc = $this->doc( 'vi-sapa' );
		$mk  = ModelProfile::active()['model_key'];
		$c   = Db::row( 'SELECT * FROM {chunks} WHERE document_id = %d AND chunk_index = 0', array( $doc ) );
		// A worker holds the item on its last attempt…
		Db::query(
			"INSERT INTO {queue} (job_id, document_id, chunk_id, model_key, content_hash, status, lease_hash, lease_owner, lease_expires, attempts, updated_at) VALUES (1, %d, 0, %s, %s, 'leased', 'old-lease', 7, %s, %d, %s)",
			array( $doc, $mk, $c['content_hash'], Util::now( 100 ), EmbeddingQueue::MAX_ATTEMPT, Util::now() )
		);
		// …when the post is edited and the item is queued again with the new text.
		Db::query( 'UPDATE {chunks} SET content_hash = %s WHERE id = %d', array( str_repeat( 'b', 64 ), (int) $c['id'] ) );
		$job = JobRepository::create( 'embed', array( 'doc_ids' => array( $doc ) ), $this->admin, 'test' );
		EmbeddingQueue::enqueue_batch( (int) $job['id'], 0, 50, array( $doc ) );

		$row = Db::row( 'SELECT status, attempts, lease_hash FROM {queue} WHERE document_id = %d AND chunk_id = 0', array( $doc ) );
		$this->assertSame( 'pending', $row['status'] );
		$this->assertSame( 0, (int) $row['attempts'], 'new content, new attempts' );
		$this->assertSame( '', $row['lease_hash'], 'the lease on the old text is void' );
		$found = false;
		for ( $i = 0; $i < 20 && ! $found; $i++ ) {
			$claim = EmbeddingQueue::claim( 99, EmbeddingQueue::MAX_CLAIM );
			if ( array() === $claim['items'] ) {
				break;
			}
			foreach ( $claim['items'] as $it ) {
				if ( $it['document_id'] === $doc && 0 === $it['chunk_index'] ) {
					$found = true;
					$this->assertSame( str_repeat( 'b', 64 ), $it['content_hash'] );
				}
			}
		}
		$this->assertTrue( $found, 'claimable again' );
	}

	public function test_an_item_out_of_attempts_does_not_keep_the_embedding_job_open() {
		$this->load_corpus();
		$this->run_full_pipeline();
		$doc = $this->doc( 'vi-sapa' );
		$job = JobRepository::create( 'embed', array( 'doc_ids' => array( $doc ) ), $this->admin, 'test' );
		EmbeddingQueue::enqueue_batch( (int) $job['id'], 0, 50, array( $doc ) );
		// State left by an older version: pending with no attempt left.
		Db::query( "UPDATE {queue} SET status = 'pending', attempts = %d WHERE document_id = %d", array( EmbeddingQueue::MAX_ATTEMPT, $doc ) );
		EmbeddingQueue::claim( 99, 1 );
		$counts = EmbeddingQueue::counts( (int) $job['id'] );
		$this->assertSame( 0, $counts['pending'] + $counts['leased'], wp_json_encode( $counts ) );
		$this->assertGreaterThan( 0, $counts['failed'] );
	}

	public function test_a_claim_hands_out_the_hash_of_the_text_it_sends() {
		$this->load_corpus();
		$this->run_full_pipeline();
		$doc = $this->doc( 'vi-sapa' );
		$job = JobRepository::create( 'embed', array( 'doc_ids' => array( $doc ) ), $this->admin, 'test' );
		EmbeddingQueue::enqueue_batch( (int) $job['id'], 0, 50, array( $doc ) );
		// The post changed after the item was queued (not re-queued yet).
		Db::query( 'UPDATE {chunks} SET text = %s, content_hash = %s WHERE document_id = %d AND chunk_index = 0', array( 'Văn bản mới', str_repeat( 'c', 64 ), $doc ) );
		Db::query( "UPDATE {queue} SET status = 'done' WHERE document_id <> %d", array( $doc ) );
		$claim = EmbeddingQueue::claim( 99, EmbeddingQueue::MAX_CLAIM );
		$item  = null;
		foreach ( $claim['items'] as $it ) {
			if ( $it['document_id'] === $doc && 0 === $it['chunk_index'] ) {
				$item = $it;
			}
		}
		$this->assertNotNull( $item );
		$this->assertSame( 'Văn bản mới', $item['text'] );
		$this->assertSame( str_repeat( 'c', 64 ), $item['content_hash'], 'the hash of the text handed out' );
	}
}
