# Hướng dẫn sử dụng — Semantic SEO Engine VI–EN (bản 1.0.0-beta.4)

Tài liệu dành cho quản trị viên/biên tập viên website. Bản tiếng Anh: [USER-GUIDE-EN.md](USER-GUIDE-EN.md).

## 0. Plugin làm gì — và không làm gì

* **Làm:** kiểm kê URL, phân tích liên kết nội bộ, rủi ro ăn thịt từ khóa (cùng ngôn ngữ), cụm Pillar–Hub–Silo, kiểm tra chất lượng nội dung, bài viết liên quan — kèm bằng chứng rõ ràng cho từng kết luận.
* **Không làm:** không tự sửa nội dung, metadata SEO, URL, canonical, noindex, redirect hay theme. Mọi thay đổi đều là **đề xuất** có diff chính xác, phải được **con người phê duyệt** trong WP Admin, và có thể **hoàn tác**.
* **Không** chatbot, **không** gọi API AI trả phí, **không** tải mô hình cho khách truy cập.
* Kết quả là **gợi ý/giả thuyết**, không phải cam kết thứ hạng. "Ăn thịt từ khóa" chỉ được xác nhận khi có dữ liệu Search Console thật do bạn nhập.

## 1. Cài đặt nhanh

1. Sao lưu website (tệp + cơ sở dữ liệu). Nên thử trên bản staging trước.
2. *Plugins → Cài mới → Tải plugin lên* → chọn `semantic-seo-engine-vi-en-1.0.0-beta.4.zip` → *Cài đặt* → *Kích hoạt*.
3. Kích hoạt **chỉ tạo bảng dữ liệu riêng của plugin** (`{prefix}sseo_*`); nội dung, SEO, URL, giao diện ngoài website giữ nguyên (đã kiểm chứng byte-by-byte trên WordPress 6.5.13, 6.9.10, 7.1.3).

Chi tiết: [INSTALLATION.md](INSTALLATION.md).

## 2. Bảng điều khiển SEO — cài xong là dùng được

Menu **Semantic SEO → Bảng điều khiển SEO**. Không cần đọc các trang kỹ thuật khác để bắt đầu.

**Banner trạng thái** ở đầu trang cho biết plugin đang *hoạt động bình thường*, *cần chú ý* hay *có lỗi*.
Nếu database của plugin chưa đầy đủ (ví dụ thiếu bảng), banner hiện số bảng đang có/cần có, lỗi SQL
(mã lỗi, SQLSTATE) và nút **Sửa database**. Nút này chỉ tạo bảng/cột còn thiếu, không xóa dữ liệu.
Khi database chưa đầy đủ, plugin không cho chạy phân tích.

**Bắt đầu nhanh trong 3 bước:**

1. **Kiểm tra hệ thống** — database, quyền WordPress của bạn, plugin SEO (ví dụ Yoast SEO, chỉ đọc),
   plugin đa ngôn ngữ (ví dụ Nguyen Ha VI–EN Core), cache (ví dụ LiteSpeed Cache), bộ nhớ và thời gian
   tối đa của PHP, WP-Cron, mô hình AI, chế độ chỉ đọc. Mỗi mục có giải thích ngắn.
2. **Phân tích thử 20 bài** — phân tích 20 bài cập nhật gần nhất ở chế độ chỉ đọc. Khi xong, trang
   hiển thị kết quả thật (số vấn đề theo mức ưu tiên), thời gian xử lý, bộ nhớ đỉnh, và dòng
   **"Đã xác minh chỉ đọc"**: plugin lấy dấu vân tay của bài viết, metadata và chuyên mục trước khi quét
   rồi so lại sau khi quét. Nếu có bài thay đổi trong lúc quét (do có người sửa, plugin khác hoặc bài
   hẹn giờ), trang sẽ nêu ID bài đó.
3. **Phân tích toàn website** — trang hiển thị trước số bài/trang/chuyên mục sẽ phân tích (đếm thật
   trong database) và **thời gian xử lý ước tính dựa trên số đo của lần chạy thử** (là mức tối thiểu:
   website càng lớn thì mỗi trang được so với càng nhiều trang). Tác vụ chạy nền theo lô nhỏ; có nút
   **Tạm dừng**, **Tiếp tục**, **Hủy**. Khi trang bảng điều khiển đang mở, chính trang sẽ đẩy tác vụ
   chạy tiếp (không phải chờ WP-Cron); đóng trang thì WP-Cron tiếp tục. Lỗi (nếu có) hiện ngay trên
   trang kèm nút chạy lại.

Không cần mô hình AI để phân tích: phần phân tích từ vựng chạy được trên mọi hosting WordPress.

**Sức khỏe SEO** (sau lần quét đầu tiên), mỗi thẻ có giải thích ngắn và liên kết "Xem chi tiết":

| Thẻ | Ý nghĩa |
| --- | --- |
| Số trang đã phân tích | Số trang đã xuất bản được đọc, chia theo ngôn ngữ (VI/EN). |
| Vấn đề theo mức ưu tiên | *Làm ngay / Nên làm sớm / Nên làm / Khi có thời gian*, và theo loại: lỗi đã xác minh, cảnh báo cần xem, gợi ý biên tập. |
| Bài chưa có liên kết nội bộ trỏ tới | Chỉ tính liên kết trong nội dung bài và mô tả chuyên mục (menu, widget, template không được quan sát). |
| Bài có nguy cơ trùng từ khóa / ý định | Giả thuyết rủi ro cần người xem (kiểm tra Search Console trước khi xử lý). Plugin không bao giờ gộp, xóa, chuyển hướng hay đổi slug. |
| Bài cần cải thiện chất lượng | Ngắn so với ý định tìm kiếm, thiếu tiêu đề phụ, ảnh thiếu alt, thiếu nguồn… |
| Lỗi kỹ thuật đã xác minh | Ví dụ liên kết nội bộ tới bài đã xóa: nên sửa trước. |

Plugin **không có điểm SEO tổng** và **không dự đoán thứ hạng Google**.

Bên dưới là **"Việc nên làm trước"** (10 mục đầu), **"So với lần quét trước"** (số phát hiện mới / đã hết),
thẻ **mô hình AI Qwen3 (tùy chọn)** với nút **Kiểm tra thiết bị này** (chỉ đọc khả năng trình duyệt,
không tải gì về), và danh sách **plugin được phép làm gì / không bao giờ tự làm gì**.
Phạm vi phân tích (loại nội dung, URL loại trừ, kích thước lô, số tài liệu tối đa) nằm trong mục
**Phạm vi phân tích (nâng cao)** ở cuối trang.

### Trang "Việc nên làm trước"

Mỗi mục ghi: mức ưu tiên, loại vấn đề, **trang (URL)**, **bằng chứng**, **độ tin cậy**, **nên làm gì**,
**lợi ích dự kiến** và liên kết mở phát hiện đầy đủ. Ví dụ:

* *Liên kết nội bộ trỏ tới bài đã xóa* → bằng chứng: anchor "…" → URL cũ → nên làm: sửa hoặc gỡ liên kết.
* *Thiếu liên kết nội bộ tới trang liên quan* → bằng chứng: anchor đề xuất "…" → bài đích "…", lý do.
* *Hai trang có thể cạnh tranh cùng truy vấn* → bằng chứng: độ tương đồng chủ đề, cụm từ chung → nên làm:
  kiểm tra Search Console, phân định trọng tâm từng trang.
* *Nhóm chủ đề chưa có bài trụ cột được xác nhận* → đề xuất bài trụ cột.

Lọc theo **ngôn ngữ**, **loại vấn đề**, **mức ưu tiên**, **URL hoặc tiêu đề**; **Tải CSV (Excel)** xuất mọi
mục khớp bộ lọc. AI agent đọc cùng danh sách qua REST API `GET /wp-json/semantic-seo/v1/priorities`.

## 3. Các màn hình chính

| Màn hình | Dùng để |
| --- | --- |
| **Kiểm kê URL / Ma trận SEO** | Bảng mọi URL thật trong CSDL: ngôn ngữ, ý định tìm kiếm, cụm/vai trò, liên kết đến/đi, robots, số vấn đề QA, rủi ro chồng lấn. Lọc, sắp xếp, phân trang trên máy chủ. **Xuất CSV** (UTF-8 có BOM, mở đúng tiếng Việt trong Excel) hoặc **XLSX**. **Nhập** chỉ các trường biên tập của plugin, luôn có bước xem trước; ô trống = không đổi. |
| **Phân tích ngữ nghĩa** | Xem một URL: *Dữ kiện* (đo được: heading, liên kết, ảnh thiếu alt, canonical, robots, bản dịch) tách riêng khỏi *Suy luận* (ý định, chủ đề, thực thể ứng viên, vector). Ghi đè biên tập, khóa URL, loại khỏi gợi ý. |
| **Gợi ý liên kết & Đồ thị** | Gợi ý nguồn → đích kèm anchor có sẵn trong bài, **vị trí byte chính xác**, lý do và bằng chứng; trang có thể bị mồ côi (kèm phạm vi quét — menu/widget/template không được quét). Nút *Tạo bản xem trước*. |
| **Rà soát ăn thịt từ khóa** | Nhóm trang cùng ngôn ngữ có rủi ro chồng lấn: trùng lặp, gần trùng lặp, có thể cạnh tranh cùng truy vấn, khác ý định, khía cạnh riêng (theo ngày / theo tháng), trụ cột + bài hỗ trợ. **Bản dịch VI–EN không bao giờ bị so sánh.** |
| **Cụm Pillar–Hub–Silo** | Cây cụm dựng từ chuyên mục hiện có; trang trụ cột/hub được khuyến nghị kèm bằng chứng; gợi ý chuyên mục cho bài chưa phân loại. Ghi đè và khóa được. Plugin **không tạo chuyên mục mới**. |
| **Kiểm tra chất lượng nội dung** | 26 quy tắc; nhãn tách bạch: *Lỗi đã xác minh* (sự thật khách quan), *Cảnh báo* (cần cân nhắc), *Gợi ý*. Không chấm "điểm chất lượng". |
| **Hiển thị bài viết liên quan** | Mặc định **tắt**. Khi bật: shortcode `[sseo_related]`, block "Related content (Semantic SEO)", hàm PHP `sseo_get_related_posts()`. Chỉ bài cùng ngôn ngữ, đã xuất bản, được lập chỉ mục; loại bỏ bản dịch và bài gần trùng lặp. |
| **Tác vụ & Thiết bị suy luận** | Theo dõi/tạm dừng/tiếp tục/hủy tác vụ; bảng thiết bị cho mô hình trong trình duyệt. |
| **Kết nối Agent / Phân quyền** | Tài khoản riêng cho agent AI, Mật khẩu ứng dụng, bật Abilities/MCP theo từng ability (chỉ quản trị viên). |
| **Cài đặt & Chẩn đoán** | Phạm vi, vận hành, giới hạn tốc độ, ngôn ngữ giao diện plugin (mặc định tiếng Việt), thiết lập chuyên gia, chính sách gỡ cài đặt, khóa/loại trừ, JSON chẩn đoán. |
| **Nhật ký / Hoàn tác** | Đề xuất (xem diff → phê duyệt), thay đổi đã áp dụng (hoàn tác), phê duyệt, nhật ký kiểm toán. |

## 4. Quy trình thay đổi an toàn (ví dụ: chèn một liên kết nội bộ)

1. *Gợi ý liên kết* → chọn gợi ý → **Tạo bản xem trước** (chưa ghi gì vào bài).
2. *Nhật ký / Hoàn tác → Đề xuất*: xem **đoạn trước/sau**, mã liên kết sẽ chèn, danh sách phần **không đổi** (heading, các liên kết khác…), `diff_hash`.
3. Đánh dấu *"Tôi đã xem chính xác thay đổi này và phê duyệt cho riêng đối tượng này"* → **Phê duyệt** (phê duyệt dùng một lần, có hạn 24 giờ mặc định).
4. **Áp dụng ngay** → plugin kiểm tra nội dung chưa đổi kể từ lúc xem trước, ghi, **đọc lại để xác minh**, tự hoàn nguyên nếu không khớp.
5. Muốn quay lại: tab *Thay đổi đã áp dụng* → đánh dấu *Xác nhận* → **Hoàn tác** (khôi phục chính xác byte nếu bài chưa bị sửa sau đó; nếu đã sửa, hoàn tác "phẫu thuật" chỉ gỡ đúng liên kết đã chèn, hoặc báo cần khôi phục thủ công).

Nếu bài viết bị ai đó sửa giữa lúc xem trước và lúc áp dụng, plugin **từ chối ghi** và yêu cầu tạo bản xem trước mới.

## 5. Mô hình trong trình duyệt (Tầng 1, tùy chọn)

1. *Tác vụ & Thiết bị suy luận* → **Kiểm tra thiết bị này** (WebGPU, WebAssembly SIMD, số luồng, bộ nhớ, iOS).
2. Đánh dấu đồng ý tải khoảng **579 MB** từ huggingface.co (chỉ tệp mô hình; nội dung bài **không** gửi đi đâu ngoài website của bạn).
3. **Tải mô hình & tự kiểm thử** — mỗi tệp được kiểm tra kích thước và SHA-256; vector chỉ được gửi lên khi tự kiểm thử khớp số liệu tham chiếu của model card.
4. Bấm *Xếp hàng vector nhúng* (khu vực *Bắt đầu tác vụ*), rồi **Xử lý hàng đợi vector nhúng**. Có thể *Tạm dừng*, *Dừng & trả lại*. Giữ tab mở; đóng tab hoặc khóa màn hình iPhone sẽ dừng xử lý (các mục đã nhận tự trở lại hàng đợi).

Máy tính có Chrome/Edge + WebGPU là nhanh nhất. Không có WebGPU thì cùng độ chính xác đó chạy bằng CPU (WASM): chậm hơn nhưng cho cùng kết quả. Nhiều điện thoại (kể cả iPhone) có thể không hỗ trợ hoặc quá chậm — khi đó cứ dùng tầng từ vựng.

> **Đã kiểm chứng:** mô hình Qwen3-Embedding-0.6B thật (q4f16) đã chạy trong Chromium (WASM) trên GitHub Actions: tải từ huggingface.co, tự kiểm thử đạt, vector trùng với bộ chạy tham chiếu, mở lại từ bộ nhớ đệm đã xác minh mà không tải lại (xem [TEST-REPORT.md](TEST-REPORT.md) §6). Chưa đo tốc độ trên GPU thật (WebGPU) và trên điện thoại thật.

## 6. Dữ liệu Search Console (tùy chọn)

Ma trận SEO → *Dữ liệu Search Console*: tải tệp xuất thật theo **trang × truy vấn** (cột page, query, clicks, impressions, tùy chọn ctr, position), nhập khoảng thời gian và nguồn. Mặc định là **chạy thử** (không ghi). Khi có dữ liệu thật cho cả hai trang, báo cáo chồng lấn có thể nâng thành "cạnh tranh cùng truy vấn" với độ tin cậy cao.

## 7. Khóa và loại trừ

* **Khóa** URL/cụm: không tạo đề xuất thay đổi; trường biên tập bị đóng băng.
* **Loại trừ**: vẫn kiểm kê nhưng không bao giờ được gợi ý, đề xuất hay làm đích liên kết.
Áp dụng như nhau cho con người và agent.

## 8. Câu hỏi thường gặp

* **Plugin có làm chậm website không?** Không chạy gì trên trang công khai, trừ khi bạn bật "bài viết liên quan" (danh sách đã tính sẵn, có bộ nhớ đệm).
* **Gỡ plugin có mất dữ liệu?** Mặc định **giữ** dữ liệu. Muốn xóa hết: Cài đặt → chính sách gỡ cài đặt → gõ `DELETE`. Bài viết WordPress không bao giờ bị xóa.
* **Vì sao gợi ý liên kết ít?** Plugin ưu tiên độ chính xác: chỉ gợi ý khi tìm được anchor tự nhiên mô tả đúng trang đích (trên bộ dữ liệu thử có nhãn, chỉ tầng từ vựng: ~79% gợi ý trả ra là đúng nhưng chỉ tìm được 24% liên kết mong đợi; khi có vector của mô hình thật: tìm được 70%, 69% gợi ý trả ra là đúng). Bài viết liên quan có độ phủ cao hơn.
* **Giá thuê, đặt cọc trong dữ liệu thử nghiệm** chỉ là dữ liệu kiểm thử, không phải bảng giá công bố.
