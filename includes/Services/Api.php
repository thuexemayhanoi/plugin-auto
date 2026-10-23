<?php
/**
 * The single business-service façade used by WP Admin, REST, Abilities and WP-CLI.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Services;

use SemanticSEO\Analysis\FindingRepository;
use SemanticSEO\Approval\ApprovalService;
use SemanticSEO\Approval\ChangeExecutor;
use SemanticSEO\Approval\ProposalService;
use SemanticSEO\Audit\AuditLog;
use SemanticSEO\Capabilities;
use SemanticSEO\Compatibility\Environment;
use SemanticSEO\Database\Db;
use SemanticSEO\Graph\LinkGraph;
use SemanticSEO\Inventory\DocumentRepository;
use SemanticSEO\Inventory\InventoryScanner;
use SemanticSEO\Jobs\JobRepository;
use SemanticSEO\Jobs\JobRunner;
use SemanticSEO\Locks\LockService;
use SemanticSEO\Matrix\MatrixQuery;
use SemanticSEO\Plugin;
use SemanticSEO\Rollback\RollbackService;
use SemanticSEO\Security\AuthContext;
use SemanticSEO\Security\UrlGuard;
use SemanticSEO\Semantic\DeviceVerification;
use SemanticSEO\Semantic\EmbeddingQueue;
use SemanticSEO\Semantic\EmbeddingRepository;
use SemanticSEO\Semantic\ModelProfile;
use SemanticSEO\Settings;
use SemanticSEO\Support\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Every public method enforces capability + rate limit through Guard, applies locks and returns
 * plain arrays or WP_Error. Transports only translate input/output.
 */
final class Api {

	const API_VERSION = '1';
	const MAX_TARGETS = 200;

	/**
	 * The *Max documents* limit against what is stored and what is in scope (real counts).
	 *
	 * @return array{max_documents:int,documents:int,in_scope:int,reached:bool,not_inventoried:int}
	 */
	public static function inventory_limit() {
		$max    = InventoryScanner::max_documents();
		$stored = (int) Db::value( "SELECT COUNT(*) FROM {documents} WHERE status <> 'deleted'" );
		$scope  = InventoryScanner::scope_counts();
		$gap    = max( 0, (int) $scope['total'] - $stored );
		return array(
			'max_documents'   => $max,
			'documents'       => $stored,
			'in_scope'        => (int) $scope['total'],
			'reached'         => $stored >= $max && $gap > 0,
			'not_inventoried' => $stored >= $max ? $gap : 0,
		);
	}

	/**
	 * Site status (semantic-seo/get-site-status).
	 *
	 * @return array|\WP_Error
	 */
	public static function status() {
		$g = Guard::check( Capabilities::READ, 'read', 'status' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$profile = ModelProfile::active();
		$state   = (array) get_option( 'sseo_state', array() );
		$env     = Environment::report();
		return array(
			'plugin_version'  => SSEO_VERSION,
			'api_version'     => self::API_VERSION,
			'mode'            => array(
				'read_only_default' => true,
				'proposals_enabled' => (bool) Settings::get( 'allow_proposals', true ),
				'agents_may_apply'  => (bool) Settings::get( 'agents_may_apply', false ),
				'related_output'    => (bool) Settings::get( 'related_enabled', false ),
			),
			'tiers'           => array(
				'lexical' => array(
					'available' => true,
					'status'    => 'ready',
				),
				'browser' => array(
					'available' => 'device-dependent',
					'status'    => 'requires an administrator to start the model in a supported browser (WebGPU or WASM)',
				),
				'worker'  => array(
					'available' => 'optional',
					'status'    => 'requires a separately configured private worker',
				),
				'active'  => (string) Settings::get( 'inference_tier', 'lexical' ),
			),
			'index'           => DocumentRepository::counts(),
			'link_coverage'   => LinkGraph::coverage(),
			'findings'        => FindingRepository::summary(),
			'semantic'        => array_merge(
				EmbeddingRepository::stats( $profile['model_key'] ),
				array(
					'model_id'  => $profile['model_id'],
					'revision'  => $profile['revision'],
					'dtype'     => $profile['dtype'],
					'dimension' => $profile['dimension'],
					'queue'     => EmbeddingQueue::counts(),
				)
			),
			'jobs_active'     => array_map( array( JobRepository::class, 'to_public' ), JobRepository::find( array( 'status' => 'running' ) ) ),
			'last_inventory'  => isset( $state['inventory_completed_at'] ) ? $state['inventory_completed_at'] : null,
			'inventory_limit' => self::inventory_limit(),
			'last_analysis'   => isset( $state['last_analysis_at'] ) ? $state['last_analysis_at'] : null,
			'agent_interface' => array(
				'rest'          => rest_url( 'semantic-seo/v1' ),
				'abilities_api' => Environment::abilities_available(),
				'mcp_adapter'   => Environment::mcp_adapter_available(),
				'mcp_enabled'   => (bool) Settings::get( 'mcp_enabled', false ),
			),
			'diagnostics'     => array(
				'wordpress'           => $env['wordpress'],
				'php'                 => $env['php'],
				'db_schema'           => $env['db_schema'],
				'seo_primary'         => $env['seo_primary'],
				'language_framework'  => $env['language_framework'],
				'translation_mapping' => $env['translation_mapping'],
				'wp_cron_disabled'    => $env['wp_cron_disabled'],
			),
		);
	}

	/**
	 * Whether the current user may see non-public documents.
	 *
	 * @return bool
	 */
	private static function can_see_private() {
		return current_user_can( 'edit_others_posts' ) && ! AuthContext::is_agent_context();
	}

	/**
	 * Paginated inventory (semantic-seo/list-urls).
	 *
	 * @param array $args locale, type, status, search, intent, role, orphan, has_qa, has_risk, limit, cursor, sort, order.
	 * @return array|\WP_Error
	 */
	public static function list_urls( array $args ) {
		$g = Guard::check( Capabilities::READ, 'read', 'list_urls' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$limit  = Util::clamp_int( isset( $args['limit'] ) ? $args['limit'] : 25, 1, 100 );
		$cursor = Util::decode_cursor( isset( $args['cursor'] ) ? $args['cursor'] : '' );
		$page   = isset( $cursor['p'] ) ? max( 1, (int) $cursor['p'] ) : max( 1, isset( $args['page'] ) ? (int) $args['page'] : 1 );
		$f      = array(
			'locale'   => isset( $args['locale'] ) ? sanitize_key( $args['locale'] ) : '',
			'subtype'  => isset( $args['type'] ) ? sanitize_key( $args['type'] ) : '',
			'status'   => isset( $args['status'] ) ? sanitize_key( $args['status'] ) : '',
			'search'   => isset( $args['search'] ) ? Util::truncate( sanitize_text_field( $args['search'] ), 100 ) : '',
			'intent'   => isset( $args['intent'] ) ? sanitize_key( $args['intent'] ) : '',
			'role'     => isset( $args['role'] ) ? sanitize_key( $args['role'] ) : '',
			'review'   => isset( $args['review'] ) ? sanitize_key( $args['review'] ) : '',
			'orphan'   => ! empty( $args['orphan'] ),
			'has_qa'   => ! empty( $args['has_qa'] ),
			'has_risk' => ! empty( $args['has_risk'] ),
			'sort'     => isset( $args['sort'] ) ? sanitize_key( $args['sort'] ) : 'id',
			'order'    => isset( $args['order'] ) ? sanitize_key( $args['order'] ) : 'asc',
			'page'     => $page,
			'per_page' => $limit,
		);
		if ( ! self::can_see_private() ) {
			$f['status'] = 'publish';
		}
		$res = MatrixQuery::query( $f );
		return array(
			'items'       => $res['items'],
			'total'       => $res['total'],
			'page'        => $res['page'],
			'pages'       => $res['pages'],
			'next_cursor' => $res['page'] < $res['pages'] ? Util::encode_cursor( array( 'p' => $res['page'] + 1 ) ) : null,
			'provenance'  => array(
				'source'   => 'WordPress database inventory (authenticated), not a public crawl',
				'coverage' => LinkGraph::coverage(),
			),
		);
	}

	/**
	 * Resolves a document from post_id / document_id / url.
	 *
	 * @param array $args Args.
	 * @return array|\WP_Error Document row.
	 */
	private static function resolve_document( array $args ) {
		$doc = null;
		if ( ! empty( $args['document_id'] ) ) {
			$doc = DocumentRepository::get( (int) $args['document_id'] );
		} elseif ( ! empty( $args['post_id'] ) ) {
			$doc = DocumentRepository::by_object( 'post', (int) $args['post_id'] );
		} elseif ( ! empty( $args['url'] ) ) {
			$url = UrlGuard::normalize( (string) $args['url'] );
			if ( '' === $url || ! UrlGuard::is_internal( $url ) ) {
				return new \WP_Error( 'sseo_invalid_url', __( 'Only URLs of this site are accepted.', 'semantic-seo-engine-vi-en' ), array( 'status' => 400 ) );
			}
			$doc = DocumentRepository::by_url( $url );
		} else {
			return new \WP_Error( 'sseo_missing_target', __( 'Provide post_id, document_id or url.', 'semantic-seo-engine-vi-en' ), array( 'status' => 400 ) );
		}
		if ( null === $doc || 'deleted' === $doc['status'] ) {
			return new \WP_Error( 'sseo_not_found', __( 'This URL is not in the inventory (run an inventory first).', 'semantic-seo-engine-vi-en' ), array( 'status' => 404 ) );
		}
		if ( 'publish' !== $doc['status'] && ! ( self::can_see_private() && ( 'post' !== $doc['object_type'] || current_user_can( 'edit_post', (int) $doc['object_id'] ) ) ) ) {
			return new \WP_Error( 'sseo_not_found', __( 'This URL is not in the inventory (run an inventory first).', 'semantic-seo-engine-vi-en' ), array( 'status' => 404 ) );
		}
		return $doc;
	}

	/**
	 * URL analysis with facts and inferences separated (semantic-seo/get-url-analysis).
	 *
	 * @param array $args post_id | document_id | url.
	 * @return array|\WP_Error
	 */
	public static function url_analysis( array $args ) {
		$g = Guard::check( Capabilities::READ, 'read', 'url_analysis' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$doc = self::resolve_document( $args );
		if ( is_wp_error( $doc ) ) {
			return $doc;
		}
		$s        = DocumentRepository::signals( $doc );
		$id       = (int) $doc['id'];
		$profile  = ModelProfile::active();
		$vec      = EmbeddingRepository::summary_vectors( array( $id ), $profile['model_key'] );
		$qa       = FindingRepository::query(
			array(
				'type'        => 'qa',
				'status'      => 'open',
				'document_id' => $id,
				'source_only' => true,
				'limit'       => 50,
				'order'       => 'severity',
			)
		);
		$links    = FindingRepository::query(
			array(
				'type'        => 'link',
				'document_id' => $id,
				'source_only' => true,
				'limit'       => 10,
			)
		);
		$cannibal = FindingRepository::query(
			array(
				'type'        => 'cannibalization',
				'status'      => 'open',
				'document_id' => $id,
				'limit'       => 20,
			)
		);
		$cluster  = (int) $doc['cluster_id'] > 0 ? Db::row( 'SELECT id, label, role, locale, recommended_pillar_id, locked, review_state FROM {clusters} WHERE id = %d', array( (int) $doc['cluster_id'] ) ) : null;
		$related  = Db::rows( 'SELECT r.related_id, r.score, r.rank_pos, d.title, d.url FROM {related} r JOIN {documents} d ON d.id = r.related_id WHERE r.document_id = %d ORDER BY r.rank_pos ASC', array( $id ) );
		$out      = Db::rows( 'SELECT target_url, anchor, resolution, target_id, http_status FROM {links} WHERE source_id = %d LIMIT 100', array( $id ) );
		$in       = Db::rows( 'SELECT l.source_id, d.title, d.url, l.anchor FROM {links} l JOIN {documents} d ON d.id = l.source_id WHERE l.target_id = %d AND l.resolution = %s LIMIT 100', array( $id, 'resolved' ) );
		return array(
			'document'         => array(
				'id'                 => $id,
				'object_type'        => $doc['object_type'],
				'object_id'          => (int) $doc['object_id'],
				'subtype'            => $doc['subtype'],
				'url'                => $doc['url'],
				'title'              => $doc['title'],
				'status'             => $doc['status'],
				'locale'             => $doc['locale'],
				'locale_source'      => $doc['locale_source'],
				'canonical'          => $doc['canonical'],
				'robots'             => $doc['robots'],
				'indexable'          => (bool) $doc['indexable'],
				'indexability_notes' => isset( $s['indexability'] ) ? $s['indexability'] : array(),
				'seo_source'         => $doc['seo_source'],
				'focus_keyword'      => array(
					'value'  => $doc['focus_keyword'],
					'source' => '' === $doc['focus_keyword'] ? 'none set' : $doc['seo_source'] . ' focus keyword field',
				),
				'translation_group'  => $doc['translation_group'],
				'translations'       => isset( $s['translations'] ) ? $s['translations'] : array(),
				'excluded'           => (bool) $doc['excluded'],
				'locked'             => LockService::is_locked( $doc['object_type'], (int) $doc['object_id'], $doc['url'] ),
				'review_state'       => $doc['review_state'],
			),
			'facts'            => array(
				'word_count'         => (int) $doc['word_count'],
				'headings'           => isset( $s['headings'] ) ? $s['headings'] : array(),
				'outbound_internal'  => (int) $doc['outbound_count'],
				'inbound_internal'   => (int) $doc['inbound_count'],
				'links_out'          => $out,
				'links_in'           => $in,
				'images'             => isset( $s['images'] ) ? (int) $s['images'] : 0,
				'images_missing_alt' => isset( $s['images_no_alt'] ) ? (int) $s['images_no_alt'] : 0,
				'jsonld_in_content'  => isset( $s['jsonld_in_content'] ) ? (int) $s['jsonld_in_content'] : 0,
				'attributes'         => isset( $s['attributes'] ) ? $s['attributes'] : array(),
				'link_coverage_note' => 'Inbound counts include only links observed in post content and term descriptions.',
			),
			'inferences'       => array(
				'intent'            => array(
					'label'      => '' !== $doc['editorial_intent'] ? $doc['editorial_intent'] : $doc['intent'],
					'source'     => '' !== $doc['editorial_intent'] ? 'editorial override' : 'heuristic cue matching',
					'confidence' => '' !== $doc['editorial_intent'] ? 'editorial' : $doc['intent_confidence'],
					'evidence'   => isset( $s['intent'] ) ? $s['intent'] : array(),
				),
				'topics'            => isset( $s['top_terms'] ) ? array_map(
					static function ( $t ) {
						return str_replace( '_', ' ', (string) $t );
					},
					array_keys( (array) $s['top_terms'] )
				) : array(),
				'entities'          => array(
					'method' => 'capitalization/unit patterns; not verified against a knowledge base',
					'items'  => isset( $s['entities'] ) ? $s['entities'] : array(),
				),
				'detected_language' => isset( $s['detected_lang'] ) ? $s['detected_lang'] : array(),
			),
			'semantic'         => array(
				'model_key'  => $profile['model_key'],
				'has_vector' => isset( $vec[ $id ] ),
				'note'       => isset( $vec[ $id ] ) ? 'Semantic similarity is one evidence component among others.' : 'No vector for the active model profile: lexical evidence only.',
			),
			'cluster'          => $cluster,
			'cluster_role'     => $doc['cluster_role'],
			'qa_issues'        => $qa['items'],
			'link_suggestions' => $links['items'],
			'overlap_risks'    => $cannibal['items'],
			'related'          => $related,
			'provenance'       => array(
				'scanned_at'        => $doc['scanned_at'],
				'indexed_at'        => $doc['indexed_at'],
				'analyzed_at'       => $doc['analyzed_at'],
				'content_hash'      => $doc['content_hash'],
				'algorithm_version' => $doc['algorithm_version'],
				'versions'          => isset( $s['versions'] ) ? $s['versions'] : array(),
			),
		);
	}

	/**
	 * Generic findings listing with optional document scope.
	 *
	 * @param string $type Type.
	 * @param array  $args Args.
	 * @param string $action Audit action.
	 * @return array|\WP_Error
	 */
	private static function findings( $type, array $args, $action ) {
		$g = Guard::check( Capabilities::READ, 'read', $action );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$limit  = Util::clamp_int( isset( $args['limit'] ) ? $args['limit'] : 20, 1, 100 );
		$cursor = Util::decode_cursor( isset( $args['cursor'] ) ? $args['cursor'] : '' );
		$offset = isset( $cursor['o'] ) ? max( 0, (int) $cursor['o'] ) : 0;
		$f      = array(
			'type'   => $type,
			'status' => isset( $args['status'] ) && in_array( $args['status'], FindingRepository::STATUSES, true ) ? $args['status'] : 'open',
			'limit'  => $limit,
			'offset' => $offset,
		);
		foreach ( array( 'locale', 'label', 'severity', 'confidence', 'group_key', 'check_id' ) as $k ) {
			if ( ! empty( $args[ $k ] ) ) {
				$f[ $k ] = sanitize_text_field( (string) $args[ $k ] );
			}
		}
		if ( isset( $args['min_score'] ) && is_numeric( $args['min_score'] ) ) {
			$f['min_score'] = Util::clamp_float( $args['min_score'], 0, 1 );
		}
		if ( ! empty( $args['post_id'] ) || ! empty( $args['url'] ) || ! empty( $args['document_id'] ) ) {
			$doc = self::resolve_document( $args );
			if ( is_wp_error( $doc ) ) {
				return $doc;
			}
			$f['document_id'] = (int) $doc['id'];
			if ( 'link' === $type ) {
				$f['source_only'] = true;
			}
		}
		if ( 'qa' === $type ) {
			$f['order'] = 'severity';
		} elseif ( 'cannibalization' === $type ) {
			$f['order'] = 'tier';
		}
		$res = FindingRepository::query( $f );
		return array(
			'items'       => $res['items'],
			'total'       => $res['total'],
			'next_cursor' => $offset + $limit < $res['total'] ? Util::encode_cursor( array( 'o' => $offset + $limit ) ) : null,
		);
	}

	/**
	 * Internal link suggestions (semantic-seo/get-link-suggestions).
	 *
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function link_suggestions( array $args ) {
		if ( isset( $args['min_relevance'] ) ) {
			$args['min_score'] = $args['min_relevance'];
		}
		$res = self::findings( 'link', $args, 'link_suggestions' );
		if ( ! is_wp_error( $res ) ) {
			$res['orphans']  = LinkGraph::orphans( isset( $args['locale'] ) ? sanitize_key( $args['locale'] ) : '', 20 );
			$res['coverage'] = LinkGraph::coverage();
			$res['note']     = 'Suggestions only. Nothing is inserted until a human approves an exact diff.';
		}
		return $res;
	}

	/**
	 * Cannibalization risk report (semantic-seo/get-cannibalization-report).
	 *
	 * @param array $args Args (locale, category, cluster, limit, cursor).
	 * @return array|\WP_Error
	 */
	public static function cannibalization( array $args ) {
		if ( ! empty( $args['category'] ) ) {
			$args['check_id'] = $args['category'];
		}
		$res = self::findings( 'cannibalization', $args, 'cannibalization' );
		if ( ! is_wp_error( $res ) ) {
			$res['note'] = 'Risk hypotheses from same-locale comparisons. Without imported Search Console data they do not prove ranking competition. Translations in other locales are never compared.';
		}
		return $res;
	}

	/**
	 * QA issues (semantic-seo/get-qa-issues).
	 *
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function qa_issues( array $args ) {
		$res = self::findings( 'qa', $args, 'qa_issues' );
		if ( ! is_wp_error( $res ) ) {
			$res['labels'] = array(
				'verified_error' => 'objectively checked fact',
				'warning'        => 'rule-based issue needing human judgement',
				'suggestion'     => 'heuristic hint',
			);
		}
		return $res;
	}

	/**
	 * Classification suggestions.
	 *
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function classification( array $args ) {
		return self::findings( 'classification', $args, 'classification' );
	}

	/**
	 * Topic clusters (semantic-seo/get-topic-clusters).
	 *
	 * @param array $args locale, cluster_id.
	 * @return array|\WP_Error
	 */
	public static function clusters( array $args ) {
		$g = Guard::check( Capabilities::READ, 'read', 'clusters' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$where = array( '1=1' );
		$a     = array();
		if ( ! empty( $args['locale'] ) ) {
			$where[] = 'locale = %s';
			$a[]     = sanitize_key( $args['locale'] );
		}
		if ( ! empty( $args['cluster_id'] ) ) {
			$where[] = 'id = %d';
			$a[]     = (int) $args['cluster_id'];
		}
		$rows = Db::rows( 'SELECT * FROM {clusters} WHERE ' . implode( ' AND ', $where ) . ' ORDER BY locale ASC, parent_id ASC, label ASC LIMIT 500', $a );
		$out  = array();
		foreach ( $rows as $c ) {
			$members = Db::rows( "SELECT id, object_id, title, url, cluster_role, cluster_locked, inbound_count FROM {documents} WHERE cluster_id = %d AND status = 'publish' ORDER BY FIELD(cluster_role,'pillar','hub','silo','supporting'), inbound_count DESC LIMIT 200", array( (int) $c['id'] ) );
			$out[]   = array(
				'id'                    => (int) $c['id'],
				'locale'                => $c['locale'],
				'parent_id'             => (int) $c['parent_id'],
				'label'                 => $c['label'],
				'role'                  => $c['role'],
				'source'                => $c['source'],
				'taxonomy'              => $c['taxonomy'],
				'term_id'               => (int) $c['term_id'],
				'recommended_pillar_id' => (int) $c['recommended_pillar_id'],
				'locked'                => (bool) $c['locked'] || LockService::is_cluster_locked( (int) $c['id'] ),
				'review_state'          => $c['review_state'],
				'version'               => (int) $c['version'],
				'member_count'          => (int) $c['member_count'],
				'members'               => $members,
				'evidence'              => Util::json_array( $c['evidence'] ),
			);
		}
		$unclustered = (int) Db::value( "SELECT COUNT(*) FROM {documents} WHERE object_type = 'post' AND status = 'publish' AND cluster_id = 0" );
		return array(
			'clusters'    => $out,
			'unclustered' => $unclustered,
			'note'        => 'Pillar/Hub/Silo/Supporting labels are editorial recommendations built from existing categories; no category is created or changed without an approved diff.',
		);
	}

	/**
	 * Related content preview for a post.
	 *
	 * @param array $args post_id.
	 * @return array|\WP_Error
	 */
	public static function related( array $args ) {
		$g = Guard::check( Capabilities::READ, 'read', 'related' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$doc = self::resolve_document( $args );
		if ( is_wp_error( $doc ) ) {
			return $doc;
		}
		return array(
			'document_id'      => (int) $doc['id'],
			'items'            => Db::rows( 'SELECT r.related_id, r.rank_pos, r.score, r.evidence, d.title, d.url, d.object_id FROM {related} r JOIN {documents} d ON d.id = r.related_id WHERE r.document_id = %d ORDER BY r.rank_pos ASC', array( (int) $doc['id'] ) ),
			'frontend_enabled' => (bool) Settings::get( 'related_enabled', false ),
		);
	}

	/**
	 * Job status (semantic-seo/get-job-status).
	 *
	 * @param int $job_id Job ID.
	 * @return array|\WP_Error
	 */
	public static function job_status( $job_id ) {
		$g = Guard::check( Capabilities::READ, 'read', 'job_status' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$job = JobRepository::get( (int) $job_id );
		if ( null === $job ) {
			return new \WP_Error( 'sseo_not_found', __( 'Job not found.', 'semantic-seo-engine-vi-en' ), array( 'status' => 404 ) );
		}
		$out = JobRepository::to_public( $job );
		if ( 'embed' === $job['type'] ) {
			$out['queue'] = EmbeddingQueue::counts( (int) $job['id'] );
		}
		return $out;
	}

	/**
	 * Job list.
	 *
	 * @param array $args status, type, limit.
	 * @return array|\WP_Error
	 */
	public static function jobs( array $args ) {
		$g = Guard::check( Capabilities::READ, 'read', 'jobs' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		return array( 'items' => array_map( array( JobRepository::class, 'to_public' ), JobRepository::find( $args ) ) );
	}

	/**
	 * Starts a bounded, idempotent job (semantic-seo/start-analysis).
	 *
	 * @param array $args type, post_ids, locale, then_analyze, idempotency_key.
	 * @return array|\WP_Error
	 */
	public static function start_analysis( array $args ) {
		$g = Guard::check( Capabilities::ANALYZE, 'analyze', 'start_analysis' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$type = isset( $args['type'] ) ? (string) $args['type'] : 'analyze';
		if ( ! in_array( $type, array( 'inventory', 'analyze', 'embed', 'verify_links' ), true ) ) {
			return new \WP_Error( 'sseo_invalid_job_type', __( 'Unknown job type.', 'semantic-seo-engine-vi-en' ), array( 'status' => 400 ) );
		}
		$params = array();
		if ( ! empty( $args['post_ids'] ) ) {
			$ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $args['post_ids'] ) ) ) );
			if ( count( $ids ) > self::MAX_TARGETS ) {
				return new \WP_Error( 'sseo_too_many_targets', sprintf( /* translators: %d: max */ __( 'At most %d targets per request.', 'semantic-seo-engine-vi-en' ), self::MAX_TARGETS ), array( 'status' => 400 ) );
			}
			if ( 'inventory' === $type ) {
				$params['post_ids'] = $ids;
			} else {
				$params['doc_ids'] = array_map( 'intval', Db::col( "SELECT id FROM {documents} WHERE object_type = 'post' AND object_id IN (" . Db::in( $ids ) . ')', $ids ) );
				if ( array() === $params['doc_ids'] ) {
					return new \WP_Error( 'sseo_not_found', __( 'None of these posts is in the inventory yet; run an inventory first.', 'semantic-seo-engine-vi-en' ), array( 'status' => 404 ) );
				}
			}
		}
		if ( ! empty( $args['locale'] ) ) {
			$params['locale'] = sanitize_key( $args['locale'] );
		}
		if ( ! empty( $args['then_analyze'] ) ) {
			$params['then_analyze'] = true;
		}
		$idempotency_key = isset( $args['idempotency_key'] ) ? substr( (string) $args['idempotency_key'], 0, 64 ) : '';
		// A retried request returns the original job, before any quota applies.
		$prior = JobRepository::by_idempotency_key( $type, get_current_user_id(), $idempotency_key );
		if ( null !== $prior ) {
			return JobRepository::to_public( $prior );
		}
		if ( AuthContext::is_agent_context() && JobRepository::active_count_for( get_current_user_id() ) >= 1 ) {
			return new \WP_Error( 'sseo_quota', __( 'Agents may run one job at a time.', 'semantic-seo-engine-vi-en' ), array( 'status' => 429 ) );
		}
		$job = JobRepository::create( $type, $params, get_current_user_id(), AuthContext::via(), $idempotency_key );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		Plugin::ensure_maintenance();
		JobRunner::schedule();
		AuditLog::record(
			'job.started',
			'job',
			(int) $job['id'],
			'ok',
			array(
				'type'   => $type,
				'params' => array_keys( $params ),
				'reused' => ! empty( $job['reused'] ),
			)
		);
		return JobRepository::to_public( $job );
	}

	/**
	 * Cancels a job (semantic-seo/cancel-analysis).
	 *
	 * @param int $job_id Job ID.
	 * @return array|\WP_Error
	 */
	public static function cancel_job( $job_id ) {
		return self::job_control( $job_id, 'cancel' );
	}

	/**
	 * Pause/resume/cancel/tick a job.
	 *
	 * @param int    $job_id Job ID.
	 * @param string $op     cancel|pause|resume|tick.
	 * @return array|\WP_Error
	 */
	public static function job_control( $job_id, $op ) {
		$g = Guard::check( Capabilities::ANALYZE, 'analyze', 'job_' . $op );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$job = JobRepository::get( (int) $job_id );
		if ( null === $job ) {
			return new \WP_Error( 'sseo_not_found', __( 'Job not found.', 'semantic-seo-engine-vi-en' ), array( 'status' => 404 ) );
		}
		if ( AuthContext::is_agent_context() && get_current_user_id() !== (int) $job['owner_id'] ) {
			return new \WP_Error( 'sseo_forbidden', __( 'Agents can only control their own jobs.', 'semantic-seo-engine-vi-en' ), array( 'status' => 403 ) );
		}
		$ok = true;
		switch ( $op ) {
			case 'cancel':
				$ok = JobRepository::cancel( (int) $job_id );
				break;
			case 'pause':
				$ok = JobRepository::pause( (int) $job_id );
				break;
			case 'resume':
				$ok = JobRepository::resume( (int) $job_id );
				JobRunner::schedule();
				break;
			case 'tick':
				$res         = JobRunner::tick( min( 15, (int) Settings::get( 'time_budget', 20 ) ), (int) $job_id );
				$out         = JobRepository::to_public( JobRepository::get( (int) $job_id ) );
				$out['tick'] = $res;
				return $out;
		}
		AuditLog::record( 'job.' . $op, 'job', (int) $job_id, $ok ? 'ok' : 'noop' );
		$out            = JobRepository::to_public( JobRepository::get( (int) $job_id ) );
		$out['changed'] = $ok;
		return $out;
	}

	/**
	 * Rebuild derived index data (semantic-seo/rebuild-index). Never touches posts.
	 *
	 * @param array $args scope (lexical|embeddings), confirm (true).
	 * @return array|\WP_Error
	 */
	public static function rebuild_index( array $args ) {
		$g = Guard::check( Capabilities::ANALYZE, 'analyze', 'rebuild_index' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		if ( empty( $args['confirm'] ) ) {
			return new \WP_Error( 'sseo_confirmation_required', __( 'Set confirm=true to rebuild. Derived data is recomputed in the background; WordPress content is not modified.', 'semantic-seo-engine-vi-en' ), array( 'status' => 400 ) );
		}
		$scope = isset( $args['scope'] ) && 'embeddings' === $args['scope'] ? 'embeddings' : 'lexical';
		if ( isset( $args['model_version'] ) && '' !== (string) $args['model_version'] && ModelProfile::active()['model_key'] !== (string) $args['model_version'] && ModelProfile::REVISION !== (string) $args['model_version'] ) {
			return new \WP_Error( 'sseo_model_mismatch', __( 'model_version must match the active pinned profile (model key or revision).', 'semantic-seo-engine-vi-en' ), array( 'status' => 409 ) );
		}
		if ( 'lexical' === $scope ) {
			DocumentRepository::mark_all_dirty();
			$job = JobRepository::create( 'incremental', array(), get_current_user_id(), AuthContext::via(), isset( $args['idempotency_key'] ) ? (string) $args['idempotency_key'] : '' );
		} else {
			$job = JobRepository::create( 'embed', array(), get_current_user_id(), AuthContext::via(), isset( $args['idempotency_key'] ) ? (string) $args['idempotency_key'] : '' );
		}
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		JobRunner::schedule();
		AuditLog::record( 'index.rebuild', 'job', (int) $job['id'], 'ok', array( 'scope' => $scope ) );
		return JobRepository::to_public( $job );
	}

	/**
	 * Creates change previews from findings (semantic-seo/create-change-preview).
	 *
	 * @param array $args finding_ids (≤ 10).
	 * @return array|\WP_Error
	 */
	public static function create_change_preview( array $args ) {
		$g = Guard::check( Capabilities::ANALYZE, 'write', 'create_change_preview' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$ids = array_values( array_unique( array_filter( array_map( 'intval', isset( $args['finding_ids'] ) ? (array) $args['finding_ids'] : array() ) ) ) );
		if ( array() === $ids || count( $ids ) > 10 ) {
			return new \WP_Error( 'sseo_invalid_targets', __( 'Provide 1–10 finding IDs.', 'semantic-seo-engine-vi-en' ), array( 'status' => 400 ) );
		}
		$out = array();
		foreach ( $ids as $id ) {
			$p     = ProposalService::from_finding( $id );
			$out[] = is_wp_error( $p )
				? array(
					'finding_id' => $id,
					'error'      => $p->get_error_code(),
					'message'    => $p->get_error_message(),
				)
				: array(
					'finding_id' => $id,
					'proposal'   => self::present_proposal( $p ),
				);
		}
		return array(
			'previews'   => $out,
			'next_step'  => 'A human administrator must review and approve each proposal in WP Admin → Semantic SEO → Audit / Undo / Rollback.',
			'guardrails' => array( 'single object per proposal', 'exact byte offset + fingerprint', 'base content hash', 'no headings/slug/canonical/robots/redirect changes', 'internal URLs only' ),
		);
	}

	/**
	 * Proposal presentation (no internal secrets).
	 *
	 * @param array $p Proposal.
	 * @return array
	 */
	public static function present_proposal( array $p ) {
		return array(
			'id'            => (int) $p['id'],
			'type'          => $p['type'],
			'status'        => $p['status'],
			'object_type'   => $p['object_type'],
			'object_id'     => (int) $p['object_id'],
			'diff'          => $p['diff'],
			'diff_hash'     => $p['diff_hash'],
			'base_hash'     => $p['base_hash'],
			'expected_hash' => $p['expected_hash'],
			'rationale'     => $p['rationale'],
			'created_by'    => (int) $p['created_by'],
			'created_via'   => $p['created_via'],
			'created_at'    => $p['created_at'],
		);
	}

	/**
	 * Proposal list.
	 *
	 * @param array $args status, limit, offset.
	 * @return array|\WP_Error
	 */
	public static function proposals( array $args ) {
		$g = Guard::check( Capabilities::READ, 'read', 'proposals' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$res = ProposalService::query( $args );
		return array(
			'items' => array_map( array( __CLASS__, 'present_proposal' ), $res['items'] ),
			'total' => $res['total'],
		);
	}

	/**
	 * Applies an approved change (semantic-seo/apply-approved-change).
	 *
	 * @param array $args proposal_id, approval_id, idempotency_key.
	 * @return array|\WP_Error
	 */
	public static function apply_approved_change( array $args ) {
		$g = Guard::check( Capabilities::APPLY, 'write', 'apply' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		return ChangeExecutor::apply(
			isset( $args['proposal_id'] ) ? (int) $args['proposal_id'] : 0,
			isset( $args['approval_id'] ) ? (int) $args['approval_id'] : 0,
			isset( $args['idempotency_key'] ) ? (string) $args['idempotency_key'] : ''
		);
	}

	/**
	 * Rolls back a change (semantic-seo/rollback-change).
	 *
	 * @param array $args change_id, approval_id.
	 * @return array|\WP_Error
	 */
	public static function rollback_change( array $args ) {
		$g = Guard::check( Capabilities::APPLY, 'write', 'rollback' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		return RollbackService::rollback( isset( $args['change_id'] ) ? (int) $args['change_id'] : 0, isset( $args['approval_id'] ) ? (int) $args['approval_id'] : 0 );
	}

	/**
	 * Changes list.
	 *
	 * @param array $args status, object_id, limit.
	 * @return array|\WP_Error
	 */
	public static function changes( array $args ) {
		$g = Guard::check( Capabilities::READ, 'read', 'changes' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		return array( 'items' => ChangeExecutor::query( $args ) );
	}

	/**
	 * Audit log (semantic-seo/get-audit-log). Agents only see their own entries.
	 *
	 * @param array $args action, result, object_id, before_id, limit.
	 * @return array|\WP_Error
	 */
	public static function audit_log( array $args ) {
		$g = Guard::check( Capabilities::READ, 'read', 'audit_log' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			$args['user_id'] = get_current_user_id();
		}
		return AuditLog::query( $args );
	}

	/**
	 * Embedding profile for workers.
	 *
	 * @return array|\WP_Error
	 */
	public static function embedding_profile() {
		$g = Guard::check( Capabilities::EMBED, 'embed', 'embedding_profile' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$profile = ModelProfile::active();
		return array(
			'profile'      => EmbeddingQueue::public_profile( $profile ),
			// Model-card similarities: every worker must reproduce them, checked by the server, before uploading vectors.
			'reference'    => $profile['reference'],
			'verification' => DeviceVerification::status( get_current_user_id(), $profile['model_key'] ),
			'queue'        => EmbeddingQueue::counts(),
		);
	}

	/**
	 * Server-side check of a device's self-test vectors.
	 *
	 * @param array $args model_key, queries, documents, source.
	 * @return array|\WP_Error
	 */
	public static function embedding_selftest( array $args ) {
		$g = Guard::check( Capabilities::EMBED, 'embed', 'embedding_selftest' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		return DeviceVerification::verify(
			get_current_user_id(),
			isset( $args['model_key'] ) ? (string) $args['model_key'] : '',
			isset( $args['queries'] ) && is_array( $args['queries'] ) ? $args['queries'] : array(),
			isset( $args['documents'] ) && is_array( $args['documents'] ) ? $args['documents'] : array(),
			isset( $args['source'] ) && 'worker' === $args['source'] ? 'worker' : 'browser'
		);
	}

	/**
	 * Claims embedding work.
	 *
	 * @param array $args limit.
	 * @return array|\WP_Error
	 */
	public static function embedding_claim( array $args ) {
		$g = Guard::check( Capabilities::EMBED, 'embed', 'embedding_claim' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		return EmbeddingQueue::claim( get_current_user_id(), isset( $args['limit'] ) ? (int) $args['limit'] : 8 );
	}

	/**
	 * Completes embedding work.
	 *
	 * @param array $args lease_token, model_key, results, source.
	 * @return array|\WP_Error
	 */
	public static function embedding_complete( array $args ) {
		$g = Guard::check( Capabilities::EMBED, 'embed', 'embedding_complete' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$source = isset( $args['source'] ) && 'worker' === $args['source'] ? 'worker' : 'browser';
		$res    = EmbeddingQueue::complete( get_current_user_id(), isset( $args['lease_token'] ) ? (string) $args['lease_token'] : '', isset( $args['model_key'] ) ? (string) $args['model_key'] : '', isset( $args['results'] ) && is_array( $args['results'] ) ? $args['results'] : array(), $source );
		if ( ! is_wp_error( $res ) && $res['stored'] > 0 ) {
			AuditLog::record(
				'embedding.stored',
				'',
				0,
				'ok',
				array(
					'stored'   => $res['stored'],
					'rejected' => count( $res['rejected'] ),
					'source'   => $source,
				)
			);
		}
		return $res;
	}

	/**
	 * Releases a lease.
	 *
	 * @param array $args lease_token, error.
	 * @return array|\WP_Error
	 */
	public static function embedding_release( array $args ) {
		$g = Guard::check( Capabilities::EMBED, 'embed', 'embedding_release' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$error = isset( $args['error'] ) ? sanitize_text_field( (string) $args['error'] ) : '';
		if ( ! empty( $args['all_mine'] ) ) {
			return array( 'released' => EmbeddingQueue::release_all_for_user( get_current_user_id(), $error ) );
		}
		if ( empty( $args['lease_token'] ) ) {
			return new \WP_Error( 'sseo_invalid', __( 'Provide lease_token or all_mine=true.', 'semantic-seo-engine-vi-en' ), array( 'status' => 400 ) );
		}
		return array( 'released' => EmbeddingQueue::release( get_current_user_id(), (string) $args['lease_token'], $error ) );
	}

	/**
	 * Human review of a finding (dismiss/accept/reopen).
	 *
	 * @param int    $id     Finding ID.
	 * @param string $status dismissed|accepted|open.
	 * @return array|\WP_Error
	 */
	public static function review_finding( $id, $status ) {
		$g = Guard::check( Capabilities::REVIEW, 'write', 'review_finding' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$h = Guard::human_only( 'review_finding' );
		if ( is_wp_error( $h ) ) {
			return $h;
		}
		if ( ! in_array( $status, array( 'dismissed', 'accepted', 'open' ), true ) || ! FindingRepository::set_status( (int) $id, $status ) ) {
			return new \WP_Error( 'sseo_invalid', __( 'Invalid finding or status.', 'semantic-seo-engine-vi-en' ), array( 'status' => 400 ) );
		}
		AuditLog::record( 'finding.' . $status, 'finding', (int) $id );
		return array(
			'id'     => (int) $id,
			'status' => $status,
		);
	}

	/**
	 * Editorial overrides on a document (plugin data only).
	 *
	 * @param int   $doc_id Document ID.
	 * @param array $fields editorial_intent, review_state, cluster_role, cluster_locked, cluster_id.
	 * @return array|\WP_Error
	 */
	public static function set_editorial( $doc_id, array $fields ) {
		$g = Guard::check( Capabilities::REVIEW, 'write', 'set_editorial' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$h = Guard::human_only( 'set_editorial' );
		if ( is_wp_error( $h ) ) {
			return $h;
		}
		$doc = DocumentRepository::get( (int) $doc_id );
		if ( null === $doc ) {
			return new \WP_Error( 'sseo_not_found', __( 'Document not found.', 'semantic-seo-engine-vi-en' ), array( 'status' => 404 ) );
		}
		$update = array();
		if ( isset( $fields['editorial_intent'] ) && in_array( $fields['editorial_intent'], array( '', 'informational', 'transactional', 'commercial', 'navigational' ), true ) ) {
			$update['editorial_intent'] = $fields['editorial_intent'];
		}
		if ( isset( $fields['review_state'] ) && in_array( $fields['review_state'], array( 'unreviewed', 'needs_review', 'reviewed', 'approved_plan' ), true ) ) {
			$update['review_state'] = $fields['review_state'];
		}
		if ( isset( $fields['cluster_role'] ) && in_array( $fields['cluster_role'], array( '', 'pillar', 'hub', 'silo', 'supporting' ), true ) ) {
			$update['cluster_role'] = $fields['cluster_role'];
		}
		if ( isset( $fields['cluster_id'] ) ) {
			$cid = (int) $fields['cluster_id'];
			if ( 0 === $cid || null !== Db::value( 'SELECT id FROM {clusters} WHERE id = %d AND locale = %s', array( $cid, $doc['locale'] ) ) ) {
				$update['cluster_id'] = $cid;
			}
		}
		if ( isset( $fields['cluster_locked'] ) ) {
			$update['cluster_locked'] = $fields['cluster_locked'] ? 1 : 0;
		}
		if ( array() === $update ) {
			return new \WP_Error( 'sseo_invalid', __( 'No valid fields.', 'semantic-seo-engine-vi-en' ), array( 'status' => 400 ) );
		}
		DocumentRepository::update( (int) $doc_id, $update );
		if ( isset( $update['cluster_id'] ) || isset( $update['cluster_role'] ) ) {
			Db::query( 'UPDATE {clusters} SET version = version + 1, updated_at = %s WHERE id IN (%d, %d)', array( Util::now(), (int) $doc['cluster_id'], isset( $update['cluster_id'] ) ? (int) $update['cluster_id'] : (int) $doc['cluster_id'] ) );
		}
		AuditLog::record( 'document.editorial', $doc['object_type'], (int) $doc['object_id'], 'ok', array( 'fields' => $update ) );
		return array(
			'document_id' => (int) $doc_id,
			'updated'     => $update,
		);
	}

	/**
	 * Adds a lock or exclusion (human only).
	 *
	 * @param string $scope  Scope.
	 * @param string $value  Value.
	 * @param string $type   lock|exclude.
	 * @param string $reason Reason.
	 * @return array|\WP_Error
	 */
	public static function add_lock( $scope, $value, $type, $reason ) {
		$g = Guard::check( Capabilities::REVIEW, 'write', 'add_lock' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$h = Guard::human_only( 'add_lock' );
		if ( is_wp_error( $h ) ) {
			return $h;
		}
		$id = LockService::add( $scope, $value, $type, $reason );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( 'document' === $scope && 'exclude' === $type ) {
			list( $ot, $oid ) = explode( ':', $value );
			Db::query( 'UPDATE {documents} SET excluded = 1 WHERE object_type = %s AND object_id = %d', array( $ot, (int) $oid ) );
		}
		AuditLog::record(
			'lock.added',
			$scope,
			0,
			'ok',
			array(
				'value' => $value,
				'type'  => $type,
			)
		);
		return array( 'id' => $id );
	}

	/**
	 * Removes a lock (human only).
	 *
	 * @param int $id Lock ID.
	 * @return array|\WP_Error
	 */
	public static function remove_lock( $id ) {
		$g = Guard::check( Capabilities::REVIEW, 'write', 'remove_lock' );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$h = Guard::human_only( 'remove_lock' );
		if ( is_wp_error( $h ) ) {
			return $h;
		}
		$lock = Db::row( 'SELECT * FROM {locks} WHERE id = %d', array( (int) $id ) );
		LockService::remove( (int) $id );
		if ( null !== $lock && 'document' === $lock['scope_type'] && 'exclude' === $lock['lock_type'] ) {
			list( $ot, $oid ) = explode( ':', $lock['scope_value'] );
			Db::query( 'UPDATE {documents} SET dirty = 1 WHERE object_type = %s AND object_id = %d', array( $ot, (int) $oid ) );
		}
		AuditLog::record( 'lock.removed', '', (int) $id );
		return array( 'removed' => (int) $id );
	}
}
