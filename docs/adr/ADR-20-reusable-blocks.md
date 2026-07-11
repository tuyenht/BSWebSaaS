# ADR‑20 — Khối tái dùng: **bảng typed `content_blocks`** + `reusable_ref` + guard + usage index

> ⚠️ **ĐÃ ĐÍNH CHÍNH theo commit land (design.md §20).** Bản staging trước đề xuất `entries.type='block'` — **council đã BÁC**, chốt **bảng typed riêng `content_blocks`**. File này viết lại theo quyết định LAND. CODE thắng.

- **Trạng thái:** **Đề xuất (Draft)** — LAND design.md dòng 20 + v2.14. **Build HOÃN** (YAGNI — An Vinh brochure chưa cần). **Chạm schema** → COUNCIL.
- **Liên quan:** ADR‑04/05/08/17 · §8 (type chỉ page|post) · §9. **Nguồn:** `docs/reusable_blocks_spec.md`, `db_audit_storage.md §B1/B3`.

## Bối cảnh (grounded CODE)
- Muốn "dựng khối 1 lần, dùng nhiều nơi" (footer/header/CTA/Liên hệ) **không shortcode**.
- **§8 là bất biến:** `entries.type ∈ {page, post}`; thực thể **hành vi khác** = **bảng typed riêng** (precedent: `products`). Khối tái dùng = hành vi khác (không URL, không list, là fragment nhúng) ⇒ **KHÔNG nhét vào `entries.type`**.
- `entry_translations` per‑locale ⇒ một khối, nhiều ngôn ngữ **không nhân bản** (khác Flatsome phải nhân đôi).
- `ValidPageJson` = allow‑list `ComponentRegistry`; rule **thuần cấu trúc, KHÔNG query DB**.

## Quyết định (khớp design.md §20 — typed table, KHÔNG `entries.type='block'`)
1. **Bảng typed `content_blocks`(+`content_block_translations`)** — mirror mô thức entries: `content_blocks(id, site_id, key, region?, status, meta, timestamps, deleted_at)` + `content_block_translations(block_id, site_id, locale, body jsonb, UNIQUE(block_id, locale))`. **+ site_id + RLS + FORCE** (D6). **KHÔNG `routes`** (khối không có URL công khai). i18n qua bảng dịch.
2. **Block `reusable_ref`** (Cms) prop `block_id:int(>0)` (+ optional `key`). §E7 `block_id` kiểu `int` → ValidPageJson cover cấu trúc. Ràng buộc "trỏ đúng `content_blocks` cùng site" đặt ở **save‑time FormRequest** + **resolve‑time guard** (KHÔNG ở ValidPageJson).
3. **Resolve ở `PageRenderer`:** gặp `reusable_ref` → nạp `content_block_translations[block_id].body` theo **locale hiện tại** → chèn inline vào `BlockRenderer`. **Export = bung tham chiếu** (không còn ref sau export).
4. **Guard bắt buộc:** chống tự‑nhúng + chu trình `A→B→A` + **độ sâu ≤3** + **ngân sách sau bung ≤100 block/256KB** (tái dùng hằng của `ValidPageJson`).
5. **"Dùng ở N nơi" (đếm O(1)):** bảng **`block_usages(site_id, block_id, entry_id, locale)`** duy trì khi lưu/xoá + **GIN `entry_translations.body jsonb_path_ops`** (tra trang nào dùng block X / media_id nào). **Guard xoá** khối đang dùng (không FK ràng → chặn/cảnh báo "đang dùng ở N nơi").

## [COUNCIL] Thay đổi
- **Schema:** tạo `content_blocks` + `content_block_translations` (+RLS/FORCE/site_id); `block_usages` (+RLS); GIN `entry_translations.body`. *(Gộp Wave 0 để khỏi migrate 2 lần dù UX hoãn.)*
- `ComponentRegistry`: +`reusable_ref`. `PageRenderer`: nhánh resolve + guard đệ quy/độ sâu/ngân sách + batch (chống N+1) + cache theo khối/bust.
- Save‑path FormRequest: check `block_id ∈ content_blocks(site)`. Policy: ai sửa khối dùng chung. Module: **Cms** (khối là nội dung CMS, không phải entry).

## Hệ quả
- **+** Không đụng bất biến §8; đa ngôn ngữ không nhân bản; typed/không mã khách; export‑được; biết "dùng ở đâu" trước khi sửa; footer/header = 1 `reusable_ref`.
- **−** Thêm bảng + tầng resolve (cache + batch); guard đệ quy bắt buộc; usage‑index phải duy trì đúng khi lưu/xoá/tách.

## Test‑first (Pest)
- `ContentBlocksRlsTest` — RLS cách ly `content_blocks`/`content_block_translations`; soft‑delete + `deleted_at IS NULL` trong scope.
- `ValidPageJsonReusableRefTest` — `block_id` phải int>0; ref tới id không thuộc `content_blocks(site)` bị chặn ở FormRequest.
- `ReusableRefResolveTest` — resolve đúng locale; chu trình A→B→A chặn; độ sâu >3 chặn; tổng >100/256KB chặn.
- `BlockUsagesCountTest` — đếm đúng khi lưu/xoá; **guard xoá** khối đang dùng; RLS `block_usages`.
- `ReusableBlockExportTest` — export bung tham chiếu.

## Phụ thuộc
Schema đi Wave 0 (cùng đợt migration COUNCIL). UX (Block Library + chèn/lưu/tách) HOÃN — An Vinh GĐ1 chưa cần; Draft giữ. Prototype `Block Library - Options.dc.html` cần **reframe**: khối = thực thể `content_blocks`, không phải entry.
