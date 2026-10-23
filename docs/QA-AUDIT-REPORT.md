# Báo cáo QA — Kiểm định độc lập kiến trúc và bảo mật (1.0.0-beta.3)

Ngày 23/10/2026 · nhánh `claude/independent-audit`, tách từ `2cdd005` (bản 1.0.0-beta.2 của PR #1).

**Không thao tác nào được thực hiện trên website thật.** Mọi kiểm thử chạy trên site WordPress tạm
trong container, dùng một trong hai loại dữ liệu:

* **Fixture có nhãn:** `tests/fixtures/corpus.php`.
* **Dữ liệu tổng hợp (SYNTHETIC):** do `tests/bench/generate.php` hoặc `probes/gen_dups.php` sinh ra.

Không thay đổi URL, nội dung hay metadata của production. Không merge. Bằng chứng nằm trong
[`docs/evidence/`](evidence/) (các tệp `audit-*.json`, `bench-1000-beta3.json`, `run-all-summary.txt`)
và trong các bài test được nêu tên trong báo cáo.

## 1. Kết luận (trả lời Phần 4)

| Câu hỏi | Trả lời |
| --- | --- |
| Đã chứng minh bao nhiêu lỗi nghiêm trọng? | **24 lỗi nghiêm trọng** (8 P0 và 16 P1), cộng thêm 6 lỗi P2 và 3 lỗi P3. Tổng cộng 33 lỗi. Mỗi lỗi có một bài test **thất bại trên code cũ** ([`audit-regressions.json`](evidence/audit-regressions.json)). Riêng E1 (worker trình duyệt) được kiểm chứng bằng mô hình thật trong CI. |
| Đã sửa bao nhiêu? | **33/33.** Mỗi bản sửa có test hồi quy. Không xóa test nào, không nới ngưỡng nào. |
| Có kiến trúc nào cần thiết kế lại không? | **Có, hai chỗ.** (1) **Đường ghi thay đổi đã được thiết kế lại** trong đợt này: thay `wp_update_post()` bằng phép ghi so-sánh-rồi-ghi (compare-and-set) chỉ chạm `post_content`. (2) **Cách tính thống kê từ vựng (df/BM25) cần thiết kế lại cho site từ khoảng 10.000 bài trở lên.** Đo được thời gian phân tích tăng nhanh hơn tuyến tính (§6), và việc này **chưa làm** vì cần đổi schema. Riêng bước dựng cụm, bước làm vỡ giới hạn bộ nhớ của WP-Cron, **đã được sửa** (§3.6). |
| Test nào ĐẠT / LỖI / CHƯA CHẠY? | Xem §10. Tất cả bộ test chạy được đều ĐẠT, không có LỖI. CHƯA CHẠY: GPU thật, iPhone thật, ChatGPT hoặc ứng dụng MCP thật, staging của site thật, và benchmark 20.000 bài chạy trọn vẹn (chỉ chạy một phần, có ghi rõ). |
| Plugin đã an toàn để thử trên staging chưa? | **Có, với bản 1.0.0-beta.3, trên một bản sao staging có backup.** **Không** dùng bản 1.0.0-beta.2 để áp dụng thay đổi: các lỗi P0 của nó có thể làm đổi URL, gỡ xuất bản bài, hoặc ghi đè bài đang được biên tập. Production: chưa, vẫn là BETA. |
| Việc nào cần người vận hành quyết định? | Xem §11. |

## 2. Phương pháp

1. **Năm mảng rà soát độc lập:**
   * đồng thời / hàng đợi;
   * bảo mật (REST, Abilities, MCP, quyền);
   * toàn vẹn dữ liệu và đường ghi;
   * embedding Qwen3;
   * logic SEO.

   Mỗi mảng đưa ra *giả thuyết* lỗi.
2. **Mỗi giả thuyết phải được tái hiện bằng một bài test trên code cũ.** Không tái hiện được thì
   không gọi là lỗi.
3. Sửa lỗi, rồi chạy lại cùng bài test cho đến khi ĐẠT. Sau đó chạy lại toàn bộ:
   * PHPUnit;
   * phpcs;
   * JS;
   * e2e trên 3 phiên bản WordPress;
   * CI.
4. Kịch bản đối kháng ở Phần 2 chạy cả qua HTTP thật, dùng Application Password, trên site tạm.

## 3. Lỗi đã chứng minh và đã sửa

Cột *Test* là bài test thất bại trên code trước bản sửa và đạt sau bản sửa.

### 3.1 Đường ghi thay đổi (`ChangeExecutor`, `RollbackService`): 12 lỗi, commit `722c875`

Nguyên nhân gốc của các lỗi đánh dấu (★) là việc áp dụng một liên kết đã duyệt đi qua
`wp_update_post()`. Hàm này lưu lại *toàn bộ* bài viết qua `wp_insert_post()`, nên làm thay đổi cả
những trường mà thay đổi không hề chạm tới.

| # | Mức | Lỗi (tái hiện trên WP 7.1.3) | Test |
| --- | --- | --- | --- |
| 1 ★ | P0 | Slug không chuẩn (ví dụ `Thue-Xe-May.html`, có từ lúc import) bị sinh lại, **URL đổi**. Thay đổi bị đánh dấu `rollback_failed` và URL không được trả lại. | `WriteIntegrityTest::test_b` |
| 2 ★ | P0 | Page có slug trùng một ảnh đã bị đổi trang cha (re-parented) thì slug bị đổi thành `-2`, **URL đổi**. | `test_c` |
| 3 ★ | P0 | Bài đã xuất bản nhưng có ngày GMT ở tương lai bị chuyển sang `future`, tức là **bị gỡ xuất bản**. | `test_d` |
| 4 ★ | P0 | Bài không có chuyên mục bị gán *Uncategorized*. Thẻ bị gán lại theo tên (đổi tên thẻ, thẻ có dấu nháy). | `test_a` |
| 5 ★ | P0 | **Mất bản sửa:** biên tập viên lưu bài đúng lúc giữa bước kiểm tra stale và bước ghi thì bản của họ bị ghi đè. | `test_f` |
| 6 ★ | P0 | Bản lưu của biên tập viên diễn ra trong lúc các hook đang chạy bị bước tự hoàn nguyên ghi đè. | `test_g` |
| 7 ★ | P0 | Rollback (cũng qua `wp_update_post`) làm đổi URL khi slug đã bị sửa sau lúc áp dụng. | `test_h` |
| 8 ★ | P1 | `&#150;` ở chỗ khác trong bài bị `content_save_pre` viết lại, rồi hoàn nguyên thất bại. | `test_e` |
| 9 | P1 | Liên kết vẫn được ghi dù trang đích (hoặc trang nguồn) đã bị chuyển vào thùng rác sau khi duyệt. | `test_i` |
| 10 | P1 | Gán thuật ngữ mà theme dùng để dựng URL của CPT (`post_type_link`) làm **URL đổi** mà không ai phát hiện. | `test_j` |
| 11 | P1 | Tiến trình chết giữa lúc ghi và lúc cập nhật trạng thái thì thay đổi kẹt ở `applying` mãi mãi và không rollback được. | `test_l` |
| 12 | P2 | Hai lần rollback song song thì lần thứ hai đưa trạng thái `rolled_back` lùi về `applied`. | `test_k` (cài xen kẽ thật qua bộ lọc `query`) |

**Thiết kế mới:**

* Ghi bằng `UPDATE … SET post_content, post_modified(_gmt) WHERE ID = %d AND SHA2(CONVERT(post_content USING charset),256) = <base hash>`.
  * Nếu 0 dòng bị ảnh hưởng thì nghĩa là có người vừa sửa bài: không ghi gì, và đề xuất chuyển sang trạng thái *stale*.
* Đọc lại đúng các byte vừa lưu **trước khi** bất kỳ hook nào chạy.
* Sau đó kích hoạt các hook mà `wp_insert_post()` thường kích hoạt, theo đúng thứ tự, để cache,
  plugin SEO và revision vẫn được cập nhật: `pre_post_update`, `edit_post`, `post_updated`,
  `save_post_{type}`, `save_post`, `wp_insert_post`, `wp_after_insert_post`.
* Nếu có hook lỗi hoặc có trường khác (kể cả permalink) bị đổi: chỉ hoàn nguyên **phép ghi của chính
  plugin**, cũng bằng compare-and-set. Bản sửa của người khác luôn được giữ nguyên.
* Chuyên mục: chỉ thêm hoặc bớt đúng một thuật ngữ.
* Trước khi ghi, kiểm tra lại trong khóa: trang nguồn và trang đích còn xuất bản, và URL đích chưa đổi.
* Rollback kiểm tra lại trạng thái trong khóa.
* Thay đổi `applying` bị bỏ dở được đối soát lại từ giá trị đang lưu.
* Không còn tạm tắt kses nữa.

`ChangeExecutorSafetyTest` vẫn giữ nguyên 4 cam kết của nó. Hai ca trước đây tiêm lỗi qua
`wp_insert_post_data` (bộ lọc này không còn được gọi) nay tiêm qua `pre_post_update` và `save_post`.

### 3.2 Chỗ chèn liên kết (`HtmlScanner`, `AnchorFinder`, `Operations`): 6 lỗi, commit `104c792`, `c9f46bf`

| # | Mức | Lỗi | Test |
| --- | --- | --- | --- |
| 13 | P0 | Gặp `alt='Hanoi's bikes'` … `title='Bob's'`, trình quét đi vào chế độ "trong dấu nháy" ngay tại dấu nháy đơn của từ `Hanoi's`. Kết quả là nó nuốt mất thẻ `<a>` đang mở, coi chữ bên trong liên kết là chữ thường, và **lồng liên kết trong liên kết**. | `AnchorSafetyTest` |
| 14 | P1 | Anchor nằm trong URL của block embed, hoặc trong một URL trần đứng riêng một dòng: oEmbed bị hỏng. | `AnchorSafetyTest` |
| 15 | P1 | Chữ trong block được dựng lại từ thuộc tính JSON (Yoast FAQ, block tùy biến, HTML tùy biến) vẫn được coi là sửa được. Hậu quả: trình soạn thảo báo lỗi block, hoặc liên kết bị mất. | `AnchorSafetyTest` |
| 16 | P2 | Anchor cắt ngang tên riêng: "thuê xe máy Hà" bên trong "thuê xe máy Hà Đông". | `AnchorSafetyTest` |
| 17 | P3 | `mb_strtolower('İ')` làm thay đổi độ dài chuỗi, khiến anchor bị lệch vị trí. | `AnchorSafetyTest` |
| 18 | P3 | Chữ nằm giữa `[caption]…[/caption]` không được bảo vệ. | `AnchorSafetyTest` |

Thêm một lớp phòng thủ: `insert_link()` phân tích lại kết quả và yêu cầu có **đúng một** liên kết mới
nằm tại đúng offset.

### 3.3 Job, hàng đợi, embedding: 7 lỗi, commit `981904c`, `609999b`, `47da7f3`

| # | Mức | Lỗi | Test |
| --- | --- | --- | --- |
| 19 | P1 | Lỗi DB khi ghi kết quả (lock timeout, đĩa đầy…) bị bỏ qua: job vẫn báo `completed`, phát hiện bị mất một cách im lặng. | `DatabaseFailureTest` 2/2 (lỗi thật của MariaDB) |
| 20 | P1 | Tick bị fatal error (hết bộ nhớ, quá thời gian) không bao giờ được tính là một lần thử: job chạy lại mãi và chặn mọi job cùng loại. | `JobRecoveryTest` |
| 21 | P1 | Một mục embedding bị xếp hàng lại với nội dung mới đúng lúc đang ở lần thử cuối thì kẹt `pending` mãi mãi, và job embed không bao giờ xong. | `JobRecoveryTest` |
| 22 | P1 | Worker trình duyệt cắt văn bản dài nhưng làm mất token kết thúc. Pooling đọc token cuối, nên vector khác với pipeline tham chiếu. Đã chuyển sang phiên bản tiền xử lý `p2`; model key mới buộc tính lại vector. | Mô hình thật trong CI: A3 cosine ≥ 0,99 có tính `long_text` (§7). Test JS cho hàm mới. |
| 23 | P2 | WP-CLI chạy hết số tick trong vài giây khi WP-Cron đang giữ lease, rồi báo "chưa xong". | `JobRecoveryTest` |
| 24 | P3 | Lúc nhận việc, worker được giao chữ mới nhưng kèm hash của chữ cũ, nên vector bị từ chối là stale. | `JobRecoveryTest` |
| 25 | P1 | Ma trận SEO và trang Tổng quan không tính trang trùng lặp hoàn toàn vào cột rủi ro chồng lấn. | `MatrixRiskColumnsTest` |

### 3.4 Bảo mật: 2 lỗi, commit `e020a4f`

| # | Mức | Lỗi | Test |
| --- | --- | --- | --- |
| 26 | P2 | Khi bật "tính cả bản nháp", agent chỉ có quyền đọc vẫn thấy tiêu đề và URL của bài nháp/bài riêng tư có liên kết tới trang (`links_in`, `related`). | `PrivacyScopeTest` |
| 27 | P2 | Nội dung bài có mật khẩu bị lưu thành chunk, được phân tích, rồi giao cho agent và worker embedding. | `PrivacyScopeTest` |

Không tìm thấy lỗi P0/P1 nào về phân quyền: xem §5, kịch bản "người dùng quyền thấp".

### 3.5 Logic SEO: 4 lỗi, commit `c9f46bf`

| # | Mức | Lỗi | Test |
| --- | --- | --- | --- |
| 28 | P1 | Quy tắc `pillar_supporting` chạy **trước** khi xét focus keyword và dữ liệu Search Console thật. Hậu quả: cạnh tranh có bằng chứng ở mức truy vấn bị hạ thành "thông tin". | `SeoLogicTest` |
| 29 | P1 | "giá rẻ" có hai âm tiết nên bị tính là "thu hẹp chủ đề": "Thuê xe máy Hà Nội giá rẻ" bị coi là bài hỗ trợ cho "Thuê xe máy Hà Nội". | `SeoLogicTest` |
| 30 | P1 | Hai trang đang cạnh tranh nhau được gợi ý liên kết exact-match **theo cả hai chiều**. Nay chỉ giữ liên kết hướng lên pillar/hub. | `SeoLogicTest` |
| 31 | P2 | Liên kết nằm trên bài nháp/riêng tư vẫn được tính là liên kết trỏ đến, che mất trang mồ côi. | `SeoLogicTest` |

Đánh giá trên bộ dữ liệu có nhãn **không đổi** sau các bản sửa:

* Liên kết: P@3 0,229 / R@3 0,244.
* Bài liên quan: P@3 0,604.
* Chồng lấn: tìm thấy 3/3 cặp kỳ vọng, 0 dương tính giả trên 7 cặp đối chứng.

### 3.6 Site lớn: 2 lỗi, commit `a55bd3e`, `674d72a`

| # | Mức | Lỗi | Test / bằng chứng |
| --- | --- | --- | --- |
| 32 | P1 | Bước dựng cụm đọc **mọi** tài liệu cùng profile đầy đủ vào bộ nhớ một lúc, và chạy 2 truy vấn đếm liên kết cho mỗi thành viên. Ở 20.000 bài: 159 giây, đỉnh 759 MB, và **fatal "Allowed memory size of 268435456 bytes exhausted"** khi chạy như một request WP-Cron giới hạn 256 MB. Phân tích nền vì thế không bao giờ hoàn tất. | `ClusterScaleTest` (48 truy vấn so với tối đa 16). Ở 20.000 bài: **10,7 giây, 156 MB** dưới giới hạn 256 MB; cụm, cụm của từng tài liệu trong 20.010 tài liệu, và bằng chứng pillar đều **giống hệt (MD5)** bản cũ ([`audit-cluster-step-20000.json`](evidence/audit-cluster-step-20000.json)) |
| 33 | P1 | Tick chạy lô cố định 25 mục, chỉ kiểm tra ngân sách thời gian giữa các lô. Ở 20.000 bài, một tick ngân sách 20 giây chạy tới **68 giây**, đủ để giới hạn thời gian request của host ngắt nó trước khi tiến độ kịp được lưu. | `TickBudgetTest` (5,00 giây cho ngân sách 1 giây trước khi sửa). Ở 20.000 bài: 20,5–22,1 giây mỗi tick 20 giây, đỉnh 54 MB |

## 4. Giả thuyết chưa sửa (có lý do)

| Hạng mục | Trạng thái | Lý do / đề xuất |
| --- | --- | --- |
| Anchor cắt ngang từ ghép: "thuê xe máy" bên trong "thuê xe máy **điện**" (P2) | **Đã tái hiện**, chưa sửa | Cần từ điển từ ghép tiếng Việt. Bản xem trước hiển thị `…[thuê xe máy] điện…` và luôn cần người duyệt. Cần quyết định có thêm từ điển hay không. |
| Cờ "dirty" bị ghi đè khi bài được sửa đúng lúc job incremental đang chạy (P2) | **Đã tái hiện** (probe F5), chưa sửa | Phân tích của bài đó cũ cho đến lần sửa sau hoặc lần chạy đầy đủ tiếp theo. Cách sửa đúng cần cột thời điểm "dirty" (đổi schema). |
| Tốc độ phân tích cặp ở 20.000 bài (P1 cho site rất lớn): CLI 0,52 giây/bài; **WP-Cron 5 giây/bài**, vì mỗi tick là một request mới và phải dựng lại cache df từ đầu. Ước tính khoảng 28 giờ tick nền. | **Đo được** (§6), chưa sửa | Cần thiết kế lại phần thống kê df: bảng df theo locale, cập nhật khi đánh chỉ mục, và phép join hẹp hơn. **Blog thật có khoảng 1.083 URL** (census), thuộc nhóm 1.000 bài, nơi hiệu năng ổn. Bộ nhớ 780 MB đo được trước đây của tiến trình CLI chủ yếu đến từ bước dựng cụm (#32, đã sửa). |
| Lease embedding cố định 180 giây, không gia hạn, tải lên theo kiểu tất cả hoặc không (P2). Self-test hết hạn sau 24 giờ. Vector hằng số vẫn được chấp nhận (P3). | Chưa sửa | Thiết bị chậm có thể phải làm lại một lô. Tài khoản worker gian lận vẫn có thể gửi vector sai: đây là giới hạn đã ghi trong `SECURITY.md`. |
| Agent đọc được job của người khác; phát hiện của bài đã bị gỡ xuất bản vẫn đọc được cho đến lần phân tích sau; analyst mở lại được phát hiện đã bỏ qua (P3) | Chưa sửa | Rủi ro thấp: chỉ là siêu dữ liệu, trong phạm vi vai trò đã được cấp. |
| Gợi ý "có thể là bản dịch" cho bài EN khai báo độc lập (P2). Một truy vấn GSC duy nhất cũng bị coi là cạnh tranh cao (P2). Broken link báo sai khi post type đã bị xóa hoặc slug đã vào thùng rác (P2). Template meta của Rank Math không được đọc (P2). | Chưa sửa | Cần dữ liệu thật và quyết định nghiệp vụ. Tất cả chỉ là *gợi ý*, không tự ghi gì. |
| Nhóm chồng lấn gộp bắc cầu (P3) | **Quan sát được**: 1.500 bài gần trùng tạo thành **1 nhóm** gồm 37.362 cặp | Đúng về mặt dữ liệu, nhưng khó duyệt. Đề xuất: chia nhỏ theo trang đại diện. |
| Ghi trùng chunk/link/cluster khi hai lượt chạy chồng nhau (P2, do reviewer nêu) | **Chưa tái hiện được độc lập** | Không kết luận là lỗi. |

## 5. Phần 2: kịch bản đối kháng

| Kịch bản | Cách kiểm | Kết quả |
| --- | --- | --- |
| Hai AI hoặc 8 operator cùng áp dụng một đề xuất đã duyệt | HTTP thật, 8 yêu cầu song song, beta.3 ([`audit-concurrency-http.json`](evidence/audit-concurrency-http.json)) | 1 lần 200, 7 lần 409 (`sseo_busy` / `sseo_not_approved`). Đúng 1 dòng thay đổi, nội dung chỉ đổi 1 lần. ĐẠT |
| Hai agent cùng tạo job | 12 yêu cầu song song (khóa idempotency khác nhau / trùng nhau) | Chỉ tạo đúng 1 job. Trùng khóa thì trả về cùng job id. Các yêu cầu thừa nhận 409/429. ĐẠT |
| Worker hoặc quota dừng giữa chừng | Lease hết hạn sau fatal error, tiến trình chết sau khi ghi | Lần thử được đếm, job `failed` sau `max_attempts`. Thay đổi `applying` được đối soát lại (`JobRecoveryTest`, `WriteIntegrityTest::test_l`). ĐẠT sau khi sửa |
| DB hoặc API timeout | Bộ lọc `query` làm câu lệnh ghi thất bại thật ở MariaDB | Job không báo xong; lỗi tạm thời được thử lại và kết quả đầy đủ (`DatabaseFailureTest`). ĐẠT sau khi sửa |
| Bản ghi bị đổi sau khi đã duyệt | Sửa trước khi apply; sửa chen vào giữa lúc kiểm tra và lúc ghi; sửa trong lúc hook chạy | Bị từ chối (stale), không ghi đè gì (`ChangeLifecycleTest`, `WriteIntegrityTest::test_f/g`). ĐẠT sau khi sửa |
| Dữ liệu embedding sai hoặc không nhất quán | Sai kích thước, NaN, không chuẩn hóa, sai model, phát lại lease; chữ đổi trong lúc đang giữ lease | Bị từ chối (`EmbeddingQueueTest`, http-agent). Hash được gắn lại với đúng chữ. Vector hằng số vẫn lọt qua (§4). ĐẠT, có giới hạn |
| Hàng nghìn bài gần trùng | 1.500 bài tổng hợp từ cùng một khuôn ([`audit-neardup-1500.json`](evidence/audit-neardup-1500.json)) | Phân tích hết 276 giây; RSS 167 MB; 37.362 cặp (24,9 cặp/bài, bị chặn bởi số ứng viên, so với 1,12 triệu nếu so mọi cặp). Ma trận sắp theo rủi ro mất 1,4 giây. ĐẠT, xem §4 về nhóm bắc cầu |
| Plugin khác can thiệp hook WordPress | Exception trong `pre_post_update` / `save_post`; plugin khác đổi tiêu đề; thử với WooCommerce, Yoast và Polylang | Không ghi, hoặc hoàn nguyên đúng phép ghi của plugin, có báo cáo (`ChangeExecutorSafetyTest`). CI chạy cùng các plugin: ĐẠT |
| Người dùng quyền thấp thử thao tác quản trị | http-agent trên 3 phiên bản WP | Reader không phân tích được; analyst không apply được; agent không bao giờ approve được; admin dùng Application Password bị coi là agent; không có route REST nào để duyệt; chặn SSRF, SQLi, XSS; 413, 429. ĐẠT |

## 6. Hiệu năng (dữ liệu SYNTHETIC)

Môi trường: 4 vCPU, 16 GB RAM, MariaDB 10.11.14, PHP 8.3.6, WP 7.1.3. Tái lập bằng lệnh
`tests/bench/run-bench.sh <số bài> <cổng>` (có cờ `SSEO_DB_NAME`).

| Số bài | Kiểm kê + phân tích (CLI) | RSS tối đa | REST `/status` trung vị | Nguồn |
| ---: | --- | ---: | ---: | --- |
| 1.000 (beta.2) | 104,3 giây | 124 MB | 41 ms | `bench-1000.json` |
| 1.000 (**beta.3**) | **94,2 giây** | 131 MB | 49 ms | [`bench-1000-beta3.json`](evidence/bench-1000-beta3.json) |
| 6.000 (beta.2) | 1.852,6 giây | 295 MB | 108 ms | `bench-6000` (PR #1) |
| 6.000 (**beta.3**) | BENCH6_BETA3 | | | |
| 20.000 (một phần) | Kiểm kê 301 giây; phân tích cặp **0,52 giây/bài** qua CLI (trung vị, đo riêng 150 bài) ⇒ ước tính khoảng **3 giờ** cho 20.010 bài; qua tick kiểu WP-Cron **5 giây/bài** | 73 MB (150 bài); **773–791 MB** với tiến trình CLI chạy 42 phút trước khi sửa #32; bước dựng cụm 759 MB ⇒ 156 MB | 950 ms *(đo trong lúc phân tích đang chạy)* | [`audit-bench-20000-partial.json`](evidence/audit-bench-20000-partial.json), [`audit-cluster-step-20000.json`](evidence/audit-cluster-step-20000.json) |

**Kết luận hiệu năng:**

* Ở 6.000 bài, mỗi bài phân tích hết khoảng 0,17 giây; ở 20.000 bài là 0,52 giây. Tức là thời gian
  phân tích tăng nhanh hơn tuyến tính.
* Ở 20.000 bài, khoảng 0,52 giây mỗi bài đi vào hai truy vấn:
  * **Ứng viên BM25:** 0,35 giây/bài. Truy vấn này join `postings` với `documents`, phải tra từng
    dòng `documents` rất rộng.
  * **Đếm df:** 0,17 giây/bài.
* Qua WP-Cron, mỗi tick là một request mới nên phải dựng lại cache df từ đầu: khoảng 5 giây/bài ở
  20.000 bài. Sau bản sửa #33, mỗi tick giữ đúng ngân sách thời gian, nhưng tổng thời gian chạy nền
  vẫn dài.
* Đây là giới hạn kiến trúc nêu ở §1: cần một bảng df riêng và một phép join hẹp hơn. Bước dựng cụm
  (từng không chạy xong được dưới WP-Cron) đã được sửa (#32).
* Bản 20.000 bài **không được chạy trọn**: đã dừng chủ động, và mọi con số trên đều là số đo thật,
  phần ước tính có ghi rõ là ước tính.

## 7. Qwen3-Embedding-0.6B

* **Pipeline:**
  * Worker trình duyệt: Transformers.js, chạy WASM hoặc WebGPU.
  * Worker Python riêng: ONNX Runtime.
  * Tệp mô hình được ghim phiên bản và kiểm tra SHA-256.
  * Máy chủ tự kiểm tra self-test của thiết bị trước khi nhận vector.
* **Sửa E1 (#22):** khi cắt văn bản dài, token kết thúc nay được giữ lại. Văn bản tiếng Việt nhiều chữ
  số bị tách thành rất nhiều token (Qwen tách từng chữ số), nên dễ chạm giới hạn độ dài.
  * Workflow mô hình thật thêm `long_text` vào phép so sánh A3 (trình duyệt so với Python, yêu cầu
    cosine ≥ 0,99).
  * Kết quả trên beta.3: **ĐẠT** (run [39962364490](https://github.com/thuexemayhanoi/plugin-auto/actions/runs/39962364490), commit `01bd4bc`, tiền xử lý `p2`):
    * trình duyệt so với Python ONNX Runtime: cosine nhỏ nhất **0,999269 trên 9 văn bản** (có `long_text`);
    * self-test q4f16: |Δ| 0,0054 (dung sai 0,03); fp16: |Δ| 0,0005;
    * đánh giá hybrid: liên kết R@3 0,702, P@3 0,521; chồng lấn 30 cặp, 0 dương tính giả, 3/3 cặp kỳ vọng; cặp bản dịch: precision 1,0, recall 0,778 ở ngưỡng 0,70;
    * worker riêng dùng mô hình thật: 89/89 vector, Δ 0,0 so với tính lại độc lập.
* **VI/EN:** chỉ so sánh trong cùng locale. Cặp có thể là bản dịch được tính riêng bằng phương pháp
  mutual-best, với ngưỡng 0,70.
* **Giới hạn thiết bị:**
  * Trên iPhone (WebKit), WebGPU không ổn định; WASM chạy 1 luồng nếu trang không cross-origin
    isolated. Giao diện có cảnh báo.
  * **GPU thật và iPhone thật: CHƯA KIỂM CHỨNG.** Chỉ có giả lập viewport trong Chromium.

## 8. Tương thích

* **WordPress 6.5.13, 6.9.10, 7.1.3:**
  * smoke-test cài từ ZIP: ĐẠT;
  * agent qua HTTP: ĐẠT;
  * CI tích hợp trên PHP 7.4, 8.1, 8.3, 8.4: ĐẠT.
* **PHP:** `php -l` trên 7.4 đến 8.4 và PHPCompatibilityWP (7.4+) đều đạt.
* **Plugin khác:**
  * WooCommerce, Yoast SEO và Polylang cùng cài trong CI: front end không đổi.
  * Ngôn ngữ của blog dùng Nguyen Ha VI-EN: chạy `NhVienLanguageTest` trong CI.
  * MCP Adapter 0.7.1: chạy http-agent.
* **Đa ngôn ngữ:** Polylang thật và plugin ngôn ngữ của blog. Bài khai báo độc lập không bao giờ bị
  báo "thiếu bản dịch".

## 9. Toàn vẹn dữ liệu, migration, rollback, backup

* **Bảng của WordPress chỉ được ghi ở một chỗ duy nhất:** khi người duyệt cho phép một thay đổi. Phép
  ghi đó chỉ đụng `post_content` cùng hai cột ngày sửa, hoặc thêm/bớt đúng một thuật ngữ. Smoke-test
  xác nhận việc kích hoạt và phân tích không ghi gì vào WordPress.
* **Migration:** schema 3. Migration không đổi trong đợt này. `ActivationMigrationTest` kiểm tra bốn điều: migration chạy lặp lại vẫn giữ dữ liệu; nâng cấp từ schema cũ khôi phục đủ cột; nâng cấp từ schema 1 thêm đủ index; nâng cấp bị gián đoạn được chạy tiếp sau khi khóa hết hạn.
* **Rollback:**
  * Chế độ exact: khôi phục đúng từng byte (UI e2e `restored byte-identical`, `WriteIntegrityTest::test_e`).
  * Chế độ surgical: dùng khi bài đã bị sửa sau đó.
  * Không bao giờ ghi đè bản sửa của người khác.
* **Backup:** plugin không tự backup. Cần backup database trước khi duyệt bất kỳ thay đổi nào (xem §11).

## 10. Kết quả kiểm thử

| Bộ kiểm thử | Nơi chạy | Kết quả |
| --- | --- | --- |
| PHP unit | cục bộ | **ĐẠT** 22/22 (93 assertion) |
| PHP integration | cục bộ, WP 7.1.3 + MariaDB | **ĐẠT** 114 test, 1.745 assertion. 2 test bỏ qua cục bộ (`HybridEvaluationTest` cần mô hình thật; `NhVienLanguageTest` cần plugin ngôn ngữ), cả hai chạy trong CI |
| phpcs (WPCS + PHPCompatibilityWP) | cục bộ + CI | **ĐẠT**: 0 lỗi, 0 cảnh báo |
| JS (node:test) | cục bộ + CI | **ĐẠT** 21/21 |
| Worker fixture (Chromium, WASM) | cục bộ | **ĐẠT** 10/10 |
| Smoke-test cài từ ZIP (7.1.3 / 6.9.10 / 6.5.13) | cục bộ | **ĐẠT** 27/27, 27/27, 26/26 |
| Agent qua HTTP (7.1.3 / 6.9.10 / 6.5.13) | cục bộ | **ĐẠT** 26/26, 26/26, 22/22 |
| Worker Python riêng | cục bộ | **ĐẠT** 89/89 vector, Δ 4,5e-8 |
| Giao diện quản trị (Playwright, axe, giả lập mobile) | cục bộ | **ĐẠT** 11/11 |
| Probe đồng thời qua HTTP | cục bộ | **ĐẠT** (§5) |
| CI 18 job (lint ×6, unit ×4, integration ×4, WPCS, JS, ZIP, coexistence) | GitHub Actions | CI_RESULT |
| Mô hình Qwen3 thật (run 39962364490) | GitHub Actions | **ĐẠT** (9/9 kiểm tra trình duyệt, hybrid, worker riêng 89/89) |
| Benchmark 20.000 bài chạy trọn | — | **CHƯA CHẠY TRỌN** (dừng chủ động, §6) |
| GPU thật, iPhone thật, ChatGPT/MCP client thật, staging của site thật | — | **CHƯA KIỂM CHỨNG** |

ZIP: `dist/semantic-seo-engine-vi-en-1.0.0-beta.3.zip`, 111 tệp, SHA-256
`137598025416b3b8fcc9812fbdadf8685a10ab2831d742f3894932dcaee3d040`. Build lần hai cho cùng SHA.

## 11. Việc cần người vận hành quyết định

1. **Merge:** PR của đợt audit trỏ vào nhánh PR #1. Việc merge PR #1 vào `main` thuộc quyền của chủ
   repository. Trong bài thử này không có PR nào được tự merge.
2. **Staging:** chọn bản sao staging của site và lịch backup database trước khi duyệt thay đổi đầu tiên.
3. **Site lớn (từ khoảng 10.000 bài):** chấp nhận lần phân tích đầu chạy nền nhiều giờ, hay đầu tư
   thiết kế lại phần df/BM25 (đổi schema) trước khi dùng.
4. **Từ ghép tiếng Việt cho anchor:** có thêm từ điển hay chỉ dựa vào người duyệt bản xem trước.
5. **Cờ "dirty" khi sửa trong lúc job chạy:** có chấp nhận đổi schema (thêm cột thời điểm) để sửa không.
6. **Vector embedding:** sau khi nâng cấp phải tính lại vector bằng worker trình duyệt (model key `p2`).
   Nếu chỉ dùng worker Python thì không ảnh hưởng.
