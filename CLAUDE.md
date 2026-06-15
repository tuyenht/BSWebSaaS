# CLAUDE.md — Nền tảng SaaS Website Builder (Bảo Sơn)

> File này được Claude Code **tự động nạp** vào context. Đây là **quy ước + guardrail BẮT BUỘC**.
> **Thiết kế đầy đủ ở `docs/design.md` (v2.7)** — đọc mục liên quan khi cần (KHÔNG nạp toàn bộ, file dài).
> Mọi quyết định kiến trúc theo **16 ADR** trong `docs/design.md` (Phụ lục A).

---

## 0. Bối cảnh & nguyên tắc tối cao

- **Dự án:** SaaS **multi-tenant** website builder (kiểu Sapo/Haravan thu gọn). GĐ1: website giới thiệu công ty/sản phẩm + giỏ hàng + đặt hàng (**COD + VietQR**, KHÔNG cổng thanh toán online).
- **Quy mô đích:** 10.000+ khách, mỗi khách nhiều site; nhưng **bắt đầu từ 1 site thật (An Vinh)**.
- **Bối cảnh phát triển:** làm **SOLO + vibe-coding**. → Thứ tự ưu tiên BẤT DI BẤT DỊCH: **chất lượng → hiệu năng → vận hành → tiến độ.**
- **Quy tắc làm việc:**
  - LUÔN **viết test trước/khi** thêm code (Pest). Không có test = chưa xong.
  - KHÔNG bỏ qua guardrail (mục 6). KHÔNG tự ý nâng major version (mục 1).
  - Commit nhỏ, thông điệp rõ. Mỗi PR/issue làm MỘT việc.
  - Khi không chắc về một quyết định thiết kế → **đọc `docs/design.md`**, không tự đoán.

---

## 1. Tech stack — PIN VERSION (không tự nâng major)

| Tầng | Công nghệ | Ghi chú |
|---|---|---|
| Runtime | PHP **8.5** + Laravel **13** + Octane + **FrankenPHP** (RoadRunner fallback) | worker mode |
| DB | PostgreSQL **17** + **RLS** + `ltree` + `pgvector` + PgBouncer (transaction mode) | |
| Cache/Queue | **Valkey** (KHÔNG Redis) + Horizon | Reverb **HOÃN sau GĐ1** |
| Search | Meilisearch + Scout | **tenant tokens** (mục 2) |
| Admin UI | Livewire 4 + React island (Inertia v2) chỉ cho builder | |
| Storefront | **Blade SSR + Alpine islands** (JS ≤ 30KB) | |
| Build FE | Vite 7 + Tailwind 4 | công cụ build, KHÔNG thay Laravel |
| Storage | Cloudflare **R2** + imgproxy | |
| AI | Laravel AI SDK → Anthropic (sonnet-4-6 / haiku-4-5) | |
| Test/QA | Pest 4 + Infection + Larastan (max) + Rector + Lighthouse CI + axe | |

> ⚠ Nhiều major mới → AI dễ gợi ý SAI cú pháp. Khi viết code framework, **bám tài liệu version đã pin**; nếu nghi ngờ cú pháp, kiểm lại, đừng đoán.

---

## 2. 🔴 BẤT BIẾN BẢO MẬT — KHÔNG BAO GIỜ vi phạm

1. **Multi-tenant:** MỌI bảng tenant có cột `site_id` + **RLS policy**. MỌI Eloquent model tenant dùng trait `BelongsToSite` (global scope tự lọc site_id). Bảng global (miễn RLS) theo danh sách D6 trong `docs/design.md` Mục 17.
2. **GATE #1 (Mục 5 design) — cô lập tenant dưới Octane:** đây là việc PHẢI chứng minh xanh TRƯỚC khi xây bất kỳ tính năng nào:
   - Middleware mở transaction + `SET LOCAL app.current_site_id` mỗi request (KHÔNG dùng `SET` thường).
   - Assertion dev/CI: trước query, khẳng định `current_setting('app.current_site_id')` KHỚP site đã resolve; lệch → ném lỗi.
   - Hook Octane `RequestReceived`/`RequestTerminated` xóa singleton "site hiện tại".
   - **Concurrency test** bắn xen kẽ site A/B qua cùng worker pool → KHÔNG lẫn dữ liệu.
   - Kiểm cả gotcha **PgBouncer transaction-mode + prepared statements** (D14).
3. **KHÔNG lộ dữ liệu chéo tenant.** Search qua **tenant tokens** (key ký nhúng site_id), không chỉ filter ở app.
4. **KHÔNG lộ `bigint id` thô** ra URL/API → public qua **slug** (content) / **code** (order) / **ulid** (media).
5. **Upload an toàn:** whitelist MIME + blocklist đuôi nguy hiểm (`.php .phar .phtml`…) + **sniff magic-bytes** (không tin đuôi/header) + **sanitize SVG** + lưu R2 ngoài web-root.
6. **Hash mật khẩu = Argon2id** + global pepper (KHÔNG derive pepper từ email). Audit qua Audit service (best-effort).
7. **Defense-in-depth:** Entitlement (site được dùng module gì) → RBAC (user làm được gì) → RLS (dữ liệu thấy được gì).

---

## 3. Quy ước DATABASE (D1–D15 — normative, `docs/design.md` Mục 17)

- **D2 · Khóa:** PK = `bigint` identity khắp nơi; thực thể lộ ra ngoài thêm cột `ulid`. Pivot chỉ bigint.
- **D5 · Tiền:** **integer (VND đồng)**, KHÔNG float.
- **D11 · Thời gian:** MỌI cột thời gian = **`timestamptz`**, lưu UTC, render theo timezone site.
- **D12 · Collation VN:** cột so sánh/sắp/unique (email, slug, name) dùng `citext`/ICU `vi-VN`.
- **D13 · Enum:** `status`/`kind`/`link_type` = **`varchar` + `CHECK`** (KHÔNG native ENUM → dễ mở rộng).
- **D4 · FK/soft-delete:** con CASCADE theo cha; tham chiếu mềm SET NULL. Soft-delete (`deleted_at`) chỉ cho thực thể cần Thùng rác — **RLS + scope phải kèm `deleted_at IS NULL`**.
- **D3 · Polymorphic:** typed FK cho content-graph (entry↔taxonomy) + `routes`; polymorphic + observer-cleanup cho đính-kèm-mềm (mediables/menu target/reviews).
- **D15 · Commerce:** `CHECK(stock≥0)` + decrement nguyên tử (KHÔNG đọc-rồi-ghi) chống oversell; payment idempotent.
- **D7 · Tên:** snake_case; KHÔNG dùng từ khóa SQL (`order`→`sort_order`); FK = `{entity}_id`; bool = `is_/has_`.
- **`meta JSONB` + quy tắc "tốt nghiệp":** field hay lọc/sắp/ràng buộc/dùng-chung → lên cột thật. Chống EAV trá hình.

---

## 4. Kiến trúc dữ liệu cốt lõi (đọc `docs/design.md` Mục 7–16 cho đầy đủ)

- **Content = `entries` + 3 trục:** `entries.type` ∈ {`page`, `post`} (VẬT LÝ); `category` (taxonomy, `ltree`) = nhóm; template = hiển thị (gán theo category). **`product` = bảng RIÊNG** (commerce typed), KHÔNG trong entries.
- Các "loại" (tin/dịch vụ/dự án/tuyển dụng…) đều là `post`, khác nhau bằng **category + template + meta**. KHÔNG đẻ type mới tùy tiện.
- **i18n = bảng dịch `*_translations`** (KHÔNG JSONB cho nội dung dài, KHÔNG nhân bản hàng). URL prefix `/{locale}` + slug bản địa hóa qua bảng `routes`.
- **Taxonomy** dùng chung (`ltree` + **typed pivots** `entry_taxonomies`/`product_taxonomies`).
- **RBAC** = spatie/laravel-permission + teams (team=site_id) + `role.level` (phân cấp) + scope own/any. **Module động + Entitlement** (site_modules/content_sections). **Workflow** 2 tầng (permission-driven mặc định + state-machine data-driven tùy chọn).

---

## 5. Coding standards

- Laravel conventions chuẩn; Eloquent + Form Request validation; Action/Service cho logic nghiệp vụ.
- MỌI action qua **Policy** (RBAC); MỌI chuyển trạng thái workflow qua **engine** (không hard-code).
- N+1 = cấm (eager load); tuân **Query Budget** (mục 6).
- Test: Pest 4. Mỗi feature có test; bug fix kèm regression test.
- Migration: expand-contract; `CREATE INDEX CONCURRENTLY`; index dẫn đầu `site_id`.

---

## 6. Guardrails CI (phải XANH trước merge)

- `Larastan` (max level) · `Pest` (unit + feature) · `Infection` (mutation) · `Rector`
- **Query Budget test** — assert số query tối đa/route hot (bắt N+1)
- **Arch test** — "mọi model tenant dùng BelongsToSite", "mọi action qua policy", "mọi transition qua engine"
- `Lighthouse CI` + `axe` (storefront: LCP mobile p75 ≤1.5s, JS ≤30KB)
- **Gate #1 concurrency test** (mục 2) — bắt buộc xanh từ Phase 0

---

## 7. Commands

> _(Cập nhật khi skeleton xong. Dự kiến:)_
```bash
# Dev
./vendor/bin/sail up            # hoặc docker compose up (PG17 + Valkey + FrankenPHP)
php artisan octane:start --watch

# Test & QA (chạy TRƯỚC khi commit)
./vendor/bin/pest
./vendor/bin/phpstan analyse    # Larastan max
./vendor/bin/rector --dry-run
```

---

## 8. Workflow mỗi issue

1. Đọc mục liên quan trong `docs/design.md` (tham chiếu trong issue).
2. **Viết test trước** (hoặc song song) — mô tả hành vi đúng + bất biến bảo mật.
3. Implement tối thiểu để xanh test.
4. Chạy guardrail (mục 6) → phải xanh.
5. Commit nhỏ, mô tả rõ. PR (nếu dùng) một việc.

> **THỨ TỰ PHASE 0:** issue #1 = **Gate #1** (cô lập tenant) → PHẢI xanh trước. Rồi mới: content engine (`post` + content_sections) → menu/banner/media → RBAC 3 tầng + cổng-đăng-theo-quyền → cụm liên hệ + form anti-spam → SEO/CWV gate.

---

## 9. KHÔNG làm (anti-patterns)

- ❌ Tạo `entries.type` mới khi chỉ cần category + template.
- ❌ Bảng `pages` riêng (trang bố cục = `entries` type `page`, layout ở `entry_translations.body`).
- ❌ EAV / `meta` cho field hay-query (dùng quy tắc "tốt nghiệp").
- ❌ Float cho tiền; `timestamp` naive; native ENUM; reserved word làm tên cột.
- ❌ Query thiếu `site_id`; bỏ qua RLS; lộ bigint id thô.
- ❌ Tự nâng major version; thêm dependency nặng không bàn.
- ❌ Reverb/realtime ở GĐ1 (YAGNI); microservices (đây là Modular Monolith).
