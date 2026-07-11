# ADR‑19 — SPEC hiện thực: kind `html` + "một body / hai editor" (map prototype → repo)

> Phần **(b) spec** của deliverable ADR‑19 (phần (a) = prototype `Page Editor - Options.dc.html` đã dựng; (c) = prompt Claude Code ở `_claude_code_ADR19_prompt.md`). Grounded CODE `tuyenht/BSWebSaaS@main c040caa` (đã đọc first‑hand). **CODE thắng.** ADR‑19 đã land design.md §19 (Draft) — spec này chi tiết hoá để build; mọi mục chạm registry/validate = **[COUNCIL] propose‑first**.

## 0. Hội đồng đã soi (tóm tắt phán quyết)
- **Security:** field raw‑render là bề mặt XSS #1 → sanitize **ON‑WRITE mọi đường ghi**, không chỉ FormRequest. **Chốt.**
- **Laravel/kiến trúc:** kind `html` = 1 entry trong `SECTION_SCHEMAS` (theme static) — KHÔNG class mới; tái dùng `SectionComponent`. **Chốt.**
- **i18n:** body per‑locale = `entry_translations.body` (D1) — prototype `data[loc].body` map 1‑1. **Chốt.**
- **Doc‑precision:** "một body/hai editor" = prose là body gồm **đúng 1 block `html`**; builder = body nhiều block. Đây là bản lề, phải phát biểu chính xác. **Chốt.**

## 1. CODE nền (đã verify — trích thật)
- `app/Modules/Theme/Providers/ThemeServiceProvider.php`: `const SECTION_SCHEMAS = ['hero'=>…, 'richtext'=>['heading'=>['type'=>'text'],'paragraphs'=>['type'=>'list','of'=>['type'=>'textarea']]], …]`; `register()` lặp → `$registry->register($type, new SectionComponent("theme::sections.{$type}", $schema))`. ⇒ **thêm kind = thêm 1 khoá vào `SECTION_SCHEMAS`** (mở rộng render+edit+validate cùng lúc).
- `app/Modules/Theme/Components/ComponentRegistry.php`: map `type → SectionComponent`; tập đã đăng ký = allow‑list.
- `app/Modules/Cms/Validation/ValidPageJson.php`: caps **100 block / 256KB**; `valueSafeForSpec($value,$specType)` phân nhánh theo KIỂU §E7 (`url`/`image`=media_id int>0/`list` đệ quy/scalar qua `e()`). **Chưa có nhánh `html`.**
- `app/Modules/Shared/Support/RichTextSanitizer.php`: singleton Octane‑safe; `::full` allow‑list (h2..h5, blockquote, ul/ol/li, table, img[library], iframe youtube/vimeo qua SafeIframe, sub/sup, a[href] + CSS allow‑list). Docblock: *"CMS bodies later — one shared module"*.
- `app/Modules/Theme/Rendering/{PageRenderer,BlockRenderer}.php`: `StorefrontController → PageRenderer → BlockRenderer` → Blade `theme::sections.{type}`.
- `entry_translations.body` = jsonb per‑locale block‑list `{"blocks":[{type,props}]}` (migration 2000; D1 page & post chung).
- `media.alt`/`title` = **jsonb per‑locale** (migration 2006).

## 2. Prototype → repo (map từng hành vi vừa dựng)
| Prototype (`Page Editor`) | Repo thật |
|---|---|
| `data[loc].body` (HTML/khối per‑locale) | `entry_translations.body[locale]` (block‑list jsonb) |
| RTE (contenteditable) = 1 khối văn bản giàu | **1 block `{type:'html', props:{html}}`** trong body |
| Tab **Soạn thảo** | View khi body = rỗng **hoặc đúng 1 block `html`** |
| Tab **Trình dựng** | View khi body có ≥1 block cấu trúc (hero/features/…) |
| `syncBody`/`captureBodyNow` nạp‑lưu theo locale | tải/ghi `body[locale]` khi đổi locale |
| AI‑translate/"Sao chép bản gốc" chép body | dịch/nhân bản `body[locale]` (Wave 3 backend) |
| `data[loc].alt` (bỏ `featuredAlt` toàn cục) | **`media.alt[locale]`** của `cover_media_id` (ảnh = global, alt = per‑locale) |
| placeholder "Bản {lang} chưa có nội dung" | trạng thái body[locale] rỗng |

## 3. Quyết định hiện thực (mỗi mục gắn phân loại)
### 3.1 Đăng ký kind `html` — [COUNCIL] (chạm registry = allow‑list)
- Thêm `'html' => ['html' => ['type' => 'html'], 'heading' => ['type' => 'text']]` vào `SECTION_SCHEMAS` (hoặc đăng ký từ `CmsServiceProvider` nếu coi là data‑bound — **council chọn nơi**; nghiêng theme‑static vì thuần trình bày).
- Blade `themes/default/sections/html.blade.php`: `{!! $props['html'] ?? '' !!}` (an toàn vì đã sanitize on‑write; CSP storefront = lưới 2).
- **Giữ kind `richtext` cũ** (paragraphs plain‑text) — `html` là sibling, KHÔNG thay thế (tương thích ngược nội dung An Vinh đã seed).

### 3.2 Kiểu §E7 `html` trong ValidPageJson — [COUNCIL]
- Trong `valueSafeForSpec`, thêm nhánh `$specType === 'html'`: hợp lệ khi `$value === null || is_string($value)` (raw‑render field — nêu tường minh, KHÔNG rơi vào nhánh scalar). **Rule thuần cấu trúc — KHÔNG chạy HTMLPurifier ở đây.**
- **Bất biến (hợp đồng):** không nơi nào `{!! !!}` field `html` chưa qua `RichTextSanitizer`. Sanitize = trách nhiệm **write‑pipeline** (§3.3).

### 3.3 Sanitize ON‑WRITE mọi đường ghi — [COUNCIL] (bảo mật)
- Một **PageJsonWriter/pipeline chung**: mọi body đi qua trước khi ghi `entry_translations.body`, chạy `RichTextSanitizer::full` trên MỌI prop kiểu `html` của MỌI block. Ba đường phải phủ: **(a) editor người** (FormRequest/Livewire action), **(b) AI‑gen Page‑JSON (ADR‑08)**, **(c) import**. Bỏ sót 1 = stored‑XSS.
- **Tái dùng `RichTextSanitizer::full`** — KHÔNG tạo sanitizer mới (docblock đã dành cho CMS).

### 3.4 Hợp đồng "một body / hai editor" — ACT (app logic, không schema)
- **Suy tab từ body:** rỗng **hoặc** đúng 1 block `html` → **Soạn thảo**; có ≥1 block cấu trúc → **Trình dựng**. Hint `body.editor='prose'|'builder'` **chỉ** để nhớ tab; payload **luôn** blocks.
- **Round‑trip:** prose→builder **luôn lossless** (prose = 1 block `html`, chèn section quanh). builder→prose lossless **chỉ khi** body chỉ gồm `html`; có section cấu trúc → **KHÔNG flatten** (section = placeholder chỉ‑đọc, hoặc khoá tab Soạn thảo). *(Prototype đã phản ánh qua banner + placeholder per‑locale.)*
- **Body per‑locale:** nạp/ghi `body[locale]` khi đổi locale (prototype `syncBody`/`captureBodyNow` = tham chiếu UX).

### 3.5 Alt per‑locale — ACT (đã đúng schema)
- Featured/cover = 1 `media_id` (global trên `entries.cover_media_id`); **alt đọc/ghi `media.alt[locale]`** (jsonb per‑locale, migration 2006). Bỏ mọi trường `featuredAlt` toàn cục / `cover_alt`. Override ngữ cảnh (hiếm) → `mediables`/prop block image.

## 4. Test‑first (Pest) — tên test đề xuất
- `HtmlKindRegisteredTest` — `ComponentRegistry` có `html`; `richtext` vẫn còn (không thay thế).
- `ValidPageJsonHtmlTypeTest` — prop `html` non‑string bị loại; null|string qua; block chưa register bị loại trước; cap 100/256KB giữ nguyên.
- `HtmlWriteSanitizeAllPathsTest` — payload `<script>/onerror/ iframe lạ` bị strip khi ghi qua **(a) FormRequest (b) AI‑gen ADR‑08 (c) import**; giữ `h2/blockquote/li`, iframe youtube/vimeo OK.
- `HtmlSectionRenderTest` — `theme::sections.html` render raw đã sanitize; body 1 block `html` ra HTML đúng; payload XSS không thực thi.
- `OneBodyTwoEditorTest` — suy tab từ body; round‑trip prose↔builder lossless đúng điều kiện; KHÔNG flatten khi có section cấu trúc.
- `CoverAltPerLocaleTest` — alt đọc/ghi `media.alt[locale]`; KHÔNG còn trường alt toàn cục.

## 5. Phạm vi / không‑phạm vi
- **Trong ADR‑19:** kind `html` + ValidPageJson + sanitize‑pipeline + hợp đồng 2‑editor + alt per‑locale. **KHÔNG migration** (body/media.alt jsonb đã có).
- **Ngoài (wave khác):** RTE island admin thực (Jodit dùng chung product) = Wave 1 UI; AI‑translate body backend = Wave 3; reusable `content_blocks` = ADR‑20; form field types = COUNCIL forms.

## 6. Rủi ro & giảm thiểu
- **XSS nếu sót đường ghi** → pipeline chung + test 3 path (a/b/c) là bắt buộc, không rải rác controller.
- **Regress nội dung cũ** (richtext paragraphs) → giữ kind `richtext`; `html` là thêm.
- **Round‑trip mất dữ liệu** → cấm flatten khi có section cấu trúc; test phủ.
