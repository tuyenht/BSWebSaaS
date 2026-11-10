# ADR‑19 — Page Builder: section **kind `html`** (khối rich‑HTML) → nền "một body / hai editor"

- **Trạng thái:** **Đã chốt (Accepted) 14/10/2026** — dòng 19 Phụ lục A của `docs/design.md`. Không chạm schema. Bản Draft tháng 7 được viết lại ở các điểm 2, 3 (bên dưới) và thêm điểm 5–7.
- **Liên quan:** ADR‑04 (Section Library + Page‑JSON) · ADR‑05 · **ADR‑08 (AI sinh Page‑JSON)** · ADR‑17 (image = media_id) · §11 (sanitizer catalog 2 profile) · D1 (page & post chung body).
- **Nguồn:** `docs/archive/2607-design-sync/builder_v2_alignment.md`; đối chiếu CODE `ComponentRegistry`/`ValidPageJson`/`RichTextSanitizer` (main c040caa).

## Bối cảnh (grounded CODE)
- `entry_translations.body` = MỘT cột `jsonb` per‑locale, block‑list phẳng; page & post chung body + renderer (D1).
- **Đã có kind `richtext`** trong ComponentRegistry = `heading + paragraphs[]` **plain‑text/escaped** → mất in‑đậm/list/blockquote (vd "Câu chuyện An Vinh").
- **`RichTextSanitizer` đã có** (`app/Modules/Shared/Support/RichTextSanitizer.php`), singleton Octane‑safe; profile **`full`** (dùng cho `product_translations.description` §11) = `h2..h5, blockquote, ul/ol/li, table, img[library], iframe[youtube/vimeo qua SafeIframe], sub/sup, a[href]` + CSS allow‑list. Docblock: *"catalog today, CMS bodies later — one shared module"* → dùng cho CMS **có chủ đích**.
- `ValidPageJson` an toàn **theo KIỂU §E7**; **chưa có nhánh kiểu `html`**. Rule thuần cấu trúc — KHÔNG chạy sanitizer.
- `ComponentRegistry` = allow‑list render + validate.

## Quyết định (khớp dòng design.md §19)
1. **Kind `html` mới** (sibling của `richtext`) đăng ký ở `ComponentRegistry` + `schema()` §E7 = `{ html: {type:'html'}, heading?: {type:'text'} }` + Blade `theme::sections.html` render `{!! $props['html'] !!}`.
2. **Lọc ở lớp dữ liệu, không phải "một lớp ghi chung".** Hai cột chứa thân bài (`entry_translations.body`, `entry_drafts.content`) dùng bộ chuyển kiểu: mỗi lần được gán, mọi trường kiểu `html` đi qua `RichTextSanitizer::full`. Nhờ vậy mọi đường ghi (editor người, AI-gen ADR-08, nhập dữ liệu, khôi phục, thay đổi chờ duyệt, nhân bản, dữ liệu mẫu) được lọc mà không phải nhớ gọi. Kiểm thử tự động cấm các lối vòng qua lớp dữ liệu (cập nhật hàng loạt, `DB::table`, `insert`/`upsert`, `setRawAttributes`, gán theo khóa dạng đường dẫn JSON). Kiểu trường `html` chỉ được khai ở đúng loại khối `html`.
3. **`ValidPageJson` thêm nhánh `type==='html'`**: cấu trúc `null | is_string` (raw‑render field, nêu tường minh — KHÔNG rơi vào scalar mặc định). **Bất biến:** không bao giờ `{!! !!}` field chưa qua sanitizer; ValidPageJson chỉ kiểm cấu trúc, sanitize là việc của bộ chuyển kiểu ở lớp dữ liệu (điểm 2).
4. **"Một body / hai editor" (D1, lossless):** suy tab từ body — rỗng **hoặc** đúng 1 block kind `html` → **Soạn thảo** (rich‑editor cả trang); có ≥1 section cấu trúc (hero/features…) → **Trình dựng**. Payload **luôn** blocks (không dùng gợi ý `body.editor`). Round‑trip prose→builder luôn lossless (prose = 1 block `html`); builder→prose lossless **chỉ khi** body chỉ gồm kind `html`; có section cấu trúc → **không flatten** (section = placeholder chỉ‑đọc, hoặc khoá tab Soạn thảo).
5. **Ảnh trong bài = mã ảnh của thư viện website** (ADR‑17), không lưu địa chỉ ảnh: `figure` / `figcaption` / `img[data-media-id]` với `data-size` / `data-align` kiểu liệt kê; HTML đã lưu không chứa địa chỉ ảnh; lúc in, địa chỉ do máy chủ dựng và `data-media-id` bị bỏ. Ảnh từ trang khác đi vào thư viện qua nút "Đưa vào thư viện"; hàm lưu hỏi trước, không bỏ ảnh âm thầm.
6. **Bài cũ:** bài chỉ gồm khối `richtext` chuyển một chiều lúc mở bài, có bản lịch sử trước lần lưu đầu.
7. **Chỗ quản trị in thân bài** (không có lưới CSP) lọc lại lúc in; có lệnh lọc lại chạy nền theo từng website, chạy lại được, bắt buộc khi danh sách cho phép bị thu hẹp hoặc bộ lọc được vá.

## [COUNCIL] Thay đổi
- `ComponentRegistry`: +kind `html`. `ValidPageJson`: +nhánh kiểu `html`. Blade `theme::sections.html`.
- **Bộ chuyển kiểu ở lớp dữ liệu**: sanitize‑on‑write phủ mọi đường ghi, kể cả editor người + AI‑gen + import.
- **KHÔNG migration** (body jsonb đã có). ADR nhẹ nhất, near‑term.

## Hệ quả
- **+** Rich inline thật; reuse sanitizer đã test; enabler cho nợ §4 "RTE body per‑locale" + Page Editor 2 tab.
- **−** Field raw‑render → kỷ luật sanitize‑on‑ALL‑write bắt buộc (kiểm thử cả AI/import và các lối vòng). Cần dựng RTE island (admin) — dùng chung toolbar/sanitizer với product (Jodit 4, §11).

## Test‑first (Pest)
- `RichTextSanitizerCmsTest` — `::full` strip `<script>`/`onerror`, giữ `h2/blockquote/li`, chặn iframe ngoài youtube/vimeo (path CMS).
- `HtmlKindAllWritePathsTest` — payload XSS bị sanitize khi ghi qua **(a) editor (b) AI‑gen ADR‑08 (c) import** — cả ba path.
- `ValidPageJsonHtmlTypeTest` — prop `html` non‑string bị loại; block chưa register bị loại trước.
- `OneBodyTwoEditorTest` — suy tab từ body; round‑trip lossless có điều kiện; không flatten khi có section cấu trúc.

## Phụ thuộc
Độc lập, làm sớm. Sau ADR‑19 → nợ §4 (RTE body per‑locale) rơi vào tự nhiên.
