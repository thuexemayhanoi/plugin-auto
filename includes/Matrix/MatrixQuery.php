<?php
/**
 * SEO Matrix: server-side filtered, sorted, paginated inventory with analysis columns.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Matrix;

use SemanticSEO\Database\Db;
use SemanticSEO\Support\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Never loads the whole site: every query is bounded by LIMIT (≤ 100 per page; exports stream
 * in batches of 200).
 */
final class MatrixQuery {

	const SORTS = array(
		'id'          => 'd.id',
		'title'       => 'd.title',
		'inbound'     => 'd.inbound_count',
		'outbound'    => 'd.outbound_count',
		'words'       => 'd.word_count',
		'modified'    => 'd.modified_at',
		'analyzed'    => 'd.analyzed_at',
		'qa'          => 'qa_count',
		'risk'        => 'GREATEST(COALESCE(risk_doc, -1), COALESCE(risk_target, -1))',
		'suggestions' => 'link_count',
	);

	const COLUMNS = array( 'document_id', 'object_type', 'object_id', 'subtype', 'url', 'locale', 'locale_source', 'status', 'title', 'taxonomy', 'primary_keyword', 'heuristic_intent', 'intent_confidence', 'editorial_intent', 'cluster', 'cluster_role', 'cluster_locked', 'canonical', 'robots', 'indexable', 'sitemap', 'translation_group', 'inbound_links', 'outbound_links', 'link_scan_coverage', 'proposed_links', 'overlap_group', 'overlap_risk', 'qa_issues', 'word_count', 'content_hash', 'algorithm_version', 'review_state', 'excluded', 'last_analyzed', 'gsc' );

	/**
	 * Runs a matrix query.
	 *
	 * @param array $f Filters: locale, subtype, status, intent, role, review, search, orphan, has_qa, has_risk, excluded, indexable, sort, order, page, per_page.
	 * @return array{items:array,total:int,page:int,per_page:int,pages:int}
	 */
	public static function query( array $f ) {
		list( $where, $args ) = self::where( $f );
		$per_page             = Util::clamp_int( isset( $f['per_page'] ) ? $f['per_page'] : 25, 1, 100 );
		$page                 = max( 1, isset( $f['page'] ) ? (int) $f['page'] : 1 );
		$sort_key             = isset( $f['sort'] ) && isset( self::SORTS[ $f['sort'] ] ) ? $f['sort'] : 'id';
		$order                = isset( $f['order'] ) && 'desc' === strtolower( (string) $f['order'] ) ? 'DESC' : 'ASC';
		$total                = (int) Db::value( 'SELECT COUNT(*) FROM {documents} d WHERE ' . $where, $args );
		$sql                  = 'SELECT d.*, ' . self::aggregates() . ' FROM {documents} d WHERE ' . $where . ' ORDER BY ' . self::SORTS[ $sort_key ] . ' ' . $order . ', d.id ASC LIMIT %d OFFSET %d';
		$rows                 = Db::rows( $sql, array_merge( $args, array( $per_page, ( $page - 1 ) * $per_page ) ) );
		return array(
			'items'    => array_map( array( __CLASS__, 'present' ), $rows ),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => (int) ceil( $total / $per_page ),
		);
	}

	/**
	 * Iterates rows in batches (export).
	 *
	 * @param array    $f        Filters.
	 * @param callable $callback Receives presented rows.
	 * @return int Rows emitted.
	 */
	public static function each( array $f, callable $callback ) {
		list( $where, $args ) = self::where( $f );
		$after                = 0;
		$count                = 0;
		do {
			$rows = Db::rows( 'SELECT d.*, ' . self::aggregates() . ' FROM {documents} d WHERE ' . $where . ' AND d.id > %d ORDER BY d.id ASC LIMIT 200', array_merge( $args, array( $after ) ) );
			foreach ( $rows as $r ) {
				$after = (int) $r['id'];
				call_user_func( $callback, self::present( $r ) );
				++$count;
			}
			$page_size = count( $rows );
		} while ( 200 === $page_size && $count < 100000 );
		return $count;
	}

	/**
	 * Correlated aggregate columns.
	 *
	 * @return string
	 */
	private static function aggregates() {
		// A document is on either side of an overlap pair. Each side is looked up through its own
		// index: one `document_id = d.id OR target_id = d.id` condition scanned every open overlap
		// finding for each row (measured: 459 ms for 20 rows at 1,000 synthetic posts).
		// Exact duplicates are labelled 'warning' (a fact, not a hypothesis): they are risks too.
		$risk  = "SELECT MAX(c.score) FROM {findings} c WHERE c.%s = d.id AND c.type = 'cannibalization' AND c.status = 'open' AND c.label IN ('risk_hypothesis','warning')";
		$group = "SELECT c2.group_key FROM {findings} c2 WHERE c2.%s = d.id AND c2.type = 'cannibalization' AND c2.status = 'open' AND LEFT(c2.group_key, 3) = 'cg-' LIMIT 1";
		return "(SELECT COUNT(*) FROM {findings} q WHERE q.document_id = d.id AND q.type = 'qa' AND q.status = 'open') AS qa_count,
			(SELECT COUNT(*) FROM {findings} l WHERE l.document_id = d.id AND l.type = 'link' AND l.status IN ('open','proposed')) AS link_count,
			(" . sprintf( $risk, 'document_id' ) . ') AS risk_doc,
			(' . sprintf( $risk, 'target_id' ) . ') AS risk_target,
			COALESCE((' . sprintf( $group, 'document_id' ) . '), (' . sprintf( $group, 'target_id' ) . ')) AS risk_group,
			(SELECT k.label FROM {clusters} k WHERE k.id = d.cluster_id) AS cluster_label,
			(SELECT COUNT(*) FROM {gsc} g WHERE g.document_id = d.id) AS gsc_rows';
	}

	/**
	 * Highest open overlap risk on either side of a pair, or null.
	 *
	 * @param array $r Row with risk_doc and risk_target.
	 * @return float|null
	 */
	private static function risk( array $r ) {
		$values = array_filter(
			array( $r['risk_doc'], $r['risk_target'] ),
			static function ( $v ) {
				return null !== $v;
			}
		);
		return array() === $values ? null : round( max( array_map( 'floatval', $values ) ), 3 );
	}

	/**
	 * WHERE clause.
	 *
	 * @param array $f Filters.
	 * @return array{0:string,1:array}
	 */
	private static function where( array $f ) {
		$w    = array( "d.status <> 'deleted'" );
		$args = array();
		foreach ( array(
			'locale'  => 'd.locale',
			'subtype' => 'd.subtype',
			'status'  => 'd.status',
			'role'    => 'd.cluster_role',
			'review'  => 'd.review_state',
		) as $key => $col ) {
			if ( isset( $f[ $key ] ) && '' !== (string) $f[ $key ] ) {
				$w[]    = "{$col} = %s";
				$args[] = (string) $f[ $key ];
			}
		}
		if ( isset( $f['intent'] ) && '' !== (string) $f['intent'] ) {
			$w[]    = "(d.editorial_intent = %s OR (d.editorial_intent = '' AND d.intent = %s))";
			$args[] = (string) $f['intent'];
			$args[] = (string) $f['intent'];
		}
		if ( ! empty( $f['search'] ) ) {
			$like   = '%' . Db::esc_like_prefix( (string) $f['search'] );
			$w[]    = '(d.title LIKE %s OR d.url LIKE %s)';
			$args[] = $like;
			$args[] = $like;
		}
		if ( ! empty( $f['orphan'] ) ) {
			$w[] = "d.inbound_count = 0 AND d.object_type = 'post' AND d.indexable = 1 AND d.status = 'publish'";
		}
		if ( ! empty( $f['has_qa'] ) ) {
			$w[] = "EXISTS (SELECT 1 FROM {findings} q WHERE q.document_id = d.id AND q.type = 'qa' AND q.status = 'open')";
		}
		if ( ! empty( $f['has_risk'] ) ) {
			$w[] = "(EXISTS (SELECT 1 FROM {findings} c WHERE c.document_id = d.id AND c.type = 'cannibalization' AND c.status = 'open' AND c.label IN ('risk_hypothesis','warning')) OR EXISTS (SELECT 1 FROM {findings} c WHERE c.target_id = d.id AND c.type = 'cannibalization' AND c.status = 'open' AND c.label IN ('risk_hypothesis','warning')))";
		}
		if ( isset( $f['excluded'] ) && '' !== (string) $f['excluded'] ) {
			$w[]    = 'd.excluded = %d';
			$args[] = (int) $f['excluded'];
		}
		if ( isset( $f['indexable'] ) && '' !== (string) $f['indexable'] ) {
			$w[]    = 'd.indexable = %d';
			$args[] = (int) $f['indexable'];
		}
		if ( ! empty( $f['ids'] ) ) {
			$ids  = array_slice( array_map( 'intval', (array) $f['ids'] ), 0, 500 );
			$w[]  = 'd.id IN (' . Db::in( $ids ) . ')';
			$args = array_merge( $args, $ids );
		}
		return array( implode( ' AND ', $w ), $args );
	}

	/**
	 * Presentation of a matrix row.
	 *
	 * @param array $r Row with aggregates.
	 * @return array
	 */
	public static function present( array $r ) {
		$terms = json_decode( (string) $r['terms_json'], true );
		$names = array();
		if ( is_array( $terms ) ) {
			foreach ( $terms as $t ) {
				$names[] = $t['taxonomy'] . ':' . $t['name'];
			}
		}
		return array(
			'document_id'        => (int) $r['id'],
			'object_type'        => $r['object_type'],
			'object_id'          => (int) $r['object_id'],
			'subtype'            => $r['subtype'],
			'url'                => $r['url'],
			'locale'             => $r['locale'],
			'locale_source'      => $r['locale_source'],
			'status'             => $r['status'],
			'title'              => $r['title'],
			'taxonomy'           => implode( ' | ', $names ),
			'primary_keyword'    => $r['focus_keyword'],
			'heuristic_intent'   => $r['intent'],
			'intent_confidence'  => $r['intent_confidence'],
			'editorial_intent'   => $r['editorial_intent'],
			'cluster'            => null === $r['cluster_label'] ? '' : (string) $r['cluster_label'],
			'cluster_role'       => $r['cluster_role'],
			'cluster_locked'     => (int) $r['cluster_locked'],
			'canonical'          => (string) $r['canonical'],
			'robots'             => $r['robots'],
			'indexable'          => (int) $r['indexable'],
			'sitemap'            => 'unverified',
			'translation_group'  => $r['translation_group'],
			'inbound_links'      => (int) $r['inbound_count'],
			'outbound_links'     => (int) $r['outbound_count'],
			'link_scan_coverage' => 'post content only',
			'proposed_links'     => (int) $r['link_count'],
			'overlap_group'      => (string) $r['risk_group'],
			'overlap_risk'       => self::risk( $r ),
			'qa_issues'          => (int) $r['qa_count'],
			'word_count'         => (int) $r['word_count'],
			'content_hash'       => substr( (string) $r['content_hash'], 0, 12 ),
			'algorithm_version'  => $r['algorithm_version'],
			'review_state'       => $r['review_state'],
			'excluded'           => (int) $r['excluded'],
			'last_analyzed'      => $r['analyzed_at'],
			'gsc'                => (int) $r['gsc_rows'] > 0 ? 'imported' : '',
		);
	}
}
