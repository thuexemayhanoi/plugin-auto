<?php
/**
 * Page 7 — Content QA.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Admin;

use SemanticSEO\Capabilities;
use SemanticSEO\Inventory\DocumentRepository;
use SemanticSEO\Quality\QaChecker;
use SemanticSEO\Services\Api;

defined( 'ABSPATH' ) || exit;

/**
 * QA issues with verified errors, warnings and suggestions kept apart.
 */
final class QaPage {

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public static function render() {
		Ui::header( __( 'Content QA', 'semantic-seo-engine-vi-en' ), __( 'Verified errors are objective facts; warnings are rules needing judgement; suggestions are heuristic hints. No quality score and no fact-checking are produced.', 'semantic-seo-engine-vi-en' ) );
		$checks = array( '' => __( 'All checks', 'semantic-seo-engine-vi-en' ) );
		foreach ( array_keys( QaChecker::CHECKS ) as $k ) {
			$checks[ $k ] = QaChecker::label( $k );
		}
		$args = array(
			'label'    => Ui::get( 'label' ),
			'severity' => Ui::get( 'severity' ),
			'check_id' => Ui::get( 'check' ),
			'locale'   => Ui::get( 'locale' ),
			'limit'    => 50,
			'cursor'   => Ui::get( 'cursor' ),
		);
		if ( '' !== Ui::get( 'doc' ) ) {
			$args['document_id'] = (int) Ui::get( 'doc' );
		}
		echo '<form method="get" class="sseo-filters"><input type="hidden" name="page" value="sseo-qa">';
		Ui::out(
			Ui::select(
				'label',
				array(
					''               => __( 'All labels', 'semantic-seo-engine-vi-en' ),
					'verified_error' => Ui::term( 'verified_error' ),
					'warning'        => Ui::term( 'warning' ),
					'suggestion'     => Ui::term( 'suggestion' ),
				),
				$args['label'],
				__( 'Label', 'semantic-seo-engine-vi-en' )
			) . ' '
		); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		Ui::out( Ui::select( 'check', $checks, $args['check_id'], __( 'Check', 'semantic-seo-engine-vi-en' ) ) . ' ' );
		Ui::out(
			Ui::select(
				'locale',
				array(
					''   => __( 'All', 'semantic-seo-engine-vi-en' ),
					'vi' => 'VI',
					'en' => 'EN',
				),
				$args['locale'],
				__( 'Locale', 'semantic-seo-engine-vi-en' )
			)
		); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo ' <button class="button">' . esc_html__( 'Filter', 'semantic-seo-engine-vi-en' ) . '</button></form>';
		$res = Api::qa_issues( $args );
		if ( is_wp_error( $res ) ) {
			Ui::error_state( $res );
			Ui::footer();
			return;
		}
		$meta = DocumentRepository::many( array_column( $res['items'], 'document_id' ) );
		$rows = array();
		foreach ( $res['items'] as $f ) {
			$e    = (array) $f['evidence'];
			$doc  = isset( $meta[ (int) $f['document_id'] ] ) ? $meta[ (int) $f['document_id'] ] : array(
				'title' => '?',
				'url'   => '',
			);
			$info = $e;
			unset( $info['check'] );
			$rows[] = array(
				'label'   => Ui::badge( $f['label'], 'label' ),
				'page'    => Ui::doc_link( $f['document_id'], $doc['title'], $doc['url'] ),
				'check'   => esc_html( QaChecker::label( (string) $f['check_id'] ) ),
				'detail'  => '<code class="sseo-evidence-json">' . esc_html( (string) wp_json_encode( $info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '</code>',
				'actions' => current_user_can( Capabilities::REVIEW ) && 'open' === $f['status'] ? Ui::action_button(
					'review_finding',
					array(
						'finding_id' => $f['id'],
						'status'     => 'dismissed',
					),
					__( 'Dismiss', 'semantic-seo-engine-vi-en' )
				) : Ui::badge( $f['status'], 'status' ),
			);
		}
		/* translators: %d: total */
		echo '<p class="sseo-count">' . esc_html( sprintf( __( '%d open issues', 'semantic-seo-engine-vi-en' ), $res['total'] ) ) . '</p>';
		Ui::table(
			__( 'QA issues', 'semantic-seo-engine-vi-en' ),
			array(
				'label'   => __( 'Label', 'semantic-seo-engine-vi-en' ),
				'page'    => __( 'Page', 'semantic-seo-engine-vi-en' ),
				'check'   => __( 'Check', 'semantic-seo-engine-vi-en' ),
				'detail'  => __( 'Evidence', 'semantic-seo-engine-vi-en' ),
				'actions' => __( 'Actions', 'semantic-seo-engine-vi-en' ),
			),
			$rows,
			__( 'No QA issues for these filters.', 'semantic-seo-engine-vi-en' )
		);
		if ( ! empty( $res['next_cursor'] ) ) {
			echo '<p><a class="button" href="' . esc_url(
				Ui::url(
					'sseo-qa',
					array_filter(
						array(
							'label'  => $args['label'],
							'check'  => $args['check_id'],
							'locale' => $args['locale'],
							'cursor' => $res['next_cursor'],
						)
					)
				)
			) . '">' . esc_html__( 'More ›', 'semantic-seo-engine-vi-en' ) . '</a></p>';
		}
		Ui::footer();
	}
}
