<?php
/**
 * Plugin settings: one non-autoloaded option with strict whitelist sanitization.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO;

use SemanticSEO\Support\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Settings repository. Defaults are read-only and preview-only.
 */
final class Settings {

	const OPTION = 'sseo_settings';

	/**
	 * Abilities that are read-only and may be exposed through the MCP adapter.
	 */
	const READ_ONLY_ABILITIES = array(
		'semantic-seo/get-site-status',
		'semantic-seo/list-urls',
		'semantic-seo/get-url-analysis',
		'semantic-seo/get-link-suggestions',
		'semantic-seo/get-cannibalization-report',
		'semantic-seo/get-topic-clusters',
		'semantic-seo/get-qa-issues',
		'semantic-seo/get-job-status',
	);

	/**
	 * All ability names (REST exposure may be opted in individually).
	 */
	const ALL_ABILITIES = array(
		'semantic-seo/get-site-status',
		'semantic-seo/list-urls',
		'semantic-seo/get-url-analysis',
		'semantic-seo/get-link-suggestions',
		'semantic-seo/get-cannibalization-report',
		'semantic-seo/get-topic-clusters',
		'semantic-seo/get-qa-issues',
		'semantic-seo/get-job-status',
		'semantic-seo/get-audit-log',
		'semantic-seo/start-analysis',
		'semantic-seo/cancel-analysis',
		'semantic-seo/rebuild-index',
		'semantic-seo/create-change-preview',
		'semantic-seo/apply-approved-change',
		'semantic-seo/rollback-change',
	);

	/**
	 * Request-level cache.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Default settings. Everything that could touch the live site is off.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'post_types'           => array( 'post', 'page' ),
			'taxonomies'           => array( 'category' ),
			'include_drafts'       => false,
			'default_locale'       => self::site_language(),
			'url_prefix_locales'   => array(),
			'exclude_post_ids'     => array(),
			'exclude_url_patterns' => array(),
			'batch_size'           => 25,
			'time_budget'          => 20,
			'max_documents'        => 5000,
			'auto_incremental'     => true,
			'allow_proposals'      => true,
			'agents_may_apply'     => false,
			'approval_ttl_hours'   => 24,
			'rate_read'            => 120,
			'rate_analyze'         => 10,
			'rate_write'           => 10,
			'audit_retention_days' => 180,
			'related_enabled'      => false,
			'related_limit'        => 5,
			'related_title'        => '',
			'related_css'          => true,
			'abilities_rest'       => array(),
			'mcp_enabled'          => false,
			'mcp_abilities'        => array(),
			'inference_tier'       => 'lexical',
			'model_dtype'          => 'q4f16',
			'embedding_dimension'  => 1024,
			'embed_body_chunks'    => 2,
			'ui_language'          => 'vi',
			'verify_http'          => false,
			'uninstall_policy'     => 'preserve',
			'wizard_completed'     => false,
			'link_max_per_source'  => 3,
			'link_min_score'       => 0.30,
			'related_min_score'    => 0.15,
			'cannibal_min_score'   => 0.30,
			'near_duplicate'       => 0.80,
			'candidate_k'          => 15,
			'w_lexical'            => 0.45,
			'w_semantic'           => 0.35,
			'w_anchor'             => 0.10,
			'w_graph'              => 0.10,
		);
	}

	/**
	 * Two-letter site language derived from the WordPress locale.
	 *
	 * @return string
	 */
	public static function site_language() {
		$locale = function_exists( 'get_locale' ) ? get_locale() : 'en_US';
		$lang   = strtolower( substr( (string) $locale, 0, 2 ) );
		return preg_match( '/^[a-z]{2}$/', $lang ) ? $lang : 'en';
	}

	/**
	 * Returns all settings merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = self::sanitize( is_array( $stored ) ? $stored : array(), false );
		}
		return self::$cache;
	}

	/**
	 * Returns one setting.
	 *
	 * @param string $key           Key.
	 * @param mixed  $default_value Fallback when unknown.
	 * @return mixed
	 */
	public static function get( $key, $default_value = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default_value;
	}

	/**
	 * Updates settings (partial). Caller must have checked sseo_manage and the nonce.
	 *
	 * @param array $changes Partial settings.
	 * @return array New settings.
	 */
	public static function update( array $changes ) {
		$merged = array_merge( self::all(), $changes );
		$clean  = self::sanitize( $merged, true );
		update_option( self::OPTION, $clean, false );
		self::$cache = $clean;
		return $clean;
	}

	/**
	 * Ensures the option exists without overwriting an existing value.
	 *
	 * @return void
	 */
	public static function install_defaults() {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, self::defaults(), '', false );
		}
		self::$cache = null;
	}

	/**
	 * Clears the request cache (tests, after external option writes).
	 *
	 * @return void
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Whitelist sanitization. Unknown keys are dropped.
	 *
	 * @param array $input  Raw input.
	 * @param bool  $strict Unused flag kept for clarity of call sites.
	 * @return array
	 */
	public static function sanitize( array $input, $strict = true ) {
		unset( $strict );
		$d   = self::defaults();
		$out = array();

		$out['post_types'] = self::slug_list( isset( $input['post_types'] ) ? $input['post_types'] : $d['post_types'], 20 );
		if ( array() === $out['post_types'] ) {
			$out['post_types'] = $d['post_types'];
		}
		$out['taxonomies']     = self::slug_list( isset( $input['taxonomies'] ) ? $input['taxonomies'] : $d['taxonomies'], 10 );
		$out['include_drafts'] = self::bool( $input, 'include_drafts', $d );
		$lang                  = isset( $input['default_locale'] ) ? strtolower( (string) $input['default_locale'] ) : $d['default_locale'];
		$out['default_locale'] = preg_match( '/^[a-z]{2}$/', $lang ) ? $lang : $d['default_locale'];

		$prefixes = array();
		if ( isset( $input['url_prefix_locales'] ) && is_array( $input['url_prefix_locales'] ) ) {
			foreach ( $input['url_prefix_locales'] as $code => $prefix ) {
				$code   = strtolower( (string) $code );
				$prefix = '/' . trim( (string) $prefix, "/ \t" ) . '/';
				if ( preg_match( '/^[a-z]{2}$/', $code ) && preg_match( '#^/[a-z0-9\-]{1,20}/$#i', $prefix ) ) {
					$prefixes[ $code ] = strtolower( $prefix );
				}
			}
		}
		$out['url_prefix_locales'] = array_slice( $prefixes, 0, 10, true );

		$ids = array();
		if ( isset( $input['exclude_post_ids'] ) ) {
			$raw = is_array( $input['exclude_post_ids'] ) ? $input['exclude_post_ids'] : preg_split( '/[\s,]+/', (string) $input['exclude_post_ids'] );
			foreach ( (array) $raw as $id ) {
				$id = (int) $id;
				if ( $id > 0 ) {
					$ids[ $id ] = $id;
				}
			}
		}
		$out['exclude_post_ids'] = array_values( array_slice( $ids, 0, 1000 ) );

		$patterns = array();
		if ( isset( $input['exclude_url_patterns'] ) ) {
			$raw = is_array( $input['exclude_url_patterns'] ) ? $input['exclude_url_patterns'] : preg_split( '/[\r\n]+/', (string) $input['exclude_url_patterns'] );
			foreach ( (array) $raw as $p ) {
				$p = trim( (string) $p );
				// Plain path fragments only (no regex) to avoid ReDoS.
				if ( '' !== $p && strlen( $p ) <= 200 && ! preg_match( '/[<>"\'\\\\]/', $p ) ) {
					$patterns[] = $p;
				}
			}
		}
		$out['exclude_url_patterns'] = array_values( array_unique( array_slice( $patterns, 0, 100 ) ) );

		$out['batch_size']           = Util::clamp_int( isset( $input['batch_size'] ) ? $input['batch_size'] : $d['batch_size'], 1, 200 );
		$out['time_budget']          = Util::clamp_int( isset( $input['time_budget'] ) ? $input['time_budget'] : $d['time_budget'], 3, 50 );
		$out['max_documents']        = Util::clamp_int( isset( $input['max_documents'] ) ? $input['max_documents'] : $d['max_documents'], 10, 100000 );
		$out['auto_incremental']     = self::bool( $input, 'auto_incremental', $d );
		$out['allow_proposals']      = self::bool( $input, 'allow_proposals', $d );
		$out['agents_may_apply']     = self::bool( $input, 'agents_may_apply', $d );
		$out['approval_ttl_hours']   = Util::clamp_int( isset( $input['approval_ttl_hours'] ) ? $input['approval_ttl_hours'] : $d['approval_ttl_hours'], 1, 168 );
		$out['rate_read']            = Util::clamp_int( isset( $input['rate_read'] ) ? $input['rate_read'] : $d['rate_read'], 10, 1000 );
		$out['rate_analyze']         = Util::clamp_int( isset( $input['rate_analyze'] ) ? $input['rate_analyze'] : $d['rate_analyze'], 1, 100 );
		$out['rate_write']           = Util::clamp_int( isset( $input['rate_write'] ) ? $input['rate_write'] : $d['rate_write'], 1, 100 );
		$out['audit_retention_days'] = Util::clamp_int( isset( $input['audit_retention_days'] ) ? $input['audit_retention_days'] : $d['audit_retention_days'], 7, 3650 );
		$out['related_enabled']      = self::bool( $input, 'related_enabled', $d );
		$out['related_limit']        = Util::clamp_int( isset( $input['related_limit'] ) ? $input['related_limit'] : $d['related_limit'], 1, 10 );
		$out['related_title']        = isset( $input['related_title'] ) ? sanitize_text_field( (string) $input['related_title'] ) : $d['related_title'];
		$out['related_css']          = self::bool( $input, 'related_css', $d );

		$rest                  = isset( $input['abilities_rest'] ) ? (array) $input['abilities_rest'] : $d['abilities_rest'];
		$out['abilities_rest'] = array_values( array_intersect( self::ALL_ABILITIES, array_map( 'strval', $rest ) ) );
		$out['mcp_enabled']    = self::bool( $input, 'mcp_enabled', $d );
		$mcp                   = isset( $input['mcp_abilities'] ) ? (array) $input['mcp_abilities'] : $d['mcp_abilities'];
		// MCP exposure is restricted to read-only abilities, whatever the input says.
		$out['mcp_abilities'] = array_values( array_intersect( self::READ_ONLY_ABILITIES, array_map( 'strval', $mcp ) ) );

		$tier                       = isset( $input['inference_tier'] ) ? (string) $input['inference_tier'] : $d['inference_tier'];
		$out['inference_tier']      = in_array( $tier, array( 'lexical', 'browser', 'worker' ), true ) ? $tier : $d['inference_tier'];
		$dtype                      = isset( $input['model_dtype'] ) ? (string) $input['model_dtype'] : $d['model_dtype'];
		$out['model_dtype']         = in_array( $dtype, array( 'q4f16', 'q8', 'fp16', 'fp32' ), true ) ? $dtype : $d['model_dtype'];
		$dim                        = isset( $input['embedding_dimension'] ) ? (int) $input['embedding_dimension'] : $d['embedding_dimension'];
		$out['embedding_dimension'] = in_array( $dim, array( 256, 512, 768, 1024 ), true ) ? $dim : $d['embedding_dimension'];
		$out['embed_body_chunks']   = Util::clamp_int( isset( $input['embed_body_chunks'] ) ? $input['embed_body_chunks'] : $d['embed_body_chunks'], 0, 8 );
		$ui                         = isset( $input['ui_language'] ) ? (string) $input['ui_language'] : $d['ui_language'];
		$out['ui_language']         = in_array( $ui, array( 'vi', 'en', 'auto' ), true ) ? $ui : $d['ui_language'];
		$out['verify_http']         = self::bool( $input, 'verify_http', $d );
		$policy                     = isset( $input['uninstall_policy'] ) ? (string) $input['uninstall_policy'] : $d['uninstall_policy'];
		$out['uninstall_policy']    = in_array( $policy, array( 'preserve', 'delete' ), true ) ? $policy : 'preserve';
		$out['wizard_completed']    = self::bool( $input, 'wizard_completed', $d );

		$out['link_max_per_source'] = Util::clamp_int( isset( $input['link_max_per_source'] ) ? $input['link_max_per_source'] : $d['link_max_per_source'], 1, 10 );
		$out['candidate_k']         = Util::clamp_int( isset( $input['candidate_k'] ) ? $input['candidate_k'] : $d['candidate_k'], 5, 50 );
		foreach ( array( 'link_min_score', 'related_min_score', 'cannibal_min_score', 'near_duplicate', 'w_lexical', 'w_semantic', 'w_anchor', 'w_graph' ) as $key ) {
			$out[ $key ] = round( Util::clamp_float( isset( $input[ $key ] ) ? $input[ $key ] : $d[ $key ], 0.0, 1.0 ), 3 );
		}
		return $out;
	}

	/**
	 * Reads a boolean setting.
	 *
	 * @param array  $input Input.
	 * @param string $key   Key.
	 * @param array  $d     Defaults.
	 * @return bool
	 */
	private static function bool( array $input, $key, array $d ) {
		if ( ! array_key_exists( $key, $input ) ) {
			return (bool) $d[ $key ];
		}
		$v = $input[ $key ];
		return true === $v || 1 === $v || '1' === $v || 'true' === $v || 'on' === $v || 'yes' === $v;
	}

	/**
	 * Sanitizes a list of slugs.
	 *
	 * @param mixed $value List.
	 * @param int   $max   Max items.
	 * @return string[]
	 */
	private static function slug_list( $value, $max ) {
		$out = array();
		foreach ( (array) $value as $item ) {
			$item = sanitize_key( (string) $item );
			if ( '' !== $item ) {
				$out[ $item ] = $item;
			}
		}
		return array_values( array_slice( $out, 0, $max ) );
	}
}
