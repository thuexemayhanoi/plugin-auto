<?php
/**
 * Small, conservative Vietnamese and English stopword lists.
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Language;

defined( 'ABSPATH' ) || exit;

/**
 * Stopwords. Vietnamese entries are single syllables because Vietnamese tokenization is syllable-based.
 */
final class Stopwords {

	const VI = array( 'và', 'của', 'là', 'những', 'các', 'được', 'cho', 'với', 'này', 'có', 'không', 'một', 'trong', 'người', 'đã', 'đang', 'sẽ', 'thì', 'mà', 'nên', 'nhưng', 'hay', 'hoặc', 'cũng', 'rất', 'ở', 'tại', 'từ', 'đến', 'khi', 'nếu', 'để', 'vì', 'do', 'bởi', 'theo', 'về', 'ra', 'vào', 'lên', 'xuống', 'nhiều', 'ít', 'đó', 'kia', 'nào', 'gì', 'ai', 'bạn', 'tôi', 'chúng', 'ta', 'mình', 'họ', 'nó', 'anh', 'chị', 'em', 'như', 'còn', 'lại', 'thêm', 'nữa', 'vẫn', 'đều', 'chỉ', 'việc', 'sự', 'bị', 'bằng', 'qua', 'trên', 'dưới', 'sau', 'trước', 'cùng', 'nhất', 'hơn', 'luôn', 'thể', 'cần', 'phải' );

	const EN = array( 'a', 'an', 'the', 'and', 'or', 'but', 'of', 'to', 'in', 'on', 'at', 'for', 'with', 'by', 'from', 'as', 'is', 'are', 'was', 'were', 'be', 'been', 'being', 'it', 'its', 'this', 'that', 'these', 'those', 'you', 'your', 'we', 'our', 'they', 'their', 'he', 'she', 'his', 'her', 'i', 'me', 'my', 'do', 'does', 'did', 'have', 'has', 'had', 'not', 'no', 'so', 'if', 'then', 'than', 'there', 'here', 'what', 'which', 'who', 'how', 'when', 'where', 'why', 'can', 'will', 'would', 'should', 'could', 'may', 'about', 'into', 'over', 'also', 'more', 'most', 'very', 'all', 'any', 'some', 'just', 'up', 'out' );

	/**
	 * English stopwords that are also meaningful unaccented Vietnamese syllables ("an toàn", "xe to",
	 * "no", "mẹ" typed without accents…). They are only dropped in documents known to be English.
	 */
	const COLLISIONS = array( 'an', 'to', 'no', 'do', 'me', 'so', 'in', 'on', 'can', 'may', 'he', 'be', 'my', 'her', 'his', 'up', 'had', 'out', 'i' );

	/**
	 * Lookup table cache.
	 *
	 * @var array<string,array<string,bool>>
	 */
	private static $sets = array();

	/**
	 * Whether a normalized token is a stopword for the given document language.
	 *
	 * @param string $token Lowercase NFC token.
	 * @param string $lang  vi|en|'' (unknown or mixed).
	 * @return bool
	 */
	public static function is_stopword( $token, $lang = '' ) {
		$lang = in_array( $lang, array( 'vi', 'en' ), true ) ? $lang : 'mixed';
		if ( ! isset( self::$sets[ $lang ] ) ) {
			if ( 'vi' === $lang ) {
				$words = self::VI;
			} elseif ( 'en' === $lang ) {
				$words = self::EN;
			} else {
				$words = array_merge( self::VI, array_diff( self::EN, self::COLLISIONS ) );
			}
			self::$sets[ $lang ] = array_fill_keys( $words, true );
		}
		return isset( self::$sets[ $lang ][ $token ] );
	}
}
