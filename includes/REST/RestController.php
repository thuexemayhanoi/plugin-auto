<?php
/**
 * REST API: /wp-json/semantic-seo/v1 — thin transport over Services\Api.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\REST;

use SemanticSEO\Capabilities;
use SemanticSEO\Services\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Every route has a permission_callback (capability) and schema-validated args; the service
 * layer re-checks permissions and applies rate limits. Approvals are deliberately NOT exposed.
 */
final class RestController {

	const NS            = 'semantic-seo/v1';
	const MAX_BODY_SIZE = 524288;

	/**
	 * Registers routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$read    = self::cap( Capabilities::READ );
		$analyze = self::cap( Capabilities::ANALYZE );
		$apply   = self::cap( Capabilities::APPLY );
		$embed   = self::cap( Capabilities::EMBED );

		self::get( '/status', array( Api::class, 'status' ), $read, array(), 'get-site-status', false );
		self::get( '/inventory', array( Api::class, 'list_urls' ), $read, Schemas::rest_args( 'list-urls' ), 'list-urls' );
		self::get( '/analysis', array( Api::class, 'url_analysis' ), $read, Schemas::rest_args( 'get-url-analysis' ), 'get-url-analysis' );
		self::get( '/suggestions/links', array( Api::class, 'link_suggestions' ), $read, Schemas::rest_args( 'get-link-suggestions' ), 'get-link-suggestions' );
		self::get( '/cannibalization', array( Api::class, 'cannibalization' ), $read, Schemas::rest_args( 'get-cannibalization-report' ), 'get-cannibalization-report' );
		self::get( '/clusters', array( Api::class, 'clusters' ), $read, Schemas::rest_args( 'get-topic-clusters' ), 'get-topic-clusters' );
		self::get( '/qa', array( Api::class, 'qa_issues' ), $read, Schemas::rest_args( 'get-qa-issues' ), 'get-qa-issues' );
		self::get( '/classification', array( Api::class, 'classification' ), $read, Schemas::rest_args( 'get-qa-issues' ), '' );
		self::get( '/related', array( Api::class, 'related' ), $read, Schemas::rest_args( 'related' ), '' );
		self::get( '/audit', array( Api::class, 'audit_log' ), $read, Schemas::rest_args( 'get-audit-log' ), 'get-audit-log' );
		self::get( '/changes', array( Api::class, 'changes' ), $read, array(), '' );
		self::get( '/changes/proposals', array( Api::class, 'proposals' ), $read, array(), '' );

		register_rest_route(
			self::NS,
			'/jobs',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => self::handler( array( Api::class, 'jobs' ) ),
					'permission_callback' => $read,
					'args'                => array(
						'status' => array(
							'type' => 'string',
							'enum' => array( 'queued', 'running', 'paused', 'completed', 'failed', 'cancelled' ),
						),
						'type'   => array(
							'type' => 'string',
							'enum' => array( 'inventory', 'analyze', 'embed', 'incremental', 'verify_links' ),
						),
						'limit'  => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => self::handler( array( Api::class, 'start_analysis' ) ),
					'permission_callback' => $analyze,
					'args'                => Schemas::rest_args( 'start-analysis' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/jobs/(?P<job_id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => self::wrap(
					static function ( \WP_REST_Request $r ) {
						return Api::job_status( (int) $r['job_id'] );
					}
				),
				'permission_callback' => $read,
				'args'                => array(
					'job_id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/jobs/(?P<job_id>\d+)/(?P<op>cancel|pause|resume|tick)',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => self::wrap(
					static function ( \WP_REST_Request $r ) {
						return Api::job_control( (int) $r['job_id'], (string) $r['op'] );
					}
				),
				'permission_callback' => $analyze,
			)
		);
		self::post( '/index/rebuild', array( Api::class, 'rebuild_index' ), $analyze, Schemas::rest_args( 'rebuild-index' ) );
		self::post( '/changes/preview', array( Api::class, 'create_change_preview' ), $analyze, Schemas::rest_args( 'create-change-preview' ) );
		self::post( '/changes/apply', array( Api::class, 'apply_approved_change' ), $apply, Schemas::rest_args( 'apply-approved-change' ) );
		self::post( '/changes/rollback', array( Api::class, 'rollback_change' ), $apply, Schemas::rest_args( 'rollback-change' ) );

		register_rest_route(
			self::NS,
			'/embeddings/profile',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => self::wrap(
					static function () {
						return Api::embedding_profile();
					}
				),
				'permission_callback' => $embed,
			)
		);
		self::post(
			'/embeddings/claim',
			array( Api::class, 'embedding_claim' ),
			$embed,
			array(
				'limit' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 16,
					'default' => 8,
				),
			)
		);
		self::post(
			'/embeddings/complete',
			array( Api::class, 'embedding_complete' ),
			$embed,
			array(
				'lease_token' => array(
					'type'      => 'string',
					'required'  => true,
					'pattern'   => '^[a-f0-9]{48}$',
				),
				'model_key'   => array(
					'type'     => 'string',
					'required' => true,
					'pattern'  => '^[a-f0-9]{40}$',
				),
				'source'      => array(
					'type' => 'string',
					'enum' => array( 'browser', 'worker' ),
				),
				'results'     => array(
					'type'     => 'array',
					'required' => true,
					'maxItems' => 16,
					'items'    => array(
						'type'       => 'object',
						'properties' => array(
							'queue_id' => array(
								'type'     => 'integer',
								'required' => true,
							),
							'vector'   => array(
								'type'     => array( 'string', 'array' ),
								'required' => true,
							),
						),
					),
				),
			)
		);
		self::post(
			'/embeddings/release',
			array( Api::class, 'embedding_release' ),
			$embed,
			array(
				'lease_token' => array(
					'type'     => 'string',
					'required' => true,
					'pattern'  => '^[a-f0-9]{48}$',
				),
				'error'       => array(
					'type'      => 'string',
					'maxLength' => 250,
				),
			)
		);
	}

	/**
	 * Capability permission callback.
	 *
	 * @param string $cap Capability.
	 * @return callable
	 */
	private static function cap( $cap ) {
		return static function () use ( $cap ) {
			if ( ! is_user_logged_in() ) {
				return new \WP_Error( 'rest_forbidden', __( 'Authentication required.', 'semantic-seo-engine-vi-en' ), array( 'status' => 401 ) );
			}
			return current_user_can( $cap ) ? true : new \WP_Error( 'rest_forbidden', __( 'You do not have permission for this action.', 'semantic-seo-engine-vi-en' ), array( 'status' => 403 ) );
		};
	}

	/**
	 * Registers a GET route that passes all params to a service method.
	 *
	 * @param string   $route   Route.
	 * @param callable $method  Service method.
	 * @param callable $perm    Permission callback.
	 * @param array    $args    Args.
	 * @param string   $schema_key Output schema key ('' none).
	 * @param bool     $with_params Whether the method takes params.
	 * @return void
	 */
	private static function get( $route, $method, $perm, array $args, $schema_key, $with_params = true ) {
		register_rest_route(
			self::NS,
			$route,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => $with_params ? self::handler( $method ) : self::wrap(
					static function () use ( $method ) {
						return call_user_func( $method );
					}
				),
				'permission_callback' => $perm,
				'args'                => $args,
				'schema'              => '' === $schema_key ? null : static function () use ( $schema_key ) {
					return array_merge(
						array(
							'$schema' => 'http://json-schema.org/draft-04/schema#',
							'title'   => 'semantic-seo-' . $schema_key,
						),
						self::envelope_schema( Schemas::output( $schema_key ) )
					);
				},
			)
		);
	}

	/**
	 * Registers a POST route.
	 *
	 * @param string   $route  Route.
	 * @param callable $method Service method.
	 * @param callable $perm   Permission callback.
	 * @param array    $args   Args.
	 * @return void
	 */
	private static function post( $route, $method, $perm, array $args ) {
		register_rest_route(
			self::NS,
			$route,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => self::handler( $method ),
				'permission_callback' => $perm,
				'args'                => $args,
			)
		);
	}

	/**
	 * Wraps a service method taking the request params.
	 *
	 * @param callable $method Method.
	 * @return callable
	 */
	private static function handler( $method ) {
		return self::wrap(
			static function ( \WP_REST_Request $r ) use ( $method ) {
				$params = array_merge( (array) $r->get_query_params(), (array) $r->get_json_params(), (array) $r->get_body_params() );
				foreach ( array_keys( $params ) as $k ) {
					if ( null !== $r->get_param( $k ) ) {
						$params[ $k ] = $r->get_param( $k ); // Sanitized/validated value.
					}
				}
				unset( $params['_wpnonce'], $params['_locale'], $params['rest_route'] );
				return call_user_func( $method, $params );
			}
		);
	}

	/**
	 * Converts service results into a predictable envelope.
	 *
	 * @param callable $fn Function.
	 * @return callable
	 */
	private static function wrap( $fn ) {
		return static function ( \WP_REST_Request $r ) use ( $fn ) {
			if ( strlen( (string) $r->get_body() ) > self::MAX_BODY_SIZE ) {
				return self::error( new \WP_Error( 'sseo_payload_too_large', __( 'Request body too large.', 'semantic-seo-engine-vi-en' ), array( 'status' => 413 ) ) );
			}
			try {
				$result = call_user_func( $fn, $r );
			} catch ( \Throwable $e ) {
				return self::error( new \WP_Error( 'sseo_internal_error', __( 'Internal error; see the audit log.', 'semantic-seo-engine-vi-en' ), array( 'status' => 500 ) ) );
			}
			if ( is_wp_error( $result ) ) {
				return self::error( $result );
			}
			return new \WP_REST_Response(
				array(
					'ok'   => true,
					'data' => $result,
					'meta' => self::meta(),
				),
				200
			);
		};
	}

	/**
	 * Error envelope (with Retry-After on 429).
	 *
	 * @param \WP_Error $e Error.
	 * @return \WP_REST_Response
	 */
	public static function error( \WP_Error $e ) {
		$data   = $e->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
		$extra  = is_array( $data ) ? array_diff_key( $data, array( 'status' => 1 ) ) : array();
		$res    = new \WP_REST_Response(
			array(
				'ok'    => false,
				'error' => array(
					'code'    => $e->get_error_code(),
					'message' => $e->get_error_message(),
					'data'    => (object) $extra,
				),
				'meta'  => self::meta(),
			),
			$status
		);
		if ( 429 === $status && isset( $extra['retry_after'] ) ) {
			$res->header( 'Retry-After', (string) (int) $extra['retry_after'] );
		}
		return $res;
	}

	/**
	 * Response meta.
	 *
	 * @return array
	 */
	private static function meta() {
		return array(
			'api_version'    => Api::API_VERSION,
			'plugin_version' => SSEO_VERSION,
			'generated_at'   => gmdate( 'c' ),
		);
	}

	/**
	 * Envelope schema around a data schema.
	 *
	 * @param array $data Data schema.
	 * @return array
	 */
	public static function envelope_schema( array $data ) {
		return array(
			'type'       => 'object',
			'properties' => array(
				'ok'   => array( 'type' => 'boolean' ),
				'data' => $data,
				'meta' => array(
					'type'       => 'object',
					'properties' => array(
						'api_version'    => array( 'type' => 'string' ),
						'plugin_version' => array( 'type' => 'string' ),
						'generated_at'   => array( 'type' => 'string' ),
					),
				),
			),
			'required'   => array( 'ok', 'data', 'meta' ),
		);
	}
}
