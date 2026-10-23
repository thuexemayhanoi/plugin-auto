<?php
/**
 * SYNTHETIC BENCHMARK FIXTURE generator (wp eval-file tests/bench/generate.php <count>).
 *
 * Creates <count> posts (≈70% Vietnamese, 30% English when Polylang is active) built from
 * templates about motorbike rental and travel, with categories, a few internal links and
 * translation pairs. Deterministic (seeded). For load/performance measurement only — not real
 * content, not real prices.
 *
 * @package SemanticSEO
 */

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited

$sseo_n   = isset( $args[0] ) ? max( 1, (int) $args[0] ) : 100;
$sseo_pll = function_exists( 'pll_set_post_language' ) && function_exists( 'PLL' );
mt_srand( 20261023 );

if ( $sseo_pll ) {
	$sseo_have = wp_list_pluck( PLL()->model->get_languages_list(), 'slug' );
	foreach ( array( array( 'Tiếng Việt', 'vi', 'vi', 0, 'vn' ), array( 'English', 'en', 'en_US', 1, 'us' ) ) as $l ) {
		if ( ! in_array( $l[1], $sseo_have, true ) ) {
			PLL()->model->add_language( array( 'name' => $l[0], 'slug' => $l[1], 'locale' => $l[2], 'rtl' => false, 'term_group' => $l[3], 'flag' => $l[4] ) );
		}
	}
	PLL()->model->clean_languages_cache();
}

$sseo_places = array( 'Hà Nội', 'Hải Phòng', 'Đà Nẵng', 'Huế', 'Sapa', 'Hà Giang', 'Ninh Bình', 'Hạ Long', 'Mộc Châu', 'Mai Châu', 'Cao Bằng', 'Hội An', 'Đà Lạt', 'Nha Trang', 'Phú Quốc' );
$sseo_places_en = array( 'Hanoi', 'Hai Phong', 'Da Nang', 'Hue', 'Sapa', 'Ha Giang', 'Ninh Binh', 'Ha Long', 'Moc Chau', 'Mai Chau', 'Cao Bang', 'Hoi An', 'Da Lat', 'Nha Trang', 'Phu Quoc' );
$sseo_topics = array(
	'vi' => array(
		'thue'    => array( 'Thuê xe máy', array( 'Thuê xe máy %s theo ngày', 'Thuê xe máy %s theo tuần', 'Thuê xe máy %s theo tháng', 'Bảng giá thuê xe máy ở %s', 'Thủ tục thuê xe máy tại %s', 'Thuê xe tay ga ở %s' ) ),
		'dulich'  => array( 'Du lịch', array( 'Kinh nghiệm du lịch %s bằng xe máy', 'Lịch trình phượt %s 3 ngày', 'Ăn gì ở %s', 'Mùa đẹp nhất để đến %s' ) ),
		'laixe'   => array( 'Kinh nghiệm lái xe', array( 'Lái xe máy an toàn ở %s', 'Đường đèo %s: lưu ý khi lái xe', 'Đổ xăng và bảo quản xe ở %s' ) ),
	),
	'en' => array(
		'rental'  => array( 'Motorbike rental', array( 'Daily motorbike rental in %s', 'Weekly motorbike rental in %s', 'Monthly motorbike rental in %s', 'Motorbike rental prices in %s', 'Scooter rental in %s' ) ),
		'travel'  => array( 'Travel', array( '%s by motorbike: travel guide', 'Three-day %s motorbike itinerary', 'What to eat in %s' ) ),
		'riding'  => array( 'Riding tips', array( 'Safe riding in %s', 'Mountain passes near %s: riding tips' ) ),
	),
);
$sseo_sentences = array(
	'vi' => array( 'Khách du lịch thường chọn xe số hoặc xe tay ga để đi lại linh hoạt.', 'Giá thuê thay đổi theo loại xe và thời gian thuê.', 'Bạn nên kiểm tra phanh, đèn và lốp trước khi nhận xe.', 'Tiền đặt cọc được thỏa thuận rõ trong hợp đồng thuê.', 'Đội mũ bảo hiểm và tuân thủ luật giao thông là bắt buộc.', 'Đường núi có nhiều khúc cua, hãy đi chậm và giữ khoảng cách.', 'Mùa khô là thời điểm thuận lợi để đi phượt bằng xe máy.', 'Nên đổ đầy bình xăng trước những chặng đường dài.', 'Các món ăn địa phương rất đáng thử sau một ngày di chuyển.', 'Hãy chụp ảnh tình trạng xe khi nhận để tránh tranh chấp.' ),
	'en' => array( 'Travellers usually pick a semi-automatic bike or a scooter for flexibility.', 'Prices depend on the bike type and the rental period.', 'Check the brakes, lights and tyres before you ride away.', 'The deposit is agreed in the rental contract.', 'Wear a helmet and follow the traffic rules at all times.', 'Mountain roads have many bends; ride slowly and keep your distance.', 'The dry season is the best time for a motorbike trip.', 'Fill the tank before long stretches between towns.', 'Local food is worth trying after a long day on the road.', 'Take photos of the bike when you collect it to avoid disputes.' ),
);

// Categories.
$sseo_cats = array();
foreach ( $sseo_topics as $lang => $topics ) {
	foreach ( $topics as $key => $t ) {
		$term = term_exists( 'bench-' . $lang . '-' . $key, 'category' );
		if ( ! $term ) {
			$term = wp_insert_term( $t[0], 'category', array( 'slug' => 'bench-' . $lang . '-' . $key ) );
		}
		$sseo_cats[ $lang ][ $key ] = (int) ( is_array( $term ) ? $term['term_id'] : $term );
		if ( $sseo_pll ) {
			pll_set_term_language( $sseo_cats[ $lang ][ $key ], $lang );
		}
	}
}

$sseo_ids = array();
$sseo_t0  = microtime( true );
for ( $i = 0; $i < $sseo_n; $i++ ) {
	$lang   = $sseo_pll && in_array( $i % 10, array( 3, 6, 9 ), true ) ? 'en' : 'vi';
	$keys   = array_keys( $sseo_topics[ $lang ] );
	$tkey   = $keys[ $i % count( $keys ) ];
	$tpl    = $sseo_topics[ $lang ][ $tkey ][1];
	$places = 'en' === $lang ? $sseo_places_en : $sseo_places;
	$place  = $places[ intdiv( $i, count( $keys ) ) % count( $places ) ];
	$title  = sprintf( $tpl[ intdiv( $i, count( $keys ) * count( $places ) ) % count( $tpl ) ], $place ) . ( $i >= count( $keys ) * count( $places ) * count( $tpl ) ? ' (' . ( 1 + intdiv( $i, count( $keys ) * count( $places ) * count( $tpl ) ) ) . ')' : '' );
	$paras  = array();
	for ( $p = 0; $p < 4 + $i % 4; $p++ ) {
		$s = $sseo_sentences[ $lang ];
		shuffle( $s );
		$paras[] = '<p>' . esc_html( $title ) . '. ' . implode( ' ', array_slice( $s, 0, 4 ) ) . '</p>';
		if ( 1 === $p ) {
			$paras[] = '<h2>' . esc_html( 'en' === $lang ? 'Tips for ' . $place : 'Lưu ý khi ở ' . $place ) . '</h2>';
		}
	}
	if ( $i > 5 && ! empty( $sseo_ids[ $lang ] ) ) {
		$target  = $sseo_ids[ $lang ][ mt_rand( 0, count( $sseo_ids[ $lang ] ) - 1 ) ];
		$paras[] = '<p><a href="' . esc_url( get_permalink( $target ) ) . '">' . esc_html( get_the_title( $target ) ) . '</a></p>';
	}
	$id = wp_insert_post(
		array(
			'post_type'     => 'post',
			'post_status'   => 'publish',
			'post_title'    => $title,
			'post_content'  => implode( "\n", $paras ),
			'post_author'   => 1,
			'post_date_gmt' => gmdate( 'Y-m-d H:i:s', strtotime( '2025-01-01 00:00:00 UTC' ) + $i * 600 ),
			'post_category' => array( $sseo_cats[ $lang ][ $tkey ] ),
		)
	);
	if ( $sseo_pll ) {
		pll_set_post_language( $id, $lang );
	}
	$sseo_ids[ $lang ][] = $id;
}
WP_CLI::log( sprintf( 'Generated %d SYNTHETIC BENCHMARK posts in %.1fs', $sseo_n, microtime( true ) - $sseo_t0 ) );
