<?php
/**
 * M5 — content QA gate with strictly separated labels.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Quality;

use SemanticSEO\Compatibility\SeoAdapter;
use SemanticSEO\Database\Db;
use SemanticSEO\Language\LanguageResolver;
use SemanticSEO\Language\TextNormalizer;

defined( 'ABSPATH' ) || exit;

/**
 * Labels: verified_error — objectively checkable fact (e.g. link to a trashed post, missing title).
 * warning         — rule-based problem that needs human judgement.
 * suggestion      — heuristic hint (thin relative to intent, repetitive phrasing…).
 * No quality/E-E-A-T score and no truth verification are produced.
 */
final class QaChecker {

	const VERSION = 'qa-3';

	const CHECKS = array(
		'missing_title'              => 'Missing title',
		'title_length'               => 'Title length outside 15–65 characters (heuristic)',
		'duplicate_title'            => 'Duplicate title in the same locale',
		'missing_meta_description'   => 'Missing meta description (SEO plugin active)',
		'duplicate_meta_description' => 'Duplicate meta description in the same locale',
		'h1_in_content'              => 'H1 heading inside content',
		'heading_skip'               => 'Heading level skipped',
		'no_subheadings'             => 'Long content without H2 subheadings',
		'broken_internal_link'       => 'Internal link to a non-public or deleted object',
		'unresolved_internal_link'   => 'Internal link not matched to an inventoried URL',
		'http_error_link'            => 'Internal link returned an HTTP error when verified',
		'redirected_internal_link'   => 'Internal link points to an old URL that redirects',
		'missing_translation'        => 'Missing translation in another site language',
		'possible_translation_pair'  => 'Possible translation in another language, not linked yet (cross-language semantic similarity)',
		'canonical_conflict'         => 'Canonical points to a different URL',
		'canonical_cross_locale'     => 'Canonical points to a page in another locale',
		'noindex_linked'             => 'Noindex page receives internal links',
		'language_mismatch'          => 'Detected text language differs from assigned locale',
		'near_duplicate'             => 'Near-duplicate content (same locale)',
		'repetitive_phrasing'        => 'Excessively repeated phrase',
		'thin_for_intent'            => 'Short content relative to heuristic intent',
		'orphan'                     => 'No observed inbound internal links',
		'few_cluster_links'          => 'Few links to pages in the same topic cluster',
		'schema_duplication_risk'    => 'Possible duplicate structured data',
		'images_missing_alt'         => 'Images without alt text',
		'no_external_sources'        => 'Long informational article without any outside source (E-E-A-T heuristic)',
	);

	/**
	 * Translated, human-readable name of a check (the CHECKS text stays English in evidence and API).
	 *
	 * @param string $check_id Check ID.
	 * @return string
	 */
	public static function label( $check_id ) {
		$labels = array(
			'missing_title'              => __( 'Missing title', 'semantic-seo-engine-vi-en' ),
			'title_length'               => __( 'Title length outside 15–65 characters (heuristic)', 'semantic-seo-engine-vi-en' ),
			'duplicate_title'            => __( 'Duplicate title in the same locale', 'semantic-seo-engine-vi-en' ),
			'missing_meta_description'   => __( 'Missing meta description (SEO plugin active)', 'semantic-seo-engine-vi-en' ),
			'duplicate_meta_description' => __( 'Duplicate meta description in the same locale', 'semantic-seo-engine-vi-en' ),
			'h1_in_content'              => __( 'H1 heading inside content', 'semantic-seo-engine-vi-en' ),
			'heading_skip'               => __( 'Heading level skipped', 'semantic-seo-engine-vi-en' ),
			'no_subheadings'             => __( 'Long content without H2 subheadings', 'semantic-seo-engine-vi-en' ),
			'broken_internal_link'       => __( 'Internal link to a non-public or deleted object', 'semantic-seo-engine-vi-en' ),
			'unresolved_internal_link'   => __( 'Internal link not matched to an inventoried URL', 'semantic-seo-engine-vi-en' ),
			'http_error_link'            => __( 'Internal link returned an HTTP error when verified', 'semantic-seo-engine-vi-en' ),
			'redirected_internal_link'   => __( 'Internal link points to an old URL that redirects', 'semantic-seo-engine-vi-en' ),
			'missing_translation'        => __( 'Missing translation in another site language', 'semantic-seo-engine-vi-en' ),
			'possible_translation_pair'  => __( 'Possible translation in another language, not linked yet (cross-language semantic similarity)', 'semantic-seo-engine-vi-en' ),
			'canonical_conflict'         => __( 'Canonical points to a different URL', 'semantic-seo-engine-vi-en' ),
			'canonical_cross_locale'     => __( 'Canonical points to a page in another locale', 'semantic-seo-engine-vi-en' ),
			'noindex_linked'             => __( 'Noindex page receives internal links', 'semantic-seo-engine-vi-en' ),
			'language_mismatch'          => __( 'Detected text language differs from assigned locale', 'semantic-seo-engine-vi-en' ),
			'near_duplicate'             => __( 'Near-duplicate content (same locale)', 'semantic-seo-engine-vi-en' ),
			'repetitive_phrasing'        => __( 'Excessively repeated phrase', 'semantic-seo-engine-vi-en' ),
			'thin_for_intent'            => __( 'Short content relative to heuristic intent', 'semantic-seo-engine-vi-en' ),
			'orphan'                     => __( 'No observed inbound internal links', 'semantic-seo-engine-vi-en' ),
			'few_cluster_links'          => __( 'Few links to pages in the same topic cluster', 'semantic-seo-engine-vi-en' ),
			'schema_duplication_risk'    => __( 'Possible duplicate structured data', 'semantic-seo-engine-vi-en' ),
			'images_missing_alt'         => __( 'Images without alt text', 'semantic-seo-engine-vi-en' ),
			'no_external_sources'        => __( 'Long informational article without any outside source (E-E-A-T heuristic)', 'semantic-seo-engine-vi-en' ),
		);
		if ( isset( $labels[ $check_id ] ) ) {
			return $labels[ $check_id ];
		}
		return isset( self::CHECKS[ $check_id ] ) ? self::CHECKS[ $check_id ] : (string) $check_id;
	}

	/**
	 * Site-wide context built once per analysis run.
	 *
	 * @return array
	 */
	public static function context() {
		$titles = array();
		$descs  = array();
		foreach ( Db::rows( "SELECT id, locale, title, meta_description FROM {documents} WHERE status = 'publish' AND object_type = 'post'" ) as $r ) {
			$t = TextNormalizer::normalize( (string) $r['title'] );
			if ( '' !== $t ) {
				$titles[ $r['locale'] . '|' . $t ][] = (int) $r['id'];
			}
			$d = TextNormalizer::normalize( (string) $r['meta_description'] );
			if ( '' !== $d ) {
				$descs[ $r['locale'] . '|' . $d ][] = (int) $r['id'];
			}
		}
		$near = array();
		foreach ( Db::rows( "SELECT document_id, target_id, check_id FROM {findings} WHERE type = 'cannibalization' AND check_id IN ('exact_duplicate','near_duplicate') AND status IN ('open','accepted')" ) as $r ) {
			$near[ (int) $r['document_id'] ][] = (int) $r['target_id'];
			$near[ (int) $r['target_id'] ][]   = (int) $r['document_id'];
		}
		$noindex_inbound = array();
		foreach ( Db::rows( "SELECT l.target_id, COUNT(DISTINCT l.source_id) AS n FROM {links} l JOIN {documents} d ON d.id = l.target_id WHERE d.indexable = 0 AND d.status = 'publish' AND l.resolution = 'resolved' GROUP BY l.target_id" ) as $r ) {
			$noindex_inbound[ (int) $r['target_id'] ] = (int) $r['n'];
		}
		// Content types the site actually translates as linked pairs (e.g. pages but not posts).
		$translated = array();
		foreach ( Db::col( "SELECT DISTINCT subtype FROM {documents} WHERE status = 'publish' AND object_type = 'post' AND translation_group <> ''" ) as $subtype ) {
			$translated[ (string) $subtype ] = true;
		}
		return array(
			'titles'          => $titles,
			'descriptions'    => $descs,
			'translated'      => $translated,
			'near'            => $near,
			'noindex_inbound' => $noindex_inbound,
			'languages'       => LanguageResolver::languages(),
			'mapping'         => LanguageResolver::has_translation_mapping(),
			'seo_active'      => 'core' !== SeoAdapter::primary(),
			'schema_plugin'   => SeoAdapter::outputs_schema(),
		);
	}

	/**
	 * Runs all checks for one document profile.
	 *
	 * @param array $p   Profile (Scorer::profile + row fields).
	 * @param array $row Raw document row.
	 * @param array $ctx Context from context().
	 * @return array<int,array> Findings.
	 */
	public static function check( array $p, array $row, array $ctx ) {
		$out = array();
		$s   = $p['signals'];
		$add = function ( $check, $label, $severity, array $evidence, $confidence = 'high', $target = 0 ) use ( &$out, $p ) {
			$out[] = array(
				'type'              => 'qa',
				'check_id'          => $check,
				'document_id'       => $p['id'],
				'target_id'         => (int) $target,
				'locale'            => $p['locale'],
				'label'             => $label,
				'severity'          => $severity,
				'confidence'        => $confidence,
				'score'             => 'error' === $severity ? 1.0 : ( 'warning' === $severity ? 0.6 : 0.3 ),
				'algorithm_version' => self::VERSION,
				'evidence'          => array_merge( array( 'check' => self::CHECKS[ $check ] ), $evidence ),
			);
		};
		if ( 'publish' !== $p['status'] ) {
			return array();
		}
		$is_post = 'post' === $p['object_type'];
		$title   = trim( (string) $row['title'] );

		if ( '' === $title ) {
			$add( 'missing_title', 'verified_error', 'error', array( 'title' => '' ) );
		} elseif ( $is_post ) {
			$len = mb_strlen( $title, 'UTF-8' );
			if ( $len < 15 || $len > 65 ) {
				$add(
					'title_length',
					'suggestion',
					'info',
					array(
						'length' => $len,
						'note'   => 'Display width, not character count, decides truncation; treat as a hint.',
					),
					'low'
				);
			}
			$key = $p['locale'] . '|' . TextNormalizer::normalize( $title );
			if ( isset( $ctx['titles'][ $key ] ) && count( $ctx['titles'][ $key ] ) > 1 ) {
				$others = array_values( array_diff( $ctx['titles'][ $key ], array( $p['id'] ) ) );
				$add( 'duplicate_title', 'warning', 'warning', array( 'other_documents' => array_slice( $others, 0, 10 ) ), 'high', $others[0] );
			}
		}
		if ( $is_post && $ctx['seo_active'] ) {
			$desc = trim( (string) $row['meta_description'] );
			if ( '' === $desc ) {
				$add(
					'missing_meta_description',
					'warning',
					'warning',
					array(
						'seo_source' => $row['seo_source'],
						'note'       => 'The SEO plugin may generate a description from a template; only the stored per-post value was read.',
					),
					'medium'
				);
			} else {
				$key = $p['locale'] . '|' . TextNormalizer::normalize( $desc );
				if ( isset( $ctx['descriptions'][ $key ] ) && count( $ctx['descriptions'][ $key ] ) > 1 ) {
					$others = array_values( array_diff( $ctx['descriptions'][ $key ], array( $p['id'] ) ) );
					$add( 'duplicate_meta_description', 'warning', 'warning', array( 'other_documents' => array_slice( $others, 0, 10 ) ), 'high', $others[0] );
				}
			}
		}

		// Headings.
		$headings = isset( $s['headings'] ) ? (array) $s['headings'] : array();
		$h1       = 0;
		$prev     = 1;
		$skips    = array();
		foreach ( $headings as $h ) {
			$lvl = (int) $h['level'];
			if ( 1 === $lvl ) {
				++$h1;
			}
			if ( $lvl > $prev + 1 ) {
				$skips[] = 'H' . $prev . '→H' . $lvl . ' "' . mb_substr( (string) $h['text'], 0, 60, 'UTF-8' ) . '"';
			}
			$prev = $lvl;
		}
		if ( $is_post && $h1 > 0 ) {
			$add(
				'h1_in_content',
				'warning',
				'warning',
				array(
					'count' => $h1,
					'note'  => 'Most themes already print the post title as H1. Changing headings requires a separate, explicit approval.',
				),
				'medium'
			);
		}
		if ( array() !== $skips ) {
			$add( 'heading_skip', 'warning', 'info', array( 'skips' => array_slice( $skips, 0, 5 ) ), 'high' );
		}
		$h2 = count(
			array_filter(
				$headings,
				static function ( $h ) {
					return 2 === (int) $h['level'];
				}
			)
		);
		if ( $is_post && 0 === $h2 && $p['word_count'] >= 800 ) {
			$add( 'no_subheadings', 'suggestion', 'info', array( 'word_count' => $p['word_count'] ), 'medium' );
		}

		// Links.
		$broken = Db::rows( "SELECT target_url, anchor, resolution, http_status, target_id FROM {links} WHERE source_id = %d AND is_internal = 1 AND (resolution IN ('broken','non_public') OR http_status >= 400) LIMIT 20", array( $p['id'] ) );
		foreach ( $broken as $b ) {
			if ( (int) $b['http_status'] >= 400 ) {
				$add(
					'http_error_link',
					'verified_error',
					'error',
					array(
						'url'         => $b['target_url'],
						'anchor'      => $b['anchor'],
						'http_status' => (int) $b['http_status'],
					),
					'high',
					(int) $b['target_id']
				);
			} else {
				$add(
					'broken_internal_link',
					'verified_error',
					'error',
					array(
						'url'        => $b['target_url'],
						'anchor'     => $b['anchor'],
						'resolution' => $b['resolution'],
					),
					'high',
					(int) $b['target_id']
				);
			}
		}
		$redirects = Db::rows( "SELECT target_url, anchor, target_id FROM {links} WHERE source_id = %d AND resolution = 'redirect' LIMIT 10", array( $p['id'] ) );
		foreach ( $redirects as $rd ) {
			$add(
				'redirected_internal_link',
				'warning',
				'info',
				array(
					'url'    => $rd['target_url'],
					'anchor' => $rd['anchor'],
					'note'   => 'WordPress redirects this old slug; linking to the current URL avoids a redirect hop.',
				),
				'high',
				(int) $rd['target_id']
			);
		}
		$unresolved = Db::rows( "SELECT target_url, anchor FROM {links} WHERE source_id = %d AND is_internal = 1 AND resolution = 'unresolved' LIMIT 10", array( $p['id'] ) );
		if ( array() !== $unresolved ) {
			$add(
				'unresolved_internal_link',
				'warning',
				'warning',
				array(
					'links' => $unresolved,
					'note'  => 'May be an archive, attachment or custom route outside the inventory; not HTTP-verified unless link verification is enabled.',
				),
				'low'
			);
		}

		// Translations (only when a mapping can be verified). A missing translation is a suggestion for
		// human review, not a warning: many bilingual sites translate selectively, by search intent.
		// Only for content types the site translates as linked pairs at all: the read-only pilot on a
		// copy of the blog found its English posts written as standalone articles (no post pair), so
		// every post would otherwise be "missing" a translation.
		// Never for a post the language plugin declares standalone by design.
		$translated_type = ! isset( $ctx['translated'] ) || isset( $ctx['translated'][ $p['subtype'] ] );
		$standalone      = ! empty( $s['translation_standalone'] );
		if ( $is_post && $translated_type && ! $standalone && $ctx['mapping'] && count( $ctx['languages'] ) > 1 ) {
			$tr      = isset( $s['translations'] ) ? array_keys( (array) $s['translations'] ) : array();
			$missing = array_values( array_diff( $ctx['languages'], $tr, array( $p['locale'] ) ) );
			if ( array() !== $missing ) {
				$add(
					'missing_translation',
					'suggestion',
					'info',
					array(
						'missing_languages' => $missing,
						'framework'         => LanguageResolver::framework(),
					),
					'high'
				);
			}
		}

		// Canonical / robots.
		$reasons = isset( $s['indexability'] ) ? (array) $s['indexability'] : array();
		if ( in_array( 'canonical points elsewhere', $reasons, true ) ) {
			$target = Db::row( 'SELECT id, locale FROM {documents} WHERE url_hash = %s', array( \SemanticSEO\Inventory\DocumentRepository::url_hash( (string) $row['canonical'] ) ) );
			if ( null !== $target && $target['locale'] !== $p['locale'] ) {
				$add(
					'canonical_cross_locale',
					'warning',
					'warning',
					array(
						'canonical'     => $row['canonical'],
						'target_locale' => $target['locale'],
						'note'          => 'Cross-language canonicals usually conflict with hreflang.',
					),
					'high',
					(int) $target['id']
				);
			} else {
				$add(
					'canonical_conflict',
					'warning',
					'info',
					array(
						'canonical' => $row['canonical'],
						'url'       => $row['url'],
						'note'      => 'May be intentional; never changed by this plugin.',
					),
					'high'
				);
			}
		}
		if ( isset( $ctx['noindex_inbound'][ $p['id'] ] ) && in_array( 'noindex', $reasons, true ) ) {
			$add( 'noindex_linked', 'suggestion', 'info', array( 'inbound_sources' => $ctx['noindex_inbound'][ $p['id'] ] ), 'medium' );
		}

		// Language mismatch (detector is a heuristic signal).
		$det = isset( $s['detected_lang'] ) ? (array) $s['detected_lang'] : array();
		if ( isset( $det['lang'], $det['confidence'] ) && in_array( $det['lang'], array( 'vi', 'en' ), true ) && in_array( $p['locale'], array( 'vi', 'en' ), true ) && $det['lang'] !== $p['locale'] && 'low' !== $det['confidence'] ) {
			$add(
				'language_mismatch',
				'warning',
				'warning',
				array(
					'assigned'      => $p['locale'],
					'locale_source' => $row['locale_source'],
					'detected'      => $det,
				),
				$det['confidence']
			);
		}

		// E-E-A-T signal observable in the content (heuristic, not a quality verdict): a long
		// informational post that cites no outside source.
		if ( $is_post && 'post' === $p['subtype'] ) {
			if ( 'informational' === $p['intent'] && $p['word_count'] >= 600 ) {
				$external = (int) Db::value( 'SELECT COUNT(*) FROM {links} WHERE source_id = %d AND is_internal = 0', array( $p['id'] ) );
				if ( 0 === $external ) {
					$add(
						'no_external_sources',
						'suggestion',
						'info',
						array(
							'word_count' => $p['word_count'],
							'note'       => 'No link to an outside source in a long informational article. Citing primary sources (regulations, official prices, maps) can support trust; add them only where they genuinely help the reader.',
						),
						'low'
					);
				}
			}
		}

		// Duplication, repetition, length.
		if ( isset( $ctx['near'][ $p['id'] ] ) ) {
			$add( 'near_duplicate', 'warning', 'warning', array( 'similar_documents' => array_slice( array_values( array_unique( $ctx['near'][ $p['id'] ] ) ), 0, 10 ) ), 'medium', $ctx['near'][ $p['id'] ][0] );
		}
		$rep = isset( $s['repetition'] ) ? (array) $s['repetition'] : array();
		if ( isset( $rep['count'] ) && $rep['count'] >= 6 && $rep['density'] >= 0.06 && $p['word_count'] >= 150 ) {
			$add(
				'repetitive_phrasing',
				'suggestion',
				'info',
				array(
					'phrase'  => $rep['phrase'],
					'count'   => $rep['count'],
					'density' => $rep['density'],
				),
				'medium'
			);
		}
		if ( $is_post ) {
			$min = array(
				'informational' => 300,
				'commercial'    => 300,
				'transactional' => 120,
				'navigational'  => 40,
			);
			if ( isset( $min[ $p['intent'] ] ) && $p['word_count'] < $min[ $p['intent'] ] ) {
				$add(
					'thin_for_intent',
					'suggestion',
					'info',
					array(
						'word_count' => $p['word_count'],
						'intent'     => $p['intent'],
						'threshold'  => $min[ $p['intent'] ],
						'note'       => 'Length is not quality; this only flags pages that may not answer the expected question.',
					),
					'low'
				);
			}
		}

		// Graph.
		$front = (int) get_option( 'page_on_front' );
		if ( $is_post && $p['indexable'] && 0 === $p['inbound_count'] && $p['object_id'] !== $front ) {
			$add( 'orphan', 'warning', 'warning', array( 'coverage' => 'Only links inside post content and term descriptions were observed; menus, widgets and templates were not.' ), 'medium' );
		}
		if ( $is_post && $p['cluster_id'] > 0 ) {
			$in_cluster = (int) Db::value(
				"SELECT COUNT(DISTINCT l.target_id) FROM {links} l JOIN {documents} d ON d.id = l.target_id WHERE l.source_id = %d AND l.resolution = 'resolved' AND d.cluster_id = %d",
				array( $p['id'], $p['cluster_id'] )
			);
			$size       = (int) Db::value( "SELECT COUNT(*) FROM {documents} WHERE cluster_id = %d AND status = 'publish'", array( $p['cluster_id'] ) );
			if ( $size >= 3 && $in_cluster < 1 ) {
				$add(
					'few_cluster_links',
					'suggestion',
					'info',
					array(
						'links_to_cluster' => $in_cluster,
						'cluster_size'     => $size,
					),
					'medium'
				);
			}
		}

		// Structured data (only with evidence).
		if ( ! empty( $s['jsonld_in_content'] ) && $ctx['schema_plugin'] ) {
			$add(
				'schema_duplication_risk',
				'warning',
				'warning',
				array(
					'inline_jsonld_blocks' => (int) $s['jsonld_in_content'],
					'seo_plugin'           => SeoAdapter::primary(),
					'note'                 => 'Evidence: JSON-LD inside content and an SEO plugin that outputs schema. Check the rendered page before changing anything.',
				),
				'medium'
			);
		}
		if ( ! empty( $s['images_no_alt'] ) ) {
			$add(
				'images_missing_alt',
				'warning',
				'info',
				array(
					'images'      => (int) $s['images'],
					'missing_alt' => (int) $s['images_no_alt'],
				),
				'high'
			);
		}
		return $out;
	}
}
