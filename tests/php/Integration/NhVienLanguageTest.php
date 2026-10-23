<?php
/**
 * Language detection through the REAL "Nguyen Ha VI-EN Core" plugin code (nguyen-ha-vi-en, used on
 * blog.rentbikehanoi.com according to the owner's wordpress-theme repository).
 *
 * Loads only its NH_VIEN_Links class (no hooks are registered) from NH_VIEN_PLUGIN_DIR, which CI
 * fills with a pinned checkout of thuexemayhanoi/wordpress-theme/plugins/nguyen-ha-vi-en. Skipped
 * when the directory is not available. The plugin's data is written here only into the throwaway
 * test database, exactly in the format its own admin screen stores.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Tests\Integration;

use SemanticSEO\Database\Db;
use SemanticSEO\Inventory\DocumentRepository;
use SemanticSEO\Language\LanguageResolver;

/**
 * @group nh-vien
 */
final class NhVienLanguageTest extends TestCase {

	/**
	 * Loads the real class or skips.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$dir = (string) getenv( 'NH_VIEN_PLUGIN_DIR' );
		if ( ! class_exists( 'NH_VIEN_Links', false ) ) {
			if ( '' === $dir || ! is_readable( $dir . '/includes/class-nh-vien-links.php' ) ) {
				$this->markTestSkipped( 'NH_VIEN_PLUGIN_DIR not set (CI checks out the real nguyen-ha-vi-en plugin).' );
			}
			require_once $dir . '/includes/class-nh-vien-links.php';
		}
		add_filter( 'sseo_language_framework', array( __CLASS__, 'force' ) );
	}

	/**
	 * Restores detection.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_filter( 'sseo_language_framework', array( __CLASS__, 'force' ) );
		if ( class_exists( 'NH_VIEN_Links', false ) ) {
			\NH_VIEN_Links::flush_cache();
		}
		parent::tear_down();
	}

	/**
	 * The test suite also loads a Polylang double; force the VI-EN code path here.
	 *
	 * @return string
	 */
	public static function force() {
		return 'nh_vien';
	}

	/**
	 * Creates a post.
	 *
	 * @param string $title Title.
	 * @param string $type  post|page.
	 * @param string $body  Content.
	 * @param int    $parent Parent page.
	 * @param string $slug   Slug (optional).
	 * @return int
	 */
	private function make( $title, $type, $body, $parent = 0, $slug = '' ) {
		$args = array(
			'post_title'   => $title,
			'post_type'    => $type,
			'post_status'  => 'publish',
			'post_content' => $body,
			'post_parent'  => $parent,
		);
		if ( '' !== $slug ) {
			$args['post_name'] = $slug;
		}
		return (int) self::factory()->post->create( $args );
	}

	public function test_locales_and_pairs_come_from_the_vi_en_plugin() {
		$this->set_permalink_structure( '/%postname%/' );
		$vi_rent  = $this->make( 'Thuê xe máy Hà Nội theo ngày', 'post', '<p>Bảng giá thuê xe máy theo ngày ở Hà Nội, thủ tục và tiền đặt cọc khi thuê xe tay ga hoặc xe số.</p>' );
		$en_rent  = $this->make( 'Daily motorbike rental in Hanoi', 'post', '<p>Daily motorbike rental prices in Hanoi, the documents and the deposit for a scooter or a semi-automatic bike.</p>' );
		$vi_only  = $this->make( 'Kinh nghiệm đi Hà Giang bằng xe máy', 'post', '<p>Lịch trình Hà Giang ba ngày, đường đèo và lưu ý an toàn khi đi xe máy.</p>' );
		$en_alone = $this->make( 'Riding etiquette in Hanoi traffic', 'post', '<p>How to ride in Hanoi traffic: horns, lanes, roundabouts and parking.</p>' );
		$en_root  = $this->make( 'English', 'page', '<p>English section.</p>', 0, 'en' );
		$vi_page  = $this->make( 'Liên hệ', 'page', '<p>Gọi hoặc nhắn Zalo để được tư vấn.</p>' );
		$en_page  = $this->make( 'Contact', 'page', '<p>Call or message us on Zalo for advice.</p>', $en_root );
		$en_about = $this->make( 'About us', 'page', '<p>Who we are.</p>', $en_root );
		$cat_en   = (int) self::factory()->category->create( array( 'name' => 'Motorbike rental' ) );
		$cat_vi   = (int) self::factory()->category->create( array( 'name' => 'Thuê xe máy' ) );
		wp_set_post_categories( $en_rent, array( $cat_en ) );
		wp_set_post_categories( $vi_rent, array( $cat_vi ) );

		// The plugin's own storage format (its admin screen writes the same options).
		update_option( 'nh_vien_translation_pairs_v1', array( $vi_rent => $en_rent ), false );
		update_option( 'nh_vien_independent_en_posts_v1', array( $en_alone ), false );
		update_option( 'nh_vien_page_pairs_v1', array( $vi_page => $en_page ), false );
		update_option( 'nh_vien_english_categories_v1', array( $cat_en ), false );
		\NH_VIEN_Links::flush_cache();

		$this->assertSame( 'nh_vien', LanguageResolver::framework() );
		$this->assertTrue( LanguageResolver::has_translation_mapping() );
		$this->assertSame( array( 'vi', 'en' ), LanguageResolver::languages() );
		$this->assertSame( 'Nguyen Ha VI-EN Core', LanguageResolver::framework_label( 'nh_vien' ) );

		$this->run_full_pipeline();

		$doc = static function ( $type, $id ) {
			$row = DocumentRepository::by_object( $type, $id );
			return null === $row ? array() : $row;
		};
		$expect = array(
			array( 'post', $vi_rent, 'vi', 'nh_vien' ),
			array( 'post', $en_rent, 'en', 'nh_vien' ),
			array( 'post', $vi_only, 'vi', 'nh_vien' ),
			array( 'post', $en_alone, 'en', 'nh_vien' ),
			array( 'post', $vi_page, 'vi', 'nh_vien' ),
			array( 'post', $en_page, 'en', 'nh_vien' ),
			array( 'post', $en_about, 'en', 'nh_vien_path' ),
			array( 'term', $cat_en, 'en', 'nh_vien' ),
			array( 'term', $cat_vi, 'vi', 'nh_vien' ),
		);
		foreach ( $expect as $e ) {
			$row = $doc( $e[0], $e[1] );
			$this->assertNotEmpty( $row, 'indexed: ' . $e[0] . ' ' . $e[1] );
			$this->assertSame( $e[2], $row['locale'], 'locale of ' . $e[0] . ' ' . $e[1] . ' (' . $row['url'] . ')' );
			$this->assertSame( $e[3], $row['locale_source'], 'source of ' . $e[0] . ' ' . $e[1] );
		}

		// Explicit pairs share a translation group; unpaired content has none.
		$g_vi = $doc( 'post', $vi_rent )['translation_group'];
		$this->assertNotSame( '', $g_vi );
		$this->assertSame( $g_vi, $doc( 'post', $en_rent )['translation_group'] );
		$this->assertSame( $doc( 'post', $vi_page )['translation_group'], $doc( 'post', $en_page )['translation_group'] );
		$this->assertSame( '', $doc( 'post', $vi_only )['translation_group'] );
		$this->assertSame( '', $doc( 'post', $en_alone )['translation_group'] );

		// The pair is never reported as cannibalization; a missing translation is only a suggestion.
		$cross = (int) Db::value( "SELECT COUNT(*) FROM {findings} f JOIN {documents} a ON a.id = f.document_id JOIN {documents} b ON b.id = f.target_id WHERE f.type = 'cannibalization' AND a.locale <> b.locale" );
		$this->assertSame( 0, $cross );
		$labels = Db::col( "SELECT DISTINCT label FROM {findings} WHERE type = 'qa' AND check_id = 'missing_translation'" );
		$this->assertNotContains( 'warning', $labels );
		$missing = Db::col( "SELECT document_id FROM {findings} WHERE type = 'qa' AND check_id = 'missing_translation'" );
		$this->assertNotContains( (string) $doc( 'post', $vi_rent )['id'], array_map( 'strval', $missing ), 'paired post is not missing a translation' );

		// Read-only: the plugin's options are untouched by indexing and analysis.
		$this->assertSame( array( $vi_rent => $en_rent ), get_option( 'nh_vien_translation_pairs_v1' ) );
		$this->assertSame( array( $en_alone ), get_option( 'nh_vien_independent_en_posts_v1' ) );
	}
}
