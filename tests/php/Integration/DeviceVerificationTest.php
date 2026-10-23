<?php
/**
 * Server-side check of a device's self-test: a device's own "passed" is never accepted.
 *
 * The vectors here are SYNTHETIC (built to reproduce or to break the expected matrix); the real
 * model passes the same check in HybridEvaluationTest and the private-worker e2e.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Tests\Integration;

use SemanticSEO\Capabilities;
use SemanticSEO\Database\Db;
use SemanticSEO\Jobs\JobRepository;
use SemanticSEO\Jobs\JobRunner;
use SemanticSEO\Semantic\DeviceVerification;
use SemanticSEO\Semantic\ModelProfile;
use SemanticSEO\Semantic\VectorMath;
use SemanticSEO\Services\Api;

/**
 * @group embeddings
 * @group security
 */
final class DeviceVerificationTest extends TestCase {

	/**
	 * Becomes a worker with one claimed item.
	 *
	 * @return array Claim.
	 */
	private function worker_with_claim() {
		$this->load_corpus();
		$this->run_full_pipeline();
		$job = JobRepository::create( 'embed', array(), $this->admin, 'test' );
		JobRunner::tick( 10, (int) $job['id'] );
		Capabilities::ensure_agent_roles();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'sseo_worker' ) ) );
		return Api::embedding_claim( array( 'limit' => 1 ) );
	}

	/**
	 * Uploads one unit vector for a claim.
	 *
	 * @param array $claim Claim.
	 * @return array|\WP_Error
	 */
	private function upload( array $claim ) {
		$v    = array_fill( 0, (int) ModelProfile::active()['dimension'], 0.0 );
		$v[5] = 1.0;
		return Api::embedding_complete(
			array(
				'lease_token' => $claim['lease_token'],
				'model_key'   => ModelProfile::active()['model_key'],
				'results'     => array(
					array(
						'queue_id' => $claim['items'][0]['queue_id'],
						'vector'   => $v,
					),
				),
				'source'      => 'worker',
			)
		);
	}

	public function test_vectors_are_refused_until_the_server_has_checked_the_self_test() {
		$claim = $this->worker_with_claim();
		$res   = $this->upload( $claim );
		$this->assertWPError( $res );
		$this->assertSame( 'sseo_selftest_required', $res->get_error_code() );
		$this->assertSame( 428, $res->get_error_data()['status'] );
		$this->assertSame( 0, (int) Db::value( 'SELECT COUNT(*) FROM {embeddings}' ) );
		$this->assertFalse( Api::embedding_profile()['verification']['verified'] );

		$this->verify_device();
		$this->assertTrue( Api::embedding_profile()['verification']['verified'] );
		$this->assertSame( 1, $this->upload( $claim )['stored'] );
		$this->assertSame( 1, (int) Db::value( "SELECT COUNT(*) FROM {audit} WHERE action = 'embedding.selftest_verified'" ) );
	}

	public function test_wrong_pipelines_fail_the_server_check() {
		$this->worker_with_claim();
		$key = ModelProfile::active()['model_key'];
		$exp = ModelProfile::active()['reference']['scores_by_dtype']['q4f16'];

		// A pipeline off by more than the tolerance (e.g. missing query instruction: +0.14).
		$off = array_map(
			static function ( $row ) {
				return array_map(
					static function ( $x ) {
						return $x - 0.14;
					},
					$row
				);
			},
			$exp
		);
		$v   = $this->reference_vectors( $off );
		$res = Api::embedding_selftest(
			array(
				'model_key' => $key,
				'queries'   => $v['queries'],
				'documents' => $v['documents'],
			)
		);
		$this->assertFalse( $res['verified'] );
		$this->assertGreaterThan( $res['tolerance'], $res['max_abs_diff'] );

		// Swapped ranking: each query prefers the wrong document.
		$v   = $this->reference_vectors( array( array( $exp[0][1], $exp[0][0] ), array( $exp[1][1], $exp[1][0] ) ) );
		$res = Api::embedding_selftest(
			array(
				'model_key' => $key,
				'queries'   => $v['queries'],
				'documents' => $v['documents'],
			)
		);
		$this->assertFalse( $res['verified'] );

		// Not normalized, wrong size, other model profile.
		$v                   = $this->reference_vectors();
		$v['queries'][0][10] = 3.0;
		$this->assertWPError(
			Api::embedding_selftest(
				array(
					'model_key' => $key,
					'queries'   => $v['queries'],
					'documents' => $v['documents'],
				)
			)
		);
		$this->assertWPError(
			Api::embedding_selftest(
				array(
					'model_key' => $key,
					'queries'   => array( array( 1.0 ), array( 1.0 ) ),
					'documents' => array( array( 1.0 ), array( 1.0 ) ),
				)
			)
		);
		$v   = $this->reference_vectors();
		$res = Api::embedding_selftest(
			array(
				'model_key' => str_repeat( 'a', 40 ),
				'queries'   => $v['queries'],
				'documents' => $v['documents'],
			)
		);
		$this->assertSame( 'sseo_model_mismatch', $res->get_error_code() );
		$this->assertFalse( DeviceVerification::is_verified( get_current_user_id(), $key ) );
		$this->assertGreaterThan( 0, (int) Db::value( "SELECT COUNT(*) FROM {audit} WHERE action = 'embedding.selftest_failed'" ) );
	}

	public function test_a_failed_recheck_or_an_expired_check_blocks_uploads_again() {
		$claim = $this->worker_with_claim();
		$this->verify_device();
		$key = ModelProfile::active()['model_key'];
		$this->assertTrue( DeviceVerification::is_verified( get_current_user_id(), $key ) );

		$meta                  = get_user_meta( get_current_user_id(), DeviceVerification::META, true );
		$meta[ $key ]['until'] = time() - 1;
		update_user_meta( get_current_user_id(), DeviceVerification::META, $meta );
		$this->assertSame( 'sseo_selftest_required', $this->upload( $claim )->get_error_code() );

		$this->verify_device();
		$v   = $this->reference_vectors( array( array( 0.1, 0.1 ), array( 0.1, 0.1 ) ) );
		$res = Api::embedding_selftest(
			array(
				'model_key' => $key,
				'queries'   => $v['queries'],
				'documents' => $v['documents'],
			)
		);
		$this->assertFalse( $res['verified'] );
		$this->assertFalse( DeviceVerification::is_verified( get_current_user_id(), $key ), 'a failed check revokes the earlier pass' );
	}

	public function test_only_embedding_workers_may_submit_a_self_test() {
		Capabilities::ensure_agent_roles();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'sseo_agent_reader' ) ) );
		$v   = $this->reference_vectors();
		$res = Api::embedding_selftest(
			array(
				'model_key' => ModelProfile::active()['model_key'],
				'queries'   => $v['queries'],
				'documents' => $v['documents'],
			)
		);
		$this->assertWPError( $res );
		$this->assertSame( 403, $res->get_error_data()['status'] );
		unset( $res, $v );
		$this->assertTrue( VectorMath::validate( $this->reference_vectors()['queries'][0], (int) ModelProfile::active()['dimension'] ) );
	}
}
