<?php
/**
 * SEO priority queue: the open findings worth acting on, most important first, each with the
 * page, the evidence, the confidence, the expected benefit and the recommended action.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Analysis;

use SemanticSEO\Database\Db;
use SemanticSEO\Quality\QaChecker;
use SemanticSEO\Support\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Priority 1 = verified technical errors (do now), 2 = likely problems (soon), 3 = linking work,
 * 4 = editorial improvements. The order is a work plan, not a ranking prediction: nothing here
 * claims a position in Google. Informational overlap categories (pillar + supporting page,
 * different intents, distinct facets) are not tasks and are left out.
 */
final class PriorityQueue {

	/**
	 * Rules: check => [priority, category]. Categories: technical, links, overlap, content,
	 * translation, structure.
	 */
	const RULES = array(
		'qa'              => array(
			'broken_internal_link'       => array( 1, 'technical' ),
			'http_error_link'            => array( 1, 'technical' ),
			'missing_title'              => array( 1, 'technical' ),
			'canonical_cross_locale'     => array( 1, 'translation' ),
			'duplicate_title'            => array( 2, 'technical' ),
			'canonical_conflict'         => array( 2, 'technical' ),
			'language_mismatch'          => array( 2, 'translation' ),
			'near_duplicate'             => array( 2, 'overlap' ),
			'orphan'                     => array( 2, 'links' ),
			'redirected_internal_link'   => array( 3, 'links' ),
			'unresolved_internal_link'   => array( 3, 'links' ),
			'noindex_linked'             => array( 3, 'links' ),
			'few_cluster_links'          => array( 3, 'links' ),
			'duplicate_meta_description' => array( 3, 'technical' ),
			'missing_meta_description'   => array( 3, 'technical' ),
			'schema_duplication_risk'    => array( 3, 'technical' ),
			'thin_for_intent'            => array( 4, 'content' ),
			'no_subheadings'             => array( 4, 'content' ),
			'heading_skip'               => array( 4, 'content' ),
			'h1_in_content'              => array( 4, 'content' ),
			'images_missing_alt'         => array( 4, 'content' ),
			'no_external_sources'        => array( 4, 'content' ),
			'repetitive_phrasing'        => array( 4, 'content' ),
			'title_length'               => array( 4, 'content' ),
			'missing_translation'        => array( 4, 'translation' ),
			'possible_translation_pair'  => array( 4, 'translation' ),
		),
		'cannibalization' => array(
			'exact_duplicate'              => array( 1, 'overlap' ),
			'near_duplicate'               => array( 2, 'overlap' ),
			'potential_competition'        => array( 2, 'overlap' ),
			'translation_mapping_conflict' => array( 2, 'translation' ),
		),
		'link'            => array(
			'internal_link' => array( 3, 'links' ),
		),
	);

	const CATEGORIES = array( 'technical', 'links', 'overlap', 'content', 'translation', 'structure' );

	/**
	 * The union of actionable findings and clusters without a confirmed pillar, as SQL.
	 *
	 * @return string
	 */
	private static function source_sql() {
		$prio = 'CASE';
		$cat  = 'CASE';
		$in   = array();
		foreach ( self::RULES as $type => $checks ) {
			$ids = array();
			foreach ( $checks as $check => $rule ) {
				$cond  = "f.type = '" . esc_sql( $type ) . "' AND f.check_id = '" . esc_sql( $check ) . "'";
				$prio .= " WHEN {$cond} THEN " . (int) $rule[0];
				$cat  .= " WHEN {$cond} THEN '" . esc_sql( $rule[1] ) . "'";
				$ids[] = "'" . esc_sql( $check ) . "'";
			}
			$in[] = "(f.type = '" . esc_sql( $type ) . "' AND f.check_id IN (" . implode( ',', $ids ) . '))';
		}
		$prio .= ' ELSE 4 END';
		$cat  .= " ELSE 'content' END";
		return "SELECT 'finding' AS kind, f.id, f.type, f.check_id, f.label, f.confidence, f.score, f.locale, f.document_id, f.target_id, f.evidence, {$prio} AS priority, {$cat} AS category FROM {findings} f WHERE f.status = 'open' AND (" . implode( ' OR ', $in ) . ')'
			. " UNION ALL SELECT 'cluster' AS kind, c.id, 'cluster' AS type, 'cluster_without_pillar' AS check_id, 'suggestion' AS label, 'medium' AS confidence, c.member_count AS score, c.locale, c.recommended_pillar_id AS document_id, 0 AS target_id, c.label AS evidence, 4 AS priority, 'structure' AS category FROM {clusters} c WHERE c.pillar_document_id = 0 AND c.recommended_pillar_id > 0 AND c.member_count >= 3";
	}

	/**
	 * Filter clause.
	 *
	 * @param array $f      Filters: locale, category, priority, search.
	 * @param array $args   Query arguments (appended).
	 * @return string
	 */
	private static function where( array $f, array &$args ) {
		$w = array( '1=1' );
		if ( ! empty( $f['locale'] ) ) {
			$w[]    = 'q.locale = %s';
			$args[] = (string) $f['locale'];
		}
		if ( ! empty( $f['category'] ) && in_array( $f['category'], self::CATEGORIES, true ) ) {
			$w[]    = 'q.category = %s';
			$args[] = (string) $f['category'];
		}
		if ( ! empty( $f['priority'] ) && (int) $f['priority'] >= 1 && (int) $f['priority'] <= 4 ) {
			$w[]    = 'q.priority = %d';
			$args[] = (int) $f['priority'];
		}
		if ( isset( $f['search'] ) && '' !== trim( (string) $f['search'] ) ) {
			global $wpdb;
			$like   = '%' . $wpdb->esc_like( trim( (string) $f['search'] ) ) . '%';
			$w[]    = '(d.url LIKE %s OR d.title LIKE %s OR t.url LIKE %s OR t.title LIKE %s)';
			$args[] = $like;
			$args[] = $like;
			$args[] = $like;
			$args[] = $like;
		}
		return implode( ' AND ', $w );
	}

	/**
	 * Paginated, ordered items.
	 *
	 * @param array $f Filters (locale, category, priority, search) plus limit (1-500) and offset.
	 * @return array{items:array[],total:int}
	 */
	public static function items( array $f = array() ) {
		$args  = array();
		$where = self::where( $f, $args );
		$from  = '(' . self::source_sql() . ') q LEFT JOIN {documents} d ON d.id = q.document_id LEFT JOIN {documents} t ON t.id = q.target_id';
		$total = (int) Db::value( "SELECT COUNT(*) FROM {$from} WHERE {$where}", $args );
		$limit = Util::clamp_int( isset( $f['limit'] ) ? $f['limit'] : 20, 1, 500 );
		$off   = max( 0, isset( $f['offset'] ) ? (int) $f['offset'] : 0 );
		$rows  = Db::rows(
			"SELECT q.*, d.title AS page_title, d.url AS page_url, d.object_id AS page_object_id, t.title AS target_title, t.url AS target_url FROM {$from} WHERE {$where} ORDER BY q.priority ASC, FIELD(q.confidence, 'high', 'medium', 'low', 'manual_review'), q.score DESC, q.kind ASC, q.id ASC LIMIT %d OFFSET %d",
			array_merge( $args, array( $limit, $off ) )
		);
		return array(
			'items' => array_map( array( __CLASS__, 'present' ), $rows ),
			'total' => $total,
		);
	}

	/**
	 * Writes every matching item as CSV (UTF-8 with BOM for Excel; formula-safe cells).
	 *
	 * @param resource $out     Output stream.
	 * @param array    $filters Filters.
	 * @return int Rows written.
	 */
	public static function csv( $out, array $filters ) {
		$cols = array( 'priority', 'priority_label', 'category_label', 'finding_kind', 'problem', 'url', 'page_title', 'locale', 'target_url', 'target_title', 'evidence', 'confidence_label', 'benefit', 'action' );
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fputcsv( $out, $cols, ',', '"', '\\' );
		$n = 0;
		for ( $off = 0; ; $off += 500 ) {
			$page = self::items(
				array_merge(
					$filters,
					array(
						'limit'  => 500,
						'offset' => $off,
					)
				)
			);
			foreach ( $page['items'] as $item ) {
				$line = array();
				foreach ( $cols as $c ) {
					$line[] = \SemanticSEO\Matrix\CsvReader::safe_cell( (string) $item[ $c ] );
				}
				fputcsv( $out, $line, ',', '"', '\\' );
				++$n;
			}
			if ( count( $page['items'] ) < 500 ) {
				return $n;
			}
		}
	}

	/**
	 * Open actionable items per priority (1-4).
	 *
	 * @param array $f Filters.
	 * @return array<int,int>
	 */
	public static function counts( array $f = array() ) {
		$args = array();
		$out  = array(
			1 => 0,
			2 => 0,
			3 => 0,
			4 => 0,
		);
		$rows = Db::rows( 'SELECT q.priority, COUNT(*) AS n FROM (' . self::source_sql() . ') q LEFT JOIN {documents} d ON d.id = q.document_id LEFT JOIN {documents} t ON t.id = q.target_id WHERE ' . self::where( $f, $args ) . ' GROUP BY q.priority', $args );
		foreach ( $rows as $r ) {
			$out[ (int) $r['priority'] ] = (int) $r['n'];
		}
		return $out;
	}

	/**
	 * Distinct pages concerned by some checks (for the dashboard).
	 *
	 * @param string   $type   Finding type.
	 * @param string[] $checks Check IDs.
	 * @param bool     $both   Count targets too (pairs).
	 * @return int
	 */
	public static function pages_with( $type, array $checks, $both = false ) {
		$in   = Db::in( $checks, '%s' );
		$args = array_merge( array( $type ), $checks );
		if ( ! $both ) {
			return (int) Db::value( "SELECT COUNT(DISTINCT document_id) FROM {findings} WHERE status = 'open' AND type = %s AND check_id IN ({$in})", $args );
		}
		return (int) Db::value( "SELECT COUNT(DISTINCT x) FROM (SELECT document_id AS x FROM {findings} WHERE status = 'open' AND type = %s AND check_id IN ({$in}) UNION ALL SELECT target_id FROM {findings} WHERE status = 'open' AND type = %s AND check_id IN ({$in}) AND target_id > 0) u", array_merge( $args, $args ) );
	}

	/**
	 * Human-facing item.
	 *
	 * @param array $r Row.
	 * @return array
	 */
	public static function present( array $r ) {
		$check = (string) $r['check_id'];
		$ev    = 'cluster' === $r['kind'] ? array( 'cluster' => (string) $r['evidence'] ) : Util::json_array( (string) $r['evidence'] );
		$texts = self::texts( (string) $r['type'], $check );
		return array(
			'kind'             => (string) $r['kind'],
			'id'               => (int) $r['id'],
			'priority'         => (int) $r['priority'],
			'priority_label'   => self::priority_label( (int) $r['priority'] ),
			'category'         => (string) $r['category'],
			'category_label'   => self::category_label( (string) $r['category'] ),
			'type'             => (string) $r['type'],
			'check_id'         => $check,
			'finding_kind'     => self::finding_kind( (string) $r['label'] ),
			'problem'          => $texts['problem'],
			'action'           => $texts['action'],
			'benefit'          => $texts['benefit'],
			'evidence'         => self::evidence_text( (string) $r['type'], $check, $ev, $r ),
			'confidence'       => (string) $r['confidence'],
			'confidence_label' => self::confidence_label( (string) $r['confidence'] ),
			'locale'           => (string) $r['locale'],
			'document_id'      => (int) $r['document_id'],
			'page_title'       => isset( $r['page_title'] ) ? (string) $r['page_title'] : '',
			'url'              => isset( $r['page_url'] ) ? (string) $r['page_url'] : '',
			'target_id'        => (int) $r['target_id'],
			'target_title'     => isset( $r['target_title'] ) ? (string) $r['target_title'] : '',
			'target_url'       => isset( $r['target_url'] ) ? (string) $r['target_url'] : '',
			'detail'           => self::detail( $r ),
		);
	}

	/**
	 * Where the full finding is reviewed (admin page and query arguments).
	 *
	 * @param array $r Row.
	 * @return array{page:string,args:array}
	 */
	private static function detail( array $r ) {
		$doc = (int) $r['document_id'];
		switch ( $r['type'] ) {
			case 'cannibalization':
				return array(
					'page' => 'sseo-cannibalization',
					'args' => array( 'doc' => $doc ),
				);
			case 'link':
				return array(
					'page' => 'sseo-links',
					'args' => array( 'doc' => $doc ),
				);
			case 'cluster':
				return array(
					'page' => 'sseo-clusters',
					'args' => array( 'locale' => (string) $r['locale'] ),
				);
			default:
				return array(
					'page' => 'sseo-qa',
					'args' => array(
						'doc'   => $doc,
						'check' => (string) $r['check_id'],
					),
				);
		}
	}

	/**
	 * Problem, recommended action and expected benefit of a check.
	 *
	 * @param string $type  Type.
	 * @param string $check Check.
	 * @return array{problem:string,action:string,benefit:string}
	 */
	public static function texts( $type, $check ) {
		$broken = array(
			'action'  => __( 'Edit the post and point the link to a live page, or remove it.', 'semantic-seo-engine-vi-en' ),
			'benefit' => __( 'Readers and search engines no longer reach an error page, and the link passes value again.', 'semantic-seo-engine-vi-en' ),
		);
		$map    = array(
			'qa:broken_internal_link'                      => $broken,
			'qa:http_error_link'                           => $broken,
			'qa:missing_title'                             => array(
				'action'  => __( 'Write a title that names the topic of the page.', 'semantic-seo-engine-vi-en' ),
				'benefit' => __( 'Search results and browser tabs show what the page is about.', 'semantic-seo-engine-vi-en' ),
			),
			'qa:canonical_cross_locale'                    => array(
				'action'  => __( 'Check the canonical in the SEO plugin: each language version should normally point to itself and be linked with hreflang.', 'semantic-seo-engine-vi-en' ),
				'benefit' => __( 'The page can be indexed in its own language instead of being folded into the other one.', 'semantic-seo-engine-vi-en' ),
			),
			'qa:duplicate_title'                           => array(
				'action'  => __( 'Give each page a title that says how it differs from the other one.', 'semantic-seo-engine-vi-en' ),
				'benefit' => __( 'Search engines and readers can tell the pages apart.', 'semantic-seo-engine-vi-en' ),
			),
			'qa:canonical_conflict'                        => array(
				'action'  => __( 'Confirm the canonical is intentional; if not, correct it in the SEO plugin.', 'semantic-seo-engine-vi-en' ),
				'benefit' => __( 'The page you want indexed is the one search engines keep.', 'semantic-seo-engine-vi-en' ),
			),
			'qa:language_mismatch'                         => array(
				'action'  => __( 'Check that the post is assigned to the right language.', 'semantic-seo-engine-vi-en' ),
				'benefit' => __( 'Visitors of each language find content in their language; hreflang stays consistent.', 'semantic-seo-engine-vi-en' ),
			),
			'qa:near_duplicate'                            => array(
				'action'  => __( 'Compare the two pages; make each one answer a different need, or keep one as the main page.', 'semantic-seo-engine-vi-en' ),
				'benefit' => __( 'Fewer pages competing with each other for the same searches.', 'semantic-seo-engine-vi-en' ),
			),
			'qa:orphan'                                    => array(
				'action'  => __( 'Add a link to this page from one or two related posts (see the link suggestions).', 'semantic-seo-engine-vi-en' ),
				'benefit' => __( 'Readers and crawlers can reach the page from the rest of the site.', 'semantic-seo-engine-vi-en' ),
			),
			'qa:redirected_internal_link'                  => array(
				'action'  => __( 'Update the link to the final URL.', 'semantic-seo-engine-vi-en' ),
				'benefit' => __( 'One redirect less for readers and crawlers.', 'semantic-seo-engine-vi-en' ),
			),
			'qa:unresolved_internal_link'                  => array(
				'action'  => __( 'Open the link once; fix it if it does not lead to the intended page.', 'semantic-seo-engine-vi-en' ),
				'benefit' => __( 'No hidden broken links.', 'semantic-seo-engine-vi-en' ),
			),
			'qa:noindex_linked'                            => array(
				'action'  => __( 'Check whether the page should really be noindex; if so, consider linking to an indexable page instead.', 'semantic-seo-engine-vi-en' ),
				'benefit' => __( 'Internal links point to pages that can appear in search.', 'semantic-seo-engine-vi-en' ),
			),
			'qa:few_cluster_links'                         => array(
				'action'  => __( 'Link to the main pages of the same topic group.', 'semantic-seo-engine-vi-en' ),
				'benefit' => __( 'The topic group is easier to navigate and its structure is clearer.', 'semantic-seo-engine-vi-en' ),
			),
			'qa:duplicate_meta_description'                => array(
				'action'  => __( 'Write a meta description specific to each page.', 'semantic-seo-engine-vi-en' ),
				'benefit' => __( 'Search snippets describe each page instead of repeating the same text.', 'semantic-seo-engine-vi-en' ),
			),
			'qa:missing_meta_description'                  => array(
				'action'  => __( 'Write a short meta description in the SEO plugin.', 'semantic-seo-engine-vi-en' ),
				'benefit' => __( 'You choose the snippet text instead of leaving it to the search engine.', 'semantic-seo-engine-vi-en' ),
			),
			'qa:schema_duplication_risk'                   => array(
				'action'  => __( 'Check the page with a structured data testing tool and keep one source of each schema type.', 'semantic-seo-engine-vi-en' ),
				'benefit' => __( 'Structured data stays valid and unambiguous.', 'semantic-seo-engine-vi-en' ),
			),
			'qa:thin_for_intent'                           => array(
				'action'  => __( 'Check whether the page fully answers the question; add the missing details (prices, steps, conditions, examples) if not.', 'semantic-seo-engine-vi-en' ),
				'benefit' => __( 'A more complete answer for readers. Length alone is not a goal.', 'semantic-seo-engine-vi-en' ),
			),
			'qa:no_subheadings'                            => array(
				'action'  => __( 'Split the text into sections with H2 subheadings.', 'semantic-seo-engine-vi-en' ),
				'benefit' => __( 'Easier to scan and to quote in search answers.', 'semantic-seo-engine-vi-en' ),
			),
			'qa:heading_skip'                              => array(
				'action'  => __( 'Use heading levels in order (H2, then H3).', 'semantic-seo-engine-vi-en' ),
				'benefit' => __( 'A clearer outline for readers and assistive technology.', 'semantic-seo-engine-vi-en' ),
			),
			'qa:h1_in_content'                             => array(
				'action'  => __( 'Check that the page has a single H1 (the theme usually prints the title as H1).', 'semantic-seo-engine-vi-en' ),
				'benefit' => __( 'A clear main heading.', 'semantic-seo-engine-vi-en' ),
			),
			'qa:images_missing_alt'                        => array(
				'action'  => __( 'Add a short alt text that describes each image.', 'semantic-seo-engine-vi-en' ),
				'benefit' => __( 'Accessible images that image search can understand.', 'semantic-seo-engine-vi-en' ),
			),
			'qa:no_external_sources'                       => array(
				'action'  => __( 'Where facts are stated, cite a reliable source.', 'semantic-seo-engine-vi-en' ),
				'benefit' => __( 'Statements readers can verify.', 'semantic-seo-engine-vi-en' ),
			),
			'qa:repetitive_phrasing'                       => array(
				'action'  => __( 'Reword the repeated phrase where it does not read naturally.', 'semantic-seo-engine-vi-en' ),
				'benefit' => __( 'More natural text; avoids looking like keyword stuffing.', 'semantic-seo-engine-vi-en' ),
			),
			'qa:title_length'                              => array(
				'action'  => __( 'Check how the title looks in search results; shorten or complete it if needed.', 'semantic-seo-engine-vi-en' ),
				'benefit' => __( 'A title that is not cut off and says enough.', 'semantic-seo-engine-vi-en' ),
			),
			'qa:missing_translation'                       => array(
				'action'  => __( 'Translate the page if it matters for visitors of the other language, then link the two versions.', 'semantic-seo-engine-vi-en' ),
				'benefit' => __( 'Visitors of each language get the page in their language.', 'semantic-seo-engine-vi-en' ),
			),
			'qa:possible_translation_pair'                 => array(
				'action'  => __( 'If the two pages are translations of each other, link them in the language plugin.', 'semantic-seo-engine-vi-en' ),
				'benefit' => __( 'Correct hreflang between the two language versions.', 'semantic-seo-engine-vi-en' ),
			),
			'cannibalization:exact_duplicate'              => array(
				'action'  => __( 'Decide which page to keep as the main one. Do not delete or redirect without checking traffic and links first.', 'semantic-seo-engine-vi-en' ),
				'benefit' => __( 'One clear page for the topic instead of two identical ones.', 'semantic-seo-engine-vi-en' ),
			),
			'cannibalization:near_duplicate'               => array(
				'action'  => __( 'Compare the two pages; differentiate them or keep one as the main page. Similar text alone is not a reason to merge, delete or redirect.', 'semantic-seo-engine-vi-en' ),
				'benefit' => __( 'Fewer pages competing with each other for the same searches.', 'semantic-seo-engine-vi-en' ),
			),
			'cannibalization:potential_competition'        => array(
				'action'  => __( 'Check in Search Console whether both pages show for the same queries; if so, give each page a distinct focus and link the supporting page to the main one.', 'semantic-seo-engine-vi-en' ),
				'benefit' => __( 'Each search need has one clear page.', 'semantic-seo-engine-vi-en' ),
			),
			'cannibalization:translation_mapping_conflict' => array(
				'action'  => __( 'Check the translation links in the language plugin: each page should have at most one translation per language.', 'semantic-seo-engine-vi-en' ),
				'benefit' => __( 'Consistent hreflang between language versions.', 'semantic-seo-engine-vi-en' ),
			),
			'link:internal_link'                           => array(
				'action'  => __( 'Add the suggested link with the proposed anchor text (preview and approval are required before anything is written).', 'semantic-seo-engine-vi-en' ),
				'benefit' => __( 'Related pages are connected for readers and crawlers.', 'semantic-seo-engine-vi-en' ),
			),
			'cluster:cluster_without_pillar'               => array(
				'action'  => __( 'Confirm the suggested pillar page for this topic group, or choose another one, and link the group members to it.', 'semantic-seo-engine-vi-en' ),
				'benefit' => __( 'A clear Pillar–Hub–Silo structure for the topic.', 'semantic-seo-engine-vi-en' ),
			),
		);
		$key    = $type . ':' . $check;
		$t      = isset( $map[ $key ] ) ? $map[ $key ] : array(
			'action'  => __( 'Review the finding.', 'semantic-seo-engine-vi-en' ),
			'benefit' => '',
		);
		$name   = 'qa' === $type ? QaChecker::label( $check ) : self::problem( $type, $check );
		return array(
			'problem' => $name,
			'action'  => $t['action'],
			'benefit' => $t['benefit'],
		);
	}

	/**
	 * Problem name for non-QA checks.
	 *
	 * @param string $type  Type.
	 * @param string $check Check.
	 * @return string
	 */
	private static function problem( $type, $check ) {
		$names = array(
			'cannibalization:exact_duplicate'              => __( 'Two pages with identical content', 'semantic-seo-engine-vi-en' ),
			'cannibalization:near_duplicate'               => __( 'Two pages with nearly the same content', 'semantic-seo-engine-vi-en' ),
			'cannibalization:potential_competition'        => __( 'Two pages may compete for the same searches', 'semantic-seo-engine-vi-en' ),
			'cannibalization:translation_mapping_conflict' => __( 'Conflicting translation links', 'semantic-seo-engine-vi-en' ),
			'link:internal_link'                           => __( 'Missing internal link to a related page', 'semantic-seo-engine-vi-en' ),
			'cluster:cluster_without_pillar'               => __( 'Topic group without a confirmed pillar page', 'semantic-seo-engine-vi-en' ),
		);
		$key   = $type . ':' . $check;
		return isset( $names[ $key ] ) ? $names[ $key ] : $check;
	}

	/**
	 * Short evidence sentence from a finding's stored evidence.
	 *
	 * @param string $type  Type.
	 * @param string $check Check.
	 * @param array  $e     Evidence.
	 * @param array  $r     Row.
	 * @return string
	 */
	private static function evidence_text( $type, $check, array $e, array $r ) {
		$target = isset( $r['target_title'] ) && '' !== (string) $r['target_title'] ? (string) $r['target_title'] : '';
		switch ( $type . ':' . $check ) {
			case 'cluster:cluster_without_pillar':
				/* translators: 1: topic group, 2: number of pages, 3: suggested pillar page */
				return sprintf( __( 'Group "%1$s": %2$d pages; suggested pillar: "%3$s".', 'semantic-seo-engine-vi-en' ), (string) $e['cluster'], (int) $r['score'], isset( $r['page_title'] ) ? (string) $r['page_title'] : '' );
			case 'link:internal_link':
				$anchor = isset( $e['anchor']['text'] ) ? (string) $e['anchor']['text'] : '';
				/* translators: 1: anchor text, 2: target page, 3: reason */
				return sprintf( __( 'Anchor "%1$s" → "%2$s". Why: %3$s.', 'semantic-seo-engine-vi-en' ), $anchor, $target, isset( $e['reason'] ) ? (string) $e['reason'] : '' );
		}
		if ( 'cannibalization' === $type ) {
			$terms = isset( $e['shared_terms'] ) ? implode( ', ', array_slice( (array) $e['shared_terms'], 0, 5 ) ) : '';
			$note  = isset( $e['notes'][0] ) ? (string) $e['notes'][0] : '';
			/* translators: 1: other page, 2: topic similarity in percent, 3: shared terms, 4: analyzer note */
			return sprintf( __( 'With "%1$s": topic similarity %2$d%%; shared terms: %3$s. %4$s', 'semantic-seo-engine-vi-en' ), $target, (int) round( 100 * ( isset( $e['topic'] ) ? (float) $e['topic'] : 0 ) ), $terms, $note );
		}
		$parts = array();
		if ( isset( $e['anchor'] ) && is_string( $e['anchor'] ) ) {
			/* translators: %s: anchor text */
			$parts[] = sprintf( __( 'anchor "%s"', 'semantic-seo-engine-vi-en' ), $e['anchor'] );
		}
		if ( isset( $e['url'] ) && is_string( $e['url'] ) && 'canonical_conflict' !== $check ) {
			$parts[] = $e['url'];
		}
		if ( isset( $e['links'] ) && is_array( $e['links'] ) ) {
			foreach ( array_slice( $e['links'], 0, 3 ) as $l ) {
				$parts[] = ( isset( $l['anchor'] ) ? '"' . $l['anchor'] . '" → ' : '' ) . ( isset( $l['target_url'] ) ? $l['target_url'] : '' );
			}
		}
		if ( isset( $e['canonical'] ) ) {
			/* translators: %s: canonical URL */
			$parts[] = sprintf( __( 'canonical: %s', 'semantic-seo-engine-vi-en' ), (string) $e['canonical'] );
		}
		if ( isset( $e['word_count'], $e['threshold'] ) ) {
			/* translators: 1: words, 2: reference threshold, 3: intent */
			$parts[] = sprintf( __( '%1$d words; reference for %3$s pages: %2$d', 'semantic-seo-engine-vi-en' ), (int) $e['word_count'], (int) $e['threshold'], isset( $e['intent'] ) ? (string) $e['intent'] : '' );
		}
		if ( isset( $e['links_to_cluster'], $e['cluster_size'] ) ) {
			/* translators: 1: links, 2: pages in the group */
			$parts[] = sprintf( __( '%1$d links to the %2$d pages of its topic group', 'semantic-seo-engine-vi-en' ), (int) $e['links_to_cluster'], (int) $e['cluster_size'] );
		}
		if ( isset( $e['missing_alt'], $e['images'] ) ) {
			/* translators: 1: images without alt, 2: images */
			$parts[] = sprintf( __( '%1$d of %2$d images without alt text', 'semantic-seo-engine-vi-en' ), (int) $e['missing_alt'], (int) $e['images'] );
		}
		if ( isset( $e['skips'] ) ) {
			$parts[] = implode( '; ', array_slice( (array) $e['skips'], 0, 3 ) );
		}
		if ( isset( $e['phrase'], $e['count'] ) ) {
			/* translators: 1: phrase, 2: count */
			$parts[] = sprintf( __( '"%1$s" × %2$d', 'semantic-seo-engine-vi-en' ), (string) $e['phrase'], (int) $e['count'] );
		}
		if ( isset( $e['missing_languages'] ) ) {
			/* translators: %s: language codes */
			$parts[] = sprintf( __( 'missing: %s', 'semantic-seo-engine-vi-en' ), strtoupper( implode( ', ', (array) $e['missing_languages'] ) ) );
		}
		if ( isset( $e['assigned'], $e['detected']['lang'] ) ) {
			/* translators: 1: assigned language, 2: detected language */
			$parts[] = sprintf( __( 'assigned %1$s, text looks %2$s', 'semantic-seo-engine-vi-en' ), strtoupper( (string) $e['assigned'] ), strtoupper( (string) $e['detected']['lang'] ) );
		}
		if ( 'orphan' === $check ) {
			$parts[] = __( 'no internal link to this page was found in post content or category descriptions (menus and widgets are not observed)', 'semantic-seo-engine-vi-en' );
		}
		if ( '' !== $target && 'orphan' !== $check ) {
			/* translators: %s: other page */
			$parts[] = sprintf( __( 'other page: "%s"', 'semantic-seo-engine-vi-en' ), $target );
		}
		return implode( ' · ', array_filter( array_map( 'strval', $parts ) ) );
	}

	/**
	 * Priority name.
	 *
	 * @param int $p Priority.
	 * @return string
	 */
	public static function priority_label( $p ) {
		$l = array(
			1 => __( 'Do now', 'semantic-seo-engine-vi-en' ),
			2 => __( 'Do soon', 'semantic-seo-engine-vi-en' ),
			3 => __( 'Should do', 'semantic-seo-engine-vi-en' ),
			4 => __( 'When there is time', 'semantic-seo-engine-vi-en' ),
		);
		return isset( $l[ $p ] ) ? $l[ $p ] : (string) $p;
	}

	/**
	 * Category name.
	 *
	 * @param string $c Category.
	 * @return string
	 */
	public static function category_label( $c ) {
		$l = array(
			'technical'   => __( 'Technical', 'semantic-seo-engine-vi-en' ),
			'links'       => __( 'Internal links', 'semantic-seo-engine-vi-en' ),
			'overlap'     => __( 'Keyword / intent overlap', 'semantic-seo-engine-vi-en' ),
			'content'     => __( 'Content quality', 'semantic-seo-engine-vi-en' ),
			'translation' => __( 'VI–EN languages', 'semantic-seo-engine-vi-en' ),
			'structure'   => __( 'Topic structure', 'semantic-seo-engine-vi-en' ),
		);
		return isset( $l[ $c ] ) ? $l[ $c ] : $c;
	}

	/**
	 * Confidence name.
	 *
	 * @param string $c Confidence.
	 * @return string
	 */
	public static function confidence_label( $c ) {
		$l = array(
			'high'          => __( 'High', 'semantic-seo-engine-vi-en' ),
			'medium'        => __( 'Medium', 'semantic-seo-engine-vi-en' ),
			'low'           => __( 'Low', 'semantic-seo-engine-vi-en' ),
			'manual_review' => __( 'Needs a human look', 'semantic-seo-engine-vi-en' ),
		);
		return isset( $l[ $c ] ) ? $l[ $c ] : $c;
	}

	/**
	 * Kind of finding: verified error, heuristic warning or editorial suggestion.
	 *
	 * @param string $label Finding label.
	 * @return string verified|heuristic|editorial
	 */
	public static function finding_kind( $label ) {
		if ( 'verified_error' === $label ) {
			return 'verified';
		}
		return in_array( $label, array( 'warning', 'risk_hypothesis' ), true ) ? 'heuristic' : 'editorial';
	}
}
