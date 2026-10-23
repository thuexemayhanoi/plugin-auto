<?php
/**
 * Vietnamese place names in titles (accent-insensitive, Vietnamese and English spellings).
 *
 * @package SemanticSEO
 */

namespace SemanticSEO\Language;

defined( 'ABSPATH' ) || exit;

/**
 * Two pages whose titles name different places (Hà Giang vs Sa Pa, Hoàn Kiếm vs Long Biên) target
 * different searches even when the model finds their text alike: on the read-only copy of a
 * motorbike-rental blog most "ambiguous" overlap pairs were travel guides and local pages of this
 * kind. Matching is on whole words of the accent-folded title. The list is filterable
 * (sseo_place_names) so a site can add its own districts or landmarks.
 */
final class Places {

	const VERSION = 'places-1';

	/**
	 * Canonical key => spellings (folded, lowercase, single spaces). Ambiguous everyday words
	 * (e.g. "vinh", which is also "vịnh" = bay) are deliberately left out.
	 */
	const NAMES = array(
		'ha noi'       => array( 'ha noi', 'hanoi' ),
		// "Hồ Chí Minh" alone is also the mausoleum in Hà Nội: only city spellings.
		'ho chi minh'  => array( 'ho chi minh city', 'thanh pho ho chi minh', 'tp ho chi minh', 'tp hcm', 'tphcm', 'hcmc', 'sai gon', 'saigon' ),
		'hai phong'    => array( 'hai phong', 'haiphong' ),
		'da nang'      => array( 'da nang', 'danang' ),
		'can tho'      => array( 'can tho' ),
		'hue'          => array( 'hue' ),
		'ha giang'     => array( 'ha giang', 'hagiang' ),
		'dong van'     => array( 'dong van' ),
		'meo vac'      => array( 'meo vac' ),
		'ma pi leng'   => array( 'ma pi leng', 'mapileng' ),
		'sa pa'        => array( 'sa pa', 'sapa' ),
		'lao cai'      => array( 'lao cai' ),
		'y ty'         => array( 'y ty' ),
		'mu cang chai' => array( 'mu cang chai' ),
		'yen bai'      => array( 'yen bai' ),
		'moc chau'     => array( 'moc chau' ),
		'son la'       => array( 'son la' ),
		'mai chau'     => array( 'mai chau' ),
		'hoa binh'     => array( 'hoa binh' ),
		'dien bien'    => array( 'dien bien' ),
		'lai chau'     => array( 'lai chau' ),
		'cao bang'     => array( 'cao bang' ),
		'ban gioc'     => array( 'ban gioc' ),
		'ba be'        => array( 'ba be' ),
		'bac kan'      => array( 'bac kan', 'bac can' ),
		'lang son'     => array( 'lang son' ),
		'thai nguyen'  => array( 'thai nguyen' ),
		'tuyen quang'  => array( 'tuyen quang' ),
		'phu tho'      => array( 'phu tho' ),
		'vinh phuc'    => array( 'vinh phuc' ),
		'tam dao'      => array( 'tam dao' ),
		'ba vi'        => array( 'ba vi' ),
		'ninh binh'    => array( 'ninh binh' ),
		'trang an'     => array( 'trang an' ),
		'tam coc'      => array( 'tam coc' ),
		'bai dinh'     => array( 'bai dinh' ),
		'ha long'      => array( 'ha long', 'halong' ),
		'cat ba'       => array( 'cat ba' ),
		'quang ninh'   => array( 'quang ninh' ),
		'bai chay'     => array( 'bai chay' ),
		'thanh hoa'    => array( 'thanh hoa' ),
		'nghe an'      => array( 'nghe an' ),
		'ha tinh'      => array( 'ha tinh' ),
		'quang binh'   => array( 'quang binh' ),
		'phong nha'    => array( 'phong nha' ),
		'quang tri'    => array( 'quang tri' ),
		'hoi an'       => array( 'hoi an', 'hoian' ),
		'quang nam'    => array( 'quang nam' ),
		'quang ngai'   => array( 'quang ngai' ),
		'quy nhon'     => array( 'quy nhon' ),
		'binh dinh'    => array( 'binh dinh' ),
		'phu yen'      => array( 'phu yen' ),
		'nha trang'    => array( 'nha trang' ),
		'khanh hoa'    => array( 'khanh hoa' ),
		'da lat'       => array( 'da lat', 'dalat' ),
		'lam dong'     => array( 'lam dong' ),
		'mui ne'       => array( 'mui ne' ),
		'phan thiet'   => array( 'phan thiet' ),
		'vung tau'     => array( 'vung tau' ),
		'phu quoc'     => array( 'phu quoc' ),
		'con dao'      => array( 'con dao' ),
		'mekong'       => array( 'mekong', 'mien tay' ),
		'ha nam'       => array( 'ha nam' ),
		'nam dinh'     => array( 'nam dinh' ),
		'thai binh'    => array( 'thai binh' ),
		'hai duong'    => array( 'hai duong' ),
		'hung yen'     => array( 'hung yen' ),
		'bac ninh'     => array( 'bac ninh' ),
		'bac giang'    => array( 'bac giang' ),
		'gia lai'      => array( 'gia lai' ),
		'kon tum'      => array( 'kon tum' ),
		'dak lak'      => array( 'dak lak', 'daklak', 'buon ma thuot' ),
		'tay ninh'     => array( 'tay ninh' ),
		'binh duong'   => array( 'binh duong' ),
		'dong nai'     => array( 'dong nai' ),
		'an giang'     => array( 'an giang' ),
		'kien giang'   => array( 'kien giang' ),
		'ca mau'       => array( 'ca mau' ),
		// Hà Nội districts, areas and landmarks.
		'hoan kiem'    => array( 'hoan kiem' ),
		'pho co'       => array( 'pho co', 'old quarter' ),
		'ba dinh'      => array( 'ba dinh' ),
		'tay ho'       => array( 'tay ho', 'west lake' ),
		'long bien'    => array( 'long bien' ),
		'cau giay'     => array( 'cau giay' ),
		'dong da'      => array( 'dong da' ),
		'hai ba trung' => array( 'hai ba trung' ),
		'hoang mai'    => array( 'hoang mai' ),
		'thanh xuan'   => array( 'thanh xuan' ),
		'nam tu liem'  => array( 'nam tu liem' ),
		'bac tu liem'  => array( 'bac tu liem' ),
		'ha dong'      => array( 'ha dong' ),
		'gia lam'      => array( 'gia lam' ),
		'dong anh'     => array( 'dong anh' ),
		'soc son'      => array( 'soc son' ),
		'noi bai'      => array( 'noi bai' ),
		'son tay'      => array( 'son tay' ),
		// Hồ Chí Minh City areas.
		'binh thanh'   => array( 'binh thanh' ),
		'phu nhuan'    => array( 'phu nhuan' ),
		'tan binh'     => array( 'tan binh' ),
		'go vap'       => array( 'go vap' ),
		'thu duc'      => array( 'thu duc' ),
		'ben thanh'    => array( 'ben thanh' ),
	);

	/**
	 * Request cache: title => places.
	 *
	 * @var array<string,string[]>
	 */
	private static $memo = array();

	/**
	 * Place keys named in a text (title).
	 *
	 * @param string $text Text.
	 * @return string[] Sorted canonical keys.
	 */
	public static function in( $text ) {
		$text = (string) $text;
		if ( isset( self::$memo[ $text ] ) ) {
			return self::$memo[ $text ];
		}
		$folded = ' ' . trim( (string) preg_replace( '/[^a-z0-9]+/', ' ', strtolower( TextNormalizer::fold( $text ) ) ) ) . ' ';
		$found  = array();
		foreach ( self::names() as $key => $spellings ) {
			foreach ( (array) $spellings as $s ) {
				if ( false !== strpos( $folded, ' ' . $s . ' ' ) ) {
					$found[ $key ] = true;
					break;
				}
			}
		}
		$out = array_keys( $found );
		sort( $out );
		if ( count( self::$memo ) > 5000 ) {
			self::$memo = array();
		}
		self::$memo[ $text ] = $out;
		return $out;
	}

	/**
	 * Both titles name a place the other does not (e.g. Hà Giang vs Sa Pa).
	 *
	 * @param string $title_a Title A.
	 * @param string $title_b Title B.
	 * @return array{0:string[],1:string[]}|null Places only in A and only in B, or null.
	 */
	public static function distinct( $title_a, $title_b ) {
		$a      = self::in( $title_a );
		$b      = self::in( $title_b );
		$only_a = array_values( array_diff( $a, $b ) );
		$only_b = array_values( array_diff( $b, $a ) );
		return array() !== $only_a && array() !== $only_b ? array( $only_a, $only_b ) : null;
	}

	/**
	 * Filtered list.
	 *
	 * @return array<string,string[]>
	 */
	private static function names() {
		static $names = null;
		if ( null === $names ) {
			/**
			 * Filters the place names (canonical key => folded lowercase spellings).
			 *
			 * @param array $names Names.
			 */
			$names = (array) apply_filters( 'sseo_place_names', self::NAMES );
		}
		return $names;
	}
}
