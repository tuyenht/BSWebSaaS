> 🗄 **LƯU TRỮ (24/09/2026)** — tài liệu làm việc của đợt đồng bộ thiết kế tháng 7/2026, giữ làm lịch sử.
> KHÔNG phải nguồn sự thật: có chỗ đã lỗi thời hoặc mâu thuẫn quyết định sau này. SSOT = `docs/design.md`
> (chỉ mục ADR ở Phụ lục A) + `docs/README.md`. Lý do lưu trữ: `docs/archive/README.md`.

# Audit CSDL & lưu trữ (BSWebSaaS) — chuẩn hoá & tối ưu

> ⚠️ **ĐÃ ĐÍNH CHÍNH (ưu tiên đọc `implementation_roadmap.md` §2 + `session_handoff.md` §3‑4):**
> (a) **Bỏ đề xuất `cover_alt` ở mục B2** — alt lấy từ **`media.alt[locale]`** (đã jsonb per‑locale trong code); override chỉ khi cần ở `mediables.alt`/prop.
> (b) **Revisions:** **đối chiếu `entry_revisions` (code có thể đã tồn tại)** trước khi thêm `content_revisions` — có thì dùng lại, chỉ bổ sung `kind=autosave`/prune nếu thiếu.
> (c) `richtext_html` **dùng lại `RichTextSanitizer::full`** (đã có), không tạo mới.

> Đọc trực tiếp migrations `main` (2026‑07). Kết luận: **schema lõi đã chuẩn & tối ưu tốt** — KHÔNG thiết kế lại.
> Chỉ **bổ sung có mục tiêu** cho các tính năng vừa thiết kế (khối tái dùng, tracking, i18n alt, hiệu năng liệt kê).
> Mọi mục = **[COUNCIL] propose‑first** (chạm migration/index).

## A. Đang RẤT tốt — giữ nguyên
- **Đa tenant:** mọi bảng tenant có `site_id` + **RLS + FORCE + policy fail‑closed** (kể cả pivot), auto‑verify bằng `RlsCoverageTest`. Cách ly tuyệt đối.
- **D‑rules nhất quán:** ULID + bigint (D2), timestamptz (D11), **citext slug** (D12), enum = **varchar + CHECK** (D13), tiền = integer/bigInteger VND (D5), soft‑delete (D4).
- **routes typed:** `CHECK num_nonnulls(entry_id,product_id,taxonomy_id)=1` + `UNIQUE(site_id,locale,path)` — một path trỏ đúng một đích.
- **i18n đúng chuẩn:** `entry_translations` `UNIQUE(entry_id,locale)`, **body jsonb per‑locale** (Page‑JSON), có `excerpt/seo_title/seo_description/og_image_id/status` **per‑locale**. → *Đính chính: "nội dung không dịch" là hạn chế của PROTOTYPE RTE (chưa bind body per‑locale), KHÔNG phải lỗi schema — schema đã đúng.*
- **taxonomy:** adjacency `parent_id` + **ltree `path` + GiST**; pivot typed có `position`.
- **catalog:** brochure/commerce **dựng một lần** (cột commerce nullable), `UNIQUE(site_id,sku) WHERE sku IS NOT NULL` (partial), `CHECK stock/price>=0`.
- **forms:** field type CHECK 6 loại; submissions có cột "graduated" (name/email/phone) + `data` jsonb + index hợp lý.

## B. Cần BỔ SUNG cho tính năng đã thiết kế
1. **Khối tái dùng = `entries type='block'`** → **sửa `entries_type_check`** thành `IN ('page','post','block')`. Khối không cần `routes` (không có URL công khai).
2. **Alt ảnh per‑locale (i18n SEO)** — đang thiếu:
   - Ảnh trong Page‑JSON: alt để trong **prop của block image** (đã per‑locale theo body) ✅ — chỉ cần chuẩn hoá prop `alt` trong §E7.
   - Ảnh **cover/OG của entry**: thêm `entry_translations.cover_alt` (+ `product_translations.cover_alt`) để dịch được. (Đây là gốc việc "Alt không dịch".)
3. **Tham chiếu khối (`reusable_ref`) + "Dùng ở N nơi":** references nằm trong body JSONB (không FK) → cần:
   - **GIN index** `entry_translations.body jsonb_path_ops` để truy vấn chứa nhanh (tìm block_id / media_id đang dùng ở đâu).
   - Tối ưu đếm O(1): bảng **`block_usages(site_id, block_id, entry_id, locale)`** duy trì khi lưu (đếm "dùng ở N nơi" tức thì) + **guard xoá** khối đang dùng (vì không có FK).
4. **Tracking & Re‑marketing:**
   - **`site_integrations(site_id, provider, enabled, public_id, secret_enc, position, strategy, consent_category, updated_at)`** — secret **mã hoá at‑rest**, RLS. (CSP động đọc từ đây.)
   - **Attribution cho lead:** thêm cột/định dạng chuẩn trên `form_submissions` — `gclid/fbclid/ttclid` + `utm_*` (cột riêng có index, hoặc `data.attribution` JSONB có shape chuẩn) → phục vụ Offline Conversion Upload.

## C. Tối ưu HIỆU NĂNG (index cho liệt kê/keyset @scale)
- **entries:** hiện `index(site_id,type,status)`. Liệt kê "tin mới nhất/dịch vụ" sắp theo `published_at DESC` → thêm **partial composite** `entries(site_id, type, status, published_at DESC) WHERE deleted_at IS NULL` (keyset, tránh sort nặng).
- **products:** đã có `published_at` (migration 2013) → thêm `products(site_id, status, published_at DESC) WHERE deleted_at IS NULL`.
- **GIN body** (mục B3) phục vụ cả reference & media lookup.
- Giữ nguyên nguyên tắc: eager‑load, keyset phía server, không N+1, không `COUNT(*)` live bảng lớn (dùng `block_usages`/cache cho đếm).

## D. Nhỏ / polish (cân nhắc)
- `entries.meta` / `products.meta` jsonb: thêm **GIN** chỉ khi thật sự truy vấn theo meta.
- `entry_translations.status` per‑locale đã hỗ trợ **xuất bản độc lập từng ngôn ngữ** (khớp "chưa dịch/đã dịch") — tận dụng ở UI.
- Analytics/event tracking KHÔNG ghi Postgres OLTP realtime → queue → store riêng (đã nêu ở tracking spec).

## E. Kết luận
Không "sai" ở lõi — schema đạt chuẩn SaaS đa tenant. Việc cần làm là **5 bổ sung [COUNCIL]**: (1) `type='block'`, (2) `cover_alt` per‑locale, (3) `block_usages` + GIN body, (4) `site_integrations` + attribution lead, (5) partial composite index cho liệt kê. Tất cả là *thêm mới, không phá vỡ* — an toàn, có thể migrate tiến dần.
