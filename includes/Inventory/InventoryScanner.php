<?php
/**
 * Read-only inventory of posts, pages, custom post types and taxonomy terms.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Inventory;

use SemanticSEO\Analysis\IntentClassifier;
use SemanticSEO\Compatibility\SeoAdapter;
use SemanticSEO\Database\Db;
use SemanticSEO\Extractor\ContentExtractor;
use SemanticSEO\Graph\LinkGraph;
use SemanticSEO\Language\LanguageResolver;
use SemanticSEO\Lexical\LexicalIndex;
use SemanticSEO\Lexical\Tokenizer;
use SemanticSEO\Locks\LockService;
use SemanticSEO\Settings;
use SemanticSEO\Support\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Walks objects with ID cursors (bounded memory) and (re)indexes only changed content.
 * It never writes to WordPress objects.
 */
final class InventoryScanner {

	const VERSION = 'inv-3';

	/**
	 * Post statuses in scope.
	 *
	 * @return string[]
	 */
	public static function statuses() {
		$statuses = array( 'publish' );
		if ( Settings::get( 'include_drafts', false ) ) {
			$statuses = array_merge( $statuses, array( 'draft', 'pending', 'private', 'future' ) );
		}
		return $statuses;
	}

	/**
	 * Post types in scope that actually exist.
	 *
	 * @return string[]
	 */
	public static function post_types() {
		return array_values( array_filter( (array) Settings::get( 'post_types', array( 'post', 'page' ) ), 'post_type_exists' ) );
	}

	/**
	 * Taxonomies in scope that actually exist.
	 *
	 * @return string[]
	 */
	public static function taxonomies() {
		return array_values( array_filter( (array) Settings::get( 'taxonomies', array( 'category' ) ), 'taxonomy_exists' ) );
	}

	/**
	 * Objects in scope (real counts from the database).
	 *
	 * @return array{posts:int,terms:int,total:int}
	 */
	public static function scope_counts() {
		$types    = self::post_types();
		$statuses = self::statuses();
		$posts    = 0;
		if ( array() !== $types ) {
			$posts = (int) Db::value(
				'SELECT COUNT(*) FROM {posts} WHERE post_type IN (' . Db::in( $types, '%s' ) . ') AND post_status IN (' . Db::in( $statuses, '%s' ) . ')',
				array_merge( $types, $statuses )
			);
		}
		$taxes = self::taxonomies();
		$terms = 0;
		if ( array() !== $taxes ) {
			$terms = (int) Db::value( 'SELECT COUNT(*) FROM {term_taxonomy} WHERE taxonomy IN (' . Db::in( $taxes, '%s' ) . ')', $taxes );
		}
		return array(
			'posts' => $posts,
			'terms' => $terms,
			'total' => $posts + $terms,
		);
	}

	/**
	 * Documents currently stored (null: not counted yet in this batch).
	 *
	 * @var int|null
	 */
	private static $stored = null;

	/**
	 * New objects skipped in this batch because the *Max documents* limit was reached.
	 *
	 * @var int
	 */
	private static $capped = 0;

	/**
	 * The *Max documents* setting: how many documents the inventory may hold.
	 *
	 * @return int
	 */
	public static function max_documents() {
		return max( 10, (int) Settings::get( 'max_documents', 5000 ) );
	}

	/**
	 * Takes a slot for a new document, or refuses when the limit is reached. Objects that already
	 * have a document are never refused, so existing documents keep being refreshed when the limit
	 * is lowered below the stored count (nothing is deleted).
	 *
	 * @return bool
	 */
	private static function reserve_slot() {
		if ( null === self::$stored ) {
			self::$stored = (int) Db::value( "SELECT COUNT(*) FROM {documents} WHERE status <> 'deleted'" );
		}
		if ( self::$stored >= self::max_documents() ) {
			++self::$capped;
			return false;
		}
		++self::$stored;
		return true;
	}

	/**
	 * Starts a batch: the document count is re-read and the skip counter reset.
	 *
	 * @return void
	 */
	public static function begin_batch() {
		self::$stored = null;
		self::$capped = 0;
	}

	/**
	 * New objects skipped in the current batch because of the limit.
	 *
	 * @return int
	 */
	public static function capped_in_batch() {
		return self::$capped;
	}

	/**
	 * Processes one batch. State: {phase: posts|terms|cleanup|done, cursor:int, processed:int,
	 * capped:int}. Objects beyond the *Max documents* limit are counted in `capped`, not indexed.
	 *
	 * @param array $state State.
	 * @param int   $batch Batch size.
	 * @param array $only  Optional bounded list of post IDs (targeted analysis).
	 * @return array{state:array,processed:int,done:bool,errors:array}
	 */
	public static function step( array $state, $batch, array $only = array() ) {
		$phase  = isset( $state['phase'] ) ? $state['phase'] : 'posts';
		$cursor = isset( $state['cursor'] ) ? (int) $state['cursor'] : 0;
		$errors = array();
		$count  = 0;
		$batch  = max( 1, (int) $batch );
		self::begin_batch();

		if ( 'posts' === $phase ) {
			$ids = self::next_post_ids( $cursor, $batch, $only );
			foreach ( $ids as $id ) {
				$cursor = $id;
				$post   = get_post( $id );
				if ( ! $post instanceof \WP_Post ) {
					continue;
				}
				try {
					self::index_post( $post );
				} catch ( \Throwable $e ) {
					$errors[] = array(
						'object' => 'post:' . $id,
						'error'  => Util::truncate( $e->getMessage() . ' @' . basename( $e->getFile() ) . ':' . $e->getLine(), 240 ),
					);
				}
				++$count;
			}
			if ( count( $ids ) < $batch ) {
				$phase  = array() === $only ? 'terms' : 'done';
				$cursor = 0;
			}
		} elseif ( 'terms' === $phase ) {
			$taxes = self::taxonomies();
			$rows  = array();
			if ( array() !== $taxes ) {
				$rows = Db::rows(
					'SELECT term_id, taxonomy FROM {term_taxonomy} WHERE taxonomy IN (' . Db::in( $taxes, '%s' ) . ') AND term_id > %d ORDER BY term_id ASC LIMIT %d',
					array_merge( $taxes, array( $cursor, $batch ) )
				);
			}
			foreach ( $rows as $r ) {
				$cursor = (int) $r['term_id'];
				$term   = get_term( (int) $r['term_id'], (string) $r['taxonomy'] );
				if ( $term instanceof \WP_Term ) {
					try {
						self::index_term( $term );
					} catch ( \Throwable $e ) {
						$errors[] = array(
							'object' => 'term:' . $cursor,
							'error'  => Util::truncate( $e->getMessage() . ' @' . basename( $e->getFile() ) . ':' . $e->getLine(), 240 ),
						);
					}
				}
				++$count;
			}
			if ( count( $rows ) < $batch ) {
				$phase  = 'cleanup';
				$cursor = 0;
			}
		} elseif ( 'cleanup' === $phase ) {
			$rows = Db::rows( "SELECT id, object_type, object_id, subtype FROM {documents} WHERE id > %d AND status <> 'deleted' ORDER BY id ASC LIMIT %d", array( $cursor, $batch * 4 ) );
			foreach ( $rows as $r ) {
				$cursor = (int) $r['id'];
				if ( ! self::object_in_scope( $r['object_type'], (int) $r['object_id'] ) ) {
					DocumentRepository::purge( (int) $r['id'] );
				}
			}
			if ( count( $rows ) < $batch * 4 ) {
				$phase = 'done';
			}
		}
		$state['phase']     = $phase;
		$state['cursor']    = $cursor;
		$state['processed'] = ( isset( $state['processed'] ) ? (int) $state['processed'] : 0 ) + $count;
		$state['capped']    = ( isset( $state['capped'] ) ? (int) $state['capped'] : 0 ) + self::$capped;
		return array(
			'state'     => $state,
			'processed' => $count,
			'done'      => 'done' === $phase,
			'errors'    => $errors,
		);
	}

	/**
	 * Next post IDs after a cursor.
	 *
	 * @param int   $cursor Cursor.
	 * @param int   $batch  Batch size.
	 * @param int[] $only   Restriction.
	 * @return int[]
	 */
	private static function next_post_ids( $cursor, $batch, array $only ) {
		$types    = self::post_types();
		$statuses = self::statuses();
		if ( array() === $types ) {
			return array();
		}
		$sql  = 'SELECT ID FROM {posts} WHERE ID > %d AND post_type IN (' . Db::in( $types, '%s' ) . ') AND post_status IN (' . Db::in( $statuses, '%s' ) . ')';
		$args = array_merge( array( $cursor ), $types, $statuses );
		if ( array() !== $only ) {
			$only = array_slice( array_values( array_unique( array_map( 'intval', $only ) ) ), 0, 500 );
			$sql .= ' AND ID IN (' . Db::in( $only ) . ')';
			$args = array_merge( $args, $only );
		}
		$sql   .= ' ORDER BY ID ASC LIMIT %d';
		$args[] = $batch;
		return array_map( 'intval', Db::col( $sql, $args ) );
	}

	/**
	 * Whether a stored object still exists and is in scope.
	 *
	 * @param string $type post|term.
	 * @param int    $id   Object ID.
	 * @return bool
	 */
	public static function object_in_scope( $type, $id ) {
		if ( 'post' === $type ) {
			$post = get_post( $id );
			return $post instanceof \WP_Post && in_array( $post->post_type, self::post_types(), true ) && in_array( $post->post_status, self::statuses(), true );
		}
		$tt = Db::row( 'SELECT taxonomy FROM {term_taxonomy} WHERE term_id = %d', array( (int) $id ) );
		return null !== $tt && in_array( $tt['taxonomy'], self::taxonomies(), true );
	}

	/**
	 * Indexes one post when its fingerprint changed (or when forced/dirty).
	 *
	 * @param \WP_Post $post  Post.
	 * @param bool     $force Force re-extraction.
	 * @return int Document ID, or 0 when the *Max documents* limit is reached for a new object.
	 */
	public static function index_post( \WP_Post $post, $force = false ) {
		$url          = (string) get_permalink( $post );
		$loc          = LanguageResolver::post_locale( $post->ID, $url );
		$translations = LanguageResolver::post_translations( $post->ID, $post->post_type );
		$standalone   = LanguageResolver::declared_standalone( $post->ID );
		$seo          = SeoAdapter::post_meta( $post );
		$canonical    = '' !== $seo['canonical'] ? $seo['canonical'] : ( 'publish' === $post->post_status ? (string) wp_get_canonical_url( $post ) : $url );

		$terms = array();
		foreach ( self::taxonomies() as $tax ) {
			if ( ! is_object_in_taxonomy( $post->post_type, $tax ) ) {
				continue;
			}
			$assigned = wp_get_post_terms( $post->ID, $tax );
			if ( is_wp_error( $assigned ) ) {
				continue;
			}
			foreach ( $assigned as $t ) {
				$terms[] = array(
					'taxonomy' => $tax,
					'term_id'  => (int) $t->term_id,
					'name'     => $t->name,
					'parent'   => (int) $t->parent,
				);
			}
		}

		$fingerprint = Util::sha256(
			Util::canonical_json(
				array(
					self::VERSION,
					Tokenizer::VERSION,
					ContentExtractor::VERSION,
					$post->post_title,
					$post->post_content,
					$post->post_excerpt,
					$post->post_status,
					$post->post_name,
					$url,
					$canonical,
					$seo,
					$terms,
					$loc,
					$translations,
					$standalone,
				)
			)
		);
		$existing    = DocumentRepository::by_object( 'post', $post->ID );
		if ( null === $existing && ! self::reserve_slot() ) {
			return 0;
		}
		$now      = Util::now();
		$excluded = LockService::is_excluded( 'post', $post->ID, $url ) ? 1 : 0;
		if ( null !== $existing && ! $force && $existing['index_hash'] === $fingerprint && 0 === (int) $existing['dirty'] ) {
			DocumentRepository::update(
				(int) $existing['id'],
				array(
					'scanned_at' => $now,
					'excluded'   => $excluded,
					'status'     => $post->post_status,
				)
			);
			return (int) $existing['id'];
		}

		$x = ContentExtractor::extract(
			array(
				'title'       => $post->post_title,
				'content'     => $post->post_content,
				'excerpt'     => $post->post_excerpt,
				'description' => $seo['description'],
				'slug'        => $post->post_name,
			)
		);
		return self::store(
			array(
				'object_type'       => 'post',
				'object_id'         => (int) $post->ID,
				'subtype'           => $post->post_type,
				'url'               => $url,
				'locale'            => $loc['locale'],
				'locale_source'     => $loc['source'],
				'status'            => $post->post_status,
				'title'             => $post->post_title,
				'canonical'         => $canonical,
				'seo'               => $seo,
				'translation_group' => LanguageResolver::group_key( $translations ),
				'translations'      => $translations,
				'standalone'        => $standalone,
				'terms'             => $terms,
				'fingerprint'       => $fingerprint,
				'excluded'          => $excluded,
				'published_at'      => '0000-00-00 00:00:00' === $post->post_date_gmt ? null : $post->post_date_gmt,
				'modified_at'       => '0000-00-00 00:00:00' === $post->post_modified_gmt ? null : $post->post_modified_gmt,
				'excerpt'           => $post->post_excerpt,
			),
			$x
		);
	}

	/**
	 * Indexes one taxonomy term (archive page).
	 *
	 * @param \WP_Term $term  Term.
	 * @param bool     $force Force.
	 * @return int Document ID, or 0 when the *Max documents* limit is reached for a new object.
	 */
	public static function index_term( \WP_Term $term, $force = false ) {
		$link         = get_term_link( $term );
		$url          = is_wp_error( $link ) ? '' : (string) $link;
		$loc          = LanguageResolver::term_locale( $term->term_id, $term->taxonomy, $url );
		$translations = array();
		if ( 'polylang' === LanguageResolver::framework() && function_exists( 'pll_get_term_translations' ) ) {
			foreach ( (array) pll_get_term_translations( $term->term_id ) as $lang => $tid ) {
				$translations[ LanguageResolver::short( $lang ) ] = (int) $tid;
			}
		}
		$fingerprint = Util::sha256( Util::canonical_json( array( self::VERSION, Tokenizer::VERSION, $term->name, $term->description, $term->slug, $term->parent, $url, $loc, $translations ) ) );
		$existing    = DocumentRepository::by_object( 'term', $term->term_id );
		if ( null === $existing && ! self::reserve_slot() ) {
			return 0;
		}
		$excluded = LockService::is_excluded( 'term', $term->term_id, $url ) ? 1 : 0;
		if ( null !== $existing && ! $force && $existing['index_hash'] === $fingerprint && 0 === (int) $existing['dirty'] ) {
			DocumentRepository::update(
				(int) $existing['id'],
				array(
					'scanned_at' => Util::now(),
					'excluded'   => $excluded,
				)
			);
			return (int) $existing['id'];
		}
		$x = ContentExtractor::extract(
			array(
				'title'   => $term->name,
				'content' => (string) $term->description,
				'slug'    => $term->slug,
			)
		);
		return self::store(
			array(
				'object_type'       => 'term',
				'object_id'         => (int) $term->term_id,
				'subtype'           => $term->taxonomy,
				'url'               => $url,
				'locale'            => $loc['locale'],
				'locale_source'     => $loc['source'],
				'status'            => 'publish',
				'title'             => $term->name,
				'canonical'         => $url,
				'seo'               => array(
					'source'        => 'core',
					'title'         => '',
					'description'   => wp_strip_all_tags( (string) $term->description ),
					'focus_keyword' => '',
					'canonical'     => '',
					'noindex'       => ! get_option( 'blog_public', 1 ),
					'robots'        => get_option( 'blog_public', 1 ) ? 'index (term defaults; SEO plugin term settings not read)' : 'noindex (site visibility setting)',
				),
				'translation_group' => LanguageResolver::group_key( $translations ),
				'translations'      => $translations,
				'terms'             => array(
					array(
						'taxonomy' => $term->taxonomy,
						'term_id'  => (int) $term->term_id,
						'name'     => $term->name,
						'parent'   => (int) $term->parent,
					),
				),
				'fingerprint'       => $fingerprint,
				'excluded'          => $excluded,
				'published_at'      => null,
				'modified_at'       => null,
				'excerpt'           => '',
			),
			$x
		);
	}

	/**
	 * Persists extraction results: document row, chunks, postings, links.
	 *
	 * @param array $d Object data.
	 * @param array $x Extraction.
	 * @return int Document ID.
	 */
	private static function store( array $d, array $x ) {
		$seo       = $d['seo'];
		$lang_hint = in_array( $d['locale'], array( 'vi', 'en' ), true ) ? $d['locale'] : '';
		$h_texts   = array();
		foreach ( $x['headings'] as $h ) {
			if ( $h['level'] <= 3 ) {
				$h_texts[] = $h['text'];
			}
		}
		$intent = IntentClassifier::classify(
			array(
				'title'      => $d['title'],
				'slug'       => isset( $x['slug'] ) ? $x['slug'] : '',
				'headings'   => $h_texts,
				'opening'    => $x['first_paragraph'],
				'attributes' => $x['attributes'],
			)
		);
		$tf     = LexicalIndex::document_terms(
			array(
				'title'       => $d['title'],
				'focus'       => $seo['focus_keyword'],
				'headings'    => implode( "\n", array_column( $x['headings'], 'text' ) ),
				'description' => '' !== $seo['description'] ? $seo['description'] : $d['excerpt'],
				'body'        => $x['text'],
			),
			$lang_hint
		);
		$top    = array();
		foreach ( $tf as $term => $w ) {
			$term = (string) $term; // Numeric terms ("150", "50") become integer array keys.
			if ( '~' !== $term[0] ) {
				$top[ $term ] = $w;
			}
		}
		arsort( $top );
		$top = array_slice( $top, 0, 20, true );

		$canonical_self = '' === (string) $d['canonical'] || LinkGraph::canonical_form( $d['canonical'] ) === LinkGraph::canonical_form( $d['url'] );
		$indexable      = ( 'publish' === $d['status'] && empty( $seo['noindex'] ) && $canonical_self ) ? 1 : 0;
		$reasons        = array();
		if ( 'publish' !== $d['status'] ) {
			$reasons[] = 'status:' . $d['status'];
		}
		if ( ! empty( $seo['noindex'] ) ) {
			$reasons[] = 'noindex';
		}
		if ( ! $canonical_self ) {
			$reasons[] = 'canonical points elsewhere';
		}

		$signals = array(
			'versions'               => array(
				'inventory' => self::VERSION,
				'extractor' => ContentExtractor::VERSION,
				'tokenizer' => Tokenizer::VERSION,
				'lexical'   => LexicalIndex::VERSION,
				'intent'    => IntentClassifier::VERSION,
			),
			'headings'               => array_slice( $x['headings'], 0, 80 ),
			'entities'               => $x['entities'],
			'attributes'             => $x['attributes'],
			'top_terms'              => array_map(
				static function ( $w ) {
					return round( $w, 2 );
				},
				$top
			),
			'repetition'             => $x['repetition'],
			'minhash'                => $x['minhash'],
			'first_paragraph'        => $x['first_paragraph'],
			'detected_lang'          => $x['detected_lang'],
			'images'                 => $x['images'],
			'images_no_alt'          => $x['images_no_alt'],
			'jsonld_in_content'      => $x['jsonld_in_content'],
			'intent'                 => $intent,
			'indexability'           => $reasons,
			'translations'           => $d['translations'],
			'translation_standalone' => ! empty( $d['standalone'] ),
			'seo_title_is_template'  => ! empty( $seo['title_is_template'] ),
			'link_count'             => count( $x['links'] ),
			'slug'                   => isset( $x['slug'] ) ? $x['slug'] : '',
		);
		$now     = Util::now();
		$doc_id  = DocumentRepository::upsert(
			array(
				'object_type'       => $d['object_type'],
				'object_id'         => $d['object_id'],
				'subtype'           => $d['subtype'],
				'url'               => $d['url'],
				'url_hash'          => DocumentRepository::url_hash( $d['url'] ),
				'locale'            => $d['locale'],
				'locale_source'     => $d['locale_source'],
				'detected_lang'     => $x['detected_lang']['lang'],
				'status'            => $d['status'],
				'title'             => Util::truncate( $d['title'], 500 ),
				'canonical'         => $d['canonical'],
				'robots'            => Util::truncate( (string) $seo['robots'], 100 ),
				'indexable'         => $indexable,
				'seo_source'        => (string) $seo['source'],
				'seo_title'         => (string) $seo['title'],
				'meta_description'  => (string) $seo['description'],
				'focus_keyword'     => Util::truncate( (string) $seo['focus_keyword'], 250 ),
				'translation_group' => $d['translation_group'],
				'terms_json'        => Util::json( $d['terms'] ),
				'content_hash'      => Util::sha256( (string) $x['text'] ),
				'index_hash'        => $d['fingerprint'],
				'word_count'        => (int) $x['word_count'],
				'intent'            => $intent['intent'],
				'intent_confidence' => $intent['confidence'],
				'excluded'          => (int) $d['excluded'],
				'signals'           => Util::json( $signals ),
				'published_at'      => $d['published_at'],
				'modified_at'       => $d['modified_at'],
				'scanned_at'        => $now,
				'indexed_at'        => $now,
				'dirty'             => 0,
				'algorithm_version' => self::VERSION,
			)
		);
		$len     = LexicalIndex::index_document( $doc_id, $tf );
		DocumentRepository::update( $doc_id, array( 'doc_len' => $len ) );
		DocumentRepository::replace_chunks( $doc_id, $x['chunks'] );
		LinkGraph::store_links( $doc_id, $x['links'], $d['url'] );
		return $doc_id;
	}
}
