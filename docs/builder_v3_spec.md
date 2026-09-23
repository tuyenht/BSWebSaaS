# Builder v3 — "Trình dựng: Section Library" — SPEC (propose‑first)

> Chốt theo khuyến nghị Council (audit 2026‑07). Bám **ADR‑04/05/08/17 + §E7** và code repo `main`.
> Đây là **blueprint cho prototype `Page Builder v3.dc.html`** (design deliverable) **và** đề xuất cho
> Claude Code (các mục đánh **[COUNCIL]** chạm §E7/ValidPageJson → propose‑first khi vào repo).
> Nguồn đối chiếu chi tiết: `docs/archive/2607-design-sync/builder_v2_alignment.md`.

## 0. Mục tiêu
Thay builder Elementor (Section›Cột›Phần tử + inline‑CSS) bằng **trình soạn Section Library** xuất **Page‑JSON**:
danh sách phẳng các *section* đã đăng ký, sửa qua **form prop có kiểu (§E7)**, trình bày bằng **theme token
per‑site** — KHÔNG chạy code khách, exportable, an toàn, đa‑locale.

## 1. Data model = Page‑JSON (BẤT BIẾN)
- `entry_translations.body` = **một** cột jsonb per‑locale = `{"blocks":[{ "type","props" }, …]}` (phẳng).
- `page` VÀ `post` dùng chung (D1). Render: `StorefrontController → PageRenderer → BlockRenderer` → Blade
  `theme::sections.{type}`. Product = bảng riêng (không nằm đây).
- Prototype lưu đúng dạng này; có **Page‑JSON inspector** (xem/copy) để chứng minh output khớp.

## 2. Section Library — block đã đăng ký + §E7 (nguồn: ThemeServiceProvider / Cms / Catalog / Forms)

| type | nguồn | props (§E7) |
|---|---|---|
| `hero` | theme | heading:text · subheading:text · image_url:image · cta_label:text · cta_url:url · cta2_label:text · cta2_url:url |
| `richtext` | theme | heading:text · paragraphs:list(textarea) |
| `richtext_html` **[COUNCIL, mới]** | theme | html:**html** (sanitize‑khi‑ghi) |
| `features` | theme | heading:text · items:list({title:text, text:textarea}) |
| `about` | theme | heading:text · paragraphs:list(textarea) · image_url:image · cta_label:text · cta_url:url |
| `partners` | theme | heading:text · logos:list({image_url:image, name:text, url:url}) |
| `cta` | theme | heading:text · button_label:text · button_url:url |
| `contact` | theme | heading:text · address:textarea · phone:url · email:url · map_embed:url |
| `latest_posts` | Cms | heading:text · limit:int |
| `entry_grid` | Cms | heading:text · taxonomy_slug:text · limit:int |
| `product_grid` | Catalog | heading:text · limit:int |
| `form` | Forms | form_key:text (tham chiếu Form theo key) · heading:text |

- Kiểu §E7: `text | textarea | url | image | list(of scalar | of object) | int | html`. Form prop **sinh tự động**
  từ schema (text→input, textarea→textarea, url→input+scheme‑guard, image→MediaPicker, list→repeater, int→number,
  html→rich mini‑editor).
- Thêm block mới = **[COUNCIL]** đăng ký component + §E7 + Blade partial + phủ ValidPageJson + test.

## 3. Block `richtext_html` [COUNCIL] — mảnh enabling "một body/hai editor"
- prop `html` = rich HTML, **sanitize‑khi‑ghi** bằng `RichTextSanitizer` (tái dùng profile của product description).
- Allow‑list: `h2 h3 h4 p strong em u s ul ol li blockquote a[href rel] sup sub br`.
  **CẤM:** `h1` (giữ single‑h1 của layout) · `img` (ảnh phải qua image‑prop/media_id — ADR‑17) · `script`/`style`/`on*`/iframe.
- Render `theme::sections.richtext_html`: `{!! $props['html'] !!}` (an toàn vì đã sanitize — đúng pattern
  `product_translations.description`).
- `ValidPageJson`: prop kiểu `html` = phải là string; an toàn dựa **sanitizer bắt buộc** (không phải escape). Test XSS.

## 4. Soạn thảo ↔ Trình dựng (một body, hai lăng kính)
- **Soạn thảo** = rich‑editor prose (tạo/sửa `richtext_html`); **Trình dựng** = xếp section.
- Cùng MỘT rich‑editor: ở Trình dựng, editor đó nhúng vào block `richtext_html` để sửa.
- **Tab mặc định suy từ body:** rỗng / chỉ 1 `richtext_html` → Soạn thảo; có ≥1 block cấu trúc → Trình dựng.
  Lưu hint `body.editor` (prose|builder) chỉ để nhớ; payload luôn là blocks.
- **Round‑trip:** Soạn thảo→Trình dựng lossless (prose = 1 block, chèn section quanh). Trình dựng→Soạn thảo:
  chỉ‑richtext → gộp prose; có block cấu trúc → **không flatten**, Soạn thảo cho sửa vùng prose, section cấu trúc =
  **placeholder chỉ‑đọc** ("▦ Hero — sửa trong Trình dựng").

## 5. Media = media_id (ADR‑17)
- prop `image` = **media_id (int>0)** hoặc null. MediaPicker trả `{id, previewUrl}`; body lưu `id`, preview chỉ để hiển thị.
- Render thật: resolve media_id → URL server (batch, width 1280). Prototype mô phỏng id + preview.
- An Vinh GĐ1 Option C: ảnh pre‑optimized trên R2; Media module hoãn GĐ3 (ghi chú trong prototype).

## 6. Guards & limits (ValidPageJson)
- `type` ∈ ComponentRegistry (allow‑list). Tối đa **100 block / 256KB** (prototype cảnh báo khi vượt).
- prop `url`: chặn scheme `javascript:/data:/vbscript:` (prototype validate ô url ngay khi nhập).
- prop `image`: media_id int; prop khác escape theo kiểu; `html`: qua sanitizer.

## 7. i18n
- Body **per‑locale**. Prototype: **công tắc locale** (An Vinh vi bật; en/ja hiển thị "chưa dịch" — GĐ1 vi‑only,
  Stream 5 mở en/ja). Link nội bộ: chọn route/slug (bản địa hoá qua routes/PathBuilder) vs URL ngoài.
- URL: default‑locale (mutable per‑site) không prefix; non‑default `/{locale}`.

## 8. Theme tokens (per‑site, KHÔNG per‑element)
- Panel token cấp site (màu thương hiệu…), validate allow‑list `Rule::in` (thật). Prototype: 1 brand color chảy vào
  preview các section. KHÔNG có màu/đệm/animation theo từng phần tử.

## 9. Persistence
- **DB = SSOT**; localStorage = **pending‑write buffer** (chỉ giữ thay đổi chưa sync; validate khi lưu; net‑fail giữ
  retry). Prototype: key **`bswebsaas:pb:v3:draft`** (KHÔNG đụng key v2), ghi rõ "khi tích hợp = lưu server per site/entry".

## 10. UX
- Rail trái = **Thư viện Section** (block đã đăng ký, có thumbnail) + tìm kiếm.
- Canvas = danh sách section: kéo‑sắp xếp · nhân bản · xoá · ẩn/hiện · chọn; **preview gần giống storefront theme**.
- Panel phải = form §E7 của section đang chọn. Thanh trên = tab Soạn thảo/Trình dựng · locale · xem thiết bị · Page‑JSON.
- **Empty/loading/error** đầy đủ (Diamond++ §10.3.6): trang trống có hướng dẫn; lỗi lưu bằng ngôn ngữ đời thường.
- SEO: h2/h3/ul/blockquote ngữ nghĩa; single‑h1 do layout; image có alt (image‑prop kèm alt).

## 11. Seed An Vinh (đóng băng, vi)
`hero(+CTA2) · about · features/services(entry_grid `dich-vu`) · projects(entry_grid `du-an`) · partners ·
latest_posts · cta · contact(info)`. Nội dung cơ khí chính xác (jig/đồ gá/khuôn mẫu/chi tiết máy).

## 12. Prototype v3 (deliverable)
- File **`Page Builder v3.dc.html`** (Design Component). Giữ `Page Builder v2.dc.html` = bản khám phá (đánh nhãn).
- Bao phủ: §1 data · §2 forms §E7 · §3 richtext_html + mini‑editor · §4 hai editor + placeholder · §5 media_id ·
  §6 caps/url · §7 locale switcher · §8 brand token · §9 buffer key v3 · §10 UX · §11 seed.

## 13. Diamond++ gate (khi Claude Code hiện thực)
§2 bảo mật (site_id/RLS, không lộ bigint, url scheme, sanitize html) · §3 DB (D1–D15) · Query Budget (media batch,
entry_grid/product_grid không N+1) · SEO/CWV (Blade SSR, LCP≤1.5s, JS≤30KB) · UX states · CI xanh · reversible.

## 14. Ngoài phạm vi / đã loại
- Elementor tự do (cột/CSS/animation per‑element) · Nhúng‑HTML page block · shortcode · Gutenberg per‑paragraph ·
  form field ngoài CHECK {text,email,tel,textarea,select,checkbox} · "Google Sheet" · HTML‑blob cho page.
