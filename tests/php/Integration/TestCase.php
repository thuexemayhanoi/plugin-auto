<?php
/**
 * Base class for integration tests (real WordPress + database).
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Tests\Integration;

use SemanticSEO\Database\Schema;
use SemanticSEO\Jobs\JobRepository;
use SemanticSEO\Jobs\JobRunner;
use SemanticSEO\Lexical\LexicalIndex;
use SemanticSEO\Locks\LockService;
use SemanticSEO\Settings;

require_once __DIR__ . '/PolylangDouble.php';

/**
 * Provides corpus loading and helpers.
 */
abstract class TestCase extends \WP_UnitTestCase {

	/**
	 * Fixture key => post ID.
	 *
	 * @var array<string,int>
	 */
	protected $posts = array();

	/**
	 * Fixture key => term ID.
	 *
	 * @var array<string,int>
	 */
	protected $cats = array();

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	protected $admin = 0;

	/**
	 * Truncates plugin tables and resets state before each test.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		global $wpdb;
		foreach ( Schema::TABLES as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Schema::table( $t ) ); // phpcs:ignore WordPress.DB
		}
		delete_option( 'sseo_settings' );
		delete_option( 'sseo_state' );
		Settings::flush();
		Settings::install_defaults();
		LockService::flush();
		LexicalIndex::flush_cache();
		\SemanticSEO\Tests\Integration\PolylangDouble::reset();
		$this->set_permalink_structure( '/%postname%/' );
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
	}

	/**
	 * Loads the labelled fixture corpus.
	 *
	 * @return array Corpus definition.
	 */
	protected function load_corpus() {
		$corpus = require dirname( __DIR__, 2 ) . '/fixtures/corpus.php';
		foreach ( $corpus['categories'] as $c ) {
			$parent = '' !== $c['parent'] ? $this->cats[ $c['parent'] ] : 0;
			$res    = wp_insert_term(
				$c['name'],
				'category',
				array(
					'slug'   => $c['slug'],
					'parent' => $parent,
				)
			);
			$this->cats[ $c['key'] ] = (int) $res['term_id'];
			PolylangDouble::set_term_language( (int) $res['term_id'], $c['lang'] );
		}
		// Pass 1: create posts (needed for {{url:key}} replacement).
		foreach ( $corpus['docs'] as $d ) {
			$status = isset( $d['status'] ) ? $d['status'] : 'publish';
			$id     = self::factory()->post->create(
				array(
					'post_type'    => $d['type'],
					'post_status'  => 'trash' === $status ? 'publish' : $status,
					'post_title'   => $d['title'],
					'post_name'    => $d['slug'],
					'post_content' => '',
				)
			);
			$this->posts[ $d['key'] ] = $id;
			PolylangDouble::set_post_language( $id, $d['lang'], $d['group'] );
			if ( 'post' === $d['type'] && array() !== $d['cats'] ) {
				wp_set_post_categories(
					$id,
					array_map(
						function ( $k ) {
							return $this->cats[ $k ];
						},
						$d['cats']
					)
				);
			}
			if ( ! empty( $d['focus'] ) ) {
				update_post_meta( $id, '_yoast_wpseo_focuskw', $d['focus'] );
			}
		}
		// Pass 2: content with resolved internal links.
		foreach ( $corpus['docs'] as $d ) {
			$content = preg_replace_callback(
				'/\{\{url:([a-z0-9\-]+)\}\}/',
				function ( $m ) {
					return get_permalink( $this->posts[ $m[1] ] );
				},
				$d['content']
			);
			wp_update_post(
				array(
					'ID'           => $this->posts[ $d['key'] ],
					'post_content' => $content,
				)
			);
			if ( isset( $d['status'] ) && 'trash' === $d['status'] ) {
				wp_trash_post( $this->posts[ $d['key'] ] );
			}
		}
		return $corpus;
	}

	/**
	 * Runs inventory + analysis to completion via the job system.
	 *
	 * @return void
	 */
	protected function run_full_pipeline() {
		$job = JobRepository::create( 'inventory', array( 'then_analyze' => true ), $this->admin, 'test' );
		$this->assertIsArray( $job );
		$res = JobRunner::run_to_completion( (int) $job['id'] );
		$this->assertSame( 'completed', $res['status'], 'inventory: ' . wp_json_encode( $res ) );
		$next = JobRepository::next_runnable();
		$this->assertGreaterThan( 0, $next, 'analysis job chained' );
		$res = JobRunner::run_to_completion( $next );
		$this->assertSame( 'completed', $res['status'], 'analysis: ' . wp_json_encode( $res ) );
	}

	/**
	 * Document ID of a fixture.
	 *
	 * @param string $key Fixture key.
	 * @return int
	 */
	protected function doc( $key ) {
		$row = \SemanticSEO\Inventory\DocumentRepository::by_object( 'post', $this->posts[ $key ] );
		return null === $row ? 0 : (int) $row['id'];
	}

	/**
	 * Simulates an interactive WP Admin request.
	 *
	 * @return void
	 */
	protected function as_admin_screen() {
		set_current_screen( 'dashboard' );
	}
}
