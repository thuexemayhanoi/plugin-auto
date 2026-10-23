<?php
/**
 * Read-only adapters for SEO plugin metadata. This plugin never writes SEO plugin data.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Compatibility;

defined( 'ABSPATH' ) || exit;

/**
 * Detects the active SEO plugin and reads its per-post metadata.
 */
final class SeoAdapter {

	/**
	 * Detected SEO plugins (all active ones, in priority order).
	 *
	 * @return string[]
	 */
	public static function detected() {
		$found = array();
		if ( defined( 'WPSEO_VERSION' ) ) {
			$found[] = 'yoast';
		}
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$found[] = 'rankmath';
		}
		if ( defined( 'AIOSEO_VERSION' ) ) {
			$found[] = 'aioseo';
		}
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			$found[] = 'seopress';
		}
		if ( defined( 'THE_SEO_FRAMEWORK_VERSION' ) ) {
			$found[] = 'tsf';
		}
		/**
		 * Filters the detected SEO plugins (priority order). Only these adapters exist:
		 * yoast, rankmath, aioseo, seopress, tsf.
		 *
		 * @param string[] $found Detected plugin keys.
		 */
		$found = (array) apply_filters( 'sseo_detected_seo_plugins', $found );
		return array_values( array_intersect( $found, array( 'yoast', 'rankmath', 'aioseo', 'seopress', 'tsf' ) ) );
	}

	/**
	 * Primary (authoritative) SEO source.
	 *
	 * @return string
	 */
	public static function primary() {
		$found = self::detected();
		return array() === $found ? 'core' : $found[0];
	}

	/**
	 * Whether the site appears to output schema through an SEO plugin (evidence for QA).
	 *
	 * @return bool
	 */
	public static function outputs_schema() {
		return in_array( self::primary(), array( 'yoast', 'rankmath', 'aioseo', 'seopress', 'tsf' ), true );
	}

	/**
	 * Metadata of a post from the authoritative source.
	 *
	 * @param \WP_Post $post Post.
	 * @return array{source:string,title:string,description:string,focus_keyword:string,canonical:string,noindex:bool,robots:string,title_is_template:bool}
	 */
	public static function post_meta( \WP_Post $post ) {
		$source = self::primary();
		$id     = (int) $post->ID;
		$meta   = array(
			'source'            => $source,
			'title'             => '',
			'description'       => '',
			'focus_keyword'     => '',
			'canonical'         => '',
			'noindex'           => false,
			'robots'            => '',
			'title_is_template' => false,
		);
		switch ( $source ) {
			case 'yoast':
				$meta['title']         = (string) get_post_meta( $id, '_yoast_wpseo_title', true );
				$meta['description']   = (string) get_post_meta( $id, '_yoast_wpseo_metadesc', true );
				$meta['focus_keyword'] = (string) get_post_meta( $id, '_yoast_wpseo_focuskw', true );
				$meta['canonical']     = (string) get_post_meta( $id, '_yoast_wpseo_canonical', true );
				$flag                  = (string) get_post_meta( $id, '_yoast_wpseo_meta-robots-noindex', true );
				if ( '1' === $flag ) {
					$meta['noindex'] = true;
				} elseif ( '' === $flag ) {
					$titles          = get_option( 'wpseo_titles', array() );
					$meta['noindex'] = is_array( $titles ) && ! empty( $titles[ 'noindex-' . $post->post_type ] );
				}
				break;
			case 'rankmath':
				$meta['title']         = (string) get_post_meta( $id, 'rank_math_title', true );
				$meta['description']   = (string) get_post_meta( $id, 'rank_math_description', true );
				$kw                    = (string) get_post_meta( $id, 'rank_math_focus_keyword', true );
				$meta['focus_keyword'] = trim( (string) strtok( $kw, ',' ) );
				$meta['canonical']     = (string) get_post_meta( $id, 'rank_math_canonical_url', true );
				$robots                = get_post_meta( $id, 'rank_math_robots', true );
				$meta['noindex']       = is_array( $robots ) && in_array( 'noindex', $robots, true );
				break;
			case 'seopress':
				$meta['title']         = (string) get_post_meta( $id, '_seopress_titles_title', true );
				$meta['description']   = (string) get_post_meta( $id, '_seopress_titles_desc', true );
				$meta['focus_keyword'] = trim( (string) strtok( (string) get_post_meta( $id, '_seopress_analysis_target_kw', true ), ',' ) );
				$meta['canonical']     = (string) get_post_meta( $id, '_seopress_robots_canonical', true );
				$meta['noindex']       = 'yes' === (string) get_post_meta( $id, '_seopress_robots_index', true );
				break;
			case 'tsf':
				$meta['title']       = (string) get_post_meta( $id, '_genesis_title', true );
				$meta['description'] = (string) get_post_meta( $id, '_genesis_description', true );
				$meta['canonical']   = (string) get_post_meta( $id, '_genesis_canonical_uri', true );
				$meta['noindex']     = '1' === (string) get_post_meta( $id, '_genesis_noindex', true );
				break;
			case 'aioseo':
				$meta = array_merge( $meta, self::aioseo_meta( $id ) );
				break;
		}
		if ( ! get_option( 'blog_public', 1 ) ) {
			$meta['noindex'] = true;
			$meta['robots']  = 'noindex (site visibility setting)';
		} elseif ( $meta['noindex'] ) {
			$meta['robots'] = 'noindex';
		} else {
			$meta['robots'] = 'index';
		}
		if ( preg_match( '/%%?[a-z_]+%%?/i', $meta['title'] ) ) {
			$meta['title_is_template'] = true;
		}
		$meta['source'] = $source;
		return $meta;
	}

	/**
	 * Reads AIOSEO's own table when present.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	private static function aioseo_meta( $post_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'aioseo_posts';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT title, description, keyphrases, canonical_url, robots_noindex FROM {$table} WHERE post_id = %d", $post_id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return array();
		}
		$kw   = '';
		$keys = json_decode( (string) $row['keyphrases'], true );
		if ( is_array( $keys ) && isset( $keys['focus']['keyphrase'] ) ) {
			$kw = (string) $keys['focus']['keyphrase'];
		}
		return array(
			'title'         => (string) $row['title'],
			'description'   => (string) $row['description'],
			'focus_keyword' => $kw,
			'canonical'     => (string) $row['canonical_url'],
			'noindex'       => ! empty( $row['robots_noindex'] ),
		);
	}
}
