# Báo cáo QA — Semantic SEO Engine VI–EN 1.0.0-beta.4

Phạm vi: sửa lỗi cơ sở dữ liệu trên MariaDB 11.8 (production), audit lại plugin, Bảng điều khiển SEO
tiếng Việt cho người không chuyên kỹ thuật, kiểm thử và gói ZIP cài đặt được.

Nguyên tắc của báo cáo: chỉ ghi điều đã kiểm chứng, và ghi rõ môi trường của từng kết quả. Những gì
chưa kiểm chứng được ghi là **CHƯA XÁC MINH**. Plugin chưa được cài lên website thật;
blog.rentbikehanoi.com vẫn đang tắt plugin như yêu cầu.

---

## 1. Nguyên nhân gốc trên MariaDB 11.8 và cách sửa

### Hiện tượng (production)

WP 7.1.3, PHP 8.3.35, MariaDB 11.8.9, Yoast, Nguyen Ha VI–EN Core 0.5.3, LiteSpeed Cache.

- `db_schema.installed = 0`, `expected = 3`.
- `missing = ["embeddings"]`, tức chỉ có 15/16 bảng.
- `sseo_migration_log` lặp lại "missing tables: embeddings".

### Nguyên nhân gốc (đã tái hiện và xác minh)

Từ MariaDB **11.7**, `VECTOR` là từ khóa: nó là tên kiểu dữ liệu vector mới. Bảng `wp_sseo_embeddings`
của beta.3 có một cột tên là `vector`:

```sql
CREATE TABLE wp_sseo_embeddings (
  ...
  content_hash char(64) NOT NULL,
  vector longblob NOT NULL,      -- MariaDB 11.7+ hiểu "vector" là kiểu dữ liệu, không phải tên cột
  norm float NOT NULL DEFAULT 0,
  ...
```

Tái hiện trên MariaDB 11.8.9 (Docker `mariadb:11.8`):

```
ERROR 1064 (42000): You have an error in your SQL syntax; check the manual that corresponds to your
MariaDB server version for the right syntax to use near 'longblob NOT NULL, PRIMARY KEY (id))'
```

- **Mã lỗi:** 1064.
- **SQLSTATE:** 42000.
- **Câu SQL lỗi:** `CREATE TABLE` của bảng embeddings, do `dbDelta()` của WordPress chạy.

`dbDelta()` không trả lỗi về cho plugin, nên 15 bảng còn lại vẫn được tạo. Phiên bản schema không
tăng, và lần migration thất bại chạy lại ở **mỗi request**, kể cả trang ngoài cho khách. Đó là vòng
lặp mà log production ghi nhận.

Bằng chứng hồi quy (`tests/php/Integration/SchemaCompatibilityTest.php`, 8 test):
- Trên code beta.3: 8/8 thất bại trên MariaDB 11.8; 7/8 thất bại trên 10.11 (test nguyên nhân gốc
  chỉ chạy trên 11.7+).
- Trên beta.4: 8/8 đạt trên cả hai.
- Trước bản sửa, 68/117 test integration thất bại trên MariaDB 11.8.9.

### Cách sửa (an toàn, idempotent, không phá dữ liệu)

| Vấn đề | Sửa |
| --- | --- |
| Cột `vector` là từ khóa trên 11.7+ | Đổi tên thành `vector_data` (schema 4). Không dùng từ khóa nào làm tên cột. |
| Site cũ (MySQL, MariaDB ≤ 11.6) đã có cột `vector` | `CHANGE COLUMN` có backtick, chạy **trước** `dbDelta()` và giữ nguyên mọi vector. Kiểm chứng trên 10.11: chuỗi byte trước/sau đều là `0000803F0000000000000000`. |
| Bảng có cả `vector` lẫn `vector_data` (lần nâng cấp bị dừng giữa chừng) | Sao chép dữ liệu, kiểm tra số dòng và chỉ xóa cột cũ khi không còn dòng nào thiếu. |
| Migration báo "thành công" khi vẫn thiếu bảng | `Migrator::verify()` kiểm tra **mọi bảng và mọi cột** theo định nghĩa. Thiếu một thứ là phiên bản không tăng. |
| Log không có SQL lỗi | Log ghi mã lỗi MySQL, SQLSTATE, thông báo và câu SQL của từng bảng lỗi (đọc qua mysqli). |
| Migration lỗi chạy lại mỗi request | Thử lại tối đa mỗi 30 phút. Thử lại ngay khi cập nhật plugin, khi kích hoạt, qua WP-CLI, hoặc khi bấm "Sửa cơ sở dữ liệu". |
| Bắt đầu quét trên DB thiếu bảng | Bị từ chối với `sseo_db_not_ready` (HTTP 503) và nêu tên bảng thiếu. |
| `/status` không nói rõ trạng thái DB | Có `ready`, `tables_present/expected`, `missing`, `missing_columns`, `last_error`, `sql_errors` và `retry_scheduled`. |
| Dashboard và `/status` truy vấn bảng đang thiếu | Không truy vấn bảng embeddings khi bảng chưa có. Báo 0 vector thay vì sinh lỗi SQL vào log. Đã có test hồi quy. |

Không có lệnh `DROP TABLE`, không xóa dữ liệu có sẵn và không cài lại DB.

Kiểm thử nâng cấp thật (`tests/e2e/upgrade-mariadb.sh`, **34/34 đạt** trên máy local):

1. Cài beta.3 trên MariaDB 11.8.9. Tái hiện đúng production: 15/16 bảng, log ghi
   "missing tables: embeddings", `/status` báo `installed 0` và `missing ["embeddings"]`, migration
   chạy lại ở mỗi request (5 → 9 lần sau 3 request).
2. Nâng cấp lên beta.4: plugin vẫn kích hoạt, có 16/16 bảng và cột `vector_data`. `/status` báo
   `installed 4 = expected 4`, `ready: true`.
3. Hết vòng lặp: thêm 6 request mà không có lần migration nào (11 → 11). debug.log không có lỗi PHP.
4. Trên 10.11: cột `vector` được đổi thành `vector_data`, vector giữ nguyên từng byte.

---

## 2. Các lỗi khác đã sửa

Mỗi lỗi có test hồi quy. Test thất bại trước bản sửa và đạt sau bản sửa.

| Mã | Mức | Lỗi | Test |
| --- | --- | --- | --- |
| D1 | P2 | Hủy hoặc tạm dừng trong lô cuối của một job bị bỏ qua, và bước phân tích vẫn được nối tiếp. | `JobChainTest` |
| D2 | P1 | Bấm quét toàn site khi một phân tích thử đang chờ thì phân tích toàn site bị **mất âm thầm**. Nay phân tích thử đang chờ được thay thế; nếu không thay được thì yêu cầu được giữ lại và chạy khi rảnh. | `JobChainTest` |
| D3 | P2 | Có thể bắt đầu quét trên DB thiếu bảng. | `JobChainTest` |
| D4 | P3 | Dashboard và `/status` sinh lỗi SQL "Table … doesn't exist" trên DB 15/16 bảng. | `AdminUiTest::test_dashboard_repairs_an_incomplete_database` |
| D5 | P3 | Bằng chứng "chỉ đọc" báo sai là bài viết đã đổi: WP-Cron của WordPress tự xóa cờ `_pingme`/`_encloseme` sau khi đăng bài. Các meta này nay được loại trừ, cùng với khóa soạn thảo. | `dashboard-e2e.mjs` |
| D6 | P3 | Độ tương phản màu và link trong đoạn văn không đạt axe (WCAG 2.1 AA) trên trang mới. | `dashboard-e2e.mjs` (axe 0 lỗi) |
| CI | — | Script e2e private worker còn đọc cột cũ `e.vector`. | Workflow Real Qwen3 |
| D7 | P1 | Hàng đợi ưu tiên, CSV, top 10 trên dashboard, `/scans` và `/health` lộ tiêu đề/URL của bài nháp/private (bài đích của link, ô tìm kiếm, danh sách so sánh scan) cho người chỉ có quyền đọc và cho AI agent. | `DashboardDataTest::test_pages_that_are_not_published_stay_hidden_from_readers`, `…test_scan_comparison_is_fixed…` |
| D8 | P2 | Phần "So với lần quét trước" bị tính lại theo dữ liệu hiện tại, nên một lần phân tích thử sau đó làm thay đổi kết quả của lần quét đã xong. Nay kết quả được ghi cố định khi quét xong. | `DashboardDataTest::test_scan_comparison_is_fixed…` |
| D9 | P2 | Nếu lệnh đổi tên cột `vector` thất bại (ví dụ khóa metadata), `dbDelta()` vẫn thêm cột `vector_data` rỗng và migration báo thành công, làm vector cũ biến mất khỏi phân tích. Nay migration dừng lại và thử lại sau; cột cũ còn sót được coi là schema chưa hoàn chỉnh. | `SchemaCompatibilityTest::test_a_failed_rename_stops_the_migration…` |
| D10 | P2 | Gộp nhiều yêu cầu phân tích đang chờ vượt 500 trang thì phần dư bị bỏ âm thầm. Nay vượt 500 trang thì chuyển thành phân tích toàn site. | `JobChainTest::test_pending_scopes_merged_beyond_one_analysis_become_site_wide` |
| D11 | P2 | "Phân tích thử 20 bài" mà không bài nào vào được inventory (đạt giới hạn tài liệu hoặc bị loại trừ) thì tự thành phân tích toàn site. Nay không phân tích, và dashboard báo lý do. | `JobChainTest::test_a_trial_whose_pages_are_not_inventoried…` |
| D12 | P3 | Yêu cầu phân tích đang chờ có thể bị mất khi hai tiến trình (WP-Cron và dashboard) cùng xử lý, hoặc bị đọc từ cache cũ. Nay có khóa và đọc thẳng từ database. | `JobChainTest::test_a_pending_request_stored_by_another_process_is_started` |
| D13 | P3 | Người chỉ có quyền đọc mở dashboard thì nhận lỗi 403 mỗi 4 giây và không thấy tiến độ. Nay chỉ theo dõi tiến độ, và giãn nhịp khi bị từ chối. | Kiểm tra code (`admin.js`) |

Các mục D7–D13 đến từ review tự động của Codex trên PR #3 và một review độc lập. Mỗi mục đã được
kiểm chứng trên code. Bảy test hồi quy mới đều **thất bại trên code trước bản sửa** và đạt sau bản sửa.

Audit theo yêu cầu, không tìm thấy lỗi mới có bằng chứng ở các phần sau:
- REST: auth, quyền, nonce, SQLi/XSS/CSRF, SSRF, rate limit.
- Ghi dữ liệu: preview/diff, phê duyệt, audit, rollback.
- Tương thích Yoast, Nguyen Ha VI–EN, `/` và `/en/`, hreflang/canonical.

Các phần này được kiểm lại bằng test HTTP (26/26) và integration (139/139).

---

## 3. Tính năng được nâng cấp

- **Trang đầu "Bảng điều khiển SEO"**, mặc định bằng tiếng Việt. Trước đây trang này chỉ là danh sách
  số liệu kỹ thuật. Nay nó có:
  - banner sức khỏe (bình thường / cần chú ý / lỗi), kèm trạng thái DB và câu SQL lỗi nếu có;
  - nút "Sửa cơ sở dữ liệu": chỉ tạo bảng/cột thiếu, chỉ quản trị viên dùng được;
  - **Bắt đầu nhanh trong 3 bước**:
    1. Kiểm tra hệ thống, gồm 9 mục: DB, quyền, plugin SEO, đa ngôn ngữ, cache, tài nguyên máy chủ,
       mô hình AI, chế độ chỉ đọc.
    2. "Phân tích thử 20 bài": chỉ đọc, có thanh tiến độ và kết quả thật.
    3. "Phân tích toàn website": nêu số trang trong phạm vi, thời gian và bộ nhớ **ước tính từ lần
       chạy thử đã đo**. Có tạm dừng, tiếp tục và hủy; báo lỗi rõ ràng. Không cần Qwen.
- Khi dashboard đang mở, nó tự đẩy tiến độ quét bằng các lượt ngắn có giới hạn và có lease chống
  chạy trùng. Trên site test ít truy cập, lượt thử 20 bài giảm từ 64,7 s (chờ WP-Cron) xuống 8,5 s.
- Mỗi job đo thời gian xử lý (`work_seconds`) và bộ nhớ đỉnh (`peak_memory`).
- Mọi phản hồi REST của plugin có `Cache-Control: no-store` và `X-LiteSpeed-Cache-Control: no-cache`,
  để LiteSpeed Cache hoặc CDN không lưu cache dữ liệu quản trị.

## 4. Tính năng mới

- **Thẻ sức khỏe SEO**:
  - số trang đã phân tích (VI/EN);
  - vấn đề theo mức ưu tiên và loại (lỗi đã xác minh, cảnh báo heuristic, gợi ý biên tập);
  - trang chưa có liên kết nội bộ trỏ tới;
  - trang có nguy cơ trùng từ khóa hoặc ý định tìm kiếm;
  - chất lượng nội dung;
  - lỗi kỹ thuật đã xác minh.

  **Không có "điểm SEO"** và không dự đoán thứ hạng Google.
- **"Việc nên làm trước"** (hàng đợi ưu tiên):
  - mỗi mục có URL, vấn đề, bằng chứng, độ tin cậy, lợi ích kỳ vọng và việc cần làm;
  - lọc theo ngôn ngữ, loại, mức ưu tiên, URL hoặc tiêu đề;
  - **xuất CSV** có BOM cho Excel và an toàn trước công thức (`'=…`);
  - nhóm chủ đề chưa có bài trụ cột được đưa vào danh sách (Pillar–Hub–Silo);
  - trùng lặp thông tin hợp lệ (khía cạnh khác nhau, bài trụ cột với bài hỗ trợ) **không** bị coi
    là việc cần làm.
- **So sánh với lần quét trước**: số vấn đề mới và số vấn đề đã hết, tính chính xác bằng mốc ID
  (watermark).
- **Bằng chứng chỉ đọc**: dấu vân tay (posts, postmeta, terms) được lấy khi bắt đầu quét và so lại
  khi kết thúc. Nếu có bài thay đổi trong lúc quét (người sửa bài, plugin khác), dashboard nêu rõ bài
  nào. Bản thân plugin không ghi vào đó.
- **Thẻ Qwen3-Embedding-0.6B** (tùy chọn): có nút kiểm tra thiết bị, **không tải mô hình**, cho kết
  luận WebGPU / WASM / không hỗ trợ. Thẻ nói rõ mô hình chỉ đo độ tương đồng nghĩa (embedding),
  **không viết văn bản**.
- **Danh sách quyền** của plugin: những gì được phép và những gì không bao giờ tự làm (sửa bài,
  301, slug, robots, canonical, hreflang, Yoast…).
- REST mới (quyền `sseo_read`): `GET /health`, `GET /priorities`, `GET /scans`. Agent đọc được báo
  cáo QA theo cấu trúc.
- CI mới:
  - integration trên **MariaDB 11.8** và **MySQL 8.4**;
  - job **"Production-like site"**: MariaDB 11.8, Yoast SEO, LiteSpeed Cache và Nguyen Ha VI–EN Core
    (bản pin `d8f6b58`). Job chạy nâng cấp từ beta.3 và dashboard trong Chromium;
  - workflow **release**: tag `v*` sẽ build ZIP hai lần (phải trùng SHA-256), chạy smoke trên
    MariaDB 11.8 và đăng GitHub pre-release kèm ZIP và SHA-256.

Chưa làm, hoặc chỉ có trên giao diện thiết kế:
- Xuất **XLSX**: chỉ có CSV, vì Excel mở trực tiếp được.
- **Preview Before Apply** cho chỉnh sửa hàng loạt: chỉ có preview/diff từng thay đổi (đã có từ
  beta.2).

---

## 5. Kết quả kiểm thử và môi trường

### Trên máy local (container 4 vCPU)

| Bộ test | Kết quả | Môi trường |
| --- | --- | --- |
| Unit PHPUnit | 22/22 | PHP 8.3 |
| Integration PHPUnit (WordPress thật) | **145/145** (2–3 skipped có điều kiện) | WP 7.1.3 test lib + MariaDB **10.11.14**, **11.8.9**, MySQL **8.4** |
| Nâng cấp MariaDB (`upgrade-mariadb.sh`) | **34/34** | beta.3 → beta.4 trên MariaDB 11.8.9 và 10.11.14 |
| Dashboard trong Chromium (`dashboard-e2e.mjs`) | **9/9** | WP 7.1.3, MariaDB 11.8.9. Thử 20 bài 8,5 s; toàn site 10,0 s có tạm dừng/tiếp tục; CSV 82 dòng; iPhone 13 giả lập 390 px; axe 0 lỗi |
| Smoke cài ZIP | **26/26** × 3 | WP 7.1.3, 6.9.10, 6.5.13 |
| HTTP agent / bảo mật | **26/26**, **26/26**, **22/22** | WP 7.1.3 và 6.9.10 (có MCP Adapter 0.7.1); 6.5.13 (không có Abilities API) |
| UI 12 trang (Playwright) | **11/11** | WP 7.1.3, axe 0 lỗi, iPhone 13 giả lập |
| Private worker (mô hình fixture) | 89/89 vector; chênh lệch 4,47e-08 | WP 7.1.3 |
| WPCS + PHPCompatibility (7.4+) | sạch | — |
| JS lint + unit | đạt | Node |

**Các bài test bắt buộc:**

| # | Test | Kết quả |
| --- | --- | --- |
| 1 | DB trống | `SchemaCompatibilityTest`, `ActivationMigrationTest` |
| 2 | DB 15/16 bảng | `SchemaCompatibilityTest`; nút sửa DB trên dashboard |
| 3 | Nâng cấp schema cũ | `SchemaCompatibilityTest`; `upgrade-mariadb.sh` phần B |
| 4 | MariaDB 11.8 và DB khác | 10.11, MySQL 8.4 |
| 5 | Không lặp migration | Đạt |
| 6 | Schema đầy đủ | Đạt |
| 7 | ≥ 20 bài VI–EN | 28 bài đăng trong `upgrade-mariadb.sh`; corpus VI/EN trong integration |
| 8 | Chạy thử 20 bài từ giao diện WP | `dashboard-e2e.mjs`, `AdminUiTest` |
| 9 | REST đọc | Đạt |
| 10 | Inventory, QA, links, clusters, cannibalization | Đạt |
| 11 | Hiệu năng và tiếp tục sau tạm dừng | Đạt; xem mục 6 |
| 12 | Không thay đổi nội dung | Snapshot độc lập + `ReadOnlyProof` |
| 13 | Tương thích và mobile | Đạt |
| 14 | PHPUnit, integration, lint, WPCS, bảo mật, ZIP smoke | Đạt |
| 15 | Có Yoast, LiteSpeed và VI–EN | Chỉ chạy được trong CI, xem bên dưới |

### Trong GitHub Actions

Kết quả CI trên commit cuối được ghi ở mục 7 khi workflow chạy xong.

Mạng của máy local chặn wordpress.org, nên Yoast SEO và LiteSpeed Cache chỉ cài được trong job CI
"Production-like site".

### Chưa xác minh

- **GPU thật**, **iPhone thật**: chỉ giả lập trong Chromium.
- **Website thật** blog.rentbikehanoi.com: chưa cài, theo đúng yêu cầu.
- ChatGPT hoặc client MCP thật: chưa kiểm thử xác thực với client thực tế, nên không tuyên bố đã
  tích hợp được.

---

## 6. Hiệu năng đã xác minh

Số liệu đo trên máy local 4 vCPU với dữ liệu **tổng hợp** (`tests/bench/generate.php`), không phải nội
dung thật. Production có khoảng 1.081 đối tượng, nên benchmark dùng 1.000 bài.

Benchmark A/B 1.000 bài (beta.4 và beta.3 chạy lần lượt trên cùng máy, cùng cấu hình, không có tải
khác) được ghi ở [`evidence/bench-1000-beta4.json`](evidence/bench-1000-beta4.json) và
[`evidence/bench-1000-beta3-ab.json`](evidence/bench-1000-beta3-ab.json):

Cấu hình: WP 7.1.3, PHP 8.3.6, MariaDB 10.11.14, máy 4 vCPU, 1.009 tài liệu trong phạm vi.

| Đo | beta.3 | **beta.4** |
| --- | --- | --- |
| Một lượt nền (tick) | 14,31 s, 59,3 MB | **14,33 s, 59,3 MB** |
| Kiểm kê + phân tích toàn bộ (1 tiến trình CLI) | 118,6 s | **118,5 s** |
| RSS tối đa | 107,2 MB | **107,5 MB** |
| Số phát hiện / postings | 16.462 / 323.749 | **16.462 / 323.749** (kết quả phân tích giống hệt) |
| REST `/status` (trung vị / p95) | 43 / 61 ms | 53 / 95 ms |
| REST `/inventory?limit=20` | 37 / 44 ms | 37 / 42 ms |
| REST `/qa?limit=20` | 30 / 37 ms | 32 / 52 ms |
| REST `/health` (mới) | — | 149 / 179 ms |
| REST `/priorities?limit=25` (mới) | — | 164 / 178 ms |
| REST `/scans` (mới) | — | 26 / 29 ms |

Kết luận: beta.4 **không làm chậm** việc phân tích. Thời gian, bộ nhớ và kết quả đều như beta.3.
`/health` và `/priorities` gộp nhiều số đếm nên mất khoảng 0,15 s ở quy mô 1.000 trang; chỉ người
quản trị hoặc agent gọi hai endpoint này, khách truy cập không bao giờ gọi.

Ghi chú: báo cáo audit trước ghi 94,2 s cho 1.000 bài. Con số đó đo trên một bộ dữ liệu sinh khác
(201.564 postings), nên không so trực tiếp được. Bảng trên chạy hai phiên bản lần lượt trên cùng
dữ liệu, cùng máy.

Lượt thử 20 bài và quét toàn site 37 trang qua REST (MariaDB 11.8.9):

| Lượt | Thời gian xử lý | Bộ nhớ đỉnh |
| --- | --- | --- |
| Thử 20 bài | 0,64 s | ≈ 54 MB |
| Toàn site 37 trang | 0,85 s | ≈ 54 MB |

Lượt thử 20 bài dùng để ước tính cho toàn site. Mỗi lượt nền (tick) có giới hạn thời gian; job có
lease và tiếp tục được sau khi bị dừng.

---

## 7. Commit cuối và trạng thái PR / merge

Được điền khi PR và CI xong; xem PR trên GitHub.

## 8. Link ZIP

Được đăng qua GitHub Release `v1.0.0-beta.4` kèm SHA-256 (workflow `release.yml`); xem mục cuối của PR.

---

## 9. Giới hạn còn lại

- Đây vẫn là bản **beta**. Nên thử trên bản sao staging trước khi bật trên site thật.
- Không có điểm SEO và không dự đoán thứ hạng. Lợi ích kỳ vọng chỉ là mô tả định tính.
- Cảnh báo cannibalization, độ phủ liên kết và chất lượng nội dung là **giả thuyết** cần biên tập
  viên xem. Chỉ các lỗi "đã xác minh" (ví dụ link hỏng) mới chắc chắn.
- Qwen3 trong trình duyệt phụ thuộc thiết bị. Trên CPU (WASM), cả site có thể mất hàng giờ. Phân tích
  SEO không cần Qwen.
- Ước tính thời gian quét toàn site là cận dưới, suy từ lượt thử 20 bài. Site có bài dài sẽ lâu hơn.
- Nếu site tắt WP-Cron, việc quét chỉ chạy khi dashboard đang mở hoặc qua WP-CLI.
- Không có plugin ngôn ngữ (hoặc Nguyen Ha VI–EN) thì mọi bài nhận ngôn ngữ mặc định của site. Plugin
  sẽ báo "ngôn ngữ phát hiện khác ngôn ngữ được gán" thay vì tự đổi.
- Xuất XLSX chưa có (chỉ CSV).

---

## 10. Cách kiểm tra trên blog.rentbikehanoi.com

Plugin vẫn đang tắt trên site thật. Việc cài là **quyết định của Tổng Thống**. Các bước đề xuất,
an toàn, theo thứ tự:

1. **Sao lưu** DB và thư mục `wp-content` (tính năng sao lưu của hosting, hoặc `wp db export`).
2. Tốt nhất là thử trên **bản sao staging**. Nếu thử thẳng trên production:
   **Plugins → Cài mới → Tải plugin lên** → chọn
   `semantic-seo-engine-vi-en-1.0.0-beta.4.zip` → **Thay thế bản hiện tại**.
   Plugin cũ (beta.3) đang tắt; dữ liệu 15 bảng cũ được giữ.
3. Kích hoạt plugin, rồi mở **Semantic SEO → Bảng điều khiển SEO**. Kết quả mong đợi:
   - banner xanh **"Plugin hoạt động bình thường"**;
   - bước 1 → "Xem chi tiết kiểm tra hệ thống" hiện "Database của plugin đã sẵn sàng —
     16/16 bảng, schema 4/4" kèm phiên bản MariaDB.

   Nếu banner đỏ, dashboard hiện câu SQL lỗi kèm mã lỗi và SQLSTATE; hãy gửi lại nguyên văn.
4. Kiểm tra bằng REST khi đang đăng nhập quản trị, mở:
   `https://blog.rentbikehanoi.com/wp-json/semantic-seo/v1/health`. Kết quả mong đợi:
   `"database": {"ready": true, "tables_present": 16, "tables_expected": 16, "missing": []}`.

   Có thể kiểm tra thêm `/wp-json/semantic-seo/v1/status`, mục `diagnostics.db_schema`, phải có
   `"installed": 4, "expected": 4, "ready": true, "last_error": ""`.
5. Kiểm tra vòng lặp đã hết: tải vài trang ngoài, rồi xem `/status` lần nữa. `last_error` vẫn rỗng,
   `retry_scheduled` là `false`, và option `sseo_migration_log` không có thêm dòng
   "missing tables: embeddings" (có thể xem bằng `wp option get sseo_migration_log`).
6. Bấm **"Phân tích thử 20 bài"**. Kết quả mong đợi: thanh tiến độ chạy tới 100%, hiện kết quả thật
   và dòng **"Đã xác minh chỉ đọc: 20 bài … giữ nguyên"**.
7. Nếu kết quả hợp lý, bấm **"Phân tích toàn website"**. Dashboard ghi trước số trang và thời gian
   ước tính; có thể tạm dừng hoặc hủy bất cứ lúc nào.
8. Xem **"Việc nên làm trước"**, lọc VI/EN, xuất CSV. Mọi chỉnh sửa vẫn phải được làm thủ công, hoặc
   qua preview → phê duyệt → áp dụng → có thể hoàn tác.
9. Nếu có vấn đề, chỉ cần **tắt plugin**. Không có bài viết, URL, Yoast, robots, canonical hay
   hreflang nào bị thay đổi.

Sau khi bật plugin, LiteSpeed Cache không cần cấu hình gì thêm: REST của plugin luôn trả
`no-store`/`no-cache`.
