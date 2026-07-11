# Lộ trình triển khai — tổng hợp & khuyến nghị (BSWebSaaS / GĐ1 An Vinh)

> Tổng hợp toàn bộ audit (thiết kế + code repo `main` 2026‑07). Grounded theo CODE (doc có thể cũ hơn).
> Mọi thay đổi schema/CSP/registry = **[COUNCIL] propose‑first** · quy trình **test‑first (Pest)**.
> Doc nền: `builder_v2_alignment.md`, `builder_v3_spec.md`, `reusable_blocks_spec.md`,
> `tracking_integrations_spec.md`, `tracking_storefront_impl.md`, `db_audit_storage.md`.

## 0. Trạng thái đã chốt (thiết kế/prototype đã dựng & kiểm chứng)
- **Page Builder v3** = trình soạn **Section Library → Page‑JSON** (mẫu trang, biến thể layout, chèn xen giữa, hover‑label, ribbon "Lưu & quay lại/Thu nhỏ", brand token, caps 100/256KB, locale VI/EN/JA).
- **Page Editor** (thêm/sửa): 2 tab Soạn thảo/Trình dựng (nhúng v3 + mở toàn màn hình có đường về), AI‑translate, SEO.
- **Block Library** (khối tái dùng): danh sách + Thêm/Sửa + "Dùng ở N nơi" (popover) + quy ước cột ngôn ngữ/hành động.
- **Integrations** (Tích hợp & Theo dõi): ~16 provider, server‑side, Consent 3 mức, CSP động minh hoạ, tooltip "i".
- **Pages Admin (3a)**, **Floating Contact**, **MediaPicker**.

## 1. Nền tảng CODE đã vững (không phải gap — tận dụng)
RLS+FORCE mọi bảng tenant · Page‑JSON = `entry_translations.body` per‑locale · `RichTextSanitizer` (full/basic) dùng ON‑WRITE ·
`ValidPageJson` chặn scheme url · `media.alt`/`title` **jsonb per‑locale** · hreflang + fallback locale + homepage (RouteResolver/PathBuilder) ·
catalog brochure/commerce một‑hình · forms 6 field type CHECK · OAuth nonce · SVG bị loại khi upload.

## 2. Đính chính các đề xuất trước (theo code thật)
- `richtext_html` → **TÁI DÙNG `RichTextSanitizer`** (đã có), không tạo mới.
- **Bỏ `cover_alt`**: alt lấy từ **`media.alt[locale]`**; override theo ngữ cảnh (nếu cần) đặt ở `mediables.alt` (jsonb) hoặc prop block image. Prototype `featuredAlt` phải map về media, **dọn nguồn‑kép**.
- **"Nội dung không dịch"** = hạn chế prototype RTE (chưa bind body per‑locale), **không phải lỗi schema**.
- Form **radio/checkboxes** ở builder P2 **lệch CHECK 6 loại** → hoặc bỏ, hoặc COUNCIL mở rộng CHECK.

## 3. KHUYẾN NGHỊ TRIỂN KHAI — theo sóng (ưu tiên giá trị & phụ thuộc)

**Wave 0 — COUNCIL schema (một đợt migration, test‑first, reversible):**
- `entries_type_check` += `'block'` (khối tái dùng).
- `content_revisions` (bảng RIÊNG, append‑only, prune) cho revisions/autosave/restore — polymorphic để dùng cả product.
- `site_integrations` (provider, enabled, public_id, secret_enc, position, strategy, consent_category) + RLS.
- `block_usages` (site_id, block_id, entry_id, locale) + **GIN `entry_translations.body`** (reference/media lookup).
- Partial composite index `(site_id,type,status,published_at DESC) WHERE deleted_at IS NULL` cho entries & products.
- Chốt: form field types (giữ 6 hay mở rộng).

**Wave 1 — Builder đúng mô hình (đóng mạch):**
- Reframe builder = Section Library xuất Page‑JSON (đã prototype v3) → hiện thực React island (Puck/Livewire) + `theme::sections.{type}`.
- **Bind RTE body per‑locale** trong Page Editor (một body, hai editor; đổi locale nạp lại; lưu vào `data[loc].body`).
- Block `richtext_html` + kiểu §E7 `html` (sanitize‑khi‑ghi) + phủ `ValidPageJson` + test XSS.

**Wave 2 — Khối tái dùng (reusable blocks):**
- Block `reusable_ref{block_id}` + resolve ở `PageRenderer` + **guard đệ quy/độ sâu/ngân sách**.
- Block Library admin + "chèn từ thư viện" + "lưu section thành khối" + "tách khỏi khối" + đếm "dùng ở N nơi" (từ `block_usages`) + guard xoá.

**Wave 3 — i18n / AI‑translate hoàn chỉnh:**
- AI‑translate **cả bản ghi per‑locale** (title/slug/excerpt/seo/body); alt từ media; **hợp đồng**: không ghi đè bản sửa tay · giữ HTML · async+queue+cache · per‑field · cờ `ai`.
- Tận dụng `entry_translations.status` cho **xuất bản độc lập từng ngôn ngữ**.

**Wave 4 — Tracking & Re‑marketing (backend):**
- Collector first‑party + queue fan‑out (Meta CAPI/GA4 MP/TikTok Events API) + **CSP động + nonce** + Consent Mode v2 + **consent record** + attribution lead (gclid/fbclid/utm) + **offline conversion upload**.
- Storefront: một Blade partial `theme::partials.tracking` (head/body_start/body_end) + strategy (nonce/async/defer/idle/interaction‑lazy); chat lazy; proxy `sgtm.<base>` (base‑domain từ config, không hardcode).

**Wave 5 — Thành phần website còn thiếu (đã phát hiện):**
- **Menu builder** (bảng menu_tables đã có) · **Redirects admin + tự tạo 301 khi đổi slug** (bảng redirects đã có) ·
  **Scheduled publish worker** (queue flip theo published_at) · **an toàn draft↔published** (qua content_revisions).

## 4. Nguyên tắc xuyên suốt
Zero hardcode brand/tenant (config) · RLS + Policy + Query Budget (keyset, no N+1, no COUNT(*) live) · CWV (JS ≤30KB, LCP ≤1.5s) ·
propose‑first cho schema/CSP · mỗi màn có empty/loading/error + chặn thoát khi dirty + quy ước màn danh sách.

## 5. Khuyến nghị "một câu"
Bắt đầu **Wave 0 (migration COUNCIL)** vì mọi wave sau phụ thuộc nó; song song **Wave 1** (builder đúng mô hình + RTE body per‑locale) để biến prototype thành thứ chạy thật. Tracking (Wave 4) tách sau vì nặng hạ tầng. Menu/redirect (Wave 5) là mảng website còn thiếu, nên chèn sớm nếu An Vinh cần điều hướng đầy đủ.
