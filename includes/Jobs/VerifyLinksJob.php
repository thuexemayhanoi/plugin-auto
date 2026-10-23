<?php
/**
 * Optional HTTP verification of internal link targets (same host only).
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Jobs;

use SemanticSEO\Database\Db;
use SemanticSEO\Security\UrlGuard;
use SemanticSEO\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Disabled unless "verify_http" is enabled. Uses wp_safe_remote_head (no private-IP targets) and
 * never follows redirects, so it cannot be abused for SSRF.
 */
final class VerifyLinksJob {

	/**
	 * Initial state.
	 *
	 * @param array $job Job.
	 * @return array{state:array,total:int}
	 */
	public static function init( array $job ) {
		unset( $job );
		return array(
			'state' => array(
				'phase'   => 'verify',
				'cursor'  => 0,
				'checked' => 0,
				'skipped' => 0,
			),
			'total' => (int) Db::value( 'SELECT COUNT(DISTINCT target_hash) FROM {links} WHERE is_internal = 1' ),
		);
	}

	/**
	 * One batch.
	 *
	 * @param array $job   Job.
	 * @param int   $batch Batch size.
	 * @return array
	 */
	public static function step( array $job, $batch ) {
		$state = $job['state'];
		if ( ! Settings::get( 'verify_http', false ) ) {
			$state['note'] = 'HTTP verification is disabled in Settings; statuses remain "unverified".';
			return array(
				'state'    => $state,
				'done'     => 0,
				'finished' => true,
			);
		}
		$rows = Db::rows( 'SELECT MIN(id) AS id, target_hash, MIN(target_url) AS target_url FROM {links} WHERE is_internal = 1 AND id > %d GROUP BY target_hash ORDER BY MIN(id) ASC LIMIT %d', array( (int) $state['cursor'], min( 10, max( 1, (int) $batch ) ) ) );
		foreach ( $rows as $r ) {
			$state['cursor'] = max( (int) $state['cursor'], (int) $r['id'] );
			$url             = (string) $r['target_url'];
			if ( ! UrlGuard::may_fetch( $url ) ) {
				++$state['skipped'];
				continue;
			}
			$res = wp_safe_remote_head(
				$url,
				array(
					'timeout'     => 5,
					'redirection' => 0,
					'user-agent'  => 'SemanticSEO-LinkCheck/' . SSEO_VERSION,
				)
			);
			if ( is_wp_error( $res ) ) {
				++$state['skipped'];
				continue;
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			Db::query( 'UPDATE {links} SET http_status = %d WHERE target_hash = %s', array( $code, $r['target_hash'] ) );
			++$state['checked'];
		}
		return array(
			'state'    => $state,
			'done'     => count( $rows ),
			'finished' => count( $rows ) < min( 10, max( 1, (int) $batch ) ),
		);
	}

	/**
	 * Completion hook.
	 *
	 * @param array $job Job.
	 * @return void
	 */
	public static function finished( array $job ) {
		unset( $job );
	}
}
