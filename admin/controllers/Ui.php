<?php
/**
 * Shared admin UI helpers (escaped output, accessible tables, badges, pagination, notices).
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * All output is escaped at the point of rendering.
 */
final class Ui {

	/**
	 * Admin URL of a plugin page.
	 *
	 * @param string $page Page slug.
	 * @param array  $args Query args.
	 * @return string
	 */
	public static function url( $page, array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Page header.
	 *
	 * @param string $title    Title.
	 * @param string $subtitle Explanation.
	 * @return void
	 */
	public static function header( $title, $subtitle = '' ) {
		echo '<div class="wrap sseo-wrap"><h1 class="sseo-title">' . esc_html( $title ) . '</h1>';
		if ( '' !== $subtitle ) {
			echo '<p class="sseo-subtitle">' . esc_html( $subtitle ) . '</p>';
		}
		self::flash();
	}

	/**
	 * Page footer.
	 *
	 * @return void
	 */
	public static function footer() {
		echo '</div>';
	}

	/**
	 * Stores a one-time notice for the current user.
	 *
	 * @param string $type    success|error|warning|info.
	 * @param string $message Message.
	 * @return void
	 */
	public static function queue_notice( $type, $message ) {
		$key   = 'sseo_notice_' . get_current_user_id();
		$queue = get_transient( $key );
		$queue = is_array( $queue ) ? $queue : array();
		$queue[] = array(
			'type'    => $type,
			'message' => (string) $message,
		);
		set_transient( $key, array_slice( $queue, -5 ), 300 );
	}

	/**
	 * Prints and clears queued notices.
	 *
	 * @return void
	 */
	public static function flash() {
		$key   = 'sseo_notice_' . get_current_user_id();
		$queue = get_transient( $key );
		if ( is_array( $queue ) ) {
			foreach ( $queue as $n ) {
				self::notice( $n['type'], $n['message'] );
			}
			delete_transient( $key );
		}
	}

	/**
	 * Prints a notice.
	 *
	 * @param string $type    Type.
	 * @param string $message Message.
	 * @return void
	 */
	public static function notice( $type, $message ) {
		$type = in_array( $type, array( 'success', 'error', 'warning', 'info' ), true ) ? $type : 'info';
		echo '<div class="notice notice-' . esc_attr( $type ) . ' sseo-notice" role="' . ( 'error' === $type ? 'alert' : 'status' ) . '"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Badge for finding labels/confidence/status.
	 *
	 * @param string $value Value.
	 * @param string $kind  label|confidence|status|severity|role|plain.
	 * @return string HTML.
	 */
	public static function badge( $value, $kind = 'plain' ) {
		$value = (string) $value;
		$text  = self::term( $value );
		return '<span class="sseo-badge sseo-badge--' . esc_attr( $kind ) . ' sseo-badge--' . esc_attr( sanitize_html_class( $value ) ) . '">' . esc_html( $text ) . '</span>';
	}

	/**
	 * Human (translated) term for an internal value.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function term( $value ) {
		$map = array(
			'verified_error'               => __( 'Verified error', 'semantic-seo-engine-vi-en' ),
			'warning'                      => __( 'Warning', 'semantic-seo-engine-vi-en' ),
			'suggestion'                   => __( 'Suggestion', 'semantic-seo-engine-vi-en' ),
			'risk_hypothesis'              => __( 'Risk hypothesis', 'semantic-seo-engine-vi-en' ),
			'info'                         => __( 'Info', 'semantic-seo-engine-vi-en' ),
			'error'                        => __( 'Error', 'semantic-seo-engine-vi-en' ),
			'high'                         => __( 'High confidence', 'semantic-seo-engine-vi-en' ),
			'medium'                       => __( 'Medium confidence', 'semantic-seo-engine-vi-en' ),
			'low'                          => __( 'Low confidence', 'semantic-seo-engine-vi-en' ),
			'manual_review'                => __( 'Manual review', 'semantic-seo-engine-vi-en' ),
			'editorial'                    => __( 'Editorial', 'semantic-seo-engine-vi-en' ),
			'open'                         => __( 'Open', 'semantic-seo-engine-vi-en' ),
			'dismissed'                    => __( 'Dismissed', 'semantic-seo-engine-vi-en' ),
			'accepted'                     => __( 'Accepted', 'semantic-seo-engine-vi-en' ),
			'proposed'                     => __( 'Proposed', 'semantic-seo-engine-vi-en' ),
			'resolved'                     => __( 'Resolved', 'semantic-seo-engine-vi-en' ),
			'queued'                       => __( 'Queued', 'semantic-seo-engine-vi-en' ),
			'running'                      => __( 'Processing', 'semantic-seo-engine-vi-en' ),
			'paused'                       => __( 'Paused', 'semantic-seo-engine-vi-en' ),
			'completed'                    => __( 'Completed', 'semantic-seo-engine-vi-en' ),
			'failed'                       => __( 'Failed', 'semantic-seo-engine-vi-en' ),
			'cancelled'                    => __( 'Cancelled', 'semantic-seo-engine-vi-en' ),
			'previewed'                    => __( 'Awaiting approval', 'semantic-seo-engine-vi-en' ),
			'approved'                     => __( 'Approved', 'semantic-seo-engine-vi-en' ),
			'applied'                      => __( 'Applied', 'semantic-seo-engine-vi-en' ),
			'rolled_back'                  => __( 'Rolled back', 'semantic-seo-engine-vi-en' ),
			'rejected'                     => __( 'Rejected', 'semantic-seo-engine-vi-en' ),
			'stale'                        => __( 'Stale', 'semantic-seo-engine-vi-en' ),
			'rollback_failed'              => __( 'Rollback failed', 'semantic-seo-engine-vi-en' ),
			'active'                       => __( 'Active', 'semantic-seo-engine-vi-en' ),
			'consumed'                     => __( 'Used', 'semantic-seo-engine-vi-en' ),
			'expired'                      => __( 'Expired', 'semantic-seo-engine-vi-en' ),
			'revoked'                      => __( 'Revoked', 'semantic-seo-engine-vi-en' ),
			'invalidated'                  => __( 'Invalidated', 'semantic-seo-engine-vi-en' ),
			'pillar'                       => __( 'Pillar', 'semantic-seo-engine-vi-en' ),
			'hub'                          => __( 'Hub', 'semantic-seo-engine-vi-en' ),
			'silo'                         => __( 'Silo', 'semantic-seo-engine-vi-en' ),
			'supporting'                   => __( 'Supporting', 'semantic-seo-engine-vi-en' ),
			'informational'                => __( 'Informational', 'semantic-seo-engine-vi-en' ),
			'transactional'                => __( 'Transactional', 'semantic-seo-engine-vi-en' ),
			'commercial'                   => __( 'Commercial investigation', 'semantic-seo-engine-vi-en' ),
			'navigational'                 => __( 'Navigational', 'semantic-seo-engine-vi-en' ),
			'unknown'                      => __( 'Unknown', 'semantic-seo-engine-vi-en' ),
			'exact_duplicate'              => __( 'Exact duplicate', 'semantic-seo-engine-vi-en' ),
			'near_duplicate'               => __( 'Near duplicate', 'semantic-seo-engine-vi-en' ),
			'potential_competition'        => __( 'Potential same-query competition', 'semantic-seo-engine-vi-en' ),
			'same_topic_different_intent'  => __( 'Same topic, different intent', 'semantic-seo-engine-vi-en' ),
			'distinct_facet'               => __( 'Distinct facet (e.g. daily vs monthly)', 'semantic-seo-engine-vi-en' ),
			'pillar_supporting'            => __( 'Pillar + supporting page', 'semantic-seo-engine-vi-en' ),
			'translation_mapping_conflict' => __( 'Translation mapping conflict', 'semantic-seo-engine-vi-en' ),
			'unreviewed'                   => __( 'Unreviewed', 'semantic-seo-engine-vi-en' ),
			'needs_review'                 => __( 'Needs review', 'semantic-seo-engine-vi-en' ),
			'reviewed'                     => __( 'Reviewed', 'semantic-seo-engine-vi-en' ),
			'approved_plan'                => __( 'Approved plan', 'semantic-seo-engine-vi-en' ),
			'publish'                      => __( 'Published', 'semantic-seo-engine-vi-en' ),
			'draft'                        => __( 'Draft', 'semantic-seo-engine-vi-en' ),
			'private'                      => __( 'Private', 'semantic-seo-engine-vi-en' ),
			'inventory'                    => __( 'Inventory', 'semantic-seo-engine-vi-en' ),
			'analyze'                      => __( 'Analysis', 'semantic-seo-engine-vi-en' ),
			'embed'                        => __( 'Embeddings', 'semantic-seo-engine-vi-en' ),
			'incremental'                  => __( 'Incremental update', 'semantic-seo-engine-vi-en' ),
			'verify_links'                 => __( 'Link verification', 'semantic-seo-engine-vi-en' ),
		);
		return isset( $map[ $value ] ) ? $map[ $value ] : $value;
	}

	/**
	 * Accessible responsive table.
	 *
	 * @param string $caption Caption (screen-reader + visible).
	 * @param array  $columns key => label.
	 * @param array  $rows    Rows: key => already-escaped HTML.
	 * @param string $empty   Empty-state message.
	 * @return void
	 */
	public static function table( $caption, array $columns, array $rows, $empty = '' ) {
		if ( array() === $rows ) {
			self::empty_state( '' !== $empty ? $empty : __( 'Nothing to show yet.', 'semantic-seo-engine-vi-en' ) );
			return;
		}
		echo '<div class="sseo-table-wrap"><table class="widefat striped sseo-table"><caption class="screen-reader-text">' . esc_html( $caption ) . '</caption><thead><tr>';
		foreach ( $columns as $label ) {
			echo '<th scope="col">' . esc_html( $label ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr>';
			foreach ( $columns as $key => $label ) {
				// Cell content is pre-escaped by callers; kses is a second safety net.
				echo '<td data-label="' . esc_attr( $label ) . '">' . self::kses( isset( $row[ $key ] ) ? $row[ $key ] : '' ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * kses with the post allowlist plus the small form vocabulary used by admin action buttons.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function kses( $html ) {
		static $allowed = null;
		if ( null === $allowed ) {
			$allowed           = wp_kses_allowed_html( 'post' );
			$common            = array(
				'class' => true,
				'id'    => true,
				'name'  => true,
				'value' => true,
				'type'  => true,
			);
			$allowed['form']   = array_merge(
				$common,
				array(
					'method'  => true,
					'action'  => true,
					'enctype' => true,
				)
			);
			$allowed['input']  = array_merge(
				$common,
				array(
					'checked'  => true,
					'required' => true,
					'min'      => true,
					'max'      => true,
					'step'     => true,
					'size'     => true,
					'placeholder' => true,
					'aria-label'  => true,
				)
			);
			$allowed['button'] = array_merge(
				$common,
				array(
					'data-sseo-confirm' => true,
					'disabled'          => true,
					'aria-label'        => true,
				)
			);
			$allowed['select'] = array_merge( $common, array( 'aria-label' => true ) );
			$allowed['option'] = array(
				'value'    => true,
				'selected' => true,
			);
			$allowed['label']  = array(
				'for'   => true,
				'class' => true,
			);
			$allowed['progress'] = array(
				'value'      => true,
				'max'        => true,
				'class'      => true,
				'aria-label' => true,
			);
		}
		return wp_kses( (string) $html, $allowed );
	}

	/**
	 * Empty state.
	 *
	 * @param string $message Message.
	 * @param string $action  Optional escaped HTML action.
	 * @return void
	 */
	public static function empty_state( $message, $action = '' ) {
		echo '<div class="sseo-empty"><p>' . esc_html( $message ) . '</p>' . self::kses( $action ) . '</div>';
	}

	/**
	 * Error state from a WP_Error.
	 *
	 * @param \WP_Error $e Error.
	 * @return void
	 */
	public static function error_state( \WP_Error $e ) {
		self::notice( 'error', $e->get_error_message() );
	}

	/**
	 * Pagination links.
	 *
	 * @param int    $total    Total items.
	 * @param int    $page     Current page.
	 * @param int    $per_page Per page.
	 * @param string $slug     Page slug.
	 * @param array  $args     Query args to keep.
	 * @return void
	 */
	public static function pagination( $total, $page, $per_page, $slug, array $args ) {
		$pages = (int) ceil( $total / max( 1, $per_page ) );
		if ( $pages <= 1 ) {
			return;
		}
		echo '<nav class="sseo-pagination" aria-label="' . esc_attr__( 'Pagination', 'semantic-seo-engine-vi-en' ) . '">';
		/* translators: 1: current page, 2: total pages, 3: total items */
		echo '<span class="sseo-pagination__info">' . esc_html( sprintf( __( 'Page %1$d of %2$d (%3$d items)', 'semantic-seo-engine-vi-en' ), $page, $pages, $total ) ) . '</span> ';
		if ( $page > 1 ) {
			echo '<a class="button" href="' . esc_url( self::url( $slug, array_merge( $args, array( 'paged' => $page - 1 ) ) ) ) . '">' . esc_html__( '‹ Previous', 'semantic-seo-engine-vi-en' ) . '</a> ';
		}
		if ( $page < $pages ) {
			echo '<a class="button" href="' . esc_url( self::url( $slug, array_merge( $args, array( 'paged' => $page + 1 ) ) ) ) . '">' . esc_html__( 'Next ›', 'semantic-seo-engine-vi-en' ) . '</a>';
		}
		echo '</nav>';
	}

	/**
	 * Opens an admin-post form with a nonce.
	 *
	 * @param string $action Action name (without prefix).
	 * @param string $class  CSS class.
	 * @param bool   $upload Multipart.
	 * @return void
	 */
	public static function form_open( $action, $class = '', $upload = false ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sseo-form ' . esc_attr( $class ) . '"' . ( $upload ? ' enctype="multipart/form-data"' : '' ) . '>';
		echo '<input type="hidden" name="action" value="' . esc_attr( 'sseo_' . $action ) . '">';
		wp_nonce_field( 'sseo_' . $action );
		$back = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		echo '<input type="hidden" name="_sseo_back" value="' . esc_attr( $back ) . '">';
	}

	/**
	 * Inline action button form.
	 *
	 * @param string $action  Action.
	 * @param array  $fields  Hidden fields.
	 * @param string $label   Button label.
	 * @param string $class   Button class.
	 * @param string $confirm Optional confirmation text.
	 * @return string HTML.
	 */
	public static function action_button( $action, array $fields, $label, $class = 'button', $confirm = '' ) {
		ob_start();
		self::form_open( $action, 'sseo-inline-form' );
		foreach ( $fields as $k => $v ) {
			echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
		}
		echo '<button type="submit" class="' . esc_attr( $class ) . '"' . ( '' !== $confirm ? ' data-sseo-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>' . esc_html( $label ) . '</button></form>';
		return (string) ob_get_clean();
	}

	/**
	 * Score bar (0..1) with an accessible text value.
	 *
	 * @param float|null $value Value.
	 * @param string     $label Label.
	 * @return string HTML.
	 */
	public static function meter( $value, $label ) {
		if ( null === $value ) {
			return '<span class="sseo-meter sseo-meter--na">' . esc_html( $label ) . ': ' . esc_html__( 'n/a', 'semantic-seo-engine-vi-en' ) . '</span>';
		}
		$pct = (int) round( max( 0, min( 1, (float) $value ) ) * 100 );
		return '<span class="sseo-meter" title="' . esc_attr( $label . ': ' . round( (float) $value, 3 ) ) . '"><span class="sseo-meter__label">' . esc_html( $label ) . '</span><span class="sseo-meter__bar" role="img" aria-label="' . esc_attr( $label . ' ' . $pct . '%' ) . '"><span style="width:' . (int) $pct . '%"></span></span><span class="sseo-meter__value">' . esc_html( (string) round( (float) $value, 2 ) ) . '</span></span>';
	}

	/**
	 * Link to a page's analysis screen.
	 *
	 * @param int    $doc_id Document ID.
	 * @param string $title  Title.
	 * @param string $url    URL.
	 * @return string HTML.
	 */
	public static function doc_link( $doc_id, $title, $url ) {
		$title = '' !== trim( (string) $title ) ? (string) $title : __( '(no title)', 'semantic-seo-engine-vi-en' );
		return '<a href="' . esc_url( self::url( 'sseo-semantic', array( 'doc' => (int) $doc_id ) ) ) . '"><strong>' . esc_html( $title ) . '</strong></a><br><a class="sseo-url" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( wp_parse_url( (string) $url, PHP_URL_PATH ) ? (string) wp_parse_url( (string) $url, PHP_URL_PATH ) : (string) $url ) . '</a>';
	}

	/**
	 * Escaped HTML-source diff with the changed middle part highlighted.
	 *
	 * @param string $before Before excerpt (raw HTML source).
	 * @param string $after  After excerpt.
	 * @return string HTML.
	 */
	public static function diff( $before, $after ) {
		$before = (string) $before;
		$after  = (string) $after;
		$p      = 0;
		$max    = min( strlen( $before ), strlen( $after ) );
		while ( $p < $max && $before[ $p ] === $after[ $p ] ) {
			++$p;
		}
		$s = 0;
		while ( $s < $max - $p && $before[ strlen( $before ) - 1 - $s ] === $after[ strlen( $after ) - 1 - $s ] ) {
			++$s;
		}
		// Keep UTF-8 sequences intact at the boundaries.
		while ( $p > 0 && ( ord( $before[ $p ] ?? "\0" ) & 0xC0 ) === 0x80 ) {
			--$p;
		}
		$prefix = substr( $before, 0, $p );
		$del    = substr( $before, $p, strlen( $before ) - $p - $s );
		$ins    = substr( $after, $p, strlen( $after ) - $p - $s );
		$suffix = substr( $before, strlen( $before ) - $s );
		return '<div class="sseo-diff"><div class="sseo-diff__col"><h4>' . esc_html__( 'Before', 'semantic-seo-engine-vi-en' ) . '</h4><pre>' . esc_html( $prefix ) . ( '' !== $del ? '<del>' . esc_html( $del ) . '</del>' : '' ) . esc_html( $suffix ) . '</pre></div><div class="sseo-diff__col"><h4>' . esc_html__( 'After', 'semantic-seo-engine-vi-en' ) . '</h4><pre>' . esc_html( $prefix ) . '<ins>' . esc_html( $ins ) . '</ins>' . esc_html( $suffix ) . '</pre></div></div>';
	}

	/**
	 * Select element.
	 *
	 * @param string $name     Name.
	 * @param array  $options  value => label.
	 * @param string $selected Selected.
	 * @param string $label    Visible label.
	 * @return string HTML.
	 */
	public static function select( $name, array $options, $selected, $label ) {
		$id   = 'sseo-' . sanitize_html_class( $name );
		$html = '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label> <select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
		foreach ( $options as $v => $l ) {
			$html .= '<option value="' . esc_attr( (string) $v ) . '"' . selected( (string) $selected, (string) $v, false ) . '>' . esc_html( $l ) . '</option>';
		}
		return $html . '</select>';
	}

	/**
	 * Current request GET value (sanitized key/text).
	 *
	 * @param string $key     Key.
	 * @param string $default Default.
	 * @return string
	 */
	public static function get( $key, $default = '' ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filters.
		return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : $default;
	}
}
