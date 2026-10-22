<?php
/**
 * Incremental invalidation: marks changed objects dirty and queues a small incremental job.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Inventory;

use SemanticSEO\Database\Migrator;
use SemanticSEO\Jobs\JobRepository;
use SemanticSEO\Jobs\JobRunner;
use SemanticSEO\Recommendations\RelatedContent;
use SemanticSEO\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks are cheap (one UPDATE). Plugin-generated data lives in custom tables and never triggers
 * these hooks, so analysis cannot loop on its own output.
 */
final class Invalidation {

	const SEO_META_PREFIXES = array( '_yoast_wpseo_', 'rank_math_', '_seopress_', '_genesis_' );

	/**
	 * Whether something changed during this request.
	 *
	 * @var bool
	 */
	private static $changed = false;

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 99, 2 );
		add_action( 'deleted_post', array( __CLASS__, 'on_post_gone' ) );
		add_action( 'trashed_post', array( __CLASS__, 'on_post_gone' ) );
		add_action( 'set_object_terms', array( __CLASS__, 'on_post_gone' ) );
		add_action( 'edited_term', array( __CLASS__, 'on_term' ) );
		add_action( 'created_term', array( __CLASS__, 'on_term' ) );
		add_action( 'delete_term', array( __CLASS__, 'on_term' ) );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_meta' ), 10, 3 );
		add_action( 'added_post_meta', array( __CLASS__, 'on_meta' ), 10, 3 );
		add_action( 'update_option_permalink_structure', array( __CLASS__, 'on_global' ) );
		add_action( 'update_option_home', array( __CLASS__, 'on_global' ) );
		add_action( 'update_option_blog_public', array( __CLASS__, 'on_global' ) );
		add_action( 'shutdown', array( __CLASS__, 'maybe_queue' ) );
	}

	/**
	 * Whether the plugin tables are ready (avoids errors before activation completes).
	 *
	 * @return bool
	 */
	private static function ready() {
		return Migrator::installed_version() > 0;
	}

	/**
	 * Post saved.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 * @return void
	 */
	public static function on_save_post( $post_id, $post ) {
		if ( ! self::ready() || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! $post instanceof \WP_Post ) {
			return;
		}
		if ( 'auto-draft' === $post->post_status || ! in_array( $post->post_type, (array) Settings::get( 'post_types', array() ), true ) ) {
			return;
		}
		DocumentRepository::mark_dirty( 'post', $post_id );
		$doc = DocumentRepository::by_object( 'post', $post_id );
		if ( null !== $doc ) {
			RelatedContent::invalidate( (int) $doc['id'] );
		}
		self::$changed = true;
	}

	/**
	 * Post deleted/trashed/terms changed.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function on_post_gone( $post_id ) {
		if ( ! self::ready() ) {
			return;
		}
		DocumentRepository::mark_dirty( 'post', (int) $post_id );
		self::$changed = true;
	}

	/**
	 * Term changed.
	 *
	 * @param int $term_id Term ID.
	 * @return void
	 */
	public static function on_term( $term_id ) {
		if ( ! self::ready() ) {
			return;
		}
		DocumentRepository::mark_dirty( 'term', (int) $term_id );
		self::$changed = true;
	}

	/**
	 * SEO plugin metadata changed.
	 *
	 * @param int    $meta_id  Meta ID.
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Key.
	 * @return void
	 */
	public static function on_meta( $meta_id, $post_id, $meta_key ) {
		unset( $meta_id );
		if ( ! self::ready() ) {
			return;
		}
		foreach ( self::SEO_META_PREFIXES as $prefix ) {
			if ( 0 === strpos( (string) $meta_key, $prefix ) ) {
				DocumentRepository::mark_dirty( 'post', (int) $post_id );
				self::$changed = true;
				return;
			}
		}
	}

	/**
	 * Site-wide URL/visibility changes.
	 *
	 * @return void
	 */
	public static function on_global() {
		if ( ! self::ready() ) {
			return;
		}
		DocumentRepository::mark_all_dirty();
		self::$changed = true;
	}

	/**
	 * Queues an incremental job at the end of the request (after the first full inventory only).
	 *
	 * @return void
	 */
	public static function maybe_queue() {
		if ( ! self::$changed || ! Settings::get( 'auto_incremental', true ) ) {
			return;
		}
		$state = (array) get_option( 'sseo_state', array() );
		if ( empty( $state['inventory_completed_at'] ) ) {
			return;
		}
		self::$changed = false;
		$job           = JobRepository::create( 'incremental', array(), 0, 'auto' );
		if ( ! is_wp_error( $job ) ) {
			JobRunner::schedule();
		}
	}

	/**
	 * Test helper: whether a change was recorded.
	 *
	 * @return bool
	 */
	public static function changed() {
		return self::$changed;
	}
}
