<?php
/**
 * Page 6 — Pillar–Hub–Silo Clusters.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Admin;

use SemanticSEO\Capabilities;
use SemanticSEO\Services\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Cluster tree per locale with recommended pillar pages, member roles, locks and classification suggestions.
 */
final class ClustersPage {

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public static function render() {
		Ui::header( __( 'Pillar–Hub–Silo Clusters', 'semantic-seo-engine-vi-en' ), __( 'Built from your existing categories. Roles are editorial recommendations you can override and lock; no category is created or changed without an approved diff.', 'semantic-seo-engine-vi-en' ) );
		$locale = Ui::get( 'locale' );
		Ui::out(
			'<form method="get" class="sseo-filters"><input type="hidden" name="page" value="sseo-clusters">' . Ui::select(
				'locale',
				array(
					''   => __( 'All', 'semantic-seo-engine-vi-en' ),
					'vi' => 'VI',
					'en' => 'EN',
				),
				$locale,
				__( 'Locale', 'semantic-seo-engine-vi-en' )
			) . ' <button class="button">' . esc_html__( 'Filter', 'semantic-seo-engine-vi-en' ) . '</button></form>'
		);
		$res = Api::clusters( array( 'locale' => $locale ) );
		if ( is_wp_error( $res ) ) {
			Ui::error_state( $res );
			Ui::footer();
			return;
		}
		if ( array() === $res['clusters'] ) {
			Ui::empty_state( __( 'No clusters yet. Run an analysis; clusters come from categories in scope.', 'semantic-seo-engine-vi-en' ) );
		}
		/* translators: %d: count */
		echo '<p>' . esc_html( sprintf( __( '%d published posts are not in any cluster (see classification suggestions below).', 'semantic-seo-engine-vi-en' ), $res['unclustered'] ) ) . '</p>';
		$by_parent = array();
		foreach ( $res['clusters'] as $c ) {
			$by_parent[ $c['parent_id'] ][] = $c;
		}
		echo '<div class="sseo-tree" role="tree">';
		self::branch( $by_parent, 0, 1 );
		echo '</div>';

		$cls  = Api::classification( array( 'limit' => 50 ) );
		$rows = array();
		if ( ! is_wp_error( $cls ) ) {
			$meta = \SemanticSEO\Inventory\DocumentRepository::many( array_column( $cls['items'], 'document_id' ) );
			foreach ( $cls['items'] as $f ) {
				$e      = $f['evidence'];
				$doc    = isset( $meta[ (int) $f['document_id'] ] ) ? $meta[ (int) $f['document_id'] ] : array(
					'title' => '?',
					'url'   => '',
				);
				$rows[] = array(
					'page'    => Ui::doc_link( $f['document_id'], $doc['title'], $doc['url'] ),
					'check'   => esc_html( 'suggested_category' === $f['check_id'] ? __( 'No category — suggested', 'semantic-seo-engine-vi-en' ) : __( 'Possibly miscategorized', 'semantic-seo-engine-vi-en' ) ),
					'target'  => esc_html( $e['suggested']['label'] . ' (' . $e['suggested']['similarity'] . ')' ),
					'actions' => ( current_user_can( Capabilities::ANALYZE ) && 'open' === $f['status'] ? Ui::action_button( 'create_preview', array( 'finding_id' => $f['id'] ), __( 'Preview category assignment', 'semantic-seo-engine-vi-en' ) ) : '' ) . ( current_user_can( Capabilities::REVIEW ) && 'open' === $f['status'] ? Ui::action_button(
						'review_finding',
						array(
							'finding_id' => $f['id'],
							'status'     => 'dismissed',
						),
						__( 'Dismiss', 'semantic-seo-engine-vi-en' )
					) : '' ),
				);
			}
		}
		echo '<h2>' . esc_html__( 'Classification suggestions', 'semantic-seo-engine-vi-en' ) . '</h2>';
		Ui::table(
			__( 'Classification suggestions', 'semantic-seo-engine-vi-en' ),
			array(
				'page'    => __( 'Page', 'semantic-seo-engine-vi-en' ),
				'check'   => __( 'Finding', 'semantic-seo-engine-vi-en' ),
				'target'  => __( 'Closest existing category (similarity)', 'semantic-seo-engine-vi-en' ),
				'actions' => __( 'Actions', 'semantic-seo-engine-vi-en' ),
			),
			$rows,
			__( 'No classification suggestions.', 'semantic-seo-engine-vi-en' )
		);
		Ui::footer();
	}

	/**
	 * Renders a tree branch.
	 *
	 * @param array $by_parent Clusters by parent.
	 * @param int   $parent_id    Parent ID.
	 * @param int   $level     Depth.
	 * @return void
	 */
	private static function branch( array $by_parent, $parent_id, $level ) {
		if ( empty( $by_parent[ $parent_id ] ) || $level > 6 ) {
			return;
		}
		echo '<ul class="sseo-tree__level" role="group">';
		foreach ( $by_parent[ $parent_id ] as $c ) {
			echo '<li role="treeitem" aria-level="' . (int) $level . '" aria-expanded="true"><details open><summary>' . Ui::badge( $c['role'], 'role' ) . ' <strong>' . esc_html( $c['label'] ) . '</strong> <span class="sseo-muted">' . esc_html( strtoupper( $c['locale'] ) . ' · ' . sprintf( /* translators: %d: members */ _n( '%d page', '%d pages', $c['member_count'], 'semantic-seo-engine-vi-en' ), $c['member_count'] ) ) . '</span>' . ( $c['locked'] ? ' 🔒' : '' ) . '</summary>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$rows = array();
			foreach ( $c['members'] as $m ) {
				$actions = '';
				if ( current_user_can( Capabilities::REVIEW ) ) {
					ob_start();
					Ui::form_open( 'editorial', 'sseo-inline-form' );
					echo '<input type="hidden" name="doc_id" value="' . esc_attr( (string) $m['id'] ) . '"><input type="hidden" name="cluster_locked_present" value="1">';
					Ui::out(
						Ui::select(
							'cluster_role',
							array(
								'pillar'     => Ui::term( 'pillar' ),
								'hub'        => Ui::term( 'hub' ),
								'silo'       => Ui::term( 'silo' ),
								'supporting' => Ui::term( 'supporting' ),
							),
							$m['cluster_role'],
							__( 'Role', 'semantic-seo-engine-vi-en' )
						)
					);
					echo ' <label><input type="checkbox" name="cluster_locked" value="1"' . checked( (int) $m['cluster_locked'], 1, false ) . '> ' . esc_html__( 'Lock', 'semantic-seo-engine-vi-en' ) . '</label> <button class="button button-small">' . esc_html__( 'Save', 'semantic-seo-engine-vi-en' ) . '</button></form>';
					$actions = (string) ob_get_clean();
				}
				$rows[] = array(
					'page'    => Ui::doc_link( $m['id'], $m['title'], $m['url'] ) . ( (int) $m['id'] === (int) $c['recommended_pillar_id'] ? ' <span class="sseo-badge sseo-badge--recommended">' . esc_html__( 'Recommended main page', 'semantic-seo-engine-vi-en' ) . '</span>' : '' ),
					'role'    => Ui::badge( $m['cluster_role'], 'role' ) . ( (int) $m['cluster_locked'] ? ' 🔒' : '' ),
					'inbound' => esc_html( (string) $m['inbound_count'] ),
					'actions' => $actions,
				);
			}
			Ui::table(
				$c['label'],
				array(
					'page'    => __( 'Page', 'semantic-seo-engine-vi-en' ),
					'role'    => __( 'Role', 'semantic-seo-engine-vi-en' ),
					'inbound' => __( 'Inbound', 'semantic-seo-engine-vi-en' ),
					'actions' => __( 'Override', 'semantic-seo-engine-vi-en' ),
				),
				$rows,
				__( 'No member pages.', 'semantic-seo-engine-vi-en' )
			);
			if ( ! empty( $c['evidence']['method'] ) ) {
				echo '<p class="description">' . esc_html__( 'Main-page recommendation:', 'semantic-seo-engine-vi-en' ) . ' ' . esc_html( $c['evidence']['method'] ) . '</p>';
			}
			if ( current_user_can( Capabilities::REVIEW ) ) {
				Ui::out(
					Ui::action_button(
						'cluster_lock',
						array(
							'cluster_id' => $c['id'],
							'lock'       => $c['locked'] ? '0' : '1',
						),
						$c['locked'] ? __( 'Unlock cluster', 'semantic-seo-engine-vi-en' ) : __( 'Lock cluster membership', 'semantic-seo-engine-vi-en' )
					)
				);
			}
			self::branch( $by_parent, (int) $c['id'], $level + 1 );
			echo '</details></li>';
		}
		echo '</ul>';
	}
}
