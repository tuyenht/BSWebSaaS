# ADR‑19 — Page Builder: section **kind `html`** (khối rich‑HTML) → nền "một body / hai editor"

- **Trạng thái:** **Đề xuất (Draft)** — ĐÃ LAND vào `docs/design.md` Phụ lục A dòng 19 + changelog v2.14. Không chạm schema.
- **Liên quan:** ADR‑04 (Section Library + Page‑JSON) · ADR‑05 · **ADR‑08 (AI sinh Page‑JSON)** · ADR‑17 (image = media_id) · §11 (sanitizer catalog 2 profile) · D1 (page & post chung body).
- **Nguồn:** `docs/builder_v2_alignment.md`; đối chiếu CODE `ComponentRegistry`/`ValidPageJson`/`RichTextSanitizer` (main c040caa).

## Bối cảnh (grounded CODE)
- `entry_translations.body` = MỘT cột `jsonb` per‑locale, block‑list phẳng; page & post chung body + renderer (D1).
- **Đã có kind `richtext`** trong ComponentRegistry = `heading + paragraphs[]` **plain‑text/escaped** → mất in‑đậm/list/blockquote (vd "Câu chuyện An Vinh").
- **`RichTextSanitizer` đã có** (`app/Modules/Shared/Support/RichTextSanitizer.php`), singleton Octane‑safe; profile **`full`** (dùng cho `product_translations.description` §11) = `h2..h5, blockquote, ul/ol/li, table, img[library], iframe[youtube/vimeo qua SafeIframe], sub/sup, a[href]` + CSS allow‑list. Docblock: *"catalog today, CMS bodies later — one shared module"* → dùng cho CMS **có chủ đích**.
- `ValidPageJson` an toàn **theo KIỂU §E7**; **chưa có nhánh kiểu `html`**. Rule thuần cấu trúc — KHÔNG chạy sanitizer.
- `ComponentRegistry` = allow‑list render + validate.

## Quyết định (khớp dòng design.md §19)
1. **Kind `html` mới** (sibling của `richtext`) đăng ký ở `ComponentRegistry` + `schema()` §E7 = `{ html: {type:'html'}, heading?: {type:'text'} }` + Blade `theme::sections.html` render `{!! $props['html'] !!}`.
2. **Sanitize `RichTextSanitizer::full` KHI‑GHI ở MỌI ĐƯỜNG GHI** — **không chỉ FormRequest editor người, mà cả (a) AI‑gen Page‑JSON (ADR‑08) (b) import**. *(Bỏ sót AI/import = stored‑XSS — đây là điểm hardening then chốt council nhấn.)* Chốt tại **PageJsonWriter/pipeline chung** (một chỗ mọi body đi qua trước khi ghi), KHÔNG rải rác từng controller.
3. **`ValidPageJson` thêm nhánh `type==='html'`**: cấu trúc `null | is_string` (raw‑render field, nêu tường minh — KHÔNG rơi vào scalar mặc định). **Bất biến:** không bao giờ `{!! !!}` field chưa qua sanitizer; ValidPageJson chỉ kiểm cấu trúc, sanitize là việc của write‑pipeline (§2).
4. **"Một body / hai editor" (D1, lossless):** suy tab từ body — rỗng **hoặc** đúng 1 block kind `html` → **Soạn thảo** (rich‑editor cả trang); có ≥1 section cấu trúc (hero/features…) → **Trình dựng**. Hint `body.editor='prose'|'builder'` chỉ nhớ tab mặc định; payload **luôn** blocks. Round‑trip prose→builder luôn lossless (prose = 1 block `html`); builder→prose lossless **chỉ khi** body chỉ gồm kind `html`; có section cấu trúc → **không flatten** (section = placeholder chỉ‑đọc, hoặc khoá tab Soạn thảo).

## [COUNCIL] Thay đổi
- `ComponentRegistry`: +kind `html`. `ValidPageJson`: +nhánh kiểu `html`. Blade `theme::sections.html`.
- **Write‑pipeline chung**: sanitize‑on‑write phủ editor người + AI‑gen + import.
- **KHÔNG migration** (body jsonb đã có). ADR nhẹ nhất, near‑term.

## Hệ quả
- **+** Rich inline thật; reuse sanitizer đã test; enabler cho nợ §4 "RTE body per‑locale" + Page Editor 2 tab.
- **−** Field raw‑render → kỷ luật sanitize‑on‑ALL‑write bắt buộc (test XSS cả AI/import). Cần dựng RTE island (admin) — dùng chung toolbar/sanitizer với product (Jodit 4, §11).

## Test‑first (Pest)
- `RichTextSanitizerCmsTest` — `::full` strip `<script>`/`onerror`, giữ `h2/blockquote/li`, chặn iframe ngoài youtube/vimeo (path CMS).
- `HtmlKindAllWritePathsTest` — payload XSS bị sanitize khi ghi qua **(a) editor (b) AI‑gen ADR‑08 (c) import** — cả ba path.
- `ValidPageJsonHtmlTypeTest` — prop `html` non‑string bị loại; block chưa register bị loại trước.
- `OneBodyTwoEditorTest` — suy tab từ body; round‑trip lossless có điều kiện; không flatten khi có section cấu trúc.

## Phụ thuộc
Độc lập, làm sớm. Sau ADR‑19 → nợ §4 (RTE body per‑locale) rơi vào tự nhiên.
