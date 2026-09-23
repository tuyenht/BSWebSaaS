# Khối tái dùng (Reusable Blocks) — SPEC (propose‑first / COUNCIL)

> Học concept "UX Blocks" của Flatsome (dựng khối một lần, dùng nhiều nơi) NHƯNG bỏ shortcode.
> Bám ADR‑04/05/08/17: Page‑JSON + Section Library, không mã khách, export‑được, RLS đa tenant.
> Nguồn liên quan: `docs/archive/2607-design-sync/builder_v2_alignment.md`, `docs/builder_v3_spec.md`.

## 1. Mô hình dữ liệu
- **Khối tái dùng = `entries type='block'`** (đồng bộ "page/post = entries"). Thân khối = **Page‑JSON** (`entry_translations.body`).
- **i18n tự nhiên:** mỗi khối có bản dịch per‑locale qua `entry_translations` → **MỘT khối, nhiều ngôn ngữ**. (Flatsome phải nhân bản "Top left" / "Top left (English)" — mình KHÔNG cần.)
- Mỗi khối có: `id` (int, không lộ thô), `key`/slug ổn định (vd `footer`), `name`, `region` gợi ý (footer/header/sidebar/inline), `status`, RLS `site_id`.

## 2. Tham chiếu — KHÔNG shortcode
- Thêm block đăng ký **`reusable_ref`** (ComponentRegistry) với prop **`block_id:int`** (+ optional `key`).
- Render: `PageRenderer` gặp `reusable_ref` → resolve `entries[block_id]` body theo **locale hiện tại** → `BlockRenderer` chèn inline. Không chèn mã, không shortcode; **export bung tham chiếu**.
- `ValidPageJson`: `block_id` phải trỏ tới entry `type='block'` cùng site (allow‑list); prop khác escape.

## 3. Guard bắt buộc
- **Chống đệ quy/vòng lặp:** khối không được nhúng chính nó, chặn chu trình (A→B→A); **giới hạn độ sâu** (vd ≤3).
- **Ngân sách:** tổng sau khi bung ≤ 100 block / 256KB.
- Xoá khối đang được dùng → cảnh báo (đang dùng ở N nơi) hoặc chặn/thay thế.

## 4. Trải nghiệm (đều dùng builder v3 sẵn có)
- **Thư viện khối tái dùng** (màn admin, như trang UX Blocks): danh sách khối, tạo/sửa bằng builder v3, xem **"Dùng ở N nơi"** trước khi sửa, badge ngôn ngữ (một hàng/khối), trạng thái.
- **Chèn từ thư viện:** trong bộ chọn section của builder thêm nhóm **"Khối tái dùng"** → chọn khối đã lưu → chèn `reusable_ref`.
- **Lưu section thành khối tái dùng** (từ một section trên trang) → tạo `entries type='block'` + thay section bằng `reusable_ref`.
- **Tách khỏi khối** (detach) → thay `reusable_ref` bằng bản sao các section (sửa riêng), như WP "convert to regular".
- **Chỉ báo tham chiếu:** section là tham chiếu hiển thị khung "khối tái dùng — sửa ở khối gốc" (không sửa tại chỗ), bấm mở khối gốc.

## 5. Footer / Header / Cấu hình
- Vùng theme (footer/header/topbar) render **một `reusable_ref`** trỏ tới khối tương ứng → đúng mô hình "Footer block" của Flatsome nhưng typed/an toàn.
- Trang Liên hệ, CTA khuyến mãi… dùng chung một khối, sửa một nơi.

## 6. Hiệu năng
- Cache render **theo khối**; sửa khối → bust cache các trang dùng nó (hoặc trang resolve từ cache khối). Tránh N+1 khi resolve nhiều tham chiếu (batch).

## 7. Lợi thế so với Flatsome (nêu khi bàn giao)
Đa ngôn ngữ không nhân bản · typed/không mã khách · export‑được · biết "dùng ở đâu" trước khi sửa · cache theo khối.

## 8. [COUNCIL] cần chốt
Thêm `entries type='block'` (+ routes? — khối KHÔNG có URL công khai) · block `reusable_ref` + resolve ở renderer · guard đệ quy/độ sâu/ngân sách · usage index (đếm "dùng ở N nơi") · quyền (ai sửa khối dùng chung — Policy) · cache/bust · export bung tham chiếu.

## 9. UI deliverable (prototype)
`Block Library - Options.dc.html` — màn "Thư viện khối tái dùng" (danh sách + tìm/lọc + hành động + giải thích khác shortcode). Luồng chèn‑từ‑thư‑viện wire vào `Page Builder v3.dc.html` (nhóm "Khối tái dùng" + `reusable_ref`).
