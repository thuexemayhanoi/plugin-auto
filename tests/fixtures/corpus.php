<?php
/**
 * LABELLED TEST FIXTURE — NOT REAL SITE DATA.
 *
 * A small bilingual (VI/EN) corpus about motorbike rental and travel in Hanoi, used by
 * integration tests and by the relevance evaluation (tests/php/Integration/EvaluationTest.php).
 * Prices follow the shop's published reference table and are fixtures only.
 *
 * Fields: key, lang, type (post|page), cats (category keys), title, slug, group (translation group),
 * focus (SEO focus keyword, optional), status, content (HTML; {{url:key}} is replaced by the
 * permalink of that fixture).
 *
 * @package SemanticSEO
 */

return array(
	'categories' => array(
		array( 'key' => 'vi-thue', 'lang' => 'vi', 'name' => 'Thuê xe máy', 'slug' => 'thue-xe-may', 'parent' => '' ),
		array( 'key' => 'vi-gia', 'lang' => 'vi', 'name' => 'Bảng giá thuê xe', 'slug' => 'bang-gia-thue-xe', 'parent' => 'vi-thue' ),
		array( 'key' => 'vi-kinh-nghiem', 'lang' => 'vi', 'name' => 'Kinh nghiệm lái xe', 'slug' => 'kinh-nghiem-lai-xe', 'parent' => '' ),
		array( 'key' => 'vi-du-lich', 'lang' => 'vi', 'name' => 'Du lịch', 'slug' => 'du-lich', 'parent' => '' ),
		array( 'key' => 'en-rental', 'lang' => 'en', 'name' => 'Motorbike rental', 'slug' => 'motorbike-rental', 'parent' => '' ),
		array( 'key' => 'en-prices', 'lang' => 'en', 'name' => 'Rental prices', 'slug' => 'rental-prices', 'parent' => 'en-rental' ),
		array( 'key' => 'en-tips', 'lang' => 'en', 'name' => 'Riding tips', 'slug' => 'riding-tips', 'parent' => '' ),
		array( 'key' => 'en-travel', 'lang' => 'en', 'name' => 'Travel', 'slug' => 'travel', 'parent' => '' ),
	),
	'docs'       => array(
		array(
			'key'     => 'vi-pillar',
			'lang'    => 'vi',
			'type'    => 'post',
			'cats'    => array( 'vi-thue' ),
			'group'   => 'g-pillar',
			'focus'   => 'thuê xe máy Hà Nội',
			'title'   => 'Thuê xe máy ở Hà Nội: bảng giá, thủ tục và kinh nghiệm',
			'slug'    => 'thue-xe-may-ha-noi',
			'content' => '<!-- wp:paragraph --><p>Thuê xe máy Hà Nội là cách di chuyển linh hoạt cho du khách và người mới chuyển đến thành phố. Bài viết này tổng hợp bảng giá thuê xe, thủ tục giấy tờ, tiền đặt cọc và kinh nghiệm chọn xe phù hợp.</p><!-- /wp:paragraph -->
<h2>Bảng giá thuê xe máy tham khảo</h2>
<p>Xe số Honda Wave có giá thuê khoảng 150.000đ mỗi ngày, xe tay ga Honda Vision khoảng 200.000đ mỗi ngày. Nếu bạn thuê dài hạn, hãy xem <a href="{{url:vi-ngay}}">thuê xe máy theo ngày</a> và <a href="{{url:vi-thang}}">thuê xe máy theo tháng</a> để có giá tốt hơn.</p>
<h2>Thủ tục và tiền đặt cọc</h2>
<p>Khi thuê xe máy, bạn cần chuẩn bị giấy tờ tùy thân và tiền đặt cọc từ 2 triệu đến 5 triệu tùy loại xe. Hợp đồng thuê ghi rõ thời hạn, tình trạng xe và điều kiện hoàn cọc.</p>
<h2>Kinh nghiệm chọn xe</h2>
<p>Người mới nên chọn xe số nhẹ hoặc xe tay ga 50cc. Hãy kiểm tra phanh, đèn, lốp và mức xăng trước khi nhận xe. Đội mũ bảo hiểm và tuân thủ luật giao thông khi đi trong phố cổ.</p>',
		),
		array(
			'key'     => 'vi-ngay',
			'lang'    => 'vi',
			'type'    => 'post',
			'cats'    => array( 'vi-gia' ),
			'group'   => 'g-ngay',
			'title'   => 'Thuê xe máy theo ngày ở Hà Nội',
			'slug'    => 'thue-xe-may-theo-ngay',
			'content' => '<p>Thuê xe máy theo ngày phù hợp với khách du lịch ngắn ngày cần di chuyển quanh Hà Nội. Giá thuê xe số khoảng 150.000đ một ngày, xe tay ga khoảng 200.000đ một ngày, đã bao gồm mũ bảo hiểm.</p>
<h2>Cách tính ngày thuê</h2>
<p>Một ngày thuê được tính 24 giờ kể từ lúc nhận xe. Nếu trả xe muộn vài giờ, cửa hàng có thể tính thêm phí theo giờ. Bạn nên báo trước nếu muốn gia hạn để giữ xe.</p>
<h2>Giấy tờ khi thuê xe theo ngày</h2>
<p>Bạn cần giấy tờ tùy thân và tiền cọc theo loại xe. Kiểm tra kỹ tình trạng xe, chụp ảnh các vết xước có sẵn trước khi nhận. Xem thêm hướng dẫn <a href="{{url:vi-pillar}}">thuê xe máy ở Hà Nội</a> để hiểu đầy đủ thủ tục.</p>
<p>Nếu ở lại lâu hơn một tuần, gói thuê dài ngày thường tiết kiệm hơn so với thuê lẻ từng ngày.</p>',
		),
		array(
			'key'     => 'vi-thang',
			'lang'    => 'vi',
			'type'    => 'post',
			'cats'    => array( 'vi-gia' ),
			'group'   => 'g-thang',
			'title'   => 'Thuê xe máy theo tháng ở Hà Nội',
			'slug'    => 'thue-xe-may-theo-thang',
			'content' => '<p>Thuê xe máy theo tháng dành cho sinh viên, người đi làm và khách lưu trú dài hạn tại Hà Nội. Giá thuê tháng của xe số Honda Wave khoảng 900.000đ đến 1.200.000đ, xe tay ga Honda Vision khoảng 1.800.000đ đến 2.000.000đ.</p>
<h2>Ưu điểm của thuê theo tháng</h2>
<p>Chi phí trung bình mỗi ngày thấp hơn nhiều so với thuê lẻ. Bạn được hỗ trợ bảo dưỡng định kỳ, thay dầu và kiểm tra phanh trong suốt thời gian thuê.</p>
<h2>Hợp đồng và đặt cọc dài hạn</h2>
<p>Hợp đồng thuê tháng ghi rõ thời hạn, tiền cọc và trách nhiệm khi xe hỏng. Tiền cọc được hoàn lại khi trả xe đúng tình trạng. Đọc thêm về <a href="{{url:vi-thu-tuc}}">thủ tục thuê xe máy và tiền đặt cọc</a>.</p>
<p>Hãy giữ liên lạc với cửa hàng để được đổi xe nhanh khi xe gặp sự cố trên đường.</p>',
		),
		array(
			'key'     => 'vi-50cc-gia',
			'lang'    => 'vi',
			'type'    => 'post',
			'cats'    => array( 'vi-gia' ),
			'group'   => 'g-50cc-gia',
			'title'   => 'Giá thuê xe máy 50cc tại Hà Nội',
			'slug'    => 'gia-thue-xe-may-50cc',
			'content' => '<p>Xe tay ga 50cc là lựa chọn của học sinh và người chưa có bằng lái A1. Giá thuê xe 50cc khoảng 200.000đ mỗi ngày, tiền cọc khoảng 4 triệu, giá tuần và tháng được báo riêng.</p>
<h2>Những mẫu xe 50cc phổ biến</h2>
<p>Các mẫu xe tay ga 50cc nhỏ gọn, tiết kiệm xăng và dễ điều khiển trong phố đông. Xe phù hợp quãng đường ngắn trong nội thành.</p>
<h2>Lưu ý khi thuê xe 50cc</h2>
<p>Hãy kiểm tra tình trạng xe trước khi nhận và đọc kỹ điều khoản đặt cọc. Muốn hiểu quy định về độ tuổi và giấy phép, xem bài về bằng lái xe 50cc.</p>',
		),
		array(
			'key'     => 'vi-50cc-bang-lai',
			'lang'    => 'vi',
			'type'    => 'post',
			'cats'    => array( 'vi-kinh-nghiem' ),
			'group'   => 'g-50cc-bang',
			'title'   => 'Bằng lái xe 50cc: quy định và giấy phép cho học sinh',
			'slug'    => 'bang-lai-xe-50cc-quy-dinh',
			'content' => '<p>Theo quy định hiện hành, người từ đủ 16 tuổi được điều khiển xe gắn máy dưới 50cc. Bài viết giải thích quy định về độ tuổi, giấy phép và những lưu ý an toàn khi học sinh đi xe 50cc.</p>
<h2>Có cần bằng lái khi đi xe 50cc không</h2>
<p>Quy định về giấy phép lái xe cho xe dưới 50cc khác với xe máy phân khối lớn hơn. Học sinh cần mang theo giấy tờ tùy thân, giấy đăng ký xe và đội mũ bảo hiểm đạt chuẩn.</p>
<h2>Lưu ý an toàn cho người mới lái</h2>
<p>Người mới nên tập lái ở nơi vắng, giữ tốc độ chậm và quan sát gương thường xuyên. Tham khảo thêm <a href="{{url:vi-an-toan}}">mẹo lái xe máy an toàn</a> trong phố cổ.</p>
<p>Luật giao thông có thể thay đổi; hãy kiểm tra văn bản pháp luật mới nhất trước khi tham gia giao thông.</p>',
		),
		array(
			'key'     => 'vi-tay-ga',
			'lang'    => 'vi',
			'type'    => 'post',
			'cats'    => array( 'vi-thue' ),
			'group'   => 'g-tay-ga',
			'title'   => 'Thuê xe tay ga Honda Vision ở Hà Nội',
			'slug'    => 'thue-xe-tay-ga-honda-vision',
			'content' => '<p>Honda Vision là mẫu xe tay ga được nhiều khách chọn khi thuê xe ở Hà Nội vì nhẹ, tiết kiệm xăng và cốp rộng. Giá thuê Honda Vision khoảng 200.000đ mỗi ngày hoặc 1.000.000đ mỗi tuần.</p>
<h2>Vì sao chọn xe tay ga</h2>
<p>Xe tay ga dễ lái trong phố đông, không cần sang số và phù hợp với người mới. Bạn có thể chở thêm hành lý nhỏ trong cốp xe.</p>
<h2>Tiền cọc khi thuê Honda Vision</h2>
<p>Tiền đặt cọc cho Honda Vision khoảng 4 đến 5 triệu tùy thời hạn thuê. Mọi điều khoản được xác nhận trong hợp đồng trước khi nhận xe.</p>',
		),
		array(
			'key'     => 'vi-thu-tuc',
			'lang'    => 'vi',
			'type'    => 'post',
			'cats'    => array( 'vi-thue' ),
			'group'   => 'g-thu-tuc',
			'title'   => 'Thủ tục thuê xe máy: giấy tờ và tiền đặt cọc',
			'slug'    => 'thu-tuc-thue-xe-may',
			'content' => '<p>Thủ tục thuê xe máy gồm xuất trình giấy tờ tùy thân, ký hợp đồng và đặt cọc. Tiền đặt cọc xe máy từ 2 triệu đến 5 triệu tùy loại xe và được hoàn trả theo hợp đồng.</p>
<h2>Giấy tờ cần chuẩn bị</h2>
<p>Bạn cần căn cước hoặc hộ chiếu còn hiệu lực. Một số phương án giấy tờ bảo đảm khác có thể được trao đổi riêng theo thỏa thuận hợp pháp.</p>
<h2>Quy trình hoàn cọc</h2>
<p>Khi trả xe, nhân viên kiểm tra tình trạng xe, mức xăng và phụ kiện. Tiền cọc được hoàn sau khi bạn hoàn thành các nghĩa vụ trong hợp đồng thuê xe máy.</p>',
		),
		array(
			'key'     => 'vi-ha-giang',
			'lang'    => 'vi',
			'type'    => 'post',
			'cats'    => array( 'vi-du-lich' ),
			'group'   => 'g-ha-giang',
			'title'   => 'Kinh nghiệm du lịch Hà Giang bằng xe máy',
			'slug'    => 'kinh-nghiem-du-lich-ha-giang',
			'content' => '<p>Cung đường Hà Giang nổi tiếng với đèo Mã Pí Lèng, sông Nho Quế và những bản làng vùng cao. Du lịch Hà Giang bằng xe máy giúp bạn chủ động dừng chân ngắm cảnh.</p>
<h2>Lịch trình gợi ý ba ngày</h2>
<p>Ngày đầu đi từ thành phố Hà Giang lên Quản Bạ và Yên Minh. Ngày thứ hai đến Đồng Văn, thăm phố cổ và đèo Mã Pí Lèng. Ngày thứ ba về Mèo Vạc rồi quay lại thành phố.</p>
<h2>Chuẩn bị trước chuyến đi</h2>
<p>Mang áo ấm, áo mưa và bộ sơ cứu. Kiểm tra phanh và lốp xe kỹ vì đường đèo dốc và nhiều khúc cua. Hãy đi chậm khi trời có sương mù.</p>',
		),
		array(
			'key'     => 'vi-ninh-binh',
			'lang'    => 'vi',
			'type'    => 'post',
			'cats'    => array( 'vi-du-lich' ),
			'group'   => 'g-ninh-binh',
			'title'   => 'Du lịch Ninh Bình tự túc hai ngày một đêm',
			'slug'    => 'du-lich-ninh-binh-tu-tuc',
			'content' => '<p>Ninh Bình cách Hà Nội khoảng hai giờ đi xe, nổi tiếng với Tràng An, Tam Cốc và chùa Bái Đính. Du lịch Ninh Bình tự túc rất phù hợp cho cuối tuần.</p>
<h2>Lịch trình hai ngày</h2>
<p>Ngày đầu đi thuyền ở Tràng An và leo Hang Múa ngắm hoàng hôn. Ngày thứ hai thăm Tam Cốc, Bích Động và chùa Bái Đính trước khi về Hà Nội.</p>
<h2>Di chuyển và ăn uống</h2>
<p>Bạn có thể đi xe khách hoặc xe máy. Đặc sản nên thử gồm cơm cháy và thịt dê núi. Hãy xem thêm <a href="{{url:vi-ha-giang}}">kinh nghiệm du lịch Hà Giang</a> nếu muốn đi xa hơn.</p>',
		),
		array(
			'key'     => 'vi-du-lich-viet-nam',
			'lang'    => 'vi',
			'type'    => 'post',
			'cats'    => array( 'vi-du-lich' ),
			'group'   => 'g-vn',
			'title'   => 'Du lịch Việt Nam: lịch trình gợi ý cho người đi lần đầu',
			'slug'    => 'du-lich-viet-nam-lich-trinh',
			'content' => '<p>Việt Nam trải dài từ Bắc vào Nam với nhiều điểm đến hấp dẫn như Hà Nội, Hạ Long, Huế, Hội An và Thành phố Hồ Chí Minh. Bài viết gợi ý lịch trình du lịch Việt Nam mười ngày cho người đi lần đầu.</p>
<h2>Miền Bắc</h2>
<p>Dành ba ngày khám phá phố cổ Hà Nội, vịnh Hạ Long và Ninh Bình. Mùa thu là thời điểm đẹp để đi du lịch miền Bắc.</p>
<h2>Miền Trung và miền Nam</h2>
<p>Bay vào Huế thăm kinh thành, sau đó đến Hội An và Đà Nẵng. Kết thúc chuyến đi ở Thành phố Hồ Chí Minh và đồng bằng sông Cửu Long.</p>',
		),
		array(
			'key'     => 'vi-xe-dien',
			'lang'    => 'vi',
			'type'    => 'post',
			'cats'    => array( 'vi-thue' ),
			'group'   => 'g-xe-dien',
			'title'   => 'Thuê xe máy điện và xe đạp điện ở Hà Nội',
			'slug'    => 'thue-xe-may-dien',
			'content' => '<p>Xe máy điện và xe đạp điện là lựa chọn yên tĩnh, không tốn xăng cho quãng đường ngắn trong nội thành Hà Nội. Giá thuê xe điện được báo giá theo từng mẫu xe, vui lòng liên hệ để xác nhận.</p>
<h2>Khi nào nên thuê xe điện</h2>
<p>Xe điện phù hợp đi lại quanh hồ Hoàn Kiếm, phố cổ hoặc khu đô thị. Bạn cần chú ý quãng đường mỗi lần sạc và vị trí sạc pin.</p>
<h2>Đặt cọc khi thuê xe điện</h2>
<p>Mức cọc được xác nhận theo mẫu xe trước khi ký hợp đồng thuê.</p>',
		),
		array(
			'key'     => 'vi-gia-re',
			'lang'    => 'vi',
			'type'    => 'post',
			'cats'    => array( 'vi-thue' ),
			'group'   => 'g-gia-re',
			'title'   => 'Thuê xe máy Hà Nội giá rẻ',
			'slug'    => 'thue-xe-may-ha-noi-gia-re',
			'content' => '<p>Bạn đang tìm nơi thuê xe máy Hà Nội giá rẻ? Bài viết tổng hợp bảng giá thuê xe máy ở Hà Nội, thủ tục giấy tờ và tiền đặt cọc để bạn so sánh trước khi thuê.</p>
<h2>Bảng giá thuê xe máy Hà Nội</h2>
<p>Xe số Honda Wave khoảng 150.000đ một ngày, xe tay ga Honda Vision khoảng 200.000đ một ngày, Honda Air Blade khoảng 200.000đ một ngày. Thuê theo tuần hoặc tháng sẽ rẻ hơn.</p>
<h2>Thủ tục thuê xe máy giá rẻ</h2>
<p>Chuẩn bị giấy tờ tùy thân và tiền cọc từ 2 triệu đến 5 triệu. Kiểm tra xe trước khi nhận và giữ hợp đồng thuê cẩn thận.</p>',
		),
		array(
			'key'     => 'vi-an-toan',
			'lang'    => 'vi',
			'type'    => 'post',
			'cats'    => array( 'vi-kinh-nghiem' ),
			'group'   => 'g-an-toan',
			'title'   => 'Mẹo lái xe máy an toàn trong phố cổ Hà Nội',
			'slug'    => 'meo-lai-xe-may-an-toan',
			'content' => '<p>Phố cổ Hà Nội có nhiều ngõ nhỏ, người đi bộ và xe đạp. Lái xe an toàn trong phố cổ đòi hỏi tốc độ chậm, quan sát liên tục và nhường đường đúng luật.</p>
<h2>Quy tắc cơ bản</h2>
<p>Luôn đội mũ bảo hiểm, bật đèn khi trời tối và giữ khoảng cách với xe phía trước. Không sử dụng điện thoại khi đang lái xe.</p>
<h2>Đỗ xe và giữ xe</h2>
<p>Gửi xe ở bãi có vé, khóa cổ xe và không để giấy tờ trong cốp. Hỏi giá gửi xe trước để tránh bị tính phí cao.</p>',
		),
		array(
			'key'     => 'vi-ngay-gia-tot',
			'lang'    => 'vi',
			'type'    => 'post',
			'cats'    => array( 'vi-gia' ),
			'group'   => 'g-ngay-dup',
			'title'   => 'Thuê xe máy theo ngày tại Hà Nội giá tốt',
			'slug'    => 'thue-xe-may-theo-ngay-gia-tot',
			'content' => '<p>Thuê xe máy theo ngày phù hợp với khách du lịch ngắn ngày cần di chuyển quanh Hà Nội. Giá thuê xe số khoảng 150.000đ một ngày, xe tay ga khoảng 200.000đ một ngày, đã bao gồm mũ bảo hiểm.</p>
<h2>Cách tính ngày thuê</h2>
<p>Một ngày thuê được tính 24 giờ kể từ lúc nhận xe. Nếu trả xe muộn vài giờ, cửa hàng có thể tính thêm phí theo giờ. Bạn nên báo trước nếu muốn gia hạn để giữ xe.</p>
<h2>Giấy tờ khi thuê xe theo ngày</h2>
<p>Bạn cần giấy tờ tùy thân và tiền cọc theo loại xe. Kiểm tra kỹ tình trạng xe, chụp ảnh các vết xước có sẵn trước khi nhận.</p>
<p>Nếu ở lại lâu hơn một tuần, gói thuê dài ngày thường tiết kiệm hơn so với thuê lẻ từng ngày.</p>',
		),
		array(
			'key'     => 'vi-sapa',
			'lang'    => 'vi',
			'type'    => 'post',
			'cats'    => array( 'vi-du-lich' ),
			'group'   => 'g-sapa',
			'title'   => 'Kinh nghiệm thuê xe máy đi Sapa',
			'slug'    => 'kinh-nghiem-thue-xe-may-di-sapa',
			'content' => '<p>Sapa có đường đèo Ô Quy Hồ và nhiều bản làng đẹp. Thuê xe máy đi Sapa giúp bạn tự do khám phá Cát Cát, Tả Van và Fansipan theo nhịp riêng.</p>
<h2>Chọn xe cho đường đèo</h2>
<p>Nên chọn xe số có phanh tốt và lốp còn mới. Kiểm tra kỹ xe trước khi lên đèo và mang theo áo mưa.</p>
<h2>Thời tiết Sapa</h2>
<p>Sapa lạnh vào mùa đông và có sương mù buổi sáng. Hãy lái chậm, bật đèn và giữ khoảng cách an toàn trên đường đèo.</p>',
		),
		array(
			'key'     => 'vi-lien-he',
			'lang'    => 'vi',
			'type'    => 'page',
			'cats'    => array(),
			'group'   => 'g-contact',
			'title'   => 'Liên hệ',
			'slug'    => 'lien-he',
			'content' => '<p>Liên hệ với chúng tôi qua điện thoại hoặc Zalo để được tư vấn thuê xe. Giờ hoạt động từ 09:00 đến 21:00 tất cả các ngày trong tuần.</p><p>Vui lòng xác nhận giá thuê, tiền cọc và tình trạng xe trước khi ký hợp đồng.</p>',
		),
		array(
			'key'     => 'vi-broken',
			'lang'    => 'vi',
			'type'    => 'post',
			'cats'    => array( 'vi-kinh-nghiem' ),
			'group'   => 'g-xang',
			'title'   => 'Cách đổ xăng và bảo quản xe máy khi đi phượt',
			'slug'    => 'cach-do-xang-bao-quan-xe',
			'content' => '<h1>Cách đổ xăng đúng cách</h1><p>Khi đi phượt đường dài, bạn nên đổ đầy xăng trước khi vào cung đường đèo và ghi nhớ các trạm xăng dọc đường. Xem thêm <a href="{{url:vi-trashed}}">bài đã bị xóa</a> và <a href="/khong-ton-tai-trang-nay/">một trang không tồn tại</a>.</p><h3>Bảo quản xe sau chuyến đi</h3><p>Rửa xe, kiểm tra xích, nhớt và lốp sau mỗi chuyến đi dài. Xe được bảo dưỡng tốt sẽ an toàn hơn cho chuyến sau.</p><img src="/xe.jpg">',
		),
		array(
			'key'     => 'vi-trashed',
			'lang'    => 'vi',
			'type'    => 'post',
			'cats'    => array( 'vi-kinh-nghiem' ),
			'group'   => 'g-trashed',
			'status'  => 'trash',
			'title'   => 'Bài viết cũ đã bị xóa',
			'slug'    => 'bai-viet-cu',
			'content' => '<p>Nội dung cũ.</p>',
		),
		array(
			'key'     => 'vi-draft',
			'lang'    => 'vi',
			'type'    => 'post',
			'cats'    => array( 'vi-thue' ),
			'group'   => 'g-draft',
			'status'  => 'draft',
			'title'   => 'Bản nháp: bảng giá thuê xe năm sau',
			'slug'    => 'ban-nhap-bang-gia',
			'content' => '<p>Nội dung nháp chưa công bố, không được xuất hiện trong kết quả công khai.</p>',
		),
		array(
			'key'     => 'en-pillar',
			'lang'    => 'en',
			'type'    => 'post',
			'cats'    => array( 'en-rental' ),
			'group'   => 'g-pillar',
			'focus'   => 'motorbike rental Hanoi',
			'title'   => 'Motorbike rental in Hanoi: prices, paperwork and tips',
			'slug'    => 'motorbike-rental-hanoi',
			'content' => '<p>Motorbike rental in Hanoi is the most flexible way for travelers and newcomers to get around the city. This guide covers rental prices, required documents, the deposit and practical tips for choosing a bike.</p>
<h2>Reference rental prices</h2>
<p>A Honda Wave manual bike costs about 150,000 VND per day and a Honda Vision scooter about 200,000 VND per day. For longer stays, see <a href="{{url:en-daily}}">daily motorbike rental</a> and <a href="{{url:en-monthly}}">monthly motorbike rental</a>.</p>
<h2>Documents and deposit</h2>
<p>You need a valid ID or passport and a deposit between 2 and 5 million VND depending on the bike. The rental contract states the period, the bike condition and the deposit refund terms.</p>
<h2>Choosing a bike</h2>
<p>Beginners should pick a light manual bike or a 50cc scooter. Check the brakes, lights, tyres and fuel before you ride, and always wear a helmet in the Old Quarter.</p>',
		),
		array(
			'key'     => 'en-daily',
			'lang'    => 'en',
			'type'    => 'post',
			'cats'    => array( 'en-prices' ),
			'group'   => 'g-ngay',
			'title'   => 'Daily motorbike rental in Hanoi',
			'slug'    => 'daily-motorbike-rental-hanoi',
			'content' => '<p>Daily motorbike rental suits short trips around Hanoi. A manual bike costs about 150,000 VND per day and a scooter about 200,000 VND per day, helmet included.</p>
<h2>How a rental day is counted</h2>
<p>One rental day is 24 hours from pick-up. Late returns may be charged by the hour, so tell the shop in advance if you want to extend.</p>
<h2>Documents for a daily rental</h2>
<p>Bring your ID and the deposit for the bike type. Inspect the bike and photograph existing scratches before riding away. Read the full <a href="{{url:en-pillar}}">motorbike rental in Hanoi guide</a> for details.</p>',
		),
		array(
			'key'     => 'en-monthly',
			'lang'    => 'en',
			'type'    => 'post',
			'cats'    => array( 'en-prices' ),
			'group'   => 'g-thang',
			'title'   => 'Monthly motorbike rental in Hanoi',
			'slug'    => 'monthly-motorbike-rental-hanoi',
			'content' => '<p>Monthly motorbike rental is for students, expats and long-stay visitors in Hanoi. A Honda Wave costs about 900,000 to 1,200,000 VND per month and a Honda Vision about 1,800,000 to 2,000,000 VND per month.</p>
<h2>Why rent by the month</h2>
<p>The average daily cost is much lower than renting day by day, and regular maintenance such as oil changes and brake checks is included during the rental.</p>
<h2>Contract and deposit</h2>
<p>The monthly contract lists the period, the deposit and who pays for breakdowns. The deposit is refunded when the bike is returned in the agreed condition.</p>',
		),
		array(
			'key'     => 'en-50cc-license',
			'lang'    => 'en',
			'type'    => 'post',
			'cats'    => array( 'en-tips' ),
			'group'   => 'g-50cc-bang',
			'title'   => '50cc motorbike license rules in Vietnam',
			'slug'    => '50cc-motorbike-license-rules',
			'content' => '<p>Under current rules, people aged 16 and over may ride mopeds under 50cc. This article explains the age rules, licence requirements and safety tips for students riding 50cc bikes.</p>
<h2>Do you need a license for a 50cc bike</h2>
<p>Licence rules for bikes under 50cc differ from larger motorbikes. Riders should carry ID, the vehicle registration and an approved helmet.</p>
<h2>Safety for new riders</h2>
<p>Practise in quiet streets, keep speeds low and check mirrors often. Traffic law can change, so always check the latest regulations.</p>',
		),
		array(
			'key'     => 'en-ha-giang',
			'lang'    => 'en',
			'type'    => 'post',
			'cats'    => array( 'en-travel' ),
			'group'   => 'g-ha-giang',
			'title'   => 'Ha Giang loop by motorbike: travel guide',
			'slug'    => 'ha-giang-loop-motorbike-guide',
			'content' => '<p>The Ha Giang loop is famous for the Ma Pi Leng pass, the Nho Que river and mountain villages. Riding the loop by motorbike lets you stop wherever the view is best.</p>
<h2>Three-day itinerary</h2>
<p>Day one goes from Ha Giang city to Quan Ba and Yen Minh. Day two reaches Dong Van and the Ma Pi Leng pass. Day three returns via Meo Vac.</p>
<h2>What to prepare</h2>
<p>Pack warm clothes, a raincoat and a first-aid kit, and check brakes and tyres because the passes are steep and winding.</p>',
		),
		array(
			'key'     => 'en-vietnam-travel',
			'lang'    => 'en',
			'type'    => 'post',
			'cats'    => array( 'en-travel' ),
			'group'   => 'g-vn',
			'title'   => 'Vietnam travel itinerary for first-timers',
			'slug'    => 'vietnam-travel-itinerary',
			'content' => '<p>Vietnam stretches from north to south with highlights such as Hanoi, Ha Long Bay, Hue, Hoi An and Ho Chi Minh City. This ten-day itinerary is designed for first-time visitors.</p>
<h2>The north</h2>
<p>Spend three days in Hanoi\'s Old Quarter, Ha Long Bay and Ninh Binh. Autumn is a lovely season to travel in the north.</p>
<h2>The centre and the south</h2>
<p>Fly to Hue for the citadel, continue to Hoi An and Da Nang, and finish in Ho Chi Minh City and the Mekong Delta.</p>',
		),
		array(
			'key'     => 'en-cheap',
			'lang'    => 'en',
			'type'    => 'post',
			'cats'    => array( 'en-rental' ),
			'group'   => 'g-gia-re',
			'title'   => 'Cheap motorbike rental in Hanoi',
			'slug'    => 'cheap-motorbike-rental-hanoi',
			'content' => '<p>Looking for cheap motorbike rental in Hanoi? This page lists motorbike rental prices in Hanoi, the paperwork and the deposit so you can compare before renting.</p>
<h2>Hanoi motorbike rental prices</h2>
<p>A Honda Wave is about 150,000 VND per day, a Honda Vision about 200,000 VND per day and a Honda Air Blade about 200,000 VND per day. Weekly and monthly rentals are cheaper.</p>
<h2>Paperwork for a cheap rental</h2>
<p>Bring ID and a deposit of 2 to 5 million VND. Inspect the bike before riding and keep the rental contract safe.</p>',
		),
		array(
			'key'     => 'en-safe-riding',
			'lang'    => 'en',
			'type'    => 'post',
			'cats'    => array( 'en-tips' ),
			'group'   => 'g-an-toan',
			'title'   => 'Safe riding tips for Hanoi\'s Old Quarter',
			'slug'    => 'safe-riding-tips-old-quarter',
			'content' => '<p>Hanoi\'s Old Quarter has narrow lanes, pedestrians and bicycles everywhere. Riding safely there means low speed, constant attention and yielding correctly.</p>
<h2>Basic rules</h2>
<p>Always wear a helmet, switch on the lights after dark and keep your distance from the vehicle in front. Never use a phone while riding.</p>
<h2>Parking</h2>
<p>Use ticketed parking, lock the steering and do not leave documents under the seat.</p>',
		),
		array(
			'key'     => 'en-contact',
			'lang'    => 'en',
			'type'    => 'page',
			'cats'    => array(),
			'group'   => 'g-contact',
			'title'   => 'Contact',
			'slug'    => 'contact',
			'content' => '<p>Contact us by phone or Zalo for rental advice. Opening hours are 09:00 to 21:00 every day.</p><p>Please confirm the price, deposit and bike condition before signing the contract.</p>',
		),
	),
	/*
	 * Evaluation labels (human-judged for this fixture).
	 * relevant: source key => keys that are genuinely useful link/related targets (same locale).
	 */
	'labels'     => array(
		'relevant' => array(
			'vi-ngay'          => array( 'vi-pillar', 'vi-thang', 'vi-thu-tuc', 'vi-tay-ga', 'vi-gia-re' ),
			'vi-thang'         => array( 'vi-pillar', 'vi-ngay', 'vi-thu-tuc', 'vi-tay-ga' ),
			'vi-50cc-gia'      => array( 'vi-pillar', 'vi-50cc-bang-lai', 'vi-tay-ga', 'vi-ngay' ),
			'vi-50cc-bang-lai' => array( 'vi-50cc-gia', 'vi-an-toan' ),
			'vi-tay-ga'        => array( 'vi-pillar', 'vi-ngay', 'vi-thang', 'vi-50cc-gia', 'vi-thu-tuc' ),
			'vi-thu-tuc'       => array( 'vi-pillar', 'vi-thang', 'vi-ngay', 'vi-tay-ga' ),
			'vi-ha-giang'      => array( 'vi-ninh-binh', 'vi-sapa', 'vi-du-lich-viet-nam' ),
			'vi-ninh-binh'     => array( 'vi-ha-giang', 'vi-du-lich-viet-nam', 'vi-sapa' ),
			'vi-sapa'          => array( 'vi-ha-giang', 'vi-ninh-binh', 'vi-pillar' ),
			'vi-an-toan'       => array( 'vi-50cc-bang-lai', 'vi-broken' ),
			'vi-xe-dien'       => array( 'vi-pillar', 'vi-thu-tuc', 'vi-tay-ga' ),
			'en-daily'         => array( 'en-pillar', 'en-monthly', 'en-cheap' ),
			'en-monthly'       => array( 'en-pillar', 'en-daily' ),
			'en-50cc-license'  => array( 'en-safe-riding' ),
			'en-ha-giang'      => array( 'en-vietnam-travel' ),
			'en-safe-riding'   => array( 'en-50cc-license' ),
		),
		// Pairs that must NOT be reported as potential_competition / duplicates.
		'not_competition' => array(
			array( 'vi-du-lich-viet-nam', 'vi-thang' ),
			array( 'vi-du-lich-viet-nam', 'vi-pillar' ),
			array( 'vi-50cc-gia', 'vi-50cc-bang-lai' ),
			array( 'vi-ngay', 'vi-thang' ),
			array( 'en-daily', 'en-monthly' ),
			array( 'vi-pillar', 'en-pillar' ),
			array( 'vi-ngay', 'en-daily' ),
		),
		// Pairs that SHOULD be flagged (category must match one of the listed ones).
		'expected_overlap' => array(
			array( 'vi-ngay', 'vi-ngay-gia-tot', array( 'near_duplicate', 'exact_duplicate' ) ),
			array( 'vi-pillar', 'vi-gia-re', array( 'potential_competition' ) ),
			array( 'en-pillar', 'en-cheap', array( 'potential_competition' ) ),
		),
	),
);
