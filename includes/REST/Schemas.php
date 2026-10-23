<?php
/**
 * JSON Schemas shared by REST routes and WordPress Abilities (one contract, two transports).
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\REST;

use SemanticSEO\Analysis\FindingRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Uses the JSON Schema subset supported by rest_validate_value_from_schema (draft-04 style).
 */
final class Schemas {

	/**
	 * Common pagination/input fragments.
	 *
	 * @return array
	 */
	public static function paging() {
		return array(
			'limit'  => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => 100,
				'default'     => 20,
				'description' => 'Page size (max 100).',
			),
			'cursor' => array(
				'type'        => 'string',
				'maxLength'   => 512,
				'description' => 'Opaque cursor from a previous response (next_cursor).',
			),
		);
	}

	/**
	 * Target selector (post_id | document_id | url).
	 *
	 * @param bool $required Whether a target is required (validated in the service).
	 * @return array
	 */
	public static function target( $required = false ) {
		unset( $required );
		return array(
			'post_id'     => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'document_id' => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'url'         => array(
				'type'      => 'string',
				'format'    => 'uri',
				'maxLength' => 2048,
			),
		);
	}

	/**
	 * Input schemas by ability/route key.
	 *
	 * @param string $key Key.
	 * @return array
	 */
	public static function input( $key ) {
		$locale = array(
			'type'    => 'string',
			'pattern' => '^[a-z]{2}$',
		);
		switch ( $key ) {
			case 'list-urls':
				return self::object(
					array_merge(
						array(
							'locale'   => $locale,
							'type'     => array(
								'type'      => 'string',
								'maxLength' => 64,
							),
							'status'   => array(
								'type'      => 'string',
								'maxLength' => 20,
							),
							'search'   => array(
								'type'      => 'string',
								'maxLength' => 100,
							),
							'intent'   => array(
								'type' => 'string',
								'enum' => array( '', 'informational', 'transactional', 'commercial', 'navigational', 'unknown' ),
							),
							'orphan'   => array( 'type' => 'boolean' ),
							'has_qa'   => array( 'type' => 'boolean' ),
							'has_risk' => array( 'type' => 'boolean' ),
							'sort'     => array(
								'type' => 'string',
								'enum' => array( 'id', 'title', 'inbound', 'outbound', 'words', 'modified', 'analyzed', 'qa', 'risk', 'suggestions' ),
							),
							'order'    => array(
								'type' => 'string',
								'enum' => array( 'asc', 'desc' ),
							),
						),
						self::paging()
					)
				);
			case 'get-url-analysis':
			case 'related':
				return self::object( self::target() );
			case 'get-link-suggestions':
				return self::object(
					array_merge(
						self::target(),
						array(
							'locale'        => $locale,
							'min_relevance' => array(
								'type'    => 'number',
								'minimum' => 0,
								'maximum' => 1,
							),
							'confidence'    => array(
								'type' => 'string',
								'enum' => FindingRepository::CONFIDENCES,
							),
						),
						self::paging()
					)
				);
			case 'get-cannibalization-report':
				return self::object(
					array_merge(
						array(
							'locale'    => $locale,
							'category'  => array(
								'type' => 'string',
								'enum' => array( 'exact_duplicate', 'near_duplicate', 'potential_competition', 'same_topic_different_intent', 'distinct_facet', 'pillar_supporting', 'translation_mapping_conflict', 'manual_review' ),
							),
							'cluster'   => array( 'type' => 'integer' ),
							'group_key' => array(
								'type'      => 'string',
								'maxLength' => 64,
							),
						),
						self::target(),
						self::paging()
					)
				);
			case 'get-topic-clusters':
				return self::object(
					array(
						'locale'     => $locale,
						'cluster_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					)
				);
			case 'get-qa-issues':
				return self::object(
					array_merge(
						array(
							'severity' => array(
								'type' => 'string',
								'enum' => array( 'error', 'warning', 'info' ),
							),
							'label'    => array(
								'type' => 'string',
								'enum' => array( 'verified_error', 'warning', 'suggestion' ),
							),
							'check_id' => array(
								'type'      => 'string',
								'maxLength' => 64,
							),
							'locale'   => $locale,
						),
						self::target(),
						self::paging()
					)
				);
			case 'get-job-status':
			case 'cancel-analysis':
				return self::object(
					array(
						'job_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					array( 'job_id' )
				);
			case 'get-audit-log':
				return self::object(
					array(
						'action'    => array(
							'type'      => 'string',
							'maxLength' => 64,
						),
						'result'    => array(
							'type' => 'string',
							'enum' => array( 'ok', 'denied', 'error', 'noop' ),
						),
						'object_id' => array( 'type' => 'integer' ),
						'before_id' => array( 'type' => 'integer' ),
						'limit'     => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
						),
					)
				);
			case 'start-analysis':
				return self::object(
					array(
						'type'            => array(
							'type'    => 'string',
							'enum'    => array( 'inventory', 'analyze', 'embed', 'verify_links' ),
							'default' => 'analyze',
						),
						'post_ids'        => array(
							'type'     => 'array',
							'items'    => array(
								'type'    => 'integer',
								'minimum' => 1,
							),
							'maxItems' => 200,
						),
						'locale'          => $locale,
						'then_analyze'    => array( 'type' => 'boolean' ),
						'pilot'           => array( 'type' => 'boolean' ),
						'idempotency_key' => array(
							'type'      => 'string',
							'minLength' => 8,
							'maxLength' => 64,
							'pattern'   => '^[A-Za-z0-9_\\-:.]+$',
						),
					)
				);
			case 'rebuild-index':
				return self::object(
					array(
						'scope'           => array(
							'type' => 'string',
							'enum' => array( 'lexical', 'embeddings' ),
						),
						'model_version'   => array(
							'type'      => 'string',
							'maxLength' => 64,
						),
						'confirm'         => array( 'type' => 'boolean' ),
						'idempotency_key' => array(
							'type'      => 'string',
							'minLength' => 8,
							'maxLength' => 64,
						),
					),
					array( 'scope', 'confirm' )
				);
			case 'create-change-preview':
				return self::object(
					array(
						'finding_ids' => array(
							'type'     => 'array',
							'items'    => array(
								'type'    => 'integer',
								'minimum' => 1,
							),
							'minItems' => 1,
							'maxItems' => 10,
						),
					),
					array( 'finding_ids' )
				);
			case 'apply-approved-change':
				return self::object(
					array(
						'proposal_id'     => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'approval_id'     => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'idempotency_key' => array(
							'type'      => 'string',
							'minLength' => 8,
							'maxLength' => 64,
							'pattern'   => '^[A-Za-z0-9_\\-:.]+$',
						),
					),
					array( 'proposal_id', 'approval_id', 'idempotency_key' )
				);
			case 'rollback-change':
				return self::object(
					array(
						'change_id'   => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'approval_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					array( 'change_id', 'approval_id' )
				);
		}
		return self::object( array() );
	}

	/**
	 * Output schemas by ability key (deliberately permissive for nested evidence).
	 *
	 * @param string $key Key.
	 * @return array
	 */
	public static function output( $key ) {
		$finding = array(
			'type'       => 'object',
			'properties' => array(
				'id'         => array( 'type' => 'integer' ),
				'type'       => array( 'type' => 'string' ),
				'check_id'   => array( 'type' => 'string' ),
				'label'      => array(
					'type' => 'string',
					'enum' => FindingRepository::LABELS,
				),
				'confidence' => array(
					'type' => 'string',
					'enum' => FindingRepository::CONFIDENCES,
				),
				'evidence'   => array( 'type' => array( 'object', 'array' ) ),
				'status'     => array(
					'type' => 'string',
					'enum' => FindingRepository::STATUSES,
				),
			),
			'required'   => array( 'id', 'type', 'label', 'confidence', 'evidence', 'status' ),
		);
		$list    = static function ( $item ) {
			return array(
				'type'       => 'object',
				'properties' => array(
					'items'       => array(
						'type'  => 'array',
						'items' => $item,
					),
					'total'       => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
					'next_cursor' => array( 'type' => array( 'string', 'null' ) ),
				),
				'required'   => array( 'items', 'total' ),
			);
		};
		$job     = array(
			'type'       => 'object',
			'properties' => array(
				'id'             => array( 'type' => 'integer' ),
				'type'           => array( 'type' => 'string' ),
				'status'         => array(
					'type' => 'string',
					'enum' => array( 'queued', 'running', 'paused', 'completed', 'failed', 'cancelled' ),
				),
				'progress_done'  => array( 'type' => 'integer' ),
				'progress_total' => array( 'type' => 'integer' ),
			),
			'required'   => array( 'id', 'type', 'status', 'progress_done', 'progress_total' ),
		);
		switch ( $key ) {
			case 'get-site-status':
				return array(
					'type'       => 'object',
					'properties' => array(
						'plugin_version'  => array( 'type' => 'string' ),
						'api_version'     => array( 'type' => 'string' ),
						'mode'            => array( 'type' => 'object' ),
						'tiers'           => array( 'type' => 'object' ),
						'index'           => array( 'type' => 'object' ),
						'link_coverage'   => array( 'type' => 'object' ),
						'inventory_limit' => array( 'type' => 'object' ),
						'diagnostics'     => array( 'type' => 'object' ),
					),
					'required'   => array( 'plugin_version', 'mode', 'tiers', 'index', 'diagnostics' ),
				);
			case 'list-urls':
				return $list(
					array(
						'type'       => 'object',
						'properties' => array(
							'document_id' => array( 'type' => 'integer' ),
							'url'         => array( 'type' => 'string' ),
							'locale'      => array( 'type' => 'string' ),
							'title'       => array( 'type' => 'string' ),
						),
						'required'   => array( 'document_id', 'url', 'locale', 'title' ),
					)
				);
			case 'get-url-analysis':
				return array(
					'type'       => 'object',
					'properties' => array(
						'document'   => array( 'type' => 'object' ),
						'facts'      => array( 'type' => 'object' ),
						'inferences' => array( 'type' => 'object' ),
						'provenance' => array( 'type' => 'object' ),
					),
					'required'   => array( 'document', 'facts', 'inferences', 'provenance' ),
				);
			case 'get-link-suggestions':
			case 'get-cannibalization-report':
			case 'get-qa-issues':
				return $list( $finding );
			case 'get-topic-clusters':
				return array(
					'type'       => 'object',
					'properties' => array(
						'clusters'    => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
						'unclustered' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'clusters', 'unclustered' ),
				);
			case 'get-job-status':
			case 'start-analysis':
			case 'cancel-analysis':
			case 'rebuild-index':
				return $job;
			case 'get-audit-log':
				return array(
					'type'       => 'object',
					'properties' => array(
						'items'          => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
						'next_before_id' => array( 'type' => 'integer' ),
					),
					'required'   => array( 'items' ),
				);
			case 'create-change-preview':
				return array(
					'type'       => 'object',
					'properties' => array(
						'previews' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
					),
					'required'   => array( 'previews' ),
				);
			case 'apply-approved-change':
			case 'rollback-change':
				return array(
					'type'       => 'object',
					'properties' => array(
						'change_id'   => array( 'type' => 'integer' ),
						'status'      => array( 'type' => 'string' ),
						'before_hash' => array( 'type' => 'string' ),
						'after_hash'  => array( 'type' => 'string' ),
						'verify'      => array( 'type' => array( 'object', 'array' ) ),
					),
					'required'   => array( 'change_id', 'status', 'verify' ),
				);
		}
		return array( 'type' => 'object' );
	}

	/**
	 * Object schema helper.
	 *
	 * @param array    $properties Properties.
	 * @param string[] $required   Required keys.
	 * @return array
	 */
	public static function object( array $properties, array $required = array() ) {
		$s = array(
			'type'                 => 'object',
			'properties'           => (object) array(),
			'additionalProperties' => false,
		);
		if ( array() !== $properties ) {
			$s['properties'] = $properties;
		}
		if ( array() !== $required ) {
			$s['required'] = $required;
		}
		return $s;
	}

	/**
	 * Converts an object input schema into register_rest_route() args.
	 *
	 * @param string $key Key.
	 * @return array
	 */
	public static function rest_args( $key ) {
		$schema   = self::input( $key );
		$required = isset( $schema['required'] ) ? $schema['required'] : array();
		$args     = array();
		$props    = is_array( $schema['properties'] ) ? $schema['properties'] : array();
		foreach ( $props as $name => $prop ) {
			$prop['required']          = in_array( $name, $required, true );
			$prop['validate_callback'] = 'rest_validate_request_arg';
			$prop['sanitize_callback'] = 'rest_sanitize_request_arg';
			$args[ $name ]             = $prop;
		}
		return $args;
	}
}
