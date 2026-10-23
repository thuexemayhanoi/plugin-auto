<?php
/**
 * Builds exact, hash-bound change proposals from findings. Creating a proposal changes nothing.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Approval;

use SemanticSEO\Analysis\FindingRepository;
use SemanticSEO\Audit\AuditLog;
use SemanticSEO\Database\Db;
use SemanticSEO\Inventory\DocumentRepository;
use SemanticSEO\Links\AnchorFinder;
use SemanticSEO\Locks\LockService;
use SemanticSEO\Security\AuthContext;
use SemanticSEO\Security\UrlGuard;
use SemanticSEO\Settings;
use SemanticSEO\Support\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Supported reversible operations:
 *  - insert_internal_link: wrap one exact text occurrence in an <a href> to an internal URL.
 *  - assign_category: add one existing term to one post (never creates terms).
 * Everything else (headings, slugs, canonical, robots, redirects, deletions, SEO plugin settings)
 * is out of scope for this plugin and can never be proposed.
 */
final class ProposalService {

	const OPERATIONS = array( 'insert_internal_link', 'assign_category' );

	/**
	 * Creates a proposal (preview) from a finding.
	 *
	 * @param int $finding_id Finding ID.
	 * @return array|\WP_Error Proposal.
	 */
	public static function from_finding( $finding_id ) {
		if ( ! Settings::get( 'allow_proposals', true ) ) {
			return new \WP_Error( 'sseo_proposals_disabled', __( 'Change proposals are disabled in Settings (read-only mode).', 'semantic-seo-engine-vi-en' ), array( 'status' => 403 ) );
		}
		$finding = FindingRepository::get( $finding_id );
		if ( null === $finding ) {
			return new \WP_Error( 'sseo_not_found', __( 'Finding not found.', 'semantic-seo-engine-vi-en' ), array( 'status' => 404 ) );
		}
		if ( 'link' === $finding['type'] ) {
			$proposal = self::build_link_proposal( $finding );
		} elseif ( 'classification' === $finding['type'] ) {
			$proposal = self::build_category_proposal( $finding );
		} else {
			return new \WP_Error( 'sseo_not_actionable', __( 'This finding type cannot be turned into an automatic change; it needs editorial work.', 'semantic-seo-engine-vi-en' ), array( 'status' => 422 ) );
		}
		if ( is_wp_error( $proposal ) ) {
			return $proposal;
		}
		// Reuse an identical open proposal (idempotent preview).
		$existing = Db::row( "SELECT id FROM {proposals} WHERE diff_hash = %s AND status IN ('previewed','approved')", array( $proposal['diff_hash'] ) );
		if ( null !== $existing ) {
			return self::get( (int) $existing['id'] );
		}
		$now = Util::now();
		$id  = Db::insert(
			'proposals',
			array(
				'type'          => $proposal['type'],
				'finding_id'    => (int) $finding['id'],
				'object_type'   => $proposal['object_type'],
				'object_id'     => (int) $proposal['object_id'],
				'payload'       => Util::json( $proposal['payload'] ),
				'diff'          => Util::json( $proposal['diff'] ),
				'diff_hash'     => $proposal['diff_hash'],
				'base_hash'     => $proposal['base_hash'],
				'expected_hash' => $proposal['expected_hash'],
				'status'        => 'previewed',
				'created_by'    => get_current_user_id(),
				'created_via'   => AuthContext::is_agent_context() ? 'agent:' . AuthContext::via() : AuthContext::via(),
				'rationale'     => Util::truncate( (string) $proposal['rationale'], 2000 ),
				'created_at'    => $now,
				'updated_at'    => $now,
			)
		);
		FindingRepository::set_status( (int) $finding['id'], 'proposed' );
		AuditLog::record(
			'proposal.created',
			$proposal['object_type'],
			(int) $proposal['object_id'],
			'ok',
			array(
				'proposal_id' => $id,
				'type'        => $proposal['type'],
				'diff_hash'   => $proposal['diff_hash'],
			)
		);
		return self::get( $id );
	}

	/**
	 * Builds an insert_internal_link proposal against the CURRENT content.
	 *
	 * @param array $finding Finding.
	 * @return array|\WP_Error
	 */
	private static function build_link_proposal( array $finding ) {
		$source = DocumentRepository::get( (int) $finding['document_id'] );
		$target = DocumentRepository::get( (int) $finding['target_id'] );
		if ( null === $source || null === $target || 'post' !== $source['object_type'] ) {
			return new \WP_Error( 'sseo_not_found', __( 'Source or target document no longer exists.', 'semantic-seo-engine-vi-en' ), array( 'status' => 404 ) );
		}
		$guard = self::guardrails( $source );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		if ( 'publish' !== $target['status'] || ! (int) $target['indexable'] || (int) $target['excluded'] ) {
			return new \WP_Error( 'sseo_invalid_target', __( 'The target page is not published, not indexable or excluded.', 'semantic-seo-engine-vi-en' ), array( 'status' => 422 ) );
		}
		$href = 'post' === $target['object_type'] ? (string) get_permalink( (int) $target['object_id'] ) : (string) $target['url'];
		if ( '' === $href || ! UrlGuard::is_internal( $href ) ) {
			return new \WP_Error( 'sseo_invalid_target', __( 'The target URL is not an internal URL of this site.', 'semantic-seo-engine-vi-en' ), array( 'status' => 422 ) );
		}
		$post    = get_post( (int) $source['object_id'] );
		$content = (string) $post->post_content;
		$anchor  = isset( $finding['evidence']['anchor'] ) && is_array( $finding['evidence']['anchor'] ) ? $finding['evidence']['anchor'] : null;
		if ( null === $anchor ) {
			return new \WP_Error( 'sseo_no_anchor', __( 'No natural anchor text was found; this suggestion needs manual editorial insertion.', 'semantic-seo-engine-vi-en' ), array( 'status' => 422 ) );
		}
		// The content may have changed since analysis: re-locate the exact anchor in current content.
		$located = null;
		if ( substr( $content, (int) $anchor['offset'], (int) $anchor['length'] ) === $anchor['text']
			&& AnchorFinder::fingerprint( $content, (int) $anchor['offset'], (int) $anchor['length'] ) === $anchor['fingerprint'] ) {
			$located = $anchor;
		} else {
			$located = AnchorFinder::find(
				$content,
				array(
					array(
						'phrase'  => $anchor['text'],
						'source'  => $anchor['phrase_source'],
						'quality' => $anchor['quality'],
					),
				)
			);
		}
		if ( null === $located ) {
			return new \WP_Error( 'sseo_stale', __( 'The anchor text is no longer present in an editable position; re-run the analysis.', 'semantic-seo-engine-vi-en' ), array( 'status' => 409 ) );
		}
		$base_hash = Util::sha256( $content );
		$insert    = Operations::link_markup( $href, $located['text'] );
		$after     = Operations::insert_link( $content, (int) $located['offset'], (int) $located['length'], $located['text'], $href );
		if ( is_wp_error( $after ) ) {
			return $after;
		}
		$expected = Util::sha256( $after );
		$payload  = array(
			'operation'          => 'insert_internal_link',
			'post_id'            => (int) $source['object_id'],
			'field'              => 'post_content',
			'offset'             => (int) $located['offset'],
			'length'             => (int) $located['length'],
			'anchor'             => $located['text'],
			'href'               => $href,
			'fingerprint'        => AnchorFinder::fingerprint( $content, (int) $located['offset'], (int) $located['length'] ),
			'target_document_id' => (int) $target['id'],
			'source_document_id' => (int) $source['id'],
		);
		$diff     = array(
			'field'     => 'post_content',
			'operation' => 'insert_internal_link',
			'before'    => self::excerpt( $content, (int) $located['offset'], (int) $located['length'] ),
			'after'     => self::excerpt( $after, (int) $located['offset'], strlen( $insert ) ),
			'inserted'  => $insert,
			'offset'    => (int) $located['offset'],
			'unchanged' => array( 'title', 'slug', 'headings', 'canonical', 'robots', 'categories', 'seo_metadata' ),
		);
		$hash_src = array(
			'op'        => 'insert_internal_link',
			'object'    => 'post:' . (int) $source['object_id'],
			'field'     => 'post_content',
			'base_hash' => $base_hash,
			'offset'    => (int) $located['offset'],
			'length'    => (int) $located['length'],
			'anchor'    => $located['text'],
			'href'      => $href,
			'expected'  => $expected,
		);
		return array(
			'type'          => 'insert_internal_link',
			'object_type'   => 'post',
			'object_id'     => (int) $source['object_id'],
			'payload'       => $payload,
			'diff'          => $diff,
			'diff_hash'     => Util::sha256( Util::canonical_json( $hash_src ) ),
			'base_hash'     => $base_hash,
			'expected_hash' => $expected,
			'rationale'     => isset( $finding['evidence']['reason'] ) ? (string) $finding['evidence']['reason'] : '',
		);
	}

	/**
	 * Builds an assign_category proposal (existing term only).
	 *
	 * @param array $finding Finding.
	 * @return array|\WP_Error
	 */
	private static function build_category_proposal( array $finding ) {
		$source = DocumentRepository::get( (int) $finding['document_id'] );
		if ( null === $source || 'post' !== $source['object_type'] ) {
			return new \WP_Error( 'sseo_not_found', __( 'Document not found.', 'semantic-seo-engine-vi-en' ), array( 'status' => 404 ) );
		}
		$guard = self::guardrails( $source );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		$sug  = isset( $finding['evidence']['suggested'] ) ? (array) $finding['evidence']['suggested'] : array();
		$tax  = isset( $sug['taxonomy'] ) ? (string) $sug['taxonomy'] : '';
		$term = isset( $sug['term_id'] ) ? get_term( (int) $sug['term_id'], $tax ) : null;
		if ( ! $term instanceof \WP_Term || ! in_array( $tax, (array) Settings::get( 'taxonomies', array() ), true ) || ! is_object_in_taxonomy( get_post_type( (int) $source['object_id'] ), $tax ) ) {
			return new \WP_Error( 'sseo_invalid_term', __( 'The suggested term does not exist or is out of scope. Terms are never created by this plugin.', 'semantic-seo-engine-vi-en' ), array( 'status' => 422 ) );
		}
		$before = wp_get_object_terms( (int) $source['object_id'], $tax, array( 'fields' => 'ids' ) );
		$before = is_wp_error( $before ) ? array() : array_map( 'intval', $before );
		sort( $before );
		if ( in_array( (int) $term->term_id, $before, true ) ) {
			return new \WP_Error( 'sseo_noop', __( 'The post already has this term.', 'semantic-seo-engine-vi-en' ), array( 'status' => 409 ) );
		}
		$after   = $before;
		$after[] = (int) $term->term_id;
		sort( $after );
		$base_hash = Util::sha256( $tax . ':' . implode( ',', $before ) );
		$expected  = Util::sha256( $tax . ':' . implode( ',', $after ) );
		$payload   = array(
			'operation' => 'assign_category',
			'post_id'   => (int) $source['object_id'],
			'taxonomy'  => $tax,
			'term_id'   => (int) $term->term_id,
			'before'    => $before,
			'after'     => $after,
		);
		$hash_src  = array(
			'op'        => 'assign_category',
			'object'    => 'post:' . (int) $source['object_id'],
			'field'     => 'terms:' . $tax,
			'base_hash' => $base_hash,
			'term_id'   => (int) $term->term_id,
			'expected'  => $expected,
		);
		return array(
			'type'          => 'assign_category',
			'object_type'   => 'post',
			'object_id'     => (int) $source['object_id'],
			'payload'       => $payload,
			'diff'          => array(
				'field'     => 'terms:' . $tax,
				'operation' => 'assign_category',
				'before'    => self::term_names( $before, $tax ),
				'after'     => self::term_names( $after, $tax ),
				'added'     => $term->name,
				'unchanged' => array( 'content', 'title', 'slug', 'permalink', 'existing terms' ),
			),
			'diff_hash'     => Util::sha256( Util::canonical_json( $hash_src ) ),
			'base_hash'     => $base_hash,
			'expected_hash' => $expected,
			'rationale'     => 'Classification suggestion: closest existing cluster "' . ( isset( $sug['label'] ) ? $sug['label'] : '' ) . '" (lexical similarity ' . ( isset( $sug['similarity'] ) ? $sug['similarity'] : '?' ) . ').',
		);
	}

	/**
	 * Common guardrails for any proposal on a source object.
	 *
	 * @param array $source Source document row.
	 * @return true|\WP_Error
	 */
	private static function guardrails( array $source ) {
		if ( 'publish' !== $source['status'] ) {
			return new \WP_Error( 'sseo_invalid_source', __( 'Only published posts can receive proposals.', 'semantic-seo-engine-vi-en' ), array( 'status' => 422 ) );
		}
		if ( LockService::is_locked( 'post', (int) $source['object_id'], (string) $source['url'] ) || (int) $source['excluded'] ) {
			return new \WP_Error( 'sseo_locked', __( 'This URL is locked or excluded by an administrator.', 'semantic-seo-engine-vi-en' ), array( 'status' => 423 ) );
		}
		return true;
	}

	/**
	 * Term names.
	 *
	 * @param int[]  $ids IDs.
	 * @param string $tax Taxonomy.
	 * @return string[]
	 */
	private static function term_names( array $ids, $tax ) {
		$out = array();
		foreach ( $ids as $id ) {
			$t     = get_term( (int) $id, $tax );
			$out[] = $t instanceof \WP_Term ? $t->name : '#' . (int) $id;
		}
		return $out;
	}

	/**
	 * Excerpt around a byte range (UTF-8 safe boundaries).
	 *
	 * @param string $s      String.
	 * @param int    $offset Offset.
	 * @param int    $len    Length.
	 * @return string
	 */
	private static function excerpt( $s, $offset, $len ) {
		$start = max( 0, $offset - 120 );
		$part  = substr( $s, $start, ( $offset - $start ) + $len + 120 );
		$part  = preg_replace( '/^[\x80-\xBF]+/', '', (string) $part );
		$part  = preg_replace( '/[\xC0-\xFF][\x80-\xBF]*$/', '', (string) $part );
		return ( $start > 0 ? '…' : '' ) . $part . '…';
	}

	/**
	 * Fetches a proposal.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		$row = Db::row( 'SELECT * FROM {proposals} WHERE id = %d', array( (int) $id ) );
		if ( null === $row ) {
			return null;
		}
		$row['payload'] = Util::json_array( $row['payload'] );
		$row['diff']    = Util::json_array( $row['diff'] );
		$row['id']      = (int) $row['id'];
		return $row;
	}

	/**
	 * Lists proposals.
	 *
	 * @param array $f status, object_id, limit, offset.
	 * @return array
	 */
	public static function query( array $f ) {
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $f['status'] ) ) {
			$where[] = 'status = %s';
			$args[]  = (string) $f['status'];
		}
		if ( ! empty( $f['object_id'] ) ) {
			$where[] = 'object_id = %d';
			$args[]  = (int) $f['object_id'];
		}
		$limit  = Util::clamp_int( isset( $f['limit'] ) ? $f['limit'] : 20, 1, 100 );
		$offset = max( 0, isset( $f['offset'] ) ? (int) $f['offset'] : 0 );
		$total  = (int) Db::value( 'SELECT COUNT(*) FROM {proposals} WHERE ' . implode( ' AND ', $where ), $args );
		$rows   = Db::rows( 'SELECT * FROM {proposals} WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d', array_merge( $args, array( $limit, $offset ) ) );
		foreach ( $rows as &$r ) {
			$r['payload'] = Util::json_array( $r['payload'] );
			$r['diff']    = Util::json_array( $r['diff'] );
			$r['id']      = (int) $r['id'];
		}
		unset( $r );
		return array(
			'items' => $rows,
			'total' => $total,
		);
	}

	/**
	 * Rejects a proposal (human review).
	 *
	 * @param int $id ID.
	 * @return bool
	 */
	public static function reject( $id ) {
		$p = self::get( $id );
		if ( null === $p || ! in_array( $p['status'], array( 'previewed', 'approved' ), true ) ) {
			return false;
		}
		Db::update(
			'proposals',
			array(
				'status'     => 'rejected',
				'updated_at' => Util::now(),
			),
			array( 'id' => (int) $id )
		);
		Db::query( "UPDATE {approvals} SET status = 'revoked' WHERE proposal_id = %d AND status = 'active'", array( (int) $id ) );
		if ( $p['finding_id'] ) {
			FindingRepository::set_status( (int) $p['finding_id'], 'dismissed' );
		}
		AuditLog::record( 'proposal.rejected', $p['object_type'], (int) $p['object_id'], 'ok', array( 'proposal_id' => (int) $id ) );
		return true;
	}
}
