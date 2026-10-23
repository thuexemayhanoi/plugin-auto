# Hướng dẫn quản trị — Semantic SEO Engine VI–EN (1.0.0-beta.2)

Tài liệu cho **người quản trị website** (không cần biết lập trình) và cho **người/AI vận hành thay
mặt quản trị viên** qua giao diện WordPress Admin. Hướng dẫn sử dụng từng màn hình nằm ở
[USER-GUIDE-VI.md](USER-GUIDE-VI.md); tích hợp API cho AI agent nằm ở
[AI-AGENT-INTEGRATION.md](AI-AGENT-INTEGRATION.md).

> Nguyên tắc: plugin **chỉ đọc theo mặc định**. Không có thao tác nào sửa bài viết, slug, canonical,
> metadata SEO, chuyển hướng hay xóa nội dung nếu chưa có **bản xem trước + phê duyệt của con người**.

---

## 1. Vai trò và quyền

| Ai | Vai trò WordPress | Được làm |
| --- | --- | --- |
| Chủ website | Administrator | Mọi việc, kể cả phê duyệt thay đổi và cấu hình Agent |
| Biên tập viên SEO | Administrator (hoặc vai trò có quyền `sseo_review`) | Rà soát, phê duyệt/từ chối đề xuất |
| AI chỉ đọc (Claude, ChatGPT…) | **SEO Agent (read-only)** | Đọc báo cáo |
| AI phân tích | **SEO Agent (read + analyze)** | Đọc + chạy phân tích có giới hạn |
| AI thực thi | **SEO Agent (may execute human-approved changes)** | Thực thi **chỉ những thay đổi con người đã phê duyệt**, khi bật cài đặt riêng |
| Máy tính chạy model riêng | **SEO Embedding Worker** | Nhận văn bản cần nhúng (embedding) và gửi vector về; không đọc báo cáo |

Không vai trò nào của AI có thể tự phê duyệt. Phiên đăng nhập bằng Application Password luôn bị coi
là agent, kể cả khi tài khoản là administrator.

## 2. Triển khai cho blog.rentbikehanoi.com (thứ tự khuyến nghị)

1. **Bản sao staging trước.** Cài ZIP lên bản sao staging của blog (VI + `/en/`), không lên production.
2. **Kích hoạt.** Kích hoạt không thay đổi nội dung, URL, dữ liệu SEO hay HTML front-end (đã kiểm
   chứng trên WordPress 6.5.13, 6.9.10, 7.1.3).
3. **Mở *Semantic SEO → Tổng quan & Thiết lập*.** Kiểm tra các dòng phát hiện môi trường:
   * *Language framework: Nguyen Ha VI-EN Core*. Blog dùng plugin ngôn ngữ riêng
     `nguyen-ha-vi-en`. Plugin này đọc (không ghi) cặp VI⇔EN, bài tiếng Anh độc lập, cặp trang và
     chuyên mục tiếng Anh của plugin đó. Nếu dòng này báo "URL prefix rules" hoặc "Site default", hãy
     kiểm tra lại plugin VI-EN Core đã được kích hoạt hay chưa.
   * *SEO plugin: Yoast SEO*. Plugin chỉ **đọc** tiêu đề SEO, mô tả, focus keyword, canonical và
     noindex của Yoast. Plugin không in ra schema, meta, canonical hay hreflang.
4. **Chạy thử chỉ-đọc** (bước 4 của trình hướng dẫn trên trang Tổng quan, nút *Bắt đầu chạy thử*):
   phân tích một mẫu nhỏ để kiểm tra số lượng và độ phủ. Sau đó bấm *Kiểm kê toàn bộ + phân tích* để
   phân tích lexical cả site. Không cần model AI cho bước này.
5. **Xem kết quả** theo thứ tự: Kiểm tra chất lượng → Gợi ý liên kết nội bộ → Rà soát ăn thịt từ
   khóa → Cụm Pillar–Hub–Silo. Các phát hiện được chia ba mức: *lỗi đã xác minh*, *cảnh báo có căn
   cứ* và *gợi ý cần người đánh giá*.
6. **Bật tầng ngữ nghĩa** (không bắt buộc, xem mục 3).
7. Chỉ sau khi đã rà soát trên staging mới quyết định cài lên production. Việc này cần phê duyệt
   riêng của chủ website.

### Những điều đã biết về hệ thống hiện tại của blog

Thông tin dưới đây lấy từ các repo của chủ website và được đọc ở chế độ chỉ đọc:

* **Theme** (bản ghi gần nhất: *Nguyen Ha Editorial*; tài liệu cũ hơn ghi *Nguyễn Hà Glass 2026*):
  * Theme đã có khối "bài viết liên quan" riêng. Vì vậy khối *Related content* của plugin **để tắt**
    (mặc định tắt), tránh hiển thị hai danh sách liên quan.
  * Mục lục (TOC) của theme đọc các thẻ `h2/h3` trong nội dung. Nếu sau này bật khối liên quan của
    plugin bên trong nội dung bài, tiêu đề khối sẽ xuất hiện trong TOC.
  * Theme có một nhóm liên kết nội bộ cố định ở trang chủ và footer. Liên kết trong menu, widget và
    template **không được quét**: báo cáo "trang mồ côi" chỉ tính liên kết nằm trong nội dung bài.
* **Yoast SEO** là nguồn duy nhất của schema, meta và canonical. Plugin không thêm và không thay đổi
  các dữ liệu này.
* **Nguyen Ha VI-EN Core** quản lý `/en/`, hreflang và cặp bản dịch. Plugin không bao giờ tự tạo cặp
  bản dịch. Trên website thật (đọc hreflang công khai ngày 23/10/2026), **chỉ 16 cặp trang** khai báo
  bản tiếng Việt tương ứng. **500 bài tiếng Anh là bài độc lập**, không bài nào ghép cặp với bài tiếng
  Việt. Vì vậy plugin chỉ gợi ý "thiếu bản dịch" cho loại nội dung mà website có ghép cặp (ở đây là
  trang), không gợi ý cho bài viết. Model chỉ thấy vài cặp bài VI/EN có thể là bản dịch của nhau (9 cặp
  ở ngưỡng 0,70), đều là gợi ý để người kiểm tra; plugin không tự ghép cặp.

### Số liệu từ bản sao chỉ-đọc của blog (23/10/2026)

Đây là con số tổng hợp trên **bản sao tạm** của nội dung công khai, không phải trên website thật. Cài
plugin lên staging sẽ cho đúng danh sách từng trang.

* 1.083 URL trong sitemap: 1.001 bài viết, 37 trang, 43 chuyên mục.
* 881 bài/trang **không có mô tả meta** trong đầu ra công khai của Yoast.
* 579 bài **không có liên kết đến từ nội dung** bài khác (chưa tính menu, widget, footer).
* 54 bài thông tin dài (≥ 600 từ) **không trích nguồn bên ngoài** nào (gợi ý E-E-A-T).
* Gợi ý liên kết nội bộ: 830 (chỉ từ vựng), 2.908 (khi có vector của model).
* Rà soát chồng lấn: 99 nhóm (chỉ từ vựng), 898 nhóm (khi có vector; xem mục 3 về thứ tự duyệt).
  Không cặp bản dịch VI–EN nào bị coi là chồng lấn.
* **Multi AI SEO Content Factory** (repo `wp-plugin`) là sản phẩm khác: nó viết bài nháp bằng AI. Hai
  plugin không trùng tên, bảng hay route. Plugin này không đọc và không ghi meta `_masf_*`.

## 3. Chọn tầng phân tích ngữ nghĩa

| Tầng | Khi nào dùng | Ghi chú |
| --- | --- | --- |
| **Lexical** (mặc định) | Luôn có sẵn | BM25, tiêu đề, heading, liên kết, taxonomy. Không tải model |
| **Trình duyệt** (Tác vụ & Thiết bị suy luận) | Máy tính có Chrome/Edge | Model Qwen3-Embedding-0.6B tải về **trình duyệt của quản trị viên** sau khi bạn đồng ý. Không bao giờ chạy cho khách truy cập |
| **Máy riêng (private worker)** | Muốn chạy tự động, nhiều trang | Script Python trên máy tính hoặc máy chủ của bạn; xác thực bằng Application Password của tài khoản *SEO Embedding Worker* ([hướng dẫn](../tools/private-worker/README.md)) |

**Độ chính xác của model** (*Cài đặt & Chẩn đoán → Model precision*). Các số dưới đây đo bằng model
thật, với file đã ghim và kiểm tra SHA-256, trong workflow GitHub Actions "Real Qwen3-Embedding-0.6B":

| Tùy chọn | Dung lượng tải | So với bản gốc fp32 | Gợi ý dùng |
| --- | --- | --- | --- |
| **q4f16** (mặc định) | ≈ 567 MB | cosine trung bình 0,926; 94% láng giềng top-5 trùng | Hầu hết trường hợp. Chạy được trên WebGPU, CPU (WASM) và máy riêng với kết quả nhất quán |
| **fp16** | ≈ 1,2 GB | gần như trùng tuyệt đối (cosine 1,000) | Máy riêng, hoặc máy có WebGPU hỗ trợ shader 16-bit |

Bản q8 (≈ 614 MB) **không được cung cấp**. Trong phép đo, q8 kém chính xác nhất (cosine 0,836) và cho
kết quả khác nhau giữa trình duyệt và máy riêng, nên vector từ hai thiết bị không so sánh được với nhau.

Đổi độ chính xác hoặc số chiều sẽ tạo "hồ sơ model" mới. Khi đó vector cũ không bị trộn với vector
mới, và hệ thống cần nhúng lại toàn bộ nội dung.

Trước khi gửi bất kỳ vector nào, mỗi thiết bị phải qua **tự kiểm tra**: tính lại một ma trận độ tương
đồng chuẩn và so với giá trị đã đo cho đúng độ chính xác đó. Nếu tự kiểm tra thất bại, thiết bị không
được gửi vector.

**Báo cáo chồng lấn khi bật model.** Với độ tương đồng thô, mọi trang đều "hơi giống" nhau. Trên bản sao
blog (chỉ đọc), số nhóm chồng lấn tăng từ 99 (chỉ từ vựng) lên 2.323. Vì vậy khi xét rủi ro
chồng lấn, plugin chỉ tính phần giống nhau **vượt trên một mức sàn**. Mức sàn mặc định là 0,40, đo trên
bộ dữ liệu có nhãn. Nếu các trang của chính website giống nhau hơn mức đó (trung vị đo lại sau mỗi lần
phân tích toàn site), mức sàn được nâng lên bằng trung vị ấy. Trên blog, trung vị là 0,38 (VI) và 0,36
(EN), nên dùng mức 0,40. Kết quả còn 898 nhóm, đều thuộc khoảng 1% cặp trang giống nhau nhất của blog.

Gợi ý liên kết và bài liên quan vẫn dùng độ tương đồng thô để xếp hạng. Mỗi phát hiện chồng lấn ghi rõ
mức sàn đã dùng (*Bằng chứng → calibration*).

**Nên duyệt theo thứ tự:** *Có thể cạnh tranh cùng truy vấn* (96 cặp trên bản sao; 54 cặp độ tin cậy
trung bình) và *trùng lặp / gần trùng lặp* trước. Sau đó đến *khía cạnh riêng*. Nhóm *cần xem xét thủ
công* (563 cặp) chỉ là giả thuyết dựa trên ngữ nghĩa, không có bằng chứng ở tiêu đề. *Khác ý định*
(212 cặp) thường là bài bổ trợ cho nhau, không phải cạnh tranh.

## 4. Thiết lập và dùng trên điện thoại (iPhone, iPad, Android)

Giao diện quản trị của plugin dùng được trên màn hình nhỏ: bảng chuyển thành thẻ, nút đủ lớn để chạm.
Phần này đã chụp màn hình kiểm tra ở chiều ngang 390 px.

**Làm được trên điện thoại**
1. Đăng nhập `…/wp-admin` → *Semantic SEO*.
2. *Tổng quan & Thiết lập*: xem trạng thái (Sẵn sàng, Đang xử lý, Cần rà soát, Lỗi), chạy thử
   chỉ-đọc, tiếp tục hoặc tạm dừng tác vụ.
3. Rà soát phát hiện: đánh dấu *đã xem*, *bỏ qua*, *chấp nhận*. Khóa một URL không cho đề xuất.
4. *Nhật ký / Hoàn tác*: xem bản diff, **phê duyệt hoặc từ chối**, hoàn tác thay đổi đã áp dụng.
5. Giữ màn hình sáng khi tác vụ đang chạy nếu WP-Cron của hosting ít lượt truy cập. Trang *Tác vụ*
   tự đẩy tác vụ khi đang mở.

**Không khuyến nghị trên điện thoại**
* Tải model AI trên iPhone/iPad (Safari). Plugin cảnh báo và **không tự tải**: 567 MB có thể vượt bộ
  nhớ, và máy có thể khóa màn hình giữa chừng. Hãy dùng máy tính hoặc máy riêng.
* Nhập/xuất file XLSX lớn. Nên làm việc này trên máy tính.

> Chưa kiểm thử trên iPhone thật. Bố cục di động đã kiểm thử bằng trình duyệt giả lập kích thước
> điện thoại (Playwright).

## 5. Quy trình cho ChatGPT Work (hoặc AI có trình duyệt) vào WordPress Admin

Dành cho AI thao tác **bằng giao diện** thay mặt chủ website, không cần đọc mã nguồn. AI dùng một tài
khoản riêng, đăng nhập thường (không dùng Application Password), và **không bao giờ phê duyệt thay
đổi**: phê duyệt là việc của con người.

**Chuẩn bị (chủ website làm một lần)**
1. *Người dùng → Thêm mới*: tạo tài khoản riêng cho AI (ví dụ `ai-operator`) với vai trò
   **SEO Agent (read + analyze)**. Dùng mật khẩu mạnh và bật 2FA nếu hosting hỗ trợ.
2. Không cấp quyền Administrator cho tài khoản AI.

**Việc AI làm mỗi lần**
1. Mở `https://<site>/wp-admin/admin.php?page=sseo` (*Tổng quan & Thiết lập*). Đọc dòng trạng thái.
2. Nếu dữ liệu cũ hơn 7 ngày: bấm **Kiểm kê toàn bộ + phân tích** (mục *Chạy phân tích* trên trang
   Tổng quan). Chờ đến khi trạng thái là *Sẵn sàng* hoặc *Cần rà soát*.
3. Đọc lần lượt các trang và ghi tóm tắt:
   * *Kiểm tra chất lượng nội dung*: lọc **Lỗi đã xác minh** trước, sau đó **Cảnh báo**.
   * *Gợi ý liên kết nội bộ*: mỗi gợi ý có câu chứa anchor, lý do và mức tin cậy.
   * *Rà soát ăn thịt từ khóa*: chỉ là **nguy cơ** khi chưa có dữ liệu Search Console. Không đề xuất
     xóa, đổi slug, redirect hay đổi canonical.
   * *Cụm Pillar–Hub–Silo*: ghi lại cụm chưa có pillar hoặc bài nằm sai chuyên mục.
4. Với liên kết nên thêm: bấm **Tạo bản xem trước**. AI dừng ở đây và báo cho chủ website link của
   đề xuất ở *Nhật ký / Hoàn tác*.
5. Chủ website mở đề xuất, xem diff, tích ô xác nhận và bấm **Phê duyệt** (hoặc **Từ chối**).
6. Chỉ khi chủ website đã phê duyệt **và** đã bật ô "cho phép tài khoản agent vai trò operator thực
   thi thay đổi con người đã duyệt" ở *Kết nối Agent / Phân quyền*, tài khoản có vai trò *SEO Agent
   (được thực thi thay đổi con người đã duyệt)* mới được bấm **Áp dụng**. Nếu không thỏa cả hai điều
   kiện, chủ website tự bấm.

**AI không được làm**
* Phê duyệt, thay đổi quyền, tạo tài khoản, đổi cài đặt bảo mật, bật hiển thị khối liên quan trên
  front-end.
* Sửa bài viết trực tiếp trong trình soạn thảo, sửa trường Yoast, cài/gỡ plugin hoặc theme.

**Mẫu yêu cầu cho ChatGPT Work**
> "Đăng nhập WordPress Admin bằng tài khoản ai-operator. Vào Semantic SEO → Tổng quan & Thiết lập. Nếu
> phân tích cũ hơn 7 ngày thì bấm "Kiểm kê toàn bộ + phân tích" và chờ xong. Tóm tắt 10 lỗi đã xác minh quan trọng nhất và 10 gợi
> ý liên kết nội bộ có độ tin cậy cao nhất (kèm câu chứa anchor). Tạo bản xem trước cho tối đa 3 liên
> kết hợp lý nhất rồi dừng lại để tôi phê duyệt. Không phê duyệt, không sửa bài, không đổi cài đặt."

> Chưa kiểm thử với tài khoản ChatGPT Work thật. Quy trình qua giao diện và các giới hạn quyền nêu trên
> đã được kiểm thử tự động: Playwright thao tác giao diện, kiểm thử HTTP các vai trò agent.

## 6. Vận hành hằng tuần (khoảng 15 phút)

1. *Tổng quan*: kiểm tra trạng thái và số phát hiện mới. Mỗi khi nội dung thay đổi, hệ thống tự kiểm
   kê lại phần thay đổi.
2. *Kiểm tra chất lượng*: xử lý **Lỗi đã xác minh**, ví dụ liên kết nội bộ hỏng hoặc trùng tiêu đề.
3. *Gợi ý liên kết nội bộ*: tạo bản xem trước rồi phê duyệt những liên kết hợp lý (không quá 2–3
   liên kết mới cho mỗi bài mỗi lần).
4. *Nhật ký / Hoàn tác*: xem lại những thay đổi đã áp dụng. Có thể hoàn tác từng thay đổi.
5. *Cài đặt & Chẩn đoán → Diagnostics JSON*: sao chép khi cần báo lỗi. Bản này không chứa bí mật.

## 7. Sao lưu, khôi phục, gỡ cài đặt

* **Trước khi áp dụng thay đổi đầu tiên trên production**: sao lưu database theo quy trình của hosting.
* **Hoàn tác từng thay đổi**: thực hiện ở *Nhật ký / Hoàn tác*. Plugin lưu ảnh chụp trước/sau. Nếu
  bài viết đã bị sửa sau đó, hệ thống chỉ hoàn tác đúng phần đã chèn.
* **Tắt plugin**: dừng tác vụ nền và giữ nguyên dữ liệu. Bật lại thì tiếp tục được.
* **Xóa plugin**: mặc định **giữ dữ liệu**. Chỉ xóa bảng `wp_sseo_*` khi bạn đã chọn chính sách
  "xóa dữ liệu khi gỡ" trong Cài đặt.
* **Dữ liệu dẫn xuất** (chỉ mục, phát hiện, vector) có thể tạo lại bất kỳ lúc nào. Việc tạo lại không
  ghi vào bài viết.

## 8. Khi gặp sự cố

Xem bảng xử lý sự cố trong [OPERATIONS.md](OPERATIONS.md). Ba trường hợp hay gặp:

* **Tác vụ đứng ở "đang chờ"**: WP-Cron không chạy. Mở trang *Tác vụ* (trang tự đẩy tác vụ khi mở)
  hoặc nhờ kỹ thuật thêm cron thật.
* **"Thiết bị không hỗ trợ"**: dùng Chrome/Edge trên máy tính hoặc máy riêng. Phân tích lexical vẫn
  hoạt động bình thường.
* **Tự kiểm tra model thất bại**: không ghi đè hay bỏ qua bước này. Thử trình duyệt khác hoặc dùng máy
  riêng, rồi gửi Diagnostics JSON.
