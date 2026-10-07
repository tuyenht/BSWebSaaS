# README — D1-2 · Trình đơn (khớp thiết kế đã có với dữ liệu thật)

## Tổng quan
Sửa tại chỗ `Trình đơn.dc.html` (thiết kế owner đã duyệt). Giữ nguyên bố cục, dữ liệu mẫu, kéo-thả + nút lên/xuống, nguồn, biểu tượng, mô tả, bảng lớn, nút nổi bật, nhãn có hạn, thiết bị, ẩn mục, tự thêm trang, "Hệ thống tự kiểm", Thùng rác 30 ngày, nhân bản, Dịch, xem trước, hoàn tác. Lưu = hiệu lực ngay, không nháp/duyệt.
Gộp luồng phụ D1-6: README D1-6 › "Gộp vào tệp chung" ghi "Không áp dụng" → bỏ qua.

## File
- `Trình đơn.dc.html` — màn (registry `menus`, kind `mount`, `#/site/menus`; không đổi Shell).
- `TODO.md` · `LESSONS.md` · `SaaS Starter - Bản đồ màn hình.md` (dòng 16).

## Mức hoàn thiện
Sửa tại chỗ 6 mục của prompt. Lượt kế tiếp: **D1-3**.

## Đã làm
1. **Trục ngôn ngữ** trong "Vị trí trên website": dưới mỗi vị trí đang có trình đơn (trừ vị trí "Giống trình đơn chính"), mỗi ngôn ngữ ngoài bản nguồn hiện đúng 1 trong 3 trạng thái:
   - "Dùng trình đơn chung — nhãn theo <ngôn ngữ>" (gán cùng trình đơn; có dòng "N nhãn còn trống — website lấy tên hiện tại của trang nguồn").
   - "Dùng trình đơn riêng: “<tên>”" — chọn trình đơn khác, hoặc "Sao chép thành trình đơn riêng rồi Dịch" (sao chép, dịch nhãn trống theo hạn ngạch §9, chuyển sang bản đó).
   - "Chưa gán — website đang hiện trình đơn tiếng Việt bằng tiếng Việt" + chip "theo Tiếng Việt" + nút "Dùng trình đơn chung".
   Ngôn ngữ bật sau (Tweak 8 ngôn ngữ: 한국어…) rơi vào "Chưa gán" và "Hệ thống tự kiểm" nhắc (nút "Dùng trình đơn chung" cho cả loạt + "Xem vị trí"). Website 1 ngôn ngữ → ẩn hẳn trục. Đổi trạng thái = 1 thay đổi chưa lưu, hoàn tác được. Trình đơn đang gán cho một ngôn ngữ không xóa được và không bị nhắc "chưa gắn vào chỗ nào".
2. **Chip nguồn theo loại** (một khuôn): "Trang chưa đăng" · "Sản phẩm chưa đăng" · "Chuyên mục chưa có bản 日本語" (vàng, icon mắt gạch) · "Trang không còn" · "Sản phẩm không còn" (đỏ, icon cảnh báo). Tooltip/aria: "Website đang tự ẩn mục này — khách không thấy". Nút tới nguồn: "Mở trang / Mở sản phẩm / Mở chuyên mục" trong "⋯" và trong "Hệ thống tự kiểm" (`bs-goto` → `pages.edit` / `catalog.list` / `posts.cats`, `params.slug` = đường dẫn công khai, không id). Chữ mới: "Website đang tự ẩn mục này cho tới khi trang được đăng"; nút "Ẩn mục này" → "Giữ ẩn cả sau khi đăng". Xem trước "Khách sẽ thấy" bỏ mục website tự ẩn. Dữ liệu mẫu thêm 2 mục ở Trình đơn chân trang: "Bánh răng côn" (sản phẩm chưa đăng), "Phụ tùng thay thế" (sản phẩm đã xóa); chuyên mục "Chế tạo khuôn mẫu" chưa có bản 日本語.
3. **Chữ**: "Lấy lại" → "Khôi phục"; "xuất bản" → "đăng"; "toà" → "tòa". Grep tệp: 0 "Lấy lại", 0 "xuất bản", 0 "lên sóng", 0 "khách bấm vào sẽ không xem được", 0 `style-focus-within`.
4. Bảng "Thiết kế có · backend chưa có" ở dưới; ghi chú thiết kế trên màn gom dưới Tweak "Hiện ghi chú thiết kế" (`designNotes`, mặc định bật).
5. **Focus**: bỏ `.mn-inp:focus` tự vẽ (đã có luật chung); ô tìm có khung bao giữ `data-focus-wrap`; ô chọn ngôn ngữ mới là `.mn-inp` → luật ô nhập chung.
6. **Token**: ~90 mã màu → token theo vai trò (chữ chính `--color-heading`/`--color-body-text`, phụ `--color-body-muted`, trạng thái `--color-admin-*-text`, nền đặc `--color-admin-primary`/`-danger-solid`, viền ô nhập `--color-border-input`, nền nhạt `color-mix` từ token). Tên mục trong cây, ô chọn ngôn ngữ, "Còn … từ AI", "Đang dùng ở …" đều qua token. Toast đảo theo token. Mục ẩn: chip "Đang ẩn" + icon mắt gạch (không chỉ màu xám). Placeholder: "Ví dụ: Bảng giá", "Ví dụ: /bang-gia" + nhãn ô hiện + dòng gợi ý dưới ô.
   **Ngoại lệ có chủ đích:** khung "Khách sẽ thấy" mô phỏng website nên giữ màu website ở cả hai chế độ (nền `#fff`, chữ `#0f172a`/`#334155`, nút nổi bật `#f59e0b`).
- Sửa kèm (nhận xét 06/10): `pointer: coarse` hoặc khung ≤620px → ô tìm, chip lọc `.mn-tab`/`.mn-sl`, nút ↑ ↓ ⋯ ≥ 44px, khoảng cách dòng 8px; chip xuống dòng. "Gỡ khỏi trình đơn" đứng cuối "⋯" sau vạch ngăn, chữ + icon `--color-admin-danger-text`.
- Khi nhúng: "Xem trên website" ở đầu thẻ "Khách sẽ thấy" (Shell ẩn header của màn).

## Ánh xạ dữ liệu
| Thiết kế | Repo |
|---|---|
| Trình đơn (`name`, mọi ngôn ngữ) | `database/migrations/2003_01_01_000000_create_menu_tables.php` › `menus` (`locales` NULL = mọi ngôn ngữ) |
| Vị trí × ngôn ngữ → 1 trình đơn (trục ngôn ngữ) | `menu_assignments` · `app/Modules/Cms/Actions/Menus/AssignMenu.php` (mỗi ngôn ngữ một dòng) |
| "Chưa gán" → hiện bản nguồn bằng nhãn bản nguồn | `app/Modules/Cms/Navigation/MenuResolver.php:68-83` |
| Mục: cha, thứ tự, loại (trang/bài · chuyên mục · sản phẩm · trang chủ · địa chỉ ngoài), mở tab mới | `menu_items` + `2019_01_01_000000_allow_menu_links_to_products.php` |
| Nhãn theo ngôn ngữ; trống → tên hiện tại của nguồn | `menu_item_translations` |
| Chip nguồn (chưa đăng / Thùng rác / đã xóa / chuyên mục chưa dịch) → website tự ẩn | `MenuResolver.php` |
| Tối đa 5 cấp | `MenuChangeRejectedException` `depth_exceeded` |
| Vùng "primary" duy nhất đang dựng | `StorefrontController.php:79` |
| Màn admin còn "sắp có" | `routes/web.php:237` |

## Mã lý do lỗi (`app/Modules/Cms/Exceptions/MenuChangeRejectedException.php`)
| Mã | Câu trên màn |
|---|---|
| name_invalid | Tên trình đơn chưa hợp lệ — đặt tên từ 1 tới 120 ký tự, ví dụ "Trình đơn chân trang". |
| locale_invalid | Ngôn ngữ này chưa bật cho website — bật ở Cài đặt › Ngôn ngữ website rồi thử lại. |
| item_invalid | Mục này không còn trong trình đơn (có thể vừa bị gỡ ở tab khác) — tải lại trang để xem bản mới nhất. |
| target_invalid | Trang, chuyên mục hoặc sản phẩm được chọn không còn — chọn nguồn khác cho mục này. |
| url_invalid | Địa chỉ chưa đúng — dùng đường dẫn bắt đầu bằng /, #, https://, số điện thoại hoặc email. |
| label_invalid | Nhãn mục chưa hợp lệ — nhập chữ khách sẽ thấy, ví dụ "Liên hệ". |
| parent_invalid | Không đặt được mục vào chỗ này (mục cha không còn hoặc nằm trong chính nó) — kéo sang mục khác. |
| depth_exceeded | Trình đơn sâu quá 5 cấp — đưa mục ra ngoài một cấp. |
| region_invalid | Giao diện đang dùng không có vị trí này — chọn vị trí khác trong "Vị trí trên website". |
| menu_in_use | Trình đơn đang dùng ở một vị trí — chọn trình đơn khác cho vị trí đó trước khi xóa. |

## Thiết kế có · backend chưa có (Chờ backend — giữ trong thiết kế)
| Tính năng | Dữ liệu cần lưu | Luật |
|---|---|---|
| Sổ vị trí do giao diện khai báo + số cấp riêng từng vị trí | `theme.json → menu_areas[{key,label,depth,mega}]` | Trình đơn dùng ở nhiều vị trí lấy mức chặt nhất; backend hiện 1 giới hạn 5 cấp, giao diện mới dựng "primary" |
| Biểu tượng mục | `menu_items.icon` | tùy chọn |
| Mô tả mục / bảng lớn (số cột) | `menu_item_translations.description`, `menu_items.mega`, `cols` | mô tả theo ngôn ngữ |
| Khối tự dựng trong bảng lớn | `menu_items.block_key` → Khối tái dùng | cần `GET /api/page-builder/blocks?usage=menu`; khối nháp → bảng trống + nhắc |
| Nút nổi bật | `menu_items.cta` | — |
| Nhãn có hạn | `menu_items.badge`, `badge_until` | hết hạn tự tắt |
| Chỉ máy tính / chỉ điện thoại | `menu_items.device` | — |
| Ẩn mục bằng tay | `menu_items.hidden` | "Giữ ẩn cả sau khi đăng" dùng cờ này |
| Tự thêm trang mới | `menus.auto_add_pages` | trang mới đăng vào cuối trình đơn chính |
| Nhân bản trình đơn / mục | hành động `DuplicateMenu`, `DuplicateMenuItem` | — |
| Liên kết hệ thống ngoài "Trang chủ" (Tìm kiếm, Giỏ hàng, Tài khoản, Gọi hotline, Zalo, email, bản đồ, sơ đồ, mục gom nhóm) | `menu_items.type = system`, `system_key` | số máy/email đọc từ Cài đặt |
| Mã nhúng trình đơn giữa trang | shortcode `[menu location="…"]` + khối "Trình đơn" | — |
| Thùng rác 30 ngày | `menu_items.deleted_at` (soft delete) + dọn sau 30 ngày | backend đang xóa hẳn |
| Nút Dịch | bộ AI (BSAI, sổ từ AI) | để sau core; hạn ngạch §7 |
| Sao chép trình đơn cho một ngôn ngữ rồi Dịch | `DuplicateMenu` + `AssignMenu` | chỉ điền nhãn trống |

## Quyền & gói
Ai có quyền sửa trình đơn thấy toàn màn (repo chưa có quyền riêng — Chờ backend: `menus.manage`). Nút Dịch theo hạn ngạch từ AI (BSAI); công tắc "Lời mời nâng gói" chưa nối vào màn này — giữ như trước.

## Token · Icon
Token: xem mục 6. Icon mới: `ri-links-line`, `ri-file-copy-2-line`, `ri-sticky-note-line`, `ri-external-link-line` (đã có), `ri-translate-2`.

## Chuỗi cần dịch (mới)
"Dùng trình đơn chung — nhãn theo :lang" · "Dùng trình đơn riêng: “:name”" · "Chưa gán — website đang hiện trình đơn :def bằng :def" · "theo :Def" · "Sao chép thành trình đơn riêng rồi Dịch" · "chưa hiển thị ra ngoài" · ":Kind chưa đăng" · ":Kind không còn" · "Chuyên mục chưa có bản :lang" · "Website đang tự ẩn mục này — khách không thấy" · "Website đang tự ẩn mục này cho tới khi :kind được đăng" · "Giữ ẩn cả sau khi đăng" · "Mở trang / Mở sản phẩm / Mở chuyên mục" · "Xem vị trí" · "Khôi phục" · "Ví dụ: Bảng giá" · "Ví dụ: /bang-gia".

## Kết quả 12 tiêu chí dễ dùng
1 đạt (tiêu đề + mô tả + 1 nút Lưu) · 2 đạt (gán ngôn ngữ: mở Vị trí → chọn, 2 bước) · 3 đạt (mã kỹ thuật sau "Hiện mã cho lập trình viên") · 4 đạt · 5 đạt (placeholder chỉ ví dụ, gợi ý dưới ô) · 6 đạt (Thùng rác 30 ngày, hoàn tác; Chờ backend) · 7 đạt (chip có icon + chữ; mục ẩn có chip) · 8 [CHƯA KIỂM ĐƯỢC] chip ngôn ngữ ở khung 262px với tên dài — cần đo · 9 [CHƯA KIỂM ĐƯỢC] cảm ứng thật · 10 đạt (phím tắt Alt+mũi tên, Ctrl+Z) · 11 [CHƯA KIỂM ĐƯỢC] · 12 đạt.

## Kết quả Chất lượng hoàn thiện (A–E)
A đạt theo code (thang 4/8, 1 nút chính) · B đạt theo code · C giữ như D0-6a (Esc đóng, toast `role="status"`) · D [CHƯA KIỂM ĐƯỢC] đo tương phản tối bằng harness — mọi chữ có nghĩa đã qua token có giá trị tối trong `admin-base.css`/DS · E [CHƯA KIỂM ĐƯỢC] — Claude Code đo bằng zip.

## Kiểm hiển thị
| Bề rộng | Chrome | Safari iOS | Firefox | Samsung |
|---|---|---|---|---|
| 360 / 390 / 768 / 1024 / 1440 × thường + Chữ lớn | [CHƯA KIỂM ĐƯỢC] chờ harness/zip | [CHƯA KIỂM ĐƯỢC] | [CHƯA KIỂM ĐƯỢC] | [CHƯA KIỂM ĐƯỢC] |
| Trong Shell `#/site/menus` 390 | [CHƯA KIỂM ĐƯỢC] | — | — | — |

Verifier: xem dòng cuối.

## Cần owner chốt
- Trục ngôn ngữ đặt dưới từng vị trí (đúng `menu_assignments` vị trí × ngôn ngữ). Nếu muốn một công tắc cho mọi vị trí thì cần chốt.

Verifier: chờ kết quả.
