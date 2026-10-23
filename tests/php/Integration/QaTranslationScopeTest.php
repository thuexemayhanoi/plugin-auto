<?php
/**
 * "Missing translation" only for content types the site translates as linked pairs.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Tests\Integration;

use SemanticSEO\Database\Db;
use SemanticSEO\Jobs\JobRepository;
use SemanticSEO\Jobs\JobRunner;

/**
 * @group qa
 */
final class QaTranslationScopeTest extends TestCase {

	public function test_no_missing_translation_for_a_type_without_any_pair() {
		$this->load_corpus();
		$this->run_full_pipeline();
		$count = static function () {
			return (int) Db::value( "SELECT COUNT(*) FROM {findings} f JOIN {documents} d ON d.id = f.document_id WHERE f.type = 'qa' AND f.check_id = 'missing_translation' AND f.status = 'open' AND d.subtype = 'post'" );
		};
		$this->assertGreaterThan( 0, $count(), 'posts are translated as pairs: unpaired posts are suggested' );
		$this->assertSame( 0, (int) Db::value( "SELECT COUNT(*) FROM {findings} WHERE check_id = 'missing_translation' AND label <> 'suggestion'" ) );

		// A site whose posts are all standalone (no post pair at all): nothing to suggest for posts.
		Db::query( "UPDATE {documents} SET translation_group = '' WHERE subtype = 'post'" );
		$job = JobRepository::create( 'analyze', array(), $this->admin, 'test' );
		$this->assertIsArray( $job );
		$res = JobRunner::run_to_completion( (int) $job['id'] );
		$this->assertSame( 'completed', $res['status'], wp_json_encode( $res ) );
		$this->assertSame( 0, $count() );
	}
}
