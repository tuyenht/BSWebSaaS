# Đối chiếu Page Builder v2 (prototype) ↔ kiến trúc thật BSWebSaaS

> Nguồn: đọc trực tiếp repo `tuyenht/BSWebSaaS@main` (2026‑07‑10). Mục đích: chỉ ra builder prototype
> hiện tại "khớp / lệch" chỗ nào so với mô hình thật, để quyết định hướng đi. Các quyết định chạm
> schema/kiến trúc = **propose‑first (COUNCIL)** theo CLAUDE.md.

## 1. Mô hình THẬT (đã chốt trong repo)

- **ADR‑04 (Chốt):** Theme = **Section Library + Page‑JSON**, **KHÔNG chạy code khách**, phải **export được**.
- **Page‑JSON** nằm ở `entry_translations.body` = `{"blocks":[{ "type", "props" }, …]}` — **danh sách phẳng**,
  **theo từng locale** (i18n = bảng dịch, mỗi bản dịch có body riêng). `ValidPageJson`: **tối đa 100 block, 256KB**;
  `type` phải nằm trong **ComponentRegistry** (allow‑list = registry, ADR‑08); prop `url` bị chặn scheme
  `javascript:/data:/vbscript:`; prop `image` = **media_id (int > 0)**, KHÔNG phải URL (ADR‑17).
- **Block types đã đăng ký** (nguồn: `ThemeServiceProvider` + `CmsServiceProvider`):
  `hero, richtext, features, about, partners, cta, contact` (static, props‑là‑nội‑dung) + `latest_posts, entry_grid`
  (data‑bound). Mỗi type có **§E7 typed schema**: `text | textarea | url | image | list(of …)`. Thêm 1 block =
  đăng ký component + schema §E7 + Blade partial `theme::sections.{type}` + phủ `ValidPageJson` + test.
- **Trình bày = theme tokens per‑site** (`ThemeResolver`) + Blade partial khoá cứng. **KHÔNG có CSS/inline‑style
  theo từng phần tử.** Token lưu JSONB, validate allow‑list `Rule::in` khi lưu (chống stored‑injection).
- **Builder (ADR‑05, Chốt):** **section‑based** + **iframe preview thật + postMessage**; React island (**Puck**, MIT)
  làm canvas admin — **chỉ là editor, KHÔNG phải renderer** (storefront render Blade từ Page‑JSON). GĐ1 An Vinh
  thực tế chạy **Livewire block editor**; điều kiện tiên quyết F2 = **Repeater editor theo §E7** để nhập được
  prop mảng/đối tượng.
- **Ảnh (ADR‑17 / An Vinh Option C):** đích = `media_id`; GĐ1 An Vinh đặt ảnh **pre‑optimized trên R2** theo key
  `sites/{site_id}/media/{ulid}.{ext}`, block props tham chiếu **URL R2** tạm (Media module hoãn GĐ3).
- **Forms** = thực thể quản trị riêng (`forms/form_fields/form_submissions`, tenant + RLS). Field type **CHECK ∈
  {text,email,tel,textarea,select,checkbox}**. Field = `key` + `label`(JSONB per‑locale) + `type` + `is_required`
  + `options`(JSONB) + `validation`(JSONB) + `sort_order`. Gửi = lưu lead + **honeypot + Cloudflare Turnstile** +
  `notify_emails` (best‑effort). **KHÔNG có "Google Sheet".** An Vinh GĐ1: `contact` = block thông tin
  (heading/address/phone/email/map_embed); form thật qua Forms module "sau".
- **i18n:** content per‑locale; **An Vinh GĐ1 = chỉ vi** (en/ja hoãn Stream 5). UI locale = en/vi/ja/zh. URL:
  default‑locale không prefix, non‑default `/{locale}`, "default" **mutable per‑site**; link nội bộ bản địa hoá
  qua `routes` + PathBuilder.
- **localStorage (admin):** = **pending‑write buffer**, KHÔNG phải mirror DB. **DB là SSOT**; validate allow‑list
  khi lưu; net‑fail giữ lại retry.
- **An Vinh — homepage stack đóng băng:** `hero(+CTA2) · about · services(entry_grid `dich-vu`) ·
  projects(entry_grid `du-an`) · partners(repeater logos[]) · latest_posts · cta · contact(info)`.
  Sản phẩm = post‑by‑category (`san-pham`), KHÔNG phải commerce module.

## 2. Prototype HIỆN TẠI (Page Builder v2.dc.html)

Elementor‑style: **Section › Cột › Phần tử**, 25+ loại phần tử, **inline‑style theo từng phần tử**
(màu/đệm/căn/animation/override thiết bị), carousel, **Nhúng HTML**, form nhúng inline, autosave localStorage
làm bản nháp chính, đơn ngữ.

## 3. KHỚP (giữ lại được)
- Canvas React island kéo‑thả, iframe/preview + xem theo thiết bị → đúng tinh thần ADR‑05.
- Bộ "khối mẫu" ánh xạ tốt sang block thật: Hero→`hero`, Ảnh+Văn bản→`about`, Icon‑box 3→`features`,
  CTA→`cta`, Partners→`partners`, "Bài viết/Sản phẩm"→`latest_posts`/`entry_grid`.
- MediaPicker dùng chung (đúng hướng ảnh‑tham‑chiếu).
- Màu thương hiệu như **token cấp site** (đúng hướng theme tokens).
- Empty/loading/error, ý thức Alt text (phục vụ CWV/SEO gate).

## 4. LỆCH (xếp theo mức độ)
1. **Cấu trúc — lệch gốc.** Free‑form Section›Cột›Phần tử + inline CSS ↔ thật là **danh sách phẳng ~9 block
   type cố định, prop có kiểu, style từ theme token**. Không có cột, không CSS per‑element. → Phần lớn builder
   không map. **Phải chuyển thành "trình soạn Section Library"** (thêm/sắp xếp section đã đăng ký; sửa qua form
   prop theo §E7), KHÔNG phải Elementor.
2. **Loại phần tử không tồn tại backend:** slider, gallery, pricing, faq, video, map, counter, testimonial,
   progress, countdown, tabs, icon, divider, spacer, list, button lẻ, embed → `ValidPageJson` **loại bỏ** ("khối lạ")
   trừ khi đăng ký component mới (mỗi cái = Blade partial + §E7 + validate + test).
3. **Nhúng HTML** vi phạm ADR‑04 (không code khách) → bỏ khỏi page block (HTML thô chỉ nằm ở `banners`, có sanitize).
4. **Ảnh** lưu object/URL ↔ thật = `media_id` (int). image prop KHÔNG phải object. MediaPicker phải trả media_id
   (hoặc URL R2 giai đoạn An Vinh).
5. **Forms** lệch: có "Google Sheet" (không tồn tại) + field radio/checkboxes/number/date (**vi phạm CHECK 6 loại**);
   thật = thực thể quản trị (key/label‑i18n/options/validation) + leads + Turnstile + notify_emails.
   → **P2 #2 (radio/checkboxes) lệch schema**; number/date cũng ngoài CHECK.
6. **Style per‑element** ↔ thật = theme token per‑site validate allow‑list. Màu/đệm/animation từng phần tử không map.
7. **i18n:** đơn ngữ + link thô ↔ thật per‑locale + link nội bộ qua routes. (An Vinh GĐ1 vi‑only nên đơn ngữ tạm
   chấp nhận, nhưng builder phải locale‑aware cho Stream 5.)
8. **Persistence:** localStorage = bản nháp chính ↔ thật DB là SSOT, localStorage chỉ là **pending‑write buffer**,
   validate khi lưu.
9. **Giới hạn/bảo mật:** chưa có cap 100 block / 256KB, chưa validate scheme URL.
10. **Công nghệ editor:** thật nhắm **Puck**/Livewire‑repeater; prototype là DC bespoke (ổn để demo ý tưởng,
    nhưng không phải editor được chọn).

## 5. Đề xuất (propose‑first)

**Move 1 — dựng lại prototype thành "Trình dựng — Section Library" (v3), không chạm schema:**
- Rail trái = **các block type đã đăng ký** (hero/about/features/services/partners/cta/contact/richtext/
  latest_posts/entry_grid), không phải 25 phần tử.
- Canvas = thêm/sắp xếp/nhân bản/xoá **section** + preview + xem thiết bị + ẩn/hiện. Bỏ cột, bỏ CSS per‑element.
- Panel phải = **form prop sinh theo §E7** (text/textarea; url có validate scheme; image = MediaPicker→media_id;
  list = **repeater** cho paragraphs[]/items[]/logos[]).
- Data = đúng `{blocks:[{type,props}]}`; cap 100 block/256KB; allow‑list scheme URL.
- Style = **panel token cấp site** (màu thương hiệu…) thay vì per‑element.
- **Locale‑aware:** công tắc locale (vi bật, en/ja stub) sửa body per‑locale; link nội bộ = chọn route/slug vs URL ngoài.
- Forms: `contact` = info block ngay; block tham chiếu `form` (6 field type + Turnstile, KHÔNG Google Sheet) làm sau.
- localStorage theo ngữ nghĩa **pending‑write buffer**; ghi rõ DB là SSOT.
- Seed = **stack homepage An Vinh đóng băng** (vi, cơ khí chính xác) cho sát thực tế.

**Move 2 — COUNCIL (propose‑first):** mọi block cần backend mới (FAQ, pricing, slider, gallery, testimonial,
counter…) = đề xuất danh sách; mỗi block = component + §E7 + Blade partial + ValidPageJson + test. Ưu tiên
khớp **§E7 Repeater editor (F2)** — mảnh enabling then chốt.

**Xử lý prototype cũ:** giữ `Page Builder v2.dc.html` như **bản "khám phá/định hướng UX" (đánh nhãn rõ)**;
artefact bàn giao = v3 khớp mô hình.

## 6. Lưu trữ kết quả builder — JSON, KHÔNG shortcode (trả lời câu hỏi + tham chiếu Flatsome)

**Câu hỏi:** cùng ô nội dung, build bằng "Trình dựng" thì kết quả có cấu trúc + lưu dạng **shortcode**?
- **Có cấu trúc: ĐÚNG.** **Shortcode: KHÔNG** — và đây là điểm cố ý.
- BSWebSaaS đã **CHỐT (ADR‑04)** lưu **Page‑JSON** ở `entry_translations.body`:
  - `type=page` → `body = {"blocks":[{type,props}]}` (builder).
  - `type=post` → `body = {"html": …}` (rich text, HTML sanitize‑khi‑ghi bằng HTMLPurifier allow‑list —
    y như `product_translations.description`).
- **Vì sao JSON > shortcode (Flatsome):**
  - *Validate:* `ValidPageJson` = allow‑list (chỉ type đã đăng ký, prop có kiểu, chặn scheme URL, cap 100/256KB).
    Chuỗi shortcode gần như không validate an toàn được.
  - *Bảo mật (ADR‑04):* không chạy code khách; prop escape theo kiểu khai báo. Shortcode = gọi callback PHP →
    tăng bề mặt tấn công, khó export sạch.
  - *i18n:* body **per‑locale** (mỗi bản dịch 1 body) — sạch. Shortcode gộp 1 field trộn locale rất rối.
  - *Export (ADR‑12/13) & CWV:* JSON render tất định, static‑able; **không parse shortcode** lúc render;
    media resolve **1 query batch** (media_id). `do_shortcode` regex của WP là nút cổ chai quen thuộc.
  - *DRY:* editor + validate + render sinh từ **một** registry/§E7.
- **Kết luận:** cái Flatsome làm (shortcode trong 1 field) chính là thứ BSWebSaaS **cố ý bỏ**. Giữ **trực giác
  "phải có cấu trúc"** của bạn, nhưng thay shortcode = **Page‑JSON**.

## 7. Quan hệ "Soạn thảo" ↔ "Trình dựng" (định nghĩa theo LOẠI, tránh bẫy round‑trip)

Repo đã trả lời sẵn qua **content‑model theo type**:
- **post** (tin tức / dự án / sản phẩm‑post) → **Soạn thảo** (rich text) → lưu `{html}` sanitize. KHÔNG blocks, KHÔNG shortcode.
- **page** (trang) → **Trình dựng** (Section Library) → lưu `{blocks:[…]}`.
- Nội dung ảnh 1 ("Câu chuyện An Vinh": heading/đoạn/list/quote) = dạng **bài viết rich text** ⇒ nếu là *post* thì
  Soạn thảo `{html}` là đúng; nếu là **1 khối trên page** thì phải là block `richtext/about`.
- **Cảnh báo §E7 hiện tại:** `richtext` = `heading + paragraphs[]` (textarea **plain, escaped**) → **không** giữ được
  in‑đậm/list/blockquote như ảnh 1. Muốn rich inline trong page block ⇒ **mở rộng §E7**: thêm block `richtext(html)`
  với prop kiểu `html` (HTMLPurifier khi ghi + mini rich‑editor). **Propose‑first** (dùng lại 2 profile HTMLPurifier
  đã có cho product description).
- **Round‑trip:** KHÔNG làm 2 view lossy của cùng nội dung tự do (bẫy shortcode Flatsome). Chuẩn: rich→builder =
  bọc HTML vào 1 block `richtext` (lossless); builder→rich chỉ lossless khi page chỉ có 1 block richtext (ngược lại
  phải cảnh báo/không cho, vì sẽ mất layout).

## 8. Học UX Flatsome trên nền JSON (đẹp/hiện đại/nhanh/mượt)

Giữ mặt UX tốt của Flatsome UX Builder (ảnh 4), nhưng output = Page‑JSON:
- **Preview iframe render THẬT** (Blade storefront) qua postMessage (ADR‑05) — WYSIWYG thật, đóng luôn spike
  "parity preview↔render". (Puck React island hoặc Livewire editor.)
- **Cây section** trái (như ảnh 4): kéo‑sắp xếp, gear=sửa, mắt=ẩn, nhân bản/xoá (đúng cái "Lớp" tôi đã có).
- **"+ Thêm" từ Section Library** (chỉ type đã đăng ký) có thumbnail.
- **Panel phải = form §E7 tự sinh:** text/textarea (mini rich‑editor cho richtext), url (validate + chọn route nội bộ),
  image (MediaPicker→media_id), list → **repeater**.
- **Nhanh/mượt:** autosave server (DB = SSOT) + localStorage = **pending‑write buffer**; **patch theo block** (không
  gửi cả body); iframe cập nhật **diff theo block** qua postMessage (không reload full); Blade SSR + không parse
  shortcode → đạt CWV (LCP ≤1.5s, JS ≤30KB).

## 9. "Một nội dung, hai editor" — page & post CHUNG một body (D1), KHÔNG bao giờ có 2

**Kiểm chứng code (trả lời trực tiếp: "lấy cái nào để hiển thị?"):**
- `entry_translations.body` = **MỘT** cột jsonb duy nhất. Không có field thứ 2.
- Render: `StorefrontController.renderEntry` → `PageRenderer.render($type, …, $body)` → `BlockRenderer.render($body)`
  cho **cả page lẫn post**. `post.blade.php` ghi rõ: *"Post bodies reuse the block‑list Page‑JSON (D1), so they
  render exactly like a page."* ⇒ **page VÀ post đều lưu block‑list Page‑JSON, cùng một renderer.**
- Ghi chú `{"html":…}` (post) ở docblock `EntryTranslation` + migration là **legacy/chưa dùng** — as‑built là D1
  block‑list cho cả hai. **Việc cần làm (propose‑first):** reconcile docblock để không ai dựng lại nhánh `{html}`
  song song.
- ⇒ **Bài viết / "sản phẩm‑post" dùng Builder là TỰ NHIÊN** (post đã là block‑list). Không phát sinh storage mới,
  không bao giờ có "2 bản".

**Quy tắc chốt (tránh bẫy WordPress/Elementor `_elementor_data` = 2 nguồn song song):**
- **KHÔNG BAO GIỜ lưu 2 biểu diễn** của cùng một nội dung. Chỉ 1 `body` (block‑list) là nguồn sự thật.
- **"Soạn thảo" và "Trình dựng" = 2 EDITOR trên cùng 1 body**, không phải 2 nơi lưu:
  - body có đúng 1 block `richtext` → mở **Soạn thảo** (như trình soạn bài thường).
  - body có nhiều block / có section bố cục → mở **Trình dựng**.
  - Toggle qua lại = **lossless** (cùng mảng blocks). Có thể lưu `body.format`/hint CHỈ để chọn editor mặc định;
    payload luôn là blocks.
- Hiển thị: storefront **luôn** render `body` qua **một** đường `BlockRenderer` → không có chuyện "chọn cái nào".

**Rich text thật (in‑đậm/list/quote như ảnh 1):**
- `richtext` §E7 hiện = `heading + paragraphs[]` plain (escaped) → không giữ inline formatting. Muốn WYSIWYG như
  "Soạn thảo" ⇒ thêm block **`richtext(html)`** (prop kiểu `html`, HTMLPurifier khi ghi — dùng lại 2 profile của
  product description). "Soạn thảo" = sửa html block đó; "Trình dựng" = xếp block. **Propose‑first (§E7).**

**Sản phẩm (commerce `Product` = bảng RIÊNG, khác `entries`):**
- `product_translations.description` = rich HTML sanitize + gallery + field commerce có kiểu (price/sku/stock),
  render bằng layout product cố định (`renderProduct`) — **KHÔNG** block‑list. Đây là "một nội dung" của product,
  không mập mờ.
- Product muốn **landing giàu bố cục** ⇒ đừng nhét blocks vào row product. Tạo **page (blocks)** giới thiệu product
  qua block **`product_grid`** (Catalog đã đăng ký vào ComponentRegistry, data‑bound) hoặc `entry_grid`. Giữ dữ liệu
  product sạch/typed, giữ bố cục ở page.
- **ĐÍNH CHÍNH (as‑built) — An Vinh KHÔNG còn dùng post‑by‑category cho sản phẩm:** `PromoteAnVinhProductPostsToCatalog`
  đã **promote post `san-pham` → commerce `Product` thật** (giữ nguyên URL, post nguồn nằm Trash, recoverable). Nội dung
  product = typed row + `product_translations.description`/`summary` = **rich HTML sanitize** (`RichTextSanitizer`
  full/basic) + gallery `product_images` (media_id) + field commerce có kiểu; `CatalogMode=brochure` (An Vinh) ẩn
  giá/đặt hàng. ⇒ **Product KHÔNG dùng block builder**; sửa ở **Catalog editor (Livewire)**, render bằng layout
  `renderProduct` cố định. Builder chỉ **tham chiếu** product qua block `product_grid`. (Củng cố quy tắc "một nội dung":
  entries → 1 `body` block‑list; product → 1 `description` HTML; không bao giờ trộn/nhân đôi.)

## 10. Cơ chế Soạn thảo ↔ Trình dựng — PHƯƠNG ÁN CHỐT (nội dung giữa 2 tab)

> Nguyên tắc: **1 body block‑list; "Soạn thảo" và "Trình dựng" là 2 LĂNG KÍNH trên cùng body, KHÔNG phải 2 mã hoá.**

1. **Prose = block `richtext(html)`** (propose‑first §E7 kiểu `html`): `props.html` = HTML **sanitize‑khi‑ghi** (HTMLPurifier
   `RichTextSanitizer`, allow‑list `h2‑h4/p/strong/em/u/s/ul/ol/li/blockquote/a[href rel]/sup/sub/br`); render `{!! $props['html'] !!}`
   (an toàn vì đã sanitize — đúng pattern `product_translations.description`). Nội dung ảnh ("Câu chuyện An Vinh": heading + bold
   + list + quote) = **1 block richtext(html)**.
2. **MỘT rich‑editor DÙNG CHUNG:** "Soạn thảo" = rich‑editor phạm vi cả trang (chuỗi prose); trong "Trình dựng", CHÍNH
   rich‑editor đó nhúng vào 1 block richtext để sửa ⇒ cùng toolbar, cùng sanitizer, cùng hành vi.
3. **Chọn tab mặc định (suy từ body, không cần field mới):** body rỗng / chỉ 1 block `richtext` → **Soạn thảo**;
   body có ≥1 block cấu trúc (hero/features/product_grid…) → **Trình dựng**. (Tuỳ chọn hint `body.editor`='prose'|'builder'
   chỉ để nhớ lựa chọn; payload luôn là blocks.)
4. **Round‑trip (tất định, trung thực):**
   - Soạn thảo → Trình dựng: **luôn lossless** (prose thành block richtext; chèn thêm section quanh nó).
   - Trình dựng → Soạn thảo: body chỉ gồm block richtext → gộp thành 1 dòng prose (lossless); body có block cấu trúc
     → **KHÔNG flatten**. Khuyến nghị **(a)** Soạn thảo cho sửa các vùng prose, section cấu trúc = **placeholder chỉ‑đọc**
     ("▦ Hero — sửa trong Trình dựng"); hoặc **(b)** khoá tab Soạn thảo + gợi ý "Trang có bố cục — mở Trình dựng".
5. **Mặc định theo loại:** post/bài viết (tin tức/dự án/giới thiệu) chủ yếu prose → Soạn thảo; page landing → Trình dựng.
   Cả hai đều mở được vì cùng 1 body block‑list (D1).
6. **Tại sao không lưu HTML‑blob cho tất cả:** page cần section có kiểu (hero/features/product_grid/form) + media_id + theme
   token + data‑bound + validate an toàn — HTML‑blob không làm được. Prose chỉ là MỘT loại block (richtext(html)).

**Chạm dữ liệu ⇒ COUNCIL propose‑first:** block `richtext(html)` + kiểu §E7 `html` (sanitize‑khi‑ghi) + Blade partial
`{!! !!}` + phủ ValidPageJson (html prop: bắt buộc qua sanitizer, không escape) + test.
