# Hướng dẫn sử dụng — Semantic SEO Engine VI–EN (bản 1.0.0-beta.1)

Tài liệu dành cho quản trị viên/biên tập viên website. Bản tiếng Anh: [USER-GUIDE-EN.md](USER-GUIDE-EN.md).

## 0. Plugin làm gì — và không làm gì

* **Làm:** kiểm kê URL, phân tích liên kết nội bộ, rủi ro ăn thịt từ khóa (cùng ngôn ngữ), cụm Pillar–Hub–Silo, kiểm tra chất lượng nội dung, bài viết liên quan — kèm bằng chứng rõ ràng cho từng kết luận.
* **Không làm:** không tự sửa nội dung, metadata SEO, URL, canonical, noindex, redirect hay theme. Mọi thay đổi đều là **đề xuất** có diff chính xác, phải được **con người phê duyệt** trong WP Admin, và có thể **hoàn tác**.
* **Không** chatbot, **không** gọi API AI trả phí, **không** tải mô hình cho khách truy cập.
* Kết quả là **gợi ý/giả thuyết**, không phải cam kết thứ hạng. "Ăn thịt từ khóa" chỉ được xác nhận khi có dữ liệu Search Console thật do bạn nhập.

## 1. Cài đặt nhanh

1. Sao lưu website (tệp + cơ sở dữ liệu). Nên thử trên bản staging trước.
2. *Plugins → Cài mới → Tải plugin lên* → chọn `semantic-seo-engine-vi-en-1.0.0-beta.1.zip` → *Cài đặt* → *Kích hoạt*.
3. Kích hoạt **chỉ tạo bảng dữ liệu riêng của plugin** (`{prefix}sseo_*`); nội dung, SEO, URL, giao diện ngoài website giữ nguyên (đã kiểm chứng byte-by-byte trên WordPress 6.5.13, 6.9.10, 7.1.3).

Chi tiết: [INSTALLATION.md](INSTALLATION.md).

## 2. Trình hướng dẫn nhanh (Tổng quan & Thiết lập)

Menu **Semantic SEO → Tổng quan & Thiết lập**:

1. **Kiểm tra tương thích** — phiên bản WordPress/PHP, bảng dữ liệu, phần mở rộng `intl`, Abilities API, nền tảng đa ngôn ngữ (Polylang/WPML/tiền tố URL/mặc định), nguồn metadata SEO (Yoast, Rank Math, SEOPress, The SEO Framework, AIOSEO — chỉ đọc).
2. **Các tầng xử lý** — Tầng 0 (từ vựng) dùng được ngay; Tầng 1 (mô hình trong trình duyệt) và Tầng 2 (worker riêng) là tùy chọn.
3. **Phạm vi** — loại nội dung, URL loại trừ, kích thước lô, số tài liệu tối đa.
4. **Chạy thử chỉ-đọc** — phân tích 20 bài cập nhật gần nhất, xem số liệu.
5. **Hoàn tất** — sau đó bấm *Kiểm kê toàn bộ + phân tích*.

Tác vụ chạy nền theo lô nhỏ. Nếu website tắt WP-Cron, hãy để trang *Tác vụ & Thiết bị suy luận* mở (trang sẽ tự chạy tiếp) hoặc dùng `wp sseo run --user=<quản trị viên>`.

## 3. Các màn hình chính

| Màn hình | Dùng để |
| --- | --- |
| **Kiểm kê URL / Ma trận SEO** | Bảng mọi URL thật trong CSDL: ngôn ngữ, ý định tìm kiếm, cụm/vai trò, liên kết đến/đi, robots, số vấn đề QA, rủi ro chồng lấn. Lọc, sắp xếp, phân trang trên máy chủ. **Xuất CSV** (UTF-8 có BOM, mở đúng tiếng Việt trong Excel) hoặc **XLSX**. **Nhập** chỉ các trường biên tập của plugin, luôn có bước xem trước; ô trống = không đổi. |
| **Phân tích ngữ nghĩa** | Xem một URL: *Dữ kiện* (đo được: heading, liên kết, ảnh thiếu alt, canonical, robots, bản dịch) tách riêng khỏi *Suy luận* (ý định, chủ đề, thực thể ứng viên, vector). Ghi đè biên tập, khóa URL, loại khỏi gợi ý. |
| **Gợi ý liên kết & Đồ thị** | Gợi ý nguồn → đích kèm anchor có sẵn trong bài, **vị trí byte chính xác**, lý do và bằng chứng; trang có thể bị mồ côi (kèm phạm vi quét — menu/widget/template không được quét). Nút *Tạo bản xem trước*. |
| **Rà soát ăn thịt từ khóa** | Nhóm trang cùng ngôn ngữ có rủi ro chồng lấn: trùng lặp, gần trùng lặp, có thể cạnh tranh cùng truy vấn, khác ý định, khía cạnh riêng (theo ngày / theo tháng), trụ cột + bài hỗ trợ. **Bản dịch VI–EN không bao giờ bị so sánh.** |
| **Cụm Pillar–Hub–Silo** | Cây cụm dựng từ chuyên mục hiện có; trang trụ cột/hub được khuyến nghị kèm bằng chứng; gợi ý chuyên mục cho bài chưa phân loại. Ghi đè và khóa được. Plugin **không tạo chuyên mục mới**. |
| **Kiểm tra chất lượng nội dung** | 24 quy tắc; nhãn tách bạch: *Lỗi đã xác minh* (sự thật khách quan), *Cảnh báo* (cần cân nhắc), *Gợi ý*. Không chấm "điểm chất lượng". |
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

> **Trạng thái beta:** chưa chạy được mô hình Qwen3 thật trong môi trường build (không tải được trọng số). Đường ống đã được kiểm chứng đầy đủ bằng một mô hình thử nghiệm nhỏ có gắn nhãn. Xem [TEST-REPORT.md](TEST-REPORT.md).

## 6. Dữ liệu Search Console (tùy chọn)

Ma trận SEO → *Dữ liệu Search Console*: tải tệp xuất thật theo **trang × truy vấn** (cột page, query, clicks, impressions, tùy chọn ctr, position), nhập khoảng thời gian và nguồn. Mặc định là **chạy thử** (không ghi). Khi có dữ liệu thật cho cả hai trang, báo cáo chồng lấn có thể nâng thành "cạnh tranh cùng truy vấn" với độ tin cậy cao.

## 7. Khóa và loại trừ

* **Khóa** URL/cụm: không tạo đề xuất thay đổi; trường biên tập bị đóng băng.
* **Loại trừ**: vẫn kiểm kê nhưng không bao giờ được gợi ý, đề xuất hay làm đích liên kết.
Áp dụng như nhau cho con người và agent.

## 8. Câu hỏi thường gặp

* **Plugin có làm chậm website không?** Không chạy gì trên trang công khai, trừ khi bạn bật "bài viết liên quan" (danh sách đã tính sẵn, có bộ nhớ đệm).
* **Gỡ plugin có mất dữ liệu?** Mặc định **giữ** dữ liệu. Muốn xóa hết: Cài đặt → chính sách gỡ cài đặt → gõ `DELETE`. Bài viết WordPress không bao giờ bị xóa.
* **Vì sao gợi ý liên kết ít?** Plugin ưu tiên độ chính xác: chỉ gợi ý khi tìm được anchor tự nhiên mô tả đúng trang đích (trên bộ dữ liệu thử có nhãn: ~79% gợi ý trả ra là đúng, độ phủ còn thấp). Bài viết liên quan có độ phủ cao hơn.
* **Giá thuê, đặt cọc trong dữ liệu thử nghiệm** chỉ là dữ liệu kiểm thử, không phải bảng giá công bố.
