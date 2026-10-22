<?php
/**
 * WP-CLI commands for authenticated operators (run with --user=<admin>).
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Cli;

use SemanticSEO\Database\Migrator;
use SemanticSEO\Jobs\JobRunner;
use SemanticSEO\Matrix\MatrixExporter;
use SemanticSEO\Services\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Semantic SEO Engine operations. Every command goes through the same service layer and
 * capability checks as WP Admin and REST, so --user must have the required capability.
 */
final class Command {

	/**
	 * Registers the command.
	 *
	 * @return void
	 */
	public static function register() {
		\WP_CLI::add_command( 'sseo', self::class );
	}

	/**
	 * Prints a result or fails with the error.
	 *
	 * @param mixed  $res    Result.
	 * @param string $format json|yaml.
	 * @return void
	 */
	private static function out( $res, $format = 'json' ) {
		if ( is_wp_error( $res ) ) {
			\WP_CLI::error( $res->get_error_code() . ': ' . $res->get_error_message() );
		}
		if ( 'yaml' === $format ) {
			\WP_CLI::line( \Spyc::YAMLDump( $res ) );
			return;
		}
		\WP_CLI::line( (string) wp_json_encode( $res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Shows site status.
	 *
	 * ## EXAMPLES
	 *     wp sseo status --user=admin
	 *
	 * @param array $args  Args.
	 * @param array $assoc Assoc args.
	 * @return void
	 */
	public function status( $args, $assoc ) {
		unset( $args );
		self::out( Api::status(), isset( $assoc['format'] ) ? $assoc['format'] : 'json' );
	}

	/**
	 * Starts a job and optionally runs it to completion in this process.
	 *
	 * ## OPTIONS
	 *
	 * <type>
	 * : inventory|analyze|embed|verify_links
	 *
	 * [--post_ids=<ids>]
	 * : Comma-separated post IDs (max 200).
	 *
	 * [--then-analyze]
	 * : Chain an analysis after an inventory.
	 *
	 * [--run]
	 * : Run the job to completion now.
	 *
	 * ## EXAMPLES
	 *     wp sseo start inventory --then-analyze --run --user=admin
	 *
	 * @param array $args  Args.
	 * @param array $assoc Assoc args.
	 * @return void
	 */
	public function start( $args, $assoc ) {
		$params = array( 'type' => $args[0] );
		if ( ! empty( $assoc['post_ids'] ) ) {
			$params['post_ids'] = array_map( 'intval', explode( ',', (string) $assoc['post_ids'] ) );
		}
		if ( ! empty( $assoc['then-analyze'] ) ) {
			$params['then_analyze'] = true;
		}
		$job = Api::start_analysis( $params );
		if ( is_wp_error( $job ) ) {
			self::out( $job );
		}
		if ( ! empty( $assoc['run'] ) ) {
			$this->run_all();
		}
		self::out( Api::job_status( $job['id'] ) );
	}

	/**
	 * Runs all runnable jobs until none is left (chained jobs included).
	 *
	 * ## EXAMPLES
	 *     wp sseo run --user=admin
	 *
	 * @return void
	 */
	public function run() {
		$this->run_all();
		self::out( Api::jobs( array( 'limit' => 10 ) ) );
	}

	/**
	 * Runs runnable jobs.
	 *
	 * @return void
	 */
	private function run_all() {
		$guard = 0;
		while ( $guard++ < 100 ) {
			$next = \SemanticSEO\Jobs\JobRepository::next_runnable();
			if ( $next <= 0 ) {
				break;
			}
			$job = \SemanticSEO\Jobs\JobRepository::get( $next );
			if ( null !== $job && 'embed' === $job['type'] ) {
				\WP_CLI::warning( 'Embedding jobs wait for a browser or private worker; skipping job ' . $next . '.' );
				break;
			}
			$res = JobRunner::run_to_completion( $next );
			\WP_CLI::log( sprintf( 'job %d → %s (%s)', $next, $res['status'], $res['message'] ) );
			if ( 'completed' !== $res['status'] ) {
				break;
			}
		}
	}

	/**
	 * Lists jobs or shows one.
	 *
	 * [<id>]
	 * : Job ID.
	 *
	 * @param array $args Args.
	 * @return void
	 */
	public function jobs( $args ) {
		self::out( isset( $args[0] ) ? Api::job_status( (int) $args[0] ) : Api::jobs( array( 'limit' => 20 ) ) );
	}

	/**
	 * Cancels a job.
	 *
	 * <id>
	 * : Job ID.
	 *
	 * @param array $args Args.
	 * @return void
	 */
	public function cancel( $args ) {
		self::out( Api::cancel_job( (int) $args[0] ) );
	}

	/**
	 * Shows the analysis of one URL or post.
	 *
	 * [--post_id=<id>]
	 * : Post ID.
	 *
	 * [--url=<url>]
	 * : URL on this site.
	 *
	 * @param array $args  Args.
	 * @param array $assoc Assoc args.
	 * @return void
	 */
	public function analysis( $args, $assoc ) {
		unset( $args );
		self::out( Api::url_analysis( $assoc ) );
	}

	/**
	 * Lists findings of a module.
	 *
	 * <module>
	 * : links|cannibalization|qa|clusters
	 *
	 * [--limit=<n>]
	 * : Max rows.
	 *
	 * [--locale=<code>]
	 * : Locale.
	 *
	 * @param array $args  Args.
	 * @param array $assoc Assoc args.
	 * @return void
	 */
	public function report( $args, $assoc ) {
		switch ( $args[0] ) {
			case 'links':
				self::out( Api::link_suggestions( $assoc ) );
				break;
			case 'cannibalization':
				self::out( Api::cannibalization( $assoc ) );
				break;
			case 'qa':
				self::out( Api::qa_issues( $assoc ) );
				break;
			case 'clusters':
				self::out( Api::clusters( $assoc ) );
				break;
			default:
				\WP_CLI::error( 'Unknown module.' );
		}
	}

	/**
	 * Exports the SEO Matrix as CSV (UTF-8 BOM) or XLSX.
	 *
	 * --file=<path>
	 * : Destination file (.csv or .xlsx).
	 *
	 * @param array $args  Args.
	 * @param array $assoc Assoc args.
	 * @return void
	 */
	public function export( $args, $assoc ) {
		unset( $args );
		if ( ! current_user_can( \SemanticSEO\Capabilities::READ ) ) {
			\WP_CLI::error( 'Run with --user=<a user with sseo_read>.' );
		}
		$file = (string) $assoc['file'];
		if ( '.xlsx' === strtolower( substr( $file, -5 ) ) ) {
			$n = MatrixExporter::xlsx( array(), $file );
			if ( is_wp_error( $n ) ) {
				\WP_CLI::error( $n->get_error_message() );
			}
		} else {
			$fh = fopen( $file, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$n  = MatrixExporter::csv( $fh, array() );
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		\WP_CLI::success( sprintf( '%d rows written to %s', $n, $file ) );
	}

	/**
	 * Runs database migrations.
	 *
	 * @return void
	 */
	public function migrate() {
		if ( ! current_user_can( 'manage_options' ) ) {
			\WP_CLI::error( 'Run with --user=<administrator>.' );
		}
		self::out( Migrator::migrate() );
	}

	/**
	 * Shows the audit log.
	 *
	 * [--limit=<n>]
	 * : Rows.
	 *
	 * @param array $args  Args.
	 * @param array $assoc Assoc args.
	 * @return void
	 */
	public function audit( $args, $assoc ) {
		unset( $args );
		self::out( Api::audit_log( $assoc ) );
	}
}
