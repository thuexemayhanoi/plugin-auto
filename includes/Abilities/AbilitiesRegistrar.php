<?php
/**
 * WordPress Abilities API integration (WP 6.9+), version-gated, private by default.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Abilities;

use SemanticSEO\Capabilities;
use SemanticSEO\Compatibility\Environment;
use SemanticSEO\REST\Schemas;
use SemanticSEO\Security\AuthContext;
use SemanticSEO\Services\Api;
use SemanticSEO\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Registers 15 abilities whose callbacks call the same Services\Api methods as REST and WP Admin.
 * Exposure: show_in_rest only for abilities the administrator enabled; MCP exposure (meta.mcp.public)
 * only for enabled read-only abilities and only when MCP is enabled in Settings.
 */
final class AbilitiesRegistrar {

	const CATEGORY = 'semantic-seo';

	/**
	 * Registers hooks when the Abilities API exists; otherwise does nothing (no fatal error).
	 *
	 * @return void
	 */
	public static function register_hooks() {
		if ( ! Environment::abilities_available() ) {
			return;
		}
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
	}

	/**
	 * Registers the ability category.
	 *
	 * @return void
	 */
	public static function register_category() {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Semantic SEO', 'semantic-seo-engine-vi-en' ),
				'description' => __( 'Inspect inventory and SEO analyses, run bounded jobs, preview and (with human approval) apply reversible changes.', 'semantic-seo-engine-vi-en' ),
			)
		);
	}

	/**
	 * Ability definitions.
	 *
	 * @return array<string,array>
	 */
	public static function definitions() {
		return array(
			'get-site-status'            => array(
				'label'       => __( 'Get Semantic SEO site status', 'semantic-seo-engine-vi-en' ),
				'description' => __( 'Returns plugin version, read-only mode, available compute tiers, index counts, link-scan coverage, findings summary and diagnostics. Use first to test the connection.', 'semantic-seo-engine-vi-en' ),
				'cap'         => Capabilities::READ,
				'access'      => 'read',
				'callback'    => static function () {
					return Api::status();
				},
				'no_input'    => true,
			),
			'list-urls'                  => array(
				'label'       => __( 'List inventoried URLs', 'semantic-seo-engine-vi-en' ),
				'description' => __( 'Paginated SEO Matrix rows (locale, status, intent, cluster role, link counts, QA and risk counts) with provenance. Filter by locale/type/intent; use next_cursor to page.', 'semantic-seo-engine-vi-en' ),
				'cap'         => Capabilities::READ,
				'access'      => 'read',
				'callback'    => array( Api::class, 'list_urls' ),
			),
			'get-url-analysis'           => array(
				'label'       => __( 'Get URL analysis', 'semantic-seo-engine-vi-en' ),
				'description' => __( 'Structured evidence for one URL: facts (headings, links, counts) separated from inferences (heuristic intent, topics, pattern entities), QA issues, link suggestions, overlap risks and provenance.', 'semantic-seo-engine-vi-en' ),
				'cap'         => Capabilities::READ,
				'access'      => 'read',
				'callback'    => array( Api::class, 'url_analysis' ),
			),
			'get-link-suggestions'       => array(
				'label'       => __( 'Get internal link suggestions', 'semantic-seo-engine-vi-en' ),
				'description' => __( 'Ranked source→target internal link candidates with reasons, anchor text, exact location and evidence components, plus orphan candidates with scan coverage. Suggestions only.', 'semantic-seo-engine-vi-en' ),
				'cap'         => Capabilities::READ,
				'access'      => 'read',
				'callback'    => array( Api::class, 'link_suggestions' ),
			),
			'get-cannibalization-report' => array(
				'label'       => __( 'Get cannibalization risk report', 'semantic-seo-engine-vi-en' ),
				'description' => __( 'Same-locale overlap pairs classified as duplicate, potential competition, different intent, distinct facet, pillar/supporting or manual review, with evidence and uncertainty. Risk hypotheses, not verdicts.', 'semantic-seo-engine-vi-en' ),
				'cap'         => Capabilities::READ,
				'access'      => 'read',
				'callback'    => array( Api::class, 'cannibalization' ),
			),
			'get-topic-clusters'         => array(
				'label'       => __( 'Get topic clusters', 'semantic-seo-engine-vi-en' ),
				'description' => __( 'Taxonomy-based Pillar/Hub/Silo tree per locale with members, recommended pillar pages and evidence. Editorial recommendations.', 'semantic-seo-engine-vi-en' ),
				'cap'         => Capabilities::READ,
				'access'      => 'read',
				'callback'    => array( Api::class, 'clusters' ),
			),
			'get-qa-issues'              => array(
				'label'       => __( 'Get content QA issues', 'semantic-seo-engine-vi-en' ),
				'description' => __( 'QA checks labelled verified_error, warning or suggestion (never conflated), filterable by severity, label, check and URL.', 'semantic-seo-engine-vi-en' ),
				'cap'         => Capabilities::READ,
				'access'      => 'read',
				'callback'    => array( Api::class, 'qa_issues' ),
			),
			'get-job-status'             => array(
				'label'       => __( 'Get job status', 'semantic-seo-engine-vi-en' ),
				'description' => __( 'Status (queued/running/paused/failed/completed/cancelled), phase and honest progress of a job.', 'semantic-seo-engine-vi-en' ),
				'cap'         => Capabilities::READ,
				'access'      => 'read',
				'callback'    => static function ( $input ) {
					return Api::job_status( (int) $input['job_id'] );
				},
			),
			'get-audit-log'              => array(
				'label'       => __( 'Get audit log', 'semantic-seo-engine-vi-en' ),
				'description' => __( 'Redacted action history. Agents only receive their own entries.', 'semantic-seo-engine-vi-en' ),
				'cap'         => Capabilities::READ,
				'access'      => 'privileged_read',
				'callback'    => array( Api::class, 'audit_log' ),
			),
			'start-analysis'             => array(
				'label'       => __( 'Start a bounded analysis job', 'semantic-seo-engine-vi-en' ),
				'description' => __( 'Starts an inventory, analysis, embedding or link-verification job (max 200 target posts, one active job per type, idempotency key supported). Read-only with respect to site content.', 'semantic-seo-engine-vi-en' ),
				'cap'         => Capabilities::ANALYZE,
				'access'      => 'controlled',
				'callback'    => array( Api::class, 'start_analysis' ),
			),
			'cancel-analysis'            => array(
				'label'       => __( 'Cancel a job', 'semantic-seo-engine-vi-en' ),
				'description' => __( 'Requests cancellation of a job; it stops at the next batch boundary.', 'semantic-seo-engine-vi-en' ),
				'cap'         => Capabilities::ANALYZE,
				'access'      => 'controlled',
				'callback'    => static function ( $input ) {
					return Api::cancel_job( (int) $input['job_id'] );
				},
			),
			'rebuild-index'              => array(
				'label'       => __( 'Rebuild the derived index', 'semantic-seo-engine-vi-en' ),
				'description' => __( 'Re-indexes lexical data or re-queues embeddings for the pinned model (confirm=true required). Never modifies posts.', 'semantic-seo-engine-vi-en' ),
				'cap'         => Capabilities::ANALYZE,
				'access'      => 'controlled',
				'callback'    => array( Api::class, 'rebuild_index' ),
			),
			'create-change-preview'      => array(
				'label'       => __( 'Create change previews', 'semantic-seo-engine-vi-en' ),
				'description' => __( 'Turns 1–10 findings into exact, hash-bound diff previews. Nothing is written; a human must approve in WP Admin.', 'semantic-seo-engine-vi-en' ),
				'cap'         => Capabilities::ANALYZE,
				'access'      => 'controlled',
				'callback'    => array( Api::class, 'create_change_preview' ),
			),
			'apply-approved-change'      => array(
				'label'       => __( 'Apply a human-approved change', 'semantic-seo-engine-vi-en' ),
				'description' => __( 'Applies a proposal that a human approved in WP Admin. Requires the approval ID and an idempotency key; rejects stale, expired, mismatched or reused approvals; returns readback verification.', 'semantic-seo-engine-vi-en' ),
				'cap'         => Capabilities::APPLY,
				'access'      => 'sensitive',
				'callback'    => array( Api::class, 'apply_approved_change' ),
			),
			'rollback-change'            => array(
				'label'       => __( 'Roll back a change', 'semantic-seo-engine-vi-en' ),
				'description' => __( 'Restores a change made by this plugin. Non-interactive callers need a separate human-issued rollback approval.', 'semantic-seo-engine-vi-en' ),
				'cap'         => Capabilities::APPLY,
				'access'      => 'sensitive',
				'callback'    => array( Api::class, 'rollback_change' ),
			),
		);
	}

	/**
	 * Registers all abilities.
	 *
	 * @return void
	 */
	public static function register_abilities() {
		$rest_enabled = (array) Settings::get( 'abilities_rest', array() );
		$mcp_on       = (bool) Settings::get( 'mcp_enabled', false );
		$mcp_enabled  = (array) Settings::get( 'mcp_abilities', array() );
		foreach ( self::definitions() as $slug => $def ) {
			$name      = 'semantic-seo/' . $slug;
			$read_only = 'read' === $def['access'];
			$meta      = array(
				'annotations'  => array(
					'readonly'    => in_array( $def['access'], array( 'read', 'privileged_read' ), true ),
					'destructive' => 'sensitive' === $def['access'],
					'idempotent'  => 'sensitive' !== $def['access'],
				),
				'show_in_rest' => in_array( $name, $rest_enabled, true ),
				'mcp'          => array(
					'public' => $mcp_on && $read_only && in_array( $name, $mcp_enabled, true ),
					'type'   => 'tool',
				),
				'access_level' => $def['access'],
			);
			$cap  = $def['cap'];
			$cb   = $def['callback'];
			$args = array(
				'label'               => $def['label'],
				'description'         => $def['description'],
				'category'            => self::CATEGORY,
				'output_schema'       => Schemas::output( $slug ),
				'execute_callback'    => static function ( $input = null ) use ( $cb ) {
					AuthContext::set_via( 'ability' );
					try {
						$result = null === $input ? call_user_func( $cb ) : call_user_func( $cb, (array) $input );
					} finally {
						AuthContext::set_via( null );
					}
					return $result;
				},
				'permission_callback' => static function () use ( $cap ) {
					return current_user_can( $cap );
				},
				'meta'                => $meta,
			);
			if ( empty( $def['no_input'] ) ) {
				$args['input_schema'] = Schemas::input( $slug );
			}
			wp_register_ability( $name, $args );
		}
	}
}
