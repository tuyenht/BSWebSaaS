# NỀN TẢNG SAAS WEBSITE BUILDER ĐA TENANT — TÀI LIỆU THIẾT KẾ HỆ THỐNG (v2.23)

**Chủ dự án:** Hoàng Thanh Tuyền (Senior Tech Lead, Bảo Sơn) · tuyenht@gmail.com · 0962 04 6262
**Trạng thái:** v2.23 — 14 quyết định 24/09 cho kế hoạch thiết kế-trước SaaS Starter (NAP theo §14 = `site_profiles` · `site_locales.is_public` · trình đơn trỏ Sản phẩm · bảng lời mời · bảng thông báo riêng · tuỳ chọn cá nhân trong `users.preferences` · một Shell cho 2 mặt, không chuyển mặt trong phiên · bộ AI = module sau core v1 · luật arch Tenancy\Models có hiệu lực lại + danh sách nợ) + sắp xếp `docs/` (roadmap · sổ màn · design-briefs · archive); trước đó v2.22 — nhóm quyền tùy chỉnh (backend, R1–R6, 0 migration) + bất biến pivot taxonomy (`position 0` = chuyên mục chính, term phụ ≥1000); trước đó v2.21 — theme token 2 tầng seed→role (as-built token hóa màu storefront) + CI job `cwv` informational + màn Thành viên resolve theo ulid trong phạm vi site (vá lộ chéo tenant); trước đó v2.20 — Hộp duyệt (bề mặt của người duyệt, `EntryPolicy::review` không đòi quyền sửa) đóng Phase 1 Workflow UI; trước đó v2.19 — bài public pre-workflow đứng ở bước `is_published` (đảo v2.18) + status-menu reset stage + audit `entries.status.changed` mọi đường; trước đó v2.18 — as-built phía soạn thảo: khối "Trạng thái duyệt" thay nút Đăng cho bài governed + đóng đường vòng đặt lịch (`scheduled` từ chối, scheduler bỏ qua) — doc-only; trước đó v2.17 — §15.4 as-built lớp bật/tắt workflow (operator-gated, entitlement, phạm vi govern, đổi-template) + đính chính tên bảng `content_workflow_log` → **`workflow_log`** (tên thật từ migration 2015) + trail tự mô tả (`workflow_id`, `labels`); trước đó v2.16 đính chính pin Build: Vite 7→8 (repo dùng `^8.0.0` từ scaffold đầu, CI xanh — doc-only); trước đó v2.15 mở rộng ADR-04: style-props token hoá + preset registry platform-curated cho Page Builder (2 council 12/07, spec `docs/notes/section-style-system.md`) · v2.14 doc-sync as-built + 4 ADR Draft · v2.13 permalink flat-default · v2.12 ADR-18 data-platform. Lõi thiết kế ĐÓNG BĂNG; đang triển khai An Vinh.
**Nguồn:** hợp nhất từ tài liệu khởi tạo v1.0→v1.30 (41 mục + 14 ADR) + khảo sát 5 website thật + schema t5g.
**Bản lịch sử (đầy đủ quá trình tranh luận):** `ke-hoach-tong-the-saas-website-platform.md` (v1.30) — *lưu ngoài repo, không có trong git*.

> **Tài liệu này được tổ chức lại theo cấu trúc logic** (không theo trình tự bồi đắp), **gập mọi tinh chỉnh vào dạng quyết định cuối** (không còn "đã sửa/đã revise"), bỏ phần thăm dò trùng lặp. Mục tiêu: tài liệu thiết kế xây dựng chuẩn nhất để khởi công.
>
> **Quy ước tham chiếu:** chú thích *(Mục NN)* / *(ADR-NN)* **ở tiêu đề mục** = mục/ADR **nguồn trong bản v1.30** (để tra cứu phần tranh luận chi tiết). Tham chiếu "(Mục N)" **trong thân bài** = mục **trong chính tài liệu v2.0 này** (1–33).

---

## MỤC LỤC

**PHẦN A — TỔNG QUAN & KIẾN TRÚC**
1. Tóm tắt điều hành & bối cảnh
2. Phạm vi theo giai đoạn (GĐ1/2/3)
3. Kiến trúc tổng thể
4. Tech stack chốt
5. Đa tenant & cô lập dữ liệu
6. Tầng trình bày (Storefront + Admin)

**PHẦN B — MÔ HÌNH DỮ LIỆU (trọng tâm)**
7. Triết lý dữ liệu (Hybrid)
8. Content engine: `entries` + 3 trục Type × Category × Template
9. Taxonomy (ltree + typed pivots)
10. Đa ngôn ngữ dữ liệu (bảng dịch + routes)
11. Commerce
12. Media + Image CDN
13. Điều hướng & Placement (menu/region + banner/zone)
14. Settings & Site Profile
15. Phân quyền (RBAC) + Workflow + Module/Entitlement (3 tầng)
16. Lược đồ tổng hợp (toàn bộ bảng)
17. Hiệu năng DB & quy tắc toàn vẹn

**PHẦN C — TÍNH NĂNG & UX**
18. SEO / AEO + Core Web Vitals
19. Theme Engine & Page Builder
20. Tích hợp AI
21. Nội dung demo ảo + tài nguyên dùng chung
22. Xuất bản độc lập (Standalone Export) + License
23. Đặc thù Việt Nam
24. UX tối ưu vô hình

**PHẦN D — VẬN HÀNH & CHẤT LƯỢNG**
25. Cache (5 tầng)
26. Tìm kiếm (Meilisearch)
27. Email · Billing · Hóa đơn điện tử · Vòng đời tài khoản
28. Tên miền (subdomain + custom domain)
29. Bảo mật (defense-in-depth)
30. Chất lượng cho solo + AI (guardrails)
31. Observability · Capacity · SLO

**PHẦN E — TRIỂN KHAI**
32. Lộ trình theo giai đoạn + An Vinh đầu tiên
33. Câu hỏi mở cần chốt để đóng yêu cầu

**PHỤ LỤC**
- A. Chỉ mục 18 ADR (trạng thái cuối)
- B. Sổ rủi ro R1–R24
- C. Khảo sát 5 website + schema t5g (cô đọng)
- E. Spec bổ sung (kéo từ docs cũ + platform-core)
- F. Changelog (v1.0 → hiện tại)

---

# PHẦN A — TỔNG QUAN & KIẾN TRÚC

## 1. Tóm tắt điều hành & bối cảnh

**Sản phẩm:** Nền tảng SaaS multi-tenant cho phép tạo và vận hành nhiều website (kiểu Sapo/Haravan thu gọn): website giới thiệu công ty/sản phẩm + giỏ hàng + đặt hàng. Quy mô mục tiêu 10.000+ khách, mỗi khách nhiều site, mỗi site có thể có CSDL/nội dung riêng (web động). Có khả năng AI sinh theme/trang, SEO + AEO tốt nhất, hiệu năng cao, và **xuất bản độc lập** (eject) cho khách muốn tự host.

**Bối cảnh phát triển (quyết định nhiều lựa chọn kiến trúc):**
- Thực hiện **một mình (solo)**, **vibe coding bằng Claude Code + AI là chính**.
- Chưa thạo sâu Laravel/Inertia/React; chưa từng vận hành hệ lớn nhiều người dùng.
- Nền tảng cũ: ~20 năm kinh nghiệm, mạnh SQL Server/ASP.NET; Bảo Sơn đang vận hành core CMS với **~5.000 website** + WHMCS (368 license BSWeb-).
- Hệ quả: ưu tiên **đơn giản vận hành, guardrail tự động bù kinh nghiệm, kiến trúc ép chất lượng** thay vì tối đa hóa công nghệ.

**Kim chỉ nam:** mô hình **hội đồng chuyên gia** (đề xuất → phản biện → chốt tối ưu). Thứ tự ưu tiên: **chất lượng → hiệu năng → vận hành → tiến độ.**

## 2. Phạm vi theo giai đoạn

| GĐ | Phạm vi | Thanh toán | Ghi chú |
|---|---|---|---|
| **GĐ1 (MVP)** | Website giới thiệu công ty/sản phẩm + danh mục + giỏ hàng + đặt hàng; CMS đa loại nội dung; SEO/AEO; đa ngôn ngữ; AI sinh theme/trang; admin phân quyền + workflow | **COD + VietQR** (KHÔNG cổng online) | Triển khai **An Vinh** (B2B cơ khí) đầu tiên |
| **GĐ2** | Mở rộng commerce, thêm locale theo nhu cầu khách, tối ưu theo số đo, headless-ready | + cổng online khi cần | Đo-rồi-mở |
| **GĐ3** | Standalone Export bản hoàn chỉnh, marketplace theme, tính năng nâng cao | Webhook/API mở | — |

**Không làm GĐ1:** cổng thanh toán online, microservices, tách DB per-tenant mặc định, tenant tự định nghĩa content type (kiến trúc đã sẵn — bật sau).

## 3. Kiến trúc tổng thể

- **Modular Monolith** (KHÔNG microservices) — module hóa theo domain trong 1 codebase; rõ ranh giới, dễ vận hành cho solo. *(ADR-01)*
- **Laravel 13 + PHP 8.5** (min 8.3) chạy **Octane + FrankenPHP** worker mode (RoadRunner fallback). *(ADR-09)*
- **PostgreSQL 17** single cluster, shared schema + `site_id` + **RLS 2 lớp**; **PgBouncer** transaction mode. *(ADR-03)*
- **Valkey** (thay Redis — BSD license sạch, drop-in) cho cache/queue/session; **Horizon** queue. *(**Reverb** realtime HOÃN sau GĐ1 — YAGNI, xem stack.)*
- **Cloudflare R2** (zero egress) + **imgproxy** cho media; **Meilisearch** cho search.
- **Cloudflare** CDN/WAF phía trước; **Cloudflare for SaaS** cho custom domain.

```
[Cloudflare CDN/WAF] → [FrankenPHP+Octane: Laravel 13]
                          ├─ Storefront: Blade SSR + Alpine islands
                          ├─ Admin: Livewire 4/Volt + React island (builder)
                          ├─ Queue: Horizon (Valkey)  · Realtime: Reverb
                          └─ Services: AI(Anthropic), Search(Meilisearch)
[PostgreSQL 17 + RLS + pgvector + ltree] ← PgBouncer
[Valkey] cache/queue/session · [R2 + imgproxy] media · [images.bsweb.vn] image CDN
```

## 4. Tech stack chốt

| Lớp | Lựa chọn | Lý do |
|---|---|---|
| Ngôn ngữ/Framework | **PHP 8.5 + Laravel 13** | Hệ sinh thái mạnh, AI hỗ trợ tốt, Bảo Sơn quen PHP |
| Runtime | **Octane + FrankenPHP** (RoadRunner fallback) | Worker mode, HTTP/2-3, hiệu năng cao |

> 🟢 **Lưu ý version (vibe-coding bằng AI):** stack dùng nhiều bản major mới nhất cùng lúc (Laravel 13 + PHP 8.5 + Tailwind 4 + Vite 8 + Livewire 4 + Pest 4 + FrankenPHP) → ít dữ liệu training/Stack Overflow → AI dễ gợi ý sai cú pháp. **Pin version cụ thể** (lock file) + chấp nhận vài chỗ cần kiểm tay. RoadRunner fallback đã có (ADR-09).
| CSDL | **PostgreSQL 17** + `pgvector` + `ltree` | RLS, JSONB, full-text, cây taxonomy |
| Pooler | **PgBouncer** (transaction mode) | Nhiều worker Octane |
| Cache/Queue/Session | **Valkey** + **Horizon** | BSD license sạch, drop-in Redis |
| Realtime | **Laravel Reverb** *(HOÃN sau GĐ1 — YAGNI)* | WebSocket native; GĐ1 chưa có tính năng cần WebSocket, notification chạy async qua Horizon → bật khi thực sự cần |
| Storefront UI | **Blade SSR + Alpine.js islands** (JS ≤30KB) | CWV, SEO, nhẹ *(ADR-02)* |
| Admin UI | **Livewire 4/Volt** + **React island** (chỉ builder canvas, qua Inertia v2) | Năng suất + builder phong phú *(ADR-02)* |
| Build | **Vite 8 + Tailwind 4** | — |
| Media | **Cloudflare R2** (private) + **Worker/cf.image** serving + custom `App\Modules\Media` (imgproxy = L4 cold) | ADR-17 (supersede §12) |
| Search | **Meilisearch + Scout** | Nhanh, gõ tiếng Việt tốt |
| AI | **Laravel AI SDK → Anthropic** (claude-sonnet-4-6 / claude-haiku-4-5) | Provider-agnostic qua interface riêng |
| Editor builder | **Puck** (MIT React editor — chỉ admin) | Cần spike parity preview iframe |
| Email giao dịch | **Postmark/SES/Resend** + SPF/DKIM/DMARC | Deliverability |
| Billing/License | **WHMCS** (đang vận hành) + hóa đơn điện tử VN | Tái dùng hạ tầng Bảo Sơn |
| Chất lượng | **Pest 4** + Infection (mutation) + **Larastan max** + Rector + ESLint + Lighthouse CI + axe | Guardrail tự động |

## 5. Đa tenant & cô lập dữ liệu *(ADR-03)*

- **Mô hình thực thể:** **`tenant`** (khách hàng) **1—N** **`site`** (website); mỗi site có domain(s) riêng. **`user`** = định danh **toàn cục** (một tài khoản); thành viên gắn qua **`tenant_users`** + **role per site** (team = site_id, Mục 15). Một user có thể thuộc nhiều site với role khác nhau (agency).

```sql
-- BẢNG TENANCY LÕI (khóa của toàn hệ — định nghĩa rõ)
tenants(id BIGINT PK, ulid, name, status, plan_id NULL, owner_user_id, settings JSONB, created_at, updated_at, deleted_at NULL)
users(id BIGINT PK, ulid, name, email UNIQUE, password, two_factor_secret NULL, admin_locale, ... , created_at, updated_at)   -- TOÀN CỤC; admin_locale = ngôn ngữ MENU admin (độc lập locale nội dung site, §15.2)
tenant_users(tenant_id, user_id, created_at, UNIQUE(tenant_id, user_id))                                        -- thành viên tenant
sites(id BIGINT PK, ulid, tenant_id, name, status,             -- status: provisioning|active|suspended|archived
      site_type_id NULL FK→site_types,                         -- hình-dạng-chức-năng: bộ module + role-template theo loại (§15.2), kế thừa LIVE
      theme_id NULL, created_at, updated_at, deleted_at NULL)   -- default-locale: site_locales.is_default (§10/ADR-11)
domains(id BIGINT PK, site_id, hostname UNIQUE, kind, is_primary, ssl_status, verified_at NULL, created_at, updated_at)
       -- kind: subdomain|custom ; is_primary: 1 domain chính/site (cho canonical + redirect)
```
- **`sites.id` = khóa tenant** (`site_id`) dùng trên MỌI bảng tenant. `tenant_id` chỉ ở `sites` (gián tiếp qua site cho dữ liệu nội dung) — RLS lọc theo `site_id`, không theo tenant_id.
- **Shared schema + `site_id`** trên mọi bảng tenant. Hai lớp cô lập:
  1. **Application:** trait `BelongsToSite` (global scope tự lọc site_id) + middleware resolve site theo domain/subdomain.
  2. **Database:** **Row-Level Security (RLS)** — policy `site_id = current_setting('app.current_site_id')::bigint`. **Đây là lưới an toàn số 1** chống lộ dữ liệu chéo, kể cả khi code quên scope (quan trọng với vibe coding solo — R1, R16).
- **PgBouncer transaction mode** + `SET LOCAL app.current_site_id` (KHÔNG dùng `SET` thường) mỗi request/transaction.

> 🔴 **GATE #1 CỦA PHASE 0 — Cô lập tenant dưới Octane (rủi ro chết người, phải CHỨNG MINH trước khi xây tính năng).** Cơ chế: Octane giữ worker sống lâu → state rò giữa request; PgBouncer transaction-mode xoay vòng kết nối; `SET LOCAL` chỉ sống trong 1 transaction. Thiết kế đúng nguyên tắc, nhưng nếu `site_id` set SAI thì **RLS thực thi đúng chính sách cho NHẦM tenant — tệ hơn không có RLS** (an toàn giả), và bug **chỉ lộ dưới tải đồng thời**, không bao giờ thấy khi test một mình. Ba kịch bản hỏng: (1) query chạy NGOÀI transaction → chưa SET LOCAL → RLS thấy site_id rỗng/cũ; (2) singleton "site hiện tại" của Octane từ request A rò sang request B; (3) lỡ dùng `SET` thường → biến dính kết nối → áp cho tenant khác qua pool. **Bốn lớp phòng thủ bắt buộc (xanh trước khi đi tiếp):**
> 1. **Middleware** mở transaction + `SET LOCAL app.current_site_id` mỗi request, lấy site từ resolve domain→site.
> 2. **Assertion ở dev/CI + sampling prod:** trước mỗi query khẳng định `current_setting('app.current_site_id')` của DB **khớp** site app đã resolve; lệch → ném lỗi NGAY (biến bug-âm-thầm thành lỗi-ồn-ào).
> 3. **Hook vòng đời Octane** (`RequestReceived`/`RequestTerminated`) **xóa singleton** "site hiện tại" ở đầu/cuối mỗi request.
> 4. **Concurrency test bắt buộc:** bắn xen kẽ request site A/B qua cùng worker pool, khẳng định KHÔNG lẫn dữ liệu. Phải xanh trước khi xây tính năng.
- **Escape-hatch:** tách DB riêng cho tenant rất lớn (driver-swap qua connection resolver) — không làm mặc định.
- **Defense-in-depth tổng thể (3 lớp + RLS):** Entitlement (site được dùng module gì) → RBAC (user làm được gì) → RLS (dữ liệu thấy được gì). Xem Mục 15, 29.

## 6. Tầng trình bày *(ADR-02)*

- **Storefront (web khách xem)** = **Blade SSR + Alpine.js islands**. Ngân sách **JS ≤ 30KB gzip**. Lý do: CWV (LCP/INP) + SEO/AEO + preview Zalo/Facebook + nhẹ. KHÔNG dùng React/Next ở storefront (xem Mục 18).
- **Admin (quản trị)** = **Livewire 4/Volt làm chính** (năng suất, ít JS thủ công) + **React island CHỈ cho builder canvas** (kéo-thả phong phú) nhúng qua **Inertia v2**. *(Livewire + Octane cần reset state đúng — lưu ý khi dựng.)*
- **Headless-ready:** tách rõ tầng nội dung/API để tương lai phục vụ app/headless mà không đập kiến trúc.
- **Làm rõ toolchain (tránh nhầm tầng):** **Laravel** = framework **backend** (app/serve, Octane); **Vite** = công cụ **build frontend** (đóng gói JS islands ≤30KB + React builder + Tailwind). Vite là build tool **mặc định đi kèm Laravel** (từ 9.19), **bổ trợ chứ KHÔNG thay thế** nhau: Laravel+Octane lo tốc độ server (TTFB), Vite lo tốc độ client (tree-shaking/code-split giữ JS nhỏ → CWV). Dùng **cả hai**.
- **Điều hướng tức thời/mượt (storefront):** cross-document **View Transitions** (L1 — CSS thuần, bật mặc định vô hình *Mục 24*) + **Speculation Rules** (L2 — điều-kiện-benchmark TTFB-VN *Mục 18*) trên nền **MPA (KHÔNG SPA)**. Quyết định & ràng buộc: `docs/notes/storefront-instant-nav.md`.

---

# PHẦN B — MÔ HÌNH DỮ LIỆU

> Phần này là trọng tâm. Triết lý: **lấy phần hay của WordPress (1 bảng nội dung + taxonomy dùng chung), bỏ phần dở (EAV `wp_postmeta`, i18n nhân bản).** Đã kiểm chứng với schema thật của t5g (Posts.ObjectType ≈ entries.type, SeoDescription ≈ bảng dịch, Category.ObjectType ≈ taxonomy.vocabulary) và 5 website Bảo Sơn.

## 7. Triết lý dữ liệu (Hybrid hiện đại) *(ADR-14, ADR-11)*

1. **`entries`** = MỘT bảng cho mọi nội dung biên tập, phân biệt bằng cột `type` + **content-type registry** (config) → thêm loại không migration.
2. **Custom field = `meta JSONB`** (KHÔNG EAV) + kỷ luật registry (xem Mục 17).
3. **`taxonomies`** = MỘT bảng dùng chung, đa cấp (Postgres `ltree`), typed pivots.
4. **i18n = bảng dịch `*_translations`** (KHÔNG nhân bản hàng theo ngôn ngữ, KHÔNG JSONB cho nội dung dài).
5. **Commerce typed riêng** (products/orders…) — hành vi giao dịch khác, tránh `wp_postmeta` hell — nhưng dùng chung taxonomy/i18n/meta.

## 8. Content engine: `entries` + 3 trục Type × Category × Template *(Mục 30, 37)*

**Ba trục ĐỘC LẬP — không gộp:**
- **`type` = HÌNH DẠNG/HÀNH VI dữ liệu** — dùng TIẾT KIỆM. **Cột vật lý `entries.type` ∈ {`page`, `post`}** (`page` = bố cục không list; `post` = mọi nội dung biên tập). **`product` là một "content-kind" ở mức KHÁI NIỆM nhưng VẬT LÝ là bảng riêng** (`products` + `product_translations` + `product_taxonomies` — Mục 11), dùng chung mô thức taxonomy/routes/i18n nhưng qua bảng của nó (commerce typed riêng — ADR-14). → tránh mâu thuẫn "product vừa trong entries vừa có bảng riêng". Thêm type mới CHỈ khi hành vi/cấu trúc khác thật (theo "quy tắc tốt nghiệp" Mục 17).
- **`category` (taxonomy) = NHÓM** nội dung (đa cấp).
- **template/style = HIỂN THỊ** — gán **theo category** (`list_template` + `detail_template`), fallback: override item → category → type → theme.

→ Các "loại" như tin/dịch vụ/dự án/tuyển dụng/ấn phẩm/sự kiện/nhân sự/FAQ/đối tác/chứng nhận/chính sách… **đều là `post`**, khác nhau bằng **category + template + vài field ở `meta`**. (Xác nhận: t5g tách `ObjectType` khỏi `ObjectFormat` = chính cặp type/template này.)

```sql
entries(
  id, site_id, type,                 -- 'page'|'post'  (product = bảng riêng, Mục 11)
  status,                            -- draft|pending|published|scheduled|private|archived (as-built; scheduled → auto-publish qua worker `entries:publish-due`; private = hoàn tất nhưng không public)
  workflow_state NULL,               -- khi gắn workflow (Mục 15); NULL = dùng status
  assigned_to NULL,                  -- người được phân công duyệt
  parent_id NULL,                    -- phân cấp (số tạp chí → bài; cây tài liệu)
  template_override NULL,            -- override template chi tiết
  author_id, cover_media_id NULL,
  published_at NULL, position,
  meta JSONB                         -- field phụ theo "kiểu" (event_date, person_title…) — có kỷ luật registry
)
entry_translations(
  id, entry_id, site_id, locale,
  title, slug, body, excerpt,        -- body: HTML/rich-text (type=post) HOẶC Page-JSON layout dạng JSONB (type=page)
  seo_title, seo_description, og_image_id,
  status,                            -- trạng thái dịch per-locale
  UNIQUE(entry_id, locale)
)
entry_revisions(id, entry_id, site_id, snapshot JSONB, created_by, created_at)  -- snapshot khi publish; retention N bản/90 ngày
```
- **Slug ↔ routes:** `slug` (admin sửa) ở `*_translations`; **`routes`** = đường dẫn ĐẦY ĐỦ đã resolve (path danh mục + slug) + lịch sử 301. Sửa slug → cập nhật route + tạo 301 từ path cũ. (Cùng quy tắc cho entries và products.)

**Ranh giới `entries` vs Page-JSON (làm rõ — KHÔNG có bảng `pages` riêng):** `entries` dạng **bộ sưu tập** (có list + detail) = type `post`. **Trang bố cục** = **`entries` type `page`**, với **Page-JSON layout** (hero/about/CTA/slider…) lưu ở **`entry_translations.body` dạng JSONB** (per-locale). Không trộn nội-dung-bộ-sưu-tập với bố-cục-trang; và mọi thứ vẫn trong `entries` (đúng ADR-14, không sinh bảng `pages`).

## 9. Taxonomy *(Mục 29, 30)*

```sql
taxonomies(
  id, site_id, vocabulary,           -- 'post_cat'|'product_cat'|'tag'|'news'|'project_cat'…
  parent_id,                         -- adjacency = nguồn sự thật
  path LTREE,                        -- Postgres ltree (GiST index) → ancestor/descendant nhanh; trigger đồng bộ từ parent_id
  list_template NULL, detail_template NULL,   -- style hiển thị theo nhóm (Mục 8)
  position, meta JSONB
)
taxonomy_translations(id, taxonomy_id, site_id, locale, name, slug, UNIQUE(taxonomy_id, locale))
entry_taxonomies(entry_id, taxonomy_id, position)   -- typed pivot (toàn vẹn FK); position = thứ tự TRONG category (D8)
product_taxonomies(product_id, taxonomy_id, position)    -- typed pivot
```
- **As-built (v2.22) — nghĩa THỰC của `position` trên 2 pivot:** thứ hạng chuyên mục CỦA item — `0..n-1` = chuyên mục, **`0` = chuyên mục CHÍNH** (định tuyến URL, section ⇒ workflow nào quản bài, danh sách chuyên mục, bộ lọc admin — 4 query SQL `position = 0` + `primaryCategory()`); term phụ (sector/tag…) ở **`≥ 1000`** (`HasCategoryTaxonomies::FACET_POSITION_BASE`) để không bao giờ bị đọc nhầm là chuyên mục, và `assignCategories()` giữ nguyên chúng. ⚠ **Lệch D8** ("thứ tự item TRONG category") từ P1 — chưa có tính năng nào sắp item trong chuyên mục nên chưa đụng nhau; **cần owner re-chốt D8 (COUNCIL) TRƯỚC khi xây "sắp xếp bài/SP trong chuyên mục"**: (a) re-chốt D8 theo as-built + cột thứ tự riêng khi cần, hoặc (b) cột `is_primary` + trả `position` về nghĩa D8 (migration).
- **`ltree`** thay materialized-path varchar: native, GiST index, toán tử `@>`/`<@`. Đã kiểm chứng nhu cầu cây 3 cấp thật (batxephanoi danh mục SP, IAPS nghiên cứu).
- **Typed pivots** thay polymorphic: chỉ 2 loại taggable (entries, products) → typed cho toàn vẹn FK (polymorphic sinh bản ghi mồ côi).

## 10. Đa ngôn ngữ dữ liệu *(ADR-11)*

- **Chuỗi UI** = lai: file defaults (vi/en/...) ⊕ DB `translation_overrides` (admin chỉnh).
- **Nội dung dịch** = **bảng dịch `*_translations`** (entries/taxonomies/products…) — quản lý tiến độ dịch/workflow/AI per-locale sạch; cache trung hòa JOIN. *(KHÔNG JSONB cho nội dung dài, KHÔNG nhân bản hàng.)*
- **Config nhỏ** (settings, menu label, banner creative) = JSONB-per-locale `{"vi":…,"en":…}` (chấp nhận vì là config/creative, không phải nội dung dài).
- **URL i18n = prefix CÓ ĐIỀU KIỆN:** locale **default-hiện-tại của site** KHÔNG chèn langcode (`/gioi-thieu`); locale còn lại prefix `/{locale}` (`/en/about`, `/ja/…`). "Default" = **per-site** (đọc `site_locales.is_default`) + **MUTABLE** (admin đổi được) — KHÔNG hardcode `vi`, KHÔNG đông cứng trạng thái default vào URL lưu trữ.
- **`routes.path` lưu path nội dung KHÔNG kèm prefix** (path danh mục + slug); `UNIQUE(site_id, locale, path)` giữ nguyên — prefix locale chỉ áp **lúc build/match**, không lưu.
- **PathBuilder DUY NHẤT:** `locale == site.currentDefault → "/"+path`, ngược lại `→ "/{locale}/"+path`. MỌI URL (menu, breadcrumb, sitemap, hreflang, canonical, 301) đi qua đây — **arch test:** không nơi nào tự ghép prefix.
- **Canonical-guard đọc `is_default` TẠI request:** URL không đúng dạng-theo-default-hiện-tại → **301** (`/{default}/path → /path`; `/{default} → /`). Đọc động nên **đổi default tự sinh 301 đúng, KHÔNG cần migration URL**.
- **Prefix locale lạ / chưa enable → 404** (KHÔNG soft-fallback → tránh trang ma trùng nội dung).
- **Hiển thị/Index theo locale:** một locale chỉ hiện/được-index khi `entry.status=published` VÀ `entry_translation.status=published`; thiếu → fallback gốc hoặc ẩn (cấu hình). hreflang chỉ trỏ locale đã publish — **dạng:** default unprefixed + non-default prefixed + `x-default → default`.
- **Locale theo site** (sống ở `site_locales`, KHÔNG pin bộ locale cứng vào phase — i18n lõi **locale-agnostic**: thêm locale bất kỳ per-site khi có khách thật cần). **An Vinh** (site tham chiếu): **vi/en/ja, default vi** — Phase 1 chỉ làm **vi**; en/ja giai đoạn sau (copy + dịch).
- **Quản lý ngôn ngữ (default MUTABLE)** — quyền **`locales.manage`** (thêm/bật-tắt locale + đặt default), gate qua RBAC hiện có:
  - **Ràng buộc:** đúng **1 default/site** (partial unique — xem schema `site_locales`); default mới phải đang `enabled`; **cấm tắt/xóa default hiện tại khi chưa gán default khác**.
  - **Soạn ≠ hiển thị (v2.23 Q2, as-built 24/09):** `enabled` = biên tập viên viết được; `is_public` = khách mở được (tiền tố URL, hreflang, sitemap) → chuẩn bị bản dịch trước khi cho khách thấy. CHECK: default luôn `enabled AND is_public`; `is_public ⇒ enabled`. Bật thêm ngôn ngữ tính vào hạn mức gói `locales.max` (mặc định 2, gồm cả default; site đã vượt giữ nguyên, chỉ không bật thêm) — đường ghi duy nhất `Tenancy\Actions\ConfigureSiteLocale`. Quyền gọi (UI D3-1) = `settings.manage` (registry chưa có `locales.manage`).
  - **Đổi default = high-impact:** kéo theo **mass 301 + recrawl** → khi đổi phải regenerate sitemap + bump `lastmod` + tag-bust cache URL/menu của site; **cảnh báo admin trước khi xác nhận**.

```sql
site_locales(site_id, locale, is_default, enabled, is_public)   -- partial UNIQUE(site_id) WHERE is_default=true → đúng 1 default/site; CHECK default ⇒ enabled+public, public ⇒ enabled
translation_overrides(site_id, locale, key, value)
routes(id, site_id, locale, path, entry_id NULL, product_id NULL, taxonomy_id NULL, UNIQUE(site_id, locale, path), CHECK(num_nonnull(entry_id,product_id,taxonomy_id)=1))   -- TYPED (D3): đúng-một đích + ON DELETE CASCADE; sửa slug → tạo 301
```

## 11. Commerce *(Mục 32, 35)*

```sql
products(id, site_id, sku, price, sale_price NULL, sale_starts_at NULL, sale_ends_at NULL,
         stock, is_featured, is_bestseller, status, meta JSONB)   -- "mới" suy từ created_at; "KM" suy từ sale_price
product_translations(product_id, site_id, locale, name, slug, description, summary, seo_*, UNIQUE(product_id, locale))
product_options(...) · product_variants(...)        -- GĐ1: ≤3 option / ≤100 biến thể
product_images(product_id, site_id, media_id, position, UNIQUE(product_id, media_id))
carts(...) · cart_items(...)
orders(id, site_id, code, customer_id, status, total, shipping_snapshot JSONB, ...)   -- snapshot địa chỉ lúc đặt
order_items(...) · order_status_logs(...)
customers(id, site_id, ...) · customer_addresses(id, site_id, customer_id, province_id, ward_id, ..., is_default)
shipping_zones(...) · shipping_rates(...)
reviews(id, site_id, reviewable_type, reviewable_id, customer_id NULL, author_name, rating, body, status)  -- moderation; rating → counter-cache lên product → AggregateRating schema
coupons(id, site_id, code, type, value, min_order, usage_limit, used_count, starts_at, ends_at, status, UNIQUE(site_id, code))
coupon_redemptions(coupon_id, order_id, site_id)
payments(id, site_id, order_id, method, amount, status, reference, paid_at, raw_payload JSONB)  -- đối soát VietQR (SePay webhook IDEMPOTENT)
```
- **Tra cứu đơn theo mã** (mã đơn + SĐT → trạng thái) — không cần tài khoản; dùng `orders` + token tra cứu.
- **Kiểu TIỀN (D5):** `price`/`sale_price`/`total`/`amount`/`min_order`/`value`(coupon) = **integer (VND đồng)**, KHÔNG float. **Bề rộng = bigint (int64)** — int32 trần ~2,1 tỷ đồng, quá nhỏ cho B2B (máy/khuôn/xe); precedent đặt từ `products.price` (as-built 2026-07) cho `orders.total` Phase-2. `orders.code` UNIQUE(site_id, code).
- **Tồn kho & toàn vẹn commerce (D15):** `CHECK(stock >= 0)` + **decrement nguyên tử** chống oversell (`UPDATE … SET stock=stock-:q WHERE stock >= :q` hoặc lock dòng trong transaction) — KHÔNG đọc-rồi-ghi. Payment/webhook **idempotent** (SePay — đã có). Unique: `products` UNIQUE(site_id, sku) (as-built: partial, `WHERE sku IS NOT NULL` — brochure nhiều hàng không sku); `product_variants` UNIQUE(product_id, sku); `taxonomy_translations.slug` UNIQUE per (site, vocabulary, locale); `coupon_redemptions` UNIQUE(coupon_id, order_id).
- **As-built GĐ1 brochure (P0/P1 2026-07):** cả 4 bảng catalog **kể cả pivot `product_taxonomies` (§9) đều mang `site_id` + RLS + FORCE per D6** (mirror `entry_taxonomies` — pivot tenant KHÔNG ngoại lệ). Cột thương mại (sku/price/sale/stock) NULLABLE = brochure. `product_translations.description` (chi tiết/specs) + `summary` (teaser card/og) = **rich HTML sanitize-KHI-GHI** (HTMLPurifier 2 profile khớp 2 toolbar: `description`=**full** (cho `img` từ library + `iframe` CHỈ youtube/vimeo qua SafeIframe + table/heading/sub-sup + CSS property-level `color|background-color|font-size|text-align|margin-left|padding-left|width|height`) vs `summary`=**basic** (KHÔNG `img`/`iframe`); ảnh SP chính vẫn qua `product_images`→`media_id`); editor admin = Jodit 4 island (icon map Remix). Ẩn giá/đặt-hàng per-site qua setting `catalog_mode` (Slice-3, chưa build).

## 12. Media + Image CDN *(Mục 41)*

> **⚠ SUPERSEDED bởi ADR-17 (toàn mục).** Kiến trúc dưới đây — imgproxy-primary · key `sites/{site_id}/media/{ulid}` · serving-qua-token · spatie/medialibrary · `images.bsweb.vn` — **đã thay** bởi **ADR-17**: R2 private + **Cloudflare Worker/cf.image** · key content-hash `{tenant}/{collection}/{slug}-{hash}` · media công khai · lib custom `App\Modules\Media` · `files.baoson.net`; imgproxy → L4 cold. **Mọi ref imgproxy-primary / images.bsweb.vn / spatie-medialibrary / ulid-media-key ở các mục khác (§3 stack, §4, §18, §32) cũng theo ADR-17.** Giữ mục này làm bối cảnh lịch sử. Epic: `docs/notes/media-system-epic.md`.

- **Lưu trữ:** **Cloudflare R2** bản gốc, key prefix `sites/{site_id}/media/{ulid}.{ext}` (cô lập vật lý); tài nguyên dùng chung ở `shared/library/{ulid}`. Chỉ lưu **bản gốc**; mọi size/format sinh **on-demand qua imgproxy** + cache CDN.
- **Cô lập 3 lớp** (không load nhầm): RLS `media.site_id` + storage prefix + serving qua token (site_id không bao giờ ở URL).
- **Ảnh dùng chung từ mẫu KHÔNG hiện ở quản lý site:** 2 namespace — `media` (của site, RLS lọc) vs `library_assets` (toàn cục). Reference-at-render; materialize khi site "nhận" (Mục 21).
- **Image CDN `images.bsweb.vn`** — URL: `https://images.bsweb.vn/{ulid}/{transform}/{seo-slug}.{fmt}`
  - `{ulid}` token mờ (không lộ site_id, chống enumerate) = "mã hóa đường dẫn".
  - `{transform}` preset hoặc tham số **ký HMAC** (imgproxy) — chặn resize-DoS; WebP/AVIF auto.
  - `{seo-slug}` mô tả (từ alt/title) = **SEO image**; chỉ trang trí nên đổi alt không vỡ link.
  - Tùy chọn white-label: CNAME `img.tendomain.com → images.bsweb.vn`.
- **SEO/Bảo mật/Tối ưu:** alt per-locale + image sitemap + srcset + schema; signed URL cho media private; R2+CDN edge + lazy + width/height (chống CLS).
- **🔴 An toàn upload (defense-in-depth — R2 đã chặn thực thi, nhưng VẪN cần các lớp sau):**
  - **R2/ngoài web-root (đã có):** file lưu trên R2, KHÔNG vào `public/`; R2 không chạy PHP → vector "upload `.php`→thực thi/RCE" **đã bị kiến trúc chặn**. Nhưng vẫn áp các lớp dưới.
  - **Whitelist MIME theo ngữ cảnh** (ảnh: jpeg/png/webp/gif/avif; tài liệu: pdf…) + **blocklist đuôi nguy hiểm** (`.php .phar .phtml .phps .pht .htaccess` + executable) — phòng cấu hình sai/tính năng tương lai chạm disk.
  - **Sniff nội dung thật (magic bytes/`finfo`), KHÔNG tin đuôi/Content-Type client** (đều giả mạo được) → lệch là từ chối.
  - **Re-encode ảnh qua imgproxy** (loại payload nhúng) + tên lưu = `ulid` (không dùng tên file client) + size limit/type.
  - **🔴 SVG = rủi ro XSS thật còn sót sau R2:** SVG là XML, chứa `<script>`/handler → nếu serve inline = stored-XSS. **Bắt buộc: sanitize SVG khi upload** (strip script/handler/ref ngoài) HOẶC chỉ serve `Content-Disposition: attachment`. (Domain ảnh `images.bsweb.vn` tách rời, cookieless → đã giảm tác động.)

```sql
media(id, site_id, ulid, disk, storage_key, original_filename, seo_slug, mime, size, width, height, hash, folder_id, alt, title, meta JSONB, created_by, deleted_at)  -- deleted_at = thùng rác (Rổ)
media_folders(id, site_id, parent_id, name, path LTREE)          -- cây thư mục per-site
library_assets(id, ulid, storage_key, mime, width, height, tags, meta JSONB)   -- DÙNG CHUNG, KHÔNG site_id
mediables(media_id, mediable_type, mediable_id, site_id, collection)            -- gắn vào entry/product
```

## 13. Điều hướng & Placement *(Mục 39, 40)*

**Nguyên tắc chung: tách "nội dung" khỏi "vị trí".**

**Menu (động, kiểu WordPress):**
```sql
menus(id, site_id, name, handle, locales JSONB NULL, meta JSONB)        -- locales: ['vi','en'] | null=all
menu_assignments(site_id, region_key, locale, menu_id)                  -- gán per (region × locale); UNIQUE(site_id, region_key, locale); menu_id FK→menus ON DELETE CASCADE; CHECK menu_id cùng site_id
menu_items(id, site_id, menu_id, parent_id, position,
           link_type,                  -- 'entry'|'taxonomy'|'dynamic'|'url'
           routable_type NULL, routable_id NULL, url NULL, dynamic_key NULL,
           meta JSONB)                 -- icon, css_class, target, rel, description, megamenu, điều kiện hiển thị
menu_item_translations(menu_item_id, site_id, locale, label)           -- rỗng → title đã dịch của routable
```
- **i18n menu:** 2 mô hình admin chọn — (A) menu dùng chung + nhãn dịch (mặc định), (B) menu riêng mỗi ngôn ngữ — qua `menus.locales` + `menu_assignments(region, locale, menu_id)`. **Model-B bật từ Phase 1** — yêu cầu thật của **An Vinh** (site tham chiếu): admin tạo nhiều menu kiểu WordPress, gán per (region × locale).
- **Resolver theo ASSIGNMENT (KHÔNG theo `handle`):** tra `menu_assignments` đúng (region, locale); thiếu assignment cho (region, locale) → **fallback menu của default-locale** (KHÔNG trả nav rỗng).
- **Vị trí menu = region do theme khai báo** (primary/top/footer/mobile…), không enum cứng.
- **Link mọi loại**; URL tự bản địa hóa qua `routes`. Builder **kéo-thả React island** (DB chỉ parent_id+position).
- **Hiệu năng:** batch resolve URL+nhãn + **cache menu đã-resolve per (site, region, locale)** chống N+1.

**Banner/Placement:**
```sql
ad_zones(id, site_id, key, label, theme_region NULL, width_hint, height_hint, max_items, meta JSONB)
banners(id, site_id, name, kind, media_id NULL, html NULL, link_url NULL, link_target, width, height, status, starts_at NULL, ends_at NULL, meta JSONB)  -- kind: image|html
banner_zone(banner_id, zone_id, site_id, position)              -- M:N: 1 banner → NHIỀU zone; 1 zone nhiều banner = slider
banner_stats(banner_id, site_id, date, impressions, clicks)     -- tùy chọn (partition+BRIN)
```
- **Zone = theme khai báo + custom**; 1 banner ở nhiều zone; slider = zone-carousel hoặc builder-section.
- **Logo/favicon = brand asset ở `site_profiles`** (feed schema), KHÔNG phải banner; ảnh nằm ở Media Library.

## 14. Settings & Site Profile *(Mục 34)*

**Nguyên tắc 3 nhà:** NAP **structured** ở `site_profiles` (feed LocalBusiness schema) · **config** ở `site_settings` · **bố cục** ở page-JSON.

```sql
site_profiles(
  site_id PRIMARY,
  company_name, industry, description, tone,             -- nhiên liệu demo + SEO
  address_street, ward_id, province_id,                  -- NAP (địa chỉ 2 cấp)
  phones JSONB, emails JSONB, business_hours JSONB, geo_lat, geo_lng, social_links JSONB, tax_code, offerings JSONB,
  business_license_no, license_issued_at, legal_representative,   -- footer pháp lý VN
  moit_registered BOOL, moit_url,                        -- badge Bộ Công Thương
  logo_media_id NULL, favicon_media_id NULL              -- brand assets (tham chiếu media)
)
site_profile_translations(site_id, locale, company_name, address_text, description)
site_settings(site_id, group, key, value JSONB, UNIQUE(site_id, group, key))   -- groups: general|seo|scripts|smtp|payment|contact_widgets|features|admin_theme
```
- **Nạp TOÀN BỘ settings/site 1 lần → cache Valkey** (đổi rất ít); typed access + validate theo registry mỗi group (không free-for-all).
- **Script Manager 2 chế độ** (nhóm `scripts`): (A) tích hợp sẵn GTM/GA4/Pixel/Zalo/chat → **nạp tối ưu tự động** (defer/facade/Partytown) = mặc định; (B) mã thô Header/Body-top/Body-bottom/Footer = escape-hatch có cảnh báo, tránh `<head>`. *(Dung hòa tiện dụng vs CWV.)*
- **SMTP** (nhóm `smtp`): password **mã hóa at-rest** (Laravel `encrypted` cast), không hiển thị plaintext; mặc định dùng email chung, SMTP riêng là override.
- **`contact_widgets`:** zalo_oa_id, hotline, messenger_id, show_back_to_top, callback_enabled.
- **`admin_theme`** (ADR-A4): site-default cho giao diện QUẢN TRỊ (Customizer 3 tầng: app → site → `users.preferences`). **TÁCH BIỆT nhóm `theme`** — nhóm `theme` được storefront ThemeResolver in thẳng vào HTML public, cấu hình admin CẤM tái dùng nhóm đó (bất biến §2).

## 15. Phân quyền (RBAC) + Workflow + Module/Entitlement (3 tầng) *(Mục 36, 38)*

### 15.1 RBAC — nhóm + phân cấp + tùy chỉnh
- **spatie/laravel-permission + teams** (`team_id = site_id`). 2 phạm vi: **Platform** (Bảo Sơn: super_admin/support/billing) và **Tenant** (per site: owner/admin/editor/author/contributor/viewer + role tùy chỉnh).
- **Permission hạt nhỏ** (`resource.action`) theo registry: `entries.create/edit/delete/submit/review/approve/publish`, `products.*`, `orders.*`, `media.*`, `settings.manage`, `users.invite/manage`, `roles.manage`, `domains.manage`, `locales.manage`…
- **Phân cấp `role.level` (chokepoint chống leo thang — gán/sửa/fork role ĐỀU qua 1 service gác):** guard `grant ≤ self` — chỉ gán role cấp thấp hơn, chỉ cấp quyền mình ĐANG có, chỉ quản user cấp thấp hơn, **trong cùng team (= site_id)**. Visibility-scope: admin chỉ THẤY + GÁN module/quyền site mình ĐƯỢC CẤP. (Provisioning system-level bỏ qua guard.)
- **Scope own/any:** `entries.edit.own` vs `.any` (author sửa bài mình, editor sửa tất cả). Đa site/1 user (agency) qua team.

```sql
permissions(id, name, guard)
roles(id, name, guard, site_id NULL, level, is_system, forked_from_role_id NULL)  -- site_id 0 = platform sentinel (cột nullable); level = phân cấp; forked_from_role_id: NULL = kế thừa role-template theo site_type LIVE, set = đã COPY-ON-WRITE bản site-riêng (§15.2); cột APP-thêm additive, KHÔNG sửa schema spatie core, KHÔNG phá team-scoping
role_has_permissions(role_id, permission_id)
model_has_roles(role_id, model_type, model_id, site_id)
model_has_permissions(...)                                     -- quyền trực tiếp per user (ngoại lệ cá nhân)
```

### 15.2 Module động + Entitlement (kích hoạt theo site)
- **Module chức năng** (platform-defined: Media/Menu/Users/Settings/Contact/Products/Orders/Quảng cáo…) + **Content section** (per-site: Tin tức/Dự án/Mạng lưới… = view của `post` lọc theo category, có sub-item Thêm/Danh sách/Nhóm).
- **Catalog module mặc định (code registry B1, key snake_case D7, label {vi,en,ja}):** `dashboard` · `posts`(list/create/categories/tags) · `pages`(list/create) · `media`(GĐ3) · `contacts` · `menus` · `banners` · `users`(list/create) · `roles` · `settings` · `trash` · `profile`. Mỗi module = key + label{vi,en,ja} + permission_prefix + sort_order + icon + cờ `needs_tier3`. (Tái dùng tên Banner/roles — KHÔNG đẻ tên mới.)
- **`site_type` (hình-dạng-chức-năng — KHÔNG copy/preset):** mỗi loại (`corporate`/`product_commerce`/`news`…) định nghĩa (a) bộ **module** + (b) bộ **role-template** của loại; `site.site_type_id` trỏ tới loại + **KẾ THỪA LIVE** (sửa loại → mọi site loại đó theo, KHÔNG nhân bản). GĐ1 chỉ `corporate` (An Vinh = B2B cơ khí); cấu trúc thêm loại sau.
- **Entitlement:** Bảo Sơn **bật module/section per site** theo gói/hợp đồng (nguồn = plans + Pennant + override).
- **3 trục TÁCH BẠCH "site được module nào":** **`site_type`** = bộ-chức-năng-theo-loại (GĐ1) · **`plan`** = giới-hạn-theo-tiền/bậc thương mại (DEFER, billing) · **`site_modules`** = lớp **override per-site** (Bảo Sơn cấp THÊM module riêng cho 1 site — additive delta; type vẫn áp, override CỘNG thêm). 3 trục không giành nhau.
- **Role-template theo loại = CODE REGISTRY keyed by `site_type`** (mở rộng `PermissionRegistry::tenantRoleTemplates`, **KHÔNG bảng `role_templates`**): role định sẵn + quyền mặc định mỗi role, đặc thù loại. Site kế thừa LIVE tới khi admin sửa 1 role → **COPY-ON-WRITE PER-ROLE**: chỉ role đó materialize thành row `roles`(is_system=false, `forked_from_role_id` set); role khác vẫn kế thừa loại LIVE; sau fork, cập nhật template KHÔNG còn lan tới role đã fork. Fork PHẢI trong trần quyền loại (grant≤self — tiện lợi, không phải đường vòng).
- **Ghi catalog = PLATFORM-ONLY:** `site_types` (và catalog module) chỉ ghi ở **operator/platform plane**; tenant/admin site **READ-ONLY**. Vì global KHÔNG RLS → KHÔNG có lớp DB chặn ghi chéo-tenant → **policy/gate tầng app PHẢI chặn** tenant mutate catalog; **cơ chế chặn cụ thể (permission + gate) chốt khi dựng guard tầng-2**. GĐ1: seed/registry, chưa có UI quản-lý-loại (DEFER).
- **Menu admin ĐỘNG** = (`site_type`.module ∪ `site_modules` override, đã bật) ∩ (quyền user `Gate::allows`), render nhãn theo `users.admin_locale`. Admin chỉ thấy module thuộc LOẠI site mình + override được cấp; không thấy catalog/loại khác.

```sql
site_modules(site_id, module_key, enabled, settings JSONB)
content_sections(id, site_id, key, label, icon, sort_order, entry_type, taxonomy_id NULL, list_template NULL, detail_template NULL, permission_prefix, meta JSONB)
site_types(id BIGINT PK, key UNIQUE, label JSONB{vi,en,ja}, meta JSONB)   -- GLOBAL catalog platform-writable-only (KHÔNG site_id/RLS — D6); bộ module + role-template của loại nguồn ở CODE registry, tham chiếu qua key
-- Migration EXPAND-SAFE + reversible: site_types tạo TRƯỚC; sites.site_type_id + roles.forked_from_role_id = nullable additive; backfill 2 site → corporate. down() drop 2 cột FK TRƯỚC rồi drop site_types (đúng thứ tự FK); KHÔNG backfill ngược type (cột mới, khác C6).
```

### 15.3 Phân cấp phân quyền ĐỘNG 3 tầng

| Tầng | Ai | Kiểm soát | Trần |
|---|---|---|---|
| **1. Platform** | Bảo Sơn (super_admin) | Catalog module/permission + **entitlement** per site | Toàn quyền |
| **2. Khách hàng** | Owner/Admin site | Trong module đã bật: tạo role, gán permission/section | ⊆ entitlement |
| **3. Nhân viên KH** | Staff | Thao tác theo quyền; sub-delegate nếu có `roles.manage` + cấp thấp hơn | ⊆ quyền người cấp |

**Bất biến:** quyền hiệu dụng ⊆ entitlement; chỉ cấp quyền mình có cho cấp thấp hơn. 2 ranh giới chặn leo thang (entitlement + level).

**Cấp quyền mặc định (2-cấp):** (1) Bảo Sơn→admin website: tạo site → admin website **tự động** nhận TRỌN quyền của LOẠI (không phân quyền tay) = TRẦN của admin; Bảo Sơn cấp thêm module override nếu cần. (2) Admin website→user/nhóm: delegate xuống, trần = quyền admin đó (grant≤self, team-scoped team=site_id; KHÔNG so level toàn cục).
**Guard (xương sống, gate-first):** `role.level` được so bởi `RoleHierarchyGuard` (từ `5d6fa8b`; trước đó INERT) → guard `grant≤self` + visibility-scope dựng TRƯỚC mọi màn gán-role/tạo-override/fork-role; Pest-red chặn leo thang (gán ≤ quyền mình OK, vượt → reject; không thấy quyền chưa cấp; fork không vượt trần loại) TRƯỚC User/Role CRUD.
**DEFERRED (ghi, GĐ1 KHÔNG xử):** (1) màn cho Bảo Sơn THẤY site/role nào đã fork để push thay đổi critical thủ công; (2) reconcile khi TRẦN quyền của loại đổi sau khi site đã fork; (3) test cô lập perm-filter của `assignableRoles` (role level-thấp mang perm actor-không-có → KHÔNG assignable) — bất khả biểu diễn với 6 template monotonic (level ⊇ perm), viết khi fork/role-template đặc thù ra đời. **(3) ĐÃ viết v2.22** cùng nhóm tùy chỉnh; (2) thay bằng luật "trần enforce lúc LƯU" dưới đây.

**Nhóm quyền tùy chỉnh — mô hình 2 cấp (owner chốt 06/07; as-built v2.22, backend — UI chờ design):**
- **2 cấp nhóm:** platform (`super_admin/support/billing`, team 0) ⊥ tenant (6 template theo `site_type`). Nhóm **hệ thống KHÓA CỨNG** (không xóa/đổi tên/sửa quyền/hạ cấp; nâng cấp template chỉ qua registry + `rbac:sync` back-fill add-only). Muốn khác → **nhóm tùy chỉnh**: fork (copy quyền nguồn, `forked_from_role_id` = nguồn — nhóm nguồn KHÔNG bị thay) hoặc trắng. Luôn per-site (`site_id = N`), không bao giờ team 0.
- **Bật theo site = feature `rbac.custom_roles`** (FeatureRegistry, mặc định TẮT; tầng feature mịn của G4, tiền lệ `workflow.editorial` — tinh chỉnh chữ "qua moduleEnabled" của chốt 06/07 viết trước khi G4 có). Tắt feature chặn TẠO; sửa/xóa nhóm đã có vẫn được (site hạ gói phải dọn được).
- **Bất biến (máy kiểm, `CustomRoleActionsTest`):** **R1** trần site = quyền có prefix thuộc module site được entitle (`ModuleCatalog.permission_prefix`; mọi quyền tenant PHẢI có module sở hữu — vì vậy thêm module ẩn `taxonomies`, `domains`) · **R2** trần người sửa = quyền mình đang giữ; quyền nhóm đang có mà người sửa không giữ thì KHÔNG thêm/bớt được (giữ nguyên) · **R3** cấp nhóm ∈ [1, cấp người sửa) (super_admin: < cấp owner) · **R4** nhóm hệ thống bất biến; nhóm tùy chỉnh xóa được khi không còn ai giữ (khóa hàng role chống gán song song) · **R5** `PermissionRegistry::IMPLIES` (hành động ⇒ `*.view`) chuẩn hóa khi lưu; template đã đóng dưới IMPLIES nên fork chuẩn hóa ra đúng nguồn · **R6** audit `rbac.role.{created,updated,deleted}` kèm diff quyền.
- **Cô lập (bảng RBAC global, không RLS):** mọi đường ghi resolve đối tượng TRONG site hiện tại trước khi gọi guard — role theo `name` + `site_id`, người theo ulid ∩ thành viên site (`Users\Index::siteMember`, bài học v2.21). Tên nhóm: 1–60 ký tự, không trùng (không phân biệt hoa thường, fold bằng PHP — không tin `lower()` theo locale DB), cấm tên template/platform.
- **Chokepoint:** chỉ `Create/UpdateCustomRole` + 2 caller hệ thống (`rbac:sync`, `ProvisionSite`) được đổi quyền của role (arch test, song song chokepoint gán role).
- **Quyền "ngoài gói"** do module bị tắt sau đó: không job reconcile — Entitlement là lớp #1 (chặn trước RBAC) nên quyền thừa vô hại; lần lưu kế tiếp tự gỡ.

### 15.4 Workflow duyệt bài — 2 tầng
- **TẦNG 1 — Permission-driven (mặc định, KHÔNG cần cấu hình):** có `entries.publish` → **đăng thẳng** (admin + role/user được cấp); không có → lưu **pending**, người có publish/approve duyệt. Admin phân quyền **theo nhóm HOẶC theo user cụ thể**.
- **TẦNG 2 — Workflow state machine (tùy chọn, data-driven):** khi cần nhiều bước (phóng viên→biên tập→giám đốc). Định nghĩa bằng DỮ LIỆU → đổi/thêm cấp không cần code (thay cho cột cứng `L1/L2` của t5g — chính chỗ "vênh" cần tránh).

```sql
workflows(id, site_id, key, name, applies_to_type, is_active)        -- template: none|admin_approval|editorial
workflow_states(id, workflow_id, key, label, sort_order, is_initial, is_published, is_terminal)
workflow_transitions(id, workflow_id, key, label, from_state, to_state, required_permission, requires_note)  -- guard = permission
workflow_log(id, site_id, workflow_id NULL, entry_id, locale NULL, transition_key, from_state, to_state, labels jsonb, actor_id, assigned_to NULL, note, created_at)
```
- Transition guard = permission (submit/review/approve/publish); assign + note (phản biện) + log + notification (Mục 27). Chỉ trạng thái `is_published` mới ra public.
- **Trail tự mô tả:** `workflow_log.workflow_id` (soft-ref, SET NULL như `actor_id` — xóa workflow KHÔNG xóa lịch sử) trả lời "dòng này thuộc quy trình nào" khi site chạy NHIỀU workflow (mỗi thể loại 1). `labels` jsonb `{transition, from, to}` chụp **nguyên văn hiển thị lúc ghi**: áp template = rebuild states/transitions và đổi template = dùng lại chính workflow row đó, nên key ghi hôm nay có thể không còn tên ngày mai — join sống sẽ in key thô ra timeline. Chỉ để hiển thị, không lọc/sắp → jsonb (lệ "tốt nghiệp" §17).
- **Bật/tắt (as-built 18/09):** mỗi (site × thể loại) tối đa 1 workflow (`UNIQUE(site_id, applies_to_type)`), **mặc định TẮT**. Màn bật/tắt = `Admin/Livewire/WorkflowSettings`, gate bằng permission **`platform.workflows.manage`** — thuộc nhóm platform và **không role-template tenant nào được cấp**, nên chỉ operator nền tảng vào được (qua `Gate::before`); muốn ủy quyền cho tenant sau này chỉ cần cấp key, không sửa code. Template `editorial` khóa sau entitlement `workflow.editorial` (mặc định false) — màn hình nêu gói hiện tại + gói có cấp, **không** bịa nút nâng cấp (billing hoãn).
- **Phạm vi govern (ràng buộc):** workflow chỉ áp được cho **post-collection có taxonomy** — `SectionResolver::forEntry` suy section từ chuyên mục chính, mà `page` không mang taxonomy nên KHÔNG BAO GIỜ chạm engine. Muốn duyệt trang bố cục ⇒ phải thêm đường resolve khác (không phải sửa UI).
- **Đổi template:** rebuild states/transitions theo template mới **+ reset `workflow_state = null`** cho entry đang giữa chừng của đúng section đó (vào lại bước đầu; `status` công khai giữ nguyên) — chống kẹt bài ở bước mà flow mới không còn định nghĩa. Áp LẠI cùng template = rebuild thuần, không đụng con trỏ.
- **Mọi đường ra public hỏi MỘT chỗ (as-built 18/09):** `Cms\Support\EntryWorkflowResolver` (section từ category chính → workflow active) — bài governed: `PublishEntry` từ chối · **đặt lịch (`scheduled`) từ chối** · scheduler `entries:publish-due` **bỏ qua** bài đã đặt lịch từ trước khi bật (đếm + báo, không đăng lén). `draft/private/archived` vẫn tự do vì chỉ gỡ khỏi public. **Editor:** khối "Trạng thái duyệt" thay nút Đăng khi governed (theo category ĐANG chọn → bài chưa lưu cũng thấy đúng khối; transition persist trước rồi apply) — nút = `allowedTransitions` từ đúng origin engine dùng (`workflow_state ?? initial`); giao việc chỉ ở transition có note, người nhận phải là thành viên site có quyền của bước ĐÍCH (validate server); trail đọc `labels`, keyset `(created_at, id)`, +3/lần. **Vị trí trong flow khi chưa có `workflow_state`** (`EntryWorkflowResolver::originFor`, dùng chung action + editor): bài **đang public** ⇒ đứng ở bước `is_published` (không tồn tại "gửi duyệt" từ đó ⇒ bật workflow KHÔNG BAO GIỜ ẩn bài khách đang xem; chỉ Gỡ bài/Lưu trữ mới đổi), còn lại ⇒ bước đầu. Row-menu `draft/private/archived` trên bài governed vẫn cho phép (chỉ gỡ khỏi public) nhưng **reset `workflow_state = null`** ⇒ đường duy nhất lên public lại là duyệt. **Giới hạn thật (chưa có revision):** sửa nội dung bài đang public rồi Lưu ⇒ hiện ra ngoài ngay — UI nói thẳng (`live_edit_hint`); gate chỉ quản quyết định công bố, không quản từng lần sửa. **Audit:** mọi đường đổi `entries.status` (publish/submit/status-menu/scheduler/workflow) ghi 1 dòng `entries.status.changed` {from,to,via,actor} vào `audit_logs` (bài học sự cố trang chủ 02/08).
- **Hộp duyệt (as-built 19/09) — bề mặt của NGƯỜI DUYỆT, tách khỏi người viết:** `Admin/Livewire/Entries/ReviewInbox`, gate `EntryPolicy::review` = `entries.review || entries.approve`, **KHÔNG đòi `entries.update`** (duyệt ≠ sửa — đúng lý do tồn tại của template `editorial`; editor vẫn đòi quyền sửa như cũ). Màn chỉ ĐỌC bài + gọi `ApplyEntryWorkflowTransition`, không chạm đường ghi nội dung. **Hàng chờ = tập STAGE KEY** (`Workflow\Services\ReviewQueue`: quyền → transitions → `from_state`), lọc entries bằng MỘT `whereIn` — **LOẠI** stage `is_initial` (nháp chưa gửi = việc của tác giả) và `is_published` (không chờ ai). Badge sidebar = count cùng truy vấn, cache 60s per (site,user) — badge trễ 1 phút chấp nhận được, danh sách luôn chính xác. **Ngày đăng:** duyệt lên public giữ `published_at` cũ nếu ở QUÁ KHỨ (gỡ-sửa-đăng lại = cùng bài báo), còn null hoặc TƯƠNG LAI (bài từng đặt lịch) ⇒ `now()` — danh sách bài sắp theo cột này.

### 15.5 Genesis — thứ tự khởi tạo & dải id *(BẤT BIẾN)*
- **Thứ tự genesis (cố định):** `migrate:fresh` (role `pgsql_admin`) → `db:seed` (`rbac:sync` + tạo operator platform **super_admin** `admin@baoson.net` = **`users.id = 1`** — account gốc của hệ thống, trên mọi tenant) → `site:provision` (owner tenant nối tiếp) → tenant content seeders (vd `SeedAnVinhContent`).
- **Dải id platform:** `rbac:sync` tạo 3 role platform theo thứ tự → chiếm **`roles.id` 1–3** (`super_admin` / `support` / `billing`; `site_id = 0` = sentinel `PLATFORM_TEAM_ID`). Role-template tenant (owner/admin/…) KHÔNG cố định id — instantiate **per-site** (team = `site_id`) lúc provision.
- **Bất biến:** dữ liệu hệ thống/platform chiếm **các id đầu**, dữ liệu khách/tenant nối sau; `migrate:fresh` reset sequence → id **liền mạch từ 1, KHÔNG khoảng trống**. Code KHÔNG giả định id cứng ngoài dải platform này.

## 16. Lược đồ tổng hợp (toàn bộ bảng)

| Nhóm | Bảng chính |
|---|---|
| **Platform** | tenants, users, tenant_users, plans, subscriptions, invoices, feature_flags(Pennant), announcements, audit_logs, ai_credit_ledgers |
| **RBAC** | permissions, roles(site_id, level, forked_from_role_id), role_has_permissions, model_has_roles(site_id), model_has_permissions |
| **Workflow** | workflows, workflow_states, workflow_transitions, workflow_log |
| **Module/Menu động** | site_types(global catalog), site_modules, content_sections |
| **Sites** | sites, domains, site_locales, site_profiles, site_profile_translations, site_settings |
| **Content** | entries(type ∈ {page, post} — page lưu Page-JSON layout ở entry_translations.body), entry_translations, entry_revisions · *(product = bảng riêng, Commerce)* |
| **Taxonomy** | taxonomies(ltree, templates), taxonomy_translations, entry_taxonomies, product_taxonomies |
| **i18n/Route** | translation_overrides, routes |
| **Commerce** | products, product_translations, product_options, product_variants, product_images, carts, cart_items, orders, order_items, order_status_logs, customers, customer_addresses, shipping_zones, shipping_rates, reviews, coupons, coupon_redemptions, payments |
| **Media** | media, media_folders, library_assets, mediables |
| **Navigation/Placement** | menus, menu_assignments, menu_items, menu_item_translations, ad_zones, banners, banner_zone, banner_stats |
| **Engagement/Hệ thống** | subscribers, notifications, notification_preferences, forms, form_fields, form_submissions, page_views, web_vitals_samples, redirects |
| **Theme/AI/Demo** | themes, theme_versions, site_theme, ai_jobs, ai_prompt_templates, demo_library_items, site_demo_state, demo_adoptions |
| **Địa giới** | provinces, wards |

## 17. Hiệu năng DB & quy tắc toàn vẹn *(Mục 30, 31)*

**Khung:** tốc độ visitor đến từ **cache + edge** (Mục 25); DB quyết định ở **cache-miss, ghi, admin, sức khỏe quy mô**.

**Indexing (đòn bẩy số 1):**
- **Composite dẫn đầu `site_id`** (mọi query lọc site_id): vd `(site_id, type, status, published_at DESC)`; index cột FK.
- **Partial** (`WHERE status='published'`), **covering/INCLUDE** (index-only scan), **expression** cho field JSONB hay lọc, **GIN** (JSONB `@>`), **GiST** (`ltree`).
- Index theo query thật (đo `pg_stat_statements`), không thừa.

**RLS index-friendly:** policy dùng index dẫn đầu site_id; hàm STABLE, rẻ.

**Query Budget ở CI (guardrail then chốt):** test **assert số query tối đa/route hot** → bắt hồi quy N+1 tự động khi vibe coding.

**Sức khỏe quy mô (KHI ĐO mới làm — KHÔNG vào schema khởi đầu, D10):** autovacuum tuning; **partition** orders/events/logs theo thời gian + **BRIN** cho bảng append; partition `entries`/`products` theo hash site_id CHỈ khi số đo đòi (phức tạp hóa migration 5.000 site → hoãn); archive dữ liệu nguội.

**Ghi:** batch insert (import/materialize demo); trigger ltree rẻ; việc không tới hạn → async Horizon.

**Noisy neighbor:** `statement_timeout`; escape-hatch tách DB cho tenant khổng lồ.

**Zero-downtime migration:** `CREATE INDEX CONCURRENTLY`; expand-contract.

**Quy tắc toàn vẹn/chất lượng dữ liệu:**

> **⬛ QUY ƯỚC NỀN TẢNG SCHEMA (chuẩn hóa — normative, áp cho mọi bảng):**
> - **D2 · Khóa & định danh (ADR-16):** **PK = `bigint` identity** khắp nơi (FK 8-byte → index composite `(site_id,…)` gọn/nhanh). Thực thể **lộ ra ngoài** có thêm cột **`ulid`** (public id, không enumerate, sortable). **TUYỆT ĐỐI không lộ `bigint id` thô** ra URL/API → public qua **slug** (content) / **code** (order) / **ulid** (media). Bảng nối/pivot chỉ cần bigint.
> - **D5 · Tiền tệ:** lưu **integer (VND đồng)** — KHÔNG dùng float/double cho `price`/`total`/`amount`/`value`. **Bề rộng = bigint (int64)** — int32 trần ~2,1 tỷ đồng, không đủ cho hàng B2B (as-built `products.price` 2026-07; áp cho mọi cột tiền sau). (Nếu cần sub-unit tương lai → `decimal`, không bao giờ float.)
> - **D4a · FK cascade:** con thuộc cha **ON DELETE CASCADE** (entry→translations/revisions; product→variants/images; order→items; taxonomy→pivot). Tham chiếu "mềm" (cover_media_id, logo_media_id…) **ON DELETE SET NULL**. Xóa **site** → cascade toàn bộ dữ liệu site (hoặc soft-delete site rồi purge job).
> - **D4b · Soft-delete (thống nhất):** chỉ các thực thể cần "Thùng rác/khôi phục" mới có **`deleted_at`** (entries, products, media, sites, tenants). Còn lại **hard-delete**. `status=archived` ≠ xóa (là trạng thái nội dung). **RLS + global scope PHẢI kèm `deleted_at IS NULL`.**
> - **D3 · Polymorphic vs typed (nhất quán):** **typed FK** cho đồ thị nội dung lõi (entry↔taxonomy) + **`routes`** (`entry_id`/`product_id`/`taxonomy_id` nullable + CHECK đúng-một + CASCADE). **Polymorphic** chỉ cho đính-kèm-mềm (`mediables`/`menu_items` target/`reviews`) — bắt buộc index `(type,id)` + **observer dọn mồ côi khi xóa cha** + job quét định kỳ.
> - **D6 · Bảng MIỄN RLS (global, KHÔNG `site_id`):** `tenants`, `users`, `tenant_users`, `permissions`, `plans`, `site_types`, `library_assets`, `provinces`, `wards`, `themes`/`theme_versions` (catalog), Pennant/Pulse/jobs hệ thống. **Mọi bảng khác = có `site_id` + RLS.** (Bất biến do `RlsCoverageTest` khẳng định bằng HEURISTIC "có cột `site_id` ⇒ buộc RLS+FORCE+policy"; bảng global không `site_id` như `site_types` tự động ngoài phạm vi — không cần allowlist.) **Lưu ý GHI:** global-no-RLS ⇒ KHÔNG có lớp DB chặn ghi chéo-tenant → catalog platform (`plans`/`site_types`/`themes`…) phải **PLATFORM-WRITABLE-ONLY qua policy/gate, tenant READ-ONLY**.
> - **D7 · Đặt tên:** snake_case; **KHÔNG dùng từ khóa SQL** làm tên cột → `"order"` đổi **`sort_order`** (workflow_states, content_sections). FK = `{entity}_id`. Boolean = `is_`/`has_`.
> - **D8 · Thứ tự per-context:** sắp xếp chung ở thực thể (`entries.position`); sắp xếp **trong từng category** → cột `position` trên **pivot** `entry_taxonomies(entry_id, taxonomy_id, position)`.
> - **D9 · Timestamps:** mọi bảng có `created_at`, `updated_at`; bảng soft-delete thêm `deleted_at`. Audit chi tiết → Audit service (Mục 29), không nhồi vào bảng nghiệp vụ.
> - **D11 · `timestamptz` (lưu UTC):** MỌI cột thời gian = **`timestamptz`**, lưu UTC, render theo timezone site. (Origin Singapore + user VN + đa-region tương lai → `timestamp` naive = bug lệch giờ.)
> - **D12 · Collation tiếng Việt:** cột cần so sánh/sắp/unique không-phân-biệt-hoa-thường + đúng dấu (email, slug, name) dùng **`citext`** hoặc **ICU collation** (`vi-VN`/`und-x-icu`). (Tìm kiếm vẫn qua Meilisearch; đây là cho sort/unique tầng DB.)
> - **D13 · Enum = `varchar` + `CHECK` (KHÔNG native ENUM):** `status`/`kind`/`link_type`/`vocabulary`… = varchar + CHECK constraint (hoặc bảng lookup). Tránh `ALTER TYPE` cứng nhắc của native ENUM → **dễ thêm giá trị, dễ mở rộng** (đúng mục tiêu nâng cấp).
> - **D14 · PgBouncer transaction-mode + prepared statements:** transaction-mode vô hiệu prepared-statement theo session → cấu hình Laravel/PG phù hợp (tắt PS hoặc xử lý protocol-level). **Kiểm trong Gate #1** (Mục 5) cùng SET LOCAL.

- **Kỷ luật `meta JSONB` + quy tắc "tốt nghiệp":** field (a) thường lọc/sắp/tìm, (b) cần ràng buộc, (c) dùng chung mọi type → **lên cột thật** (1 migration hiếm); type có NHIỀU field query → bảng mở rộng typed **1:1** (vd `event_details(entry_id PK→entries, starts_at, venue…)`). Chống "EAV trá hình".
- **Hiển thị theo locale** (Mục 10): entry.status × translation.status.
- **Revisions** có retention (N bản/90 ngày).
- **Đo-rồi-tối-ưu:** read-replica/partition khi số đo đòi, không non. Cache là khiên.

---

# PHẦN C — TÍNH NĂNG & UX

## 18. SEO / AEO + Core Web Vitals *(Mục 7, 24)*

**SEO/AEO (thuộc tính hệ thống, tự kiểm):**
- SSR HTML đầy đủ (khác site client-render → tốt cho Google + bot Zalo/Facebook).
- Schema JSON-LD tự sinh: Organization, **LocalBusiness** (từ `site_profiles`), Product + AggregateRating, Article, BreadcrumbList, **FAQPage** (từ post category=faq).
- Sitemap.xml + image sitemap + hreflang (chỉ locale đã publish) + `robots.txt` + **`llms.txt`** (AEO).
- **OG/Zalo preview per-page** (og:title/description/image đúng kích thước) — quan trọng cho thị trường VN.
- Meta SEO per-entry per-locale (ở `entry_translations`); slug bản địa hóa qua `routes`; 301 tự động khi đổi slug.

**Core Web Vitals — SLO (đo trang thật, mobile 4G, mạng VN):**

> 🟡 **Lưu ý TTFB-VN ≤200ms là MỤC TIÊU cần BENCHMARK, không phải mặc nhiên đạt.** Cloudflare định tuyến Anycast: ISP VN tự chọn PoP theo quan hệ mạng, KHÔNG theo địa lý — **VNPT/Viettel (~70% thị phần) thường đẩy traffic Cloudflare sang Singapore/HK** thay vì PoP trong nước (FPT route nội địa tốt hơn) → +50–150ms. Với origin Singapore (ADR-15): cache-HIT đạt ~40–90ms (sát ngưỡng), cache-MISS dễ vượt 200ms ở p75. **Phải benchmark TTFB thật từ cả 3 ISP (VNPT/Viettel/FPT), đo cả cache-hit lẫn cache-miss, ngay đầu Phase 0-1.** Nếu không đạt → kích hoạt nhánh ADR-15: **chuyển origin về VN (CloudFly.vn) + CDN có PoP VN thật** (Bunny.net/VNCDN <20ms) cho storefront, Cloudflare chỉ giữ DNS/SSL/WAF. (⚠ KHÔNG đặt Cloudflare TRƯỚC một origin-VN — thành vòng vèo VN→SG→VN→SG.)

| Chỉ số | Ngưỡng p75 |
|---|---|
| LCP (mobile) | ≤ 1.5s |
| INP | ≤ 200ms |
| CLS | < 0.1 |
| TTFB (VN) | ≤ 200ms |
| JS public | ≤ 30KB gzip |
| Lighthouse Perf | ≥ 95 |

**Chống bẫy BẰNG KIẾN TRÚC (không dựa kỷ luật thủ công):**
- Schema ảnh **ép tối ưu** (kích thước/format/lazy/width-height → chống CLS); imgproxy WebP/AVIF + responsive.
- **Script Manager ép defer/facade/Partytown** cho 3rd-party (chat/pixel) — thủ phạm INP số 1.
- Cache tập trung (Mục 25) → TTFB thấp.
- **Cổng CI:** Lighthouse CI + axe (a11y) gate khi publish + **RUM field** (`web_vitals_samples`).

## 19. Theme Engine & Page Builder *(ADR-04, ADR-05)*

- **Theme = Section Library + Page JSON**; **KHÔNG chạy code khách** (an toàn, export được). *(ADR-04)*
- **Token 2 tầng (v2.21):** *seed* LƯU per-site (`site_settings[theme][tokens]`: `primary/surface/ink/body/font` — contract, đổi tên = migrate data) → *role* (`--color-*`/`--brand-*`/thang spacing…) SINH từ seed trong CSS, đổi tên tự do; utility/preset chỉ tiêu thụ role. `ThemeResolver` sanitize key+value (chống style breakout). Spec: `docs/notes/section-style-system.md` §1.
- **Trình bày per-section (ADR-04 MỞ RỘNG v2.15) = style-props token hoá + preset platform-curated — CẤM raw CSS/class tự nhập ở mọi tầng tenant** (spec đầy đủ: `docs/notes/section-style-system.md`):
  - **Style-props chuẩn** áp MỌI section (kể cả data-bound). Đợt 1a build 12 prop: `bg(none|surface|tint|dark|brand|brand-soft|image|gradient-1..3)` (+`bg_image`=media_id ADR-17 batch-resolve + `overlay 0|20|40|60`) · `spacing_top/bottom(none|s|m|l|xl)` · `width(contained|narrow|full)` · `align(left|center)` · `corner(none|soft)` · `shadow(none|soft)` · `hide_on(none|mobile|desktop)` · `anchor_id`(slug-sanitize khi ghi + escape khi render) · `motion(none|fade|rise)`; Đợt 2: `overlay_tone` · `divider_top/bottom` · `border` · `motion_stagger` · `extra_style: list(enum theme-registered)`. **18 tên prop reserved toàn cục** (drift-sentinel — schema section KHÔNG khai trùng). Khai báo = **1 fragment §E7 dùng chung** merge tại `ComponentRegistry` (leaf mới `enum`); validate trong `ValidPageJson`; Blade map token→class qua **bảng tra fail-closed của theme** — KHÔNG nội suy giá trị đã lưu vào `class`/`style`; enum values **append-only**; editor lưu sparse (bỏ prop = default).
  - **Preset per-section** (3–6/loại = bundle variant+style-props, vet contrast AA) = **CODE REGISTRY platform-writable-only** (`SectionPresetRegistry` — lệ role-template §15.2, KHÔNG bảng DB: preset tham chiếu token/class phải deploy atomic cùng theme CSS); áp preset = **expand thành props rồi lưu Page-JSON, KHÔNG lưu tên preset** → platform đổi preset không phá trang cũ. AI-gen (ADR-08) ưu tiên sinh preset key → server expand.
  - **Ràng buộc CWV/a11y (enforce, không khuyến cáo suông):** `bg=image` render `<img>` + width/height (KHÔNG CSS-background — giữ LCP discoverable); eager/lazy theo **VỊ TRÍ block** (chỉ block đầu eager); `motion` = transform/opacity-only + **tự strip ở block đầu** + `prefers-reduced-motion` + IO module trong `sections.js` (fail-safe: JS không tải → hiện tĩnh); `hide_on` = ẩn CSS thuần (nội dung vẫn trong DOM/vẫn index — công cụ trình bày, KHÔNG phải visibility). Tiền đề Đợt 1a: **token hóa theme CSS** (PR đầu, visual no-op — gỡ hardcode brand khỏi `storefront.css`, trả nợ white-label).
- **Builder section-based** + **iframe preview thật** + postMessage; React island (Puck) cho canvas admin — **chỉ là editor, KHÔNG phải renderer storefront** (storefront render Blade từ Page JSON). Cần spike parity preview↔render. *(ADR-05)*
  > *Thực-tế (11/07):* editor admin đang chạy = **Livewire form-based** (`App\Modules\Admin\Livewire\Entries\Editor` + `ValidPageJson`), là MVP. **Puck** (canvas kéo-thả trực quan) = roadmap, **CHƯA build** — ADR-05 giữ nguyên hướng builder trực quan tương lai.
- **Template hiển thị theo category** (Mục 8): list/detail template thuộc Section Library; AI sinh template mới được.
- Theme versioned (`themes`, `theme_versions`, `site_theme`).

## 20. Tích hợp AI *(ADR-08)*

- **AI sinh JSON theo schema** (theme/trang/nội dung), **validate server-side bằng PHP** (spatie/laravel-data hoặc JSON Schema — KHÔNG Zod làm nguồn sự thật). *(ADR-08)*
- **Laravel AI SDK → Anthropic** qua **interface riêng** (provider-agnostic); pgvector cho embedding/tìm kiếm ngữ nghĩa.
- Hàng đợi qua Horizon; ngân sách AI per tenant (`ai_credit_ledgers`); prompt templates (`ai_prompt_templates`).
- *(Điểm cần kiểm chứng sớm: độ ổn định JSON theo schema — build để xác nhận.)*

**🟢 Luồng AI Onboarding "<10 phút có site" (tính năng KHÁC BIỆT cốt lõi — đặc tả hạng nhất GĐ1):** chủ site **điền vài trường** (tên công ty, ngành, mô tả, tông giọng, NAP — vào `site_profiles`) → **AI sinh** theme + trang chủ + nội dung mẫu (validate PHP, Mục 8/20) → **site sống dưới subdomain** trong <10 phút (dùng nội dung demo ảo Mục 21, `noindex` tới khi thật). Đây là luồng UX phải mượt và liền mạch, không để loãng giữa Mục 20+21 — là điểm bán hàng số 1. Phase 1 đặc tả + đo thời gian thật tới "site sống".

## 21. Nội dung demo ảo + tài nguyên dùng chung *(Mục 28)*

**Chống phình CSDL khi 10.000+ site:** "kho demo dùng chung + cá nhân hóa ẢO lúc render + vật chất hóa khi cam kết + noindex tới khi thật".
- `demo_library_items` (dùng chung) + `site_demo_state` + `DemoContentResolver` (thay biến + AI Haiku cache Valkey — **KHÔNG tạo bản ghi DB** cho nội dung demo).
- **UX:** công tắc "Sử dụng dữ liệu mẫu" (tắt→ẩn, không xóa); mục mẫu chỉ-đọc chỉ có "Sao chép"; Sao chép = đóng băng bản cá nhân hóa đang hiển thị → bản ghi thật (`demo_adoptions`/`materialized_from_demo_key`); mục mẫu gốc tự ẩn.
- **Liên thông media** (Mục 12): ảnh mẫu ở `library_assets` (toàn cục) → không hiện ở quản lý site; materialize khi nhận.
- Nội dung demo `noindex` tới khi thành nội dung thật (chống duplicate content — R6).

## 22. Xuất bản độc lập (Standalone Export) + License *(ADR-12, ADR-13)*

- **Export** = package-first monorepo + exporter; "single-tenant = N=1"; driver-swap (DB/cache/storage). *(ADR-12)*
- **License bản export = WHMCS Software Licensing addon** (Bảo Sơn đang vận hành, 368 license BSWeb-, đã xác nhận bản quyền hợp lệ). Bind domain; không phép → trang trắng câm; đổi domain qua Reissue. *(ADR-13)*
- Định giá Standalone Export theo từng khách; mẫu license agreement cần luật sư (gắn câu #3).

### 22.1 Cấu trúc thư mục (Modular Monolith) + diễn giải ADR-12 *(chốt v2.8 — normative)*

**Diễn giải ADR-12 "package-first":** = **ranh-giới-module-first**, KHÔNG tách Composer package ngày-1. Giai đoạn phát triển = **Modular Monolith** (CLAUDE.md §9): mỗi bounded context là một thư mục `app/Modules/<Context>/` (PSR-4 `App\Modules\<Context>`, một autoload root duy nhất). Đóng-gói thành Composer package **hoãn tới Phase 3** (khi dựng exporter) và khi đó là thao tác **cơ học** vì ranh giới đã sạch.

**Cây thư mục — SaaS (đang phát triển):**
```
app/Modules/
  Tenancy/      # 🔴 nền Gate #1: Site resolver · BelongsToSite · RLS · SET LOCAL · Octane reset · Entitlement
  Cms/ Commerce/ Media/ Rbac/ Taxonomy/ Forms/ …   # thêm module = thêm thư mục
  Shared/Contracts/   # interface liên-module CHỈ ở điểm swap/external (DomainProvisioner, WHMCS, AI)
database/migrations/  # TẬP TRUNG, đánh số theo tier FK (core 1xxx · content 2xxx · commerce 3xxx)
tests/Arch/           # ép HƯỚNG phụ thuộc acyclic; Tenancy phụ thuộc 0 module khác
themes/<name>/        # asset trình bày bundled theme (Blade layouts/partials/sections) — KHÔNG PSR-4; engine ở Modules/Theme
```

**Cây thư mục — bản export (Phase 3, N=1):** exporter dời `app/Modules/*` (đã bật theo entitlement) → `packages/*`, swap driver (DB/cache/storage), chèn License middleware (WHMCS, ADR-13). `BelongsToSite` vẫn chạy (site_id cố định); RLS thành tùy chọn (N=1).

**Cơ chế ranh giới (chốt):** ép bằng **arch test** (`tests/Arch/ModuleBoundariesTest.php` — hướng phụ thuộc acyclic, `Tenancy` là nền) + **interface contract CHỈ ở điểm swap/external** (KHÔNG ép interface mọi cạnh nội bộ → tránh trừu-tượng-sớm). FK/pivot **xuyên module được phép** (đồ thị quan hệ là MỘT — `entry_taxonomies`/`product_taxonomies`); chủ sở hữu pivot = module `Taxonomy`.

**Eject là gì (định khung lại):** ~**80% công sức = rút dữ liệu per-tenant (ETL `site_id=X`) + driver-swap + hạ RLS→N=1 + license**; chỉ ~20% là di chuyển code → tất cả thuộc **Phase 3**. ⇒ KHÔNG tối ưu cấu trúc code sớm cho eject.

## 23. Đặc thù Việt Nam *(Mục 33)*

- **Cụm liên hệ nhanh nổi** (Zalo OA + hotline click-to-call + Messenger + back-to-top) — **nạp facade** để không giết INP. Cấu hình ở `site_settings.contact_widgets`.
- **Footer pháp lý:** tên/MST/địa chỉ/GCN ĐKKD/người đại diện (từ `site_profiles`) + **badge "Đã thông báo Bộ Công Thương"** (online.gov.vn) cho site bán hàng.
- **Trang chính sách** (bảo mật/đổi trả/giao hàng/thanh toán/hướng dẫn) = `post` category=policy (mẫu sẵn theo ngành; chi tiết bắt buộc → câu #3 luật sư).
- **OG/Zalo link preview** per-page (Mục 18). **Định dạng VN:** VND, dd/mm/yyyy, địa chỉ 2 cấp (provinces/wards), font Việt subset, slug không dấu.
- **B2B (An Vinh):** form báo giá/gọi lại, tải catalog PDF, đối tác/chứng nhận/dự án (post + category).
- 🟡 **Chống spam form công khai (BẮT BUỘC GĐ1 — form là kênh Lead chính của B2B):** **Cloudflare Turnstile** (ẩn, không bắt user giải đố) + **honeypot** + **rate-limit**. Form liên hệ không chống bot sẽ ngập rác trong vài ngày → chủ site mất niềm tin vào tính năng Lead. (Rủi ro R20.)

## 24. UX tối ưu vô hình *(Mục 26)*

- Người dùng chỉ quyết định **kinh doanh/thẩm mỹ**; kỹ thuật ẩn đi. 3 tầng: tự-động-vô-hình / lựa-chọn-ngôn-ngữ-thường / tự-đề-xuất-một-chạm.
- "**Sức khỏe website**" thay điểm Lighthouse; không thuật ngữ kỹ thuật trong UI.
- Tối ưu (ảnh/script/cache/SEO) diễn ra tự động sau hậu trường (Mục 18) — chủ site không phải hiểu CWV.

---

# PHẦN D — VẬN HÀNH & CHẤT LƯỢNG

## 25. Cache (5 tầng) *(ADR-06)*

OPcache → **Valkey** → fragment → full-page → CDN, với **tag invalidation**.
- Cache là **tấm khiên** cho DB (Mục 17). Invalidate theo tag khi nội dung/menu/banner/settings đổi.
- Chống cache stampede (lock); full-page cache cho storefront ẩn danh; ESI/fragment cho phần động.
- 🟡 **Đặc thù multi-tenant "cold-cache long-tail":** host 30k site mà đa số ít người xem → cache site ít traffic dễ hết hạn GIỮA các lượt xem → tỉ lệ cache-miss cao hơn một app bận rộn → nhiều request rơi xuống SSR+DB+RLS hơn. **Giảm thiểu: ưu tiên invalidate-on-publish (TTL dài/vô hạn + bust theo tag) thay vì TTL ngắn theo thời gian** → site ít traffic vẫn "nóng" giữa các lần đổi nội dung (chỉ mất cache khi publish, không phải giữa mỗi view). Cache-hit-ratio quyết định kích cỡ origin/DB và chi phí → **phải ĐO trong load test ở scale thật** (Mục 31), không suy từ cảm giác.
- **Escape-hatch tương lai (ghi nhận, không xây nay):** vì storefront render từ Page JSON, có thể thêm bước **render-ra-static lúc Publish** như lớp tối ưu cho các site đã ổn định — không phải đập kiến trúc.

## 26. Tìm kiếm (Meilisearch) *(ADR-10)*

- **Meilisearch + Scout**; index theo **(loại × locale)**, document = entry **join bản dịch** theo locale; cập nhật qua Scout observer.
- 🟡 **Cô lập tenant cho search = `tenant tokens` (scoped search keys), KHÔNG chỉ filter `site_id` ở app.** Vì Meilisearch không biết RLS của Postgres → nếu chỉ dựa vào việc MỌI truy vấn nhớ thêm `filter: site_id=X` thì một lần quên (dễ khi vibe-coding) = lộ kết quả chéo. Giải: sinh **API key đã ký, nhúng sẵn `site_id=X`** bên trong; search phục vụ qua token đó → **engine tự ép filter** bất kể truy vấn yêu cầu gì (chuyển cô lập từ "kỷ luật app" mong manh sang "engine tự ép" chắc chắn — đúng tinh thần RLS). Vẫn index field `site_id` như cũ.
- Gõ tiếng Việt tốt, typo-tolerant, nhanh.

## 27. Email · Billing · Hóa đơn điện tử · Vòng đời tài khoản *(Mục 27)*

- **Email giao dịch** (Postmark/SES/Resend) + SPF/DKIM/DMARC + Laravel Notifications (R18 chống rớt spam). Thông báo workflow/đơn hàng/form qua đây.
- **Billing SaaS = WHMCS** + **hóa đơn điện tử VN**.
- **Quota enforcement (kéo concept từ review docs cũ — v2.x chỉ có bảng plans/subscriptions tĩnh):** **soft limit** (cảnh báo 80%) / **hard limit** (chặn + **grace 48h**) trên các metric (sites, pages, users, ai_tokens, automation_runs, exports, form_submits, analytics_events) + `incrementUsage()` + Quota API + retention (usage ~12 tháng, invoice 7 năm theo luật VN). Giới hạn cụ thể/tier → Phụ lục E1 (đổi USD→VND, bỏ Stripe).
- **Vòng đời tài khoản/dữ liệu:** grace → suspend → retention → xóa (tuân PDPD).
- **Analytics privacy-first cookieless** (`page_views`) — không cần cookie banner trừ khi gắn tracker bên thứ ba.
- **Liên tục kinh doanh solo** (R19): backup, runbook, monitoring tự động.
- Gia cố: idempotency (webhook VietQR), cache stampede, dead-letter queue.

## 28. Tên miền (subdomain + custom domain) *(ADR-07)*

- **Subdomain wildcard** mặc định + **custom domain qua Cloudflare for SaaS** (SSL tự động).
- **Chống SEO spam-network 15k subdomain** (R17): subdomain demo/chưa-thật để `noindex`; chỉ index khi lên domain thật + nội dung thật.
- **DEFERRED (ghi, GĐ1 KHÔNG xử): per-host session cookie name.** Cô lập session theo host bằng cookie name riêng (bs_<hash(host)>_session) đã thử và BỎ — config('session.cookie') runtime là no-op dưới Octane (SessionManager cache tên cookie lúc build store, không đọc lại config mỗi request; verified curl + --force-recreate). Nếu làm lại khi custom domain go-live: dùng session store / $request->session()->setName() đúng thời điểm, KHÔNG config() runtime. Hiện SESSION_DOMAIN=null (cookie host-only) đã cô lập chéo-host — xem Mục 29, Mục 5.

## 29. Bảo mật (defense-in-depth) *(Mục 5, 15)*

- **3 lớp + RLS:** Entitlement → RBAC → RLS (Mục 5, 15). Mọi bảng `site_id` + RLS.
- **Phân cấp không leo thang** (role.level + entitlement ceiling).
- **Media:** token mờ (không lộ site_id), transform ký HMAC, signed URL cho private.
- **Secrets:** SMTP password mã hóa at-rest; KHÔNG để credential trong code/config public.
- **Hash mật khẩu (chốt theo review docs cũ):** **Argon2id** làm lớp dưới (platform-core dùng bcrypt — yếu hơn, nâng lên) + **global pepper** (`AUTH_PASSWORD_PEPPER`) + **rotation zero-downtime** (`*_PREVIOUS`, tự re-hash khi login, command `password:check-rotation`). ⚠ **KHÔNG derive pepper từ email/id** (user đổi email → mật khẩu chết); nếu cần user-specific thì từ cột `password_pepper_salt` random cố định/user. Lợi ích user-specific cận biên thấp → **global pepper + Argon2id thường là đủ**.
- **2FA TOTP** (port từ platform-core — code chạy được): secret + recovery codes **encrypt at-rest**, challenge flow, QR. **Bật cho platform-admin (Bảo Sơn) ở Phase 1** (gần như bắt buộc compliance). Tenant: tùy chọn.
- **Audit service** (port từ platform-core — thiết kế hoàn chỉnh, v2.x mới chỉ có bảng trống): `Audit::record` **best-effort không throw** (không chặn nghiệp vụ) + taxonomy `domain.entity.action` + retention (`audit:prune`) + **X-Request-Id correlation**. Mọi thao tác nhạy cảm (role/permission/entitlement/domain/billing) ghi qua đây.
- **RBAC tooling** (port từ platform-core): `rbac:sync` / `rbac:grant` / `rbac:bootstrap-super-admin` / **`rbac:export-permissions` → type-safe FE** + helper `<Can>`/`useCan`. ⚠ platform-core **KHÔNG có `role.level`** trong code → **giữ thiết kế `role.level` của tài liệu này (tốt hơn)**, khi port phải tự thêm cột level (Mục 15.1).
- **Cô lập tenant** là bất biến số 1 — arch test kiểm "mọi model BelongsToSite", "mọi action qua policy", "mọi transition qua engine".
- **HTTP security headers (chốt, áp middleware):** `Strict-Transport-Security` (HSTS, includeSubDomains+preload) · `X-Content-Type-Options: nosniff` · `Referrer-Policy: strict-origin-when-cross-origin` · `Permissions-Policy` (tắt tính năng không dùng) · `X-Frame-Options`/`frame-ancestors`. ⚠ **Sắc thái: builder dùng iframe preview (ADR-05) → `frame-ancestors 'self'`** (admin origin), KHÔNG blanket DENY; storefront thì chặt.
- **CSP — phần TINH VI, không "9 directive cứng" được, vì xung đột với Script Manager (Mục 14) + iframe builder:**
  - **Admin:** CSP **chặt** (không cho script tùy biến trong admin) + nonce inline.
  - **Storefront:** CSP **sinh ĐỘNG per-site** = allowlist các domain tích hợp đã biết (GTM/GA4/Pixel/Zalo/chat) + nonce; site dùng **raw custom code** (escape-hatch Mục 14) → CSP buộc nới cho site đó (đánh đổi có cảnh báo, ghi rõ). Quản lý qua `spatie/laravel-csp`, policy per-route/per-site.
  - Lý do không thể CSP cứng: nền tảng **cho tenant tự nhúng script** — CSP cứng sẽ chặn chính tính năng đó; phải cân giữa bảo vệ và tiện dụng.
- ⚠ **Vận hành:** đổi mật khẩu DB t5g đã lộ trong lịch sử trao đổi; không gửi credential/dữ liệu thật khi phân tích.

## 30. Chất lượng cho solo + AI (guardrails) *(Mục 11, 20, 21)*

> "Ngôn ngữ nhanh nhất là bẫy" — tốc độ đến từ kiến trúc, chất lượng đến từ **guardrail tự động** (đội QA thay người review).

- **Larastan max** + **Rector** + **mutation testing (Infection)** + **Pest 4** + arch test (cách ly tenant, ranh giới module).
- **Query Budget ở CI** (Mục 17) chống N+1; **Lighthouse CI + axe** gate khi publish + RUM.
- **Pre-commit hooks**; ESLint cho island.
- **Walking skeleton** trước, "xây trên nền đã lát" (skeleton + guardrail dựng trước khi code tính năng).
- **RLS = lưới an toàn số 1** (bù việc vibe coding solo có thể quên scope — R16).
- **Definition of Done (DoD)** cho mọi thực thể/bảng: site_id+RLS, i18n đúng pattern, validation biên + ràng buộc DB, index theo query thật + Query Budget, audit thay đổi nhạy cảm, arch test bất biến.

## 31. Observability · Capacity · SLO *(Mục 9, 21)*

- **Observability:** structured logging, `pg_stat_statements`, slow query log, lock/connection monitor, RUM (`web_vitals_samples`), Horizon dashboard.
- **Load test ở scale thực** trên dữ liệu seed cỡ 10k-tenant (xác nhận RLS/PgBouncer/cache trước khi mở rộng). **Đo `cache-hit-ratio`** (đặc thù long-tail multi-tenant — Mục 25): quyết định kích cỡ origin/DB + chi phí; đo cả concurrency-isolation (Gate #1).
- **SLO:** CWV (Mục 18) + uptime + queue latency + error rate. Đo-rồi-tối-ưu (read-replica/partition khi số đo đòi).

---

# PHẦN E — TRIỂN KHAI

## 32. Lộ trình theo giai đoạn + An Vinh đầu tiên

**Triết lý triển khai:** dựng **An Vinh** (B2B cơ khí) như **walking skeleton thật** trước, rồi tổng quát hóa thành nền tảng + template ngành.

**Phase 0 — Nền móng (skeleton + guardrail):**
1. `CLAUDE.md` (quy ước dự án + guardrail + kiến trúc) + cấu trúc skeleton package-first.
2. Hạ tầng dev/CI; PostgreSQL 17 + **RLS** + PgBouncer; Octane+FrankenPHP; Valkey. **Deploy target (ADR-15):** dev local Docker + CI GitHub Actions → **Laravel Cloud (Singapore) + Cloudflare + R2** cho staging/production; giữ code portable.
3. **Guardrail trước tiên:** Larastan max, Rector, Pest, arch test cách ly tenant, **Query Budget**, Lighthouse CI + axe, pre-commit. **+ GATE #1 (Mục 5): cô lập tenant dưới Octane** (SET LOCAL + assertion site_id + reset singleton + **concurrency test** — phải XANH trước khi xây tính năng). **+ Benchmark TTFB-VN** từ 3 ISP (Điểm SLO Mục 18). **+ Search tenant tokens** (Mục 26).
4. Multi-tenant lõi: sites/domains, `BelongsToSite` + RLS + middleware resolve site, `SET LOCAL site_id`.

**Phase 1 — An Vinh chạy thật (MVP):**
5. Content engine `entries` (page/post) + `entry_translations` + content-type registry + content_sections.
6. Taxonomy (ltree) + routes + i18n lõi (**locale theo site**; An Vinh vi/en/ja, default vi).
7. Theme + Page Builder (Section Library + Page JSON + iframe preview) + template-theo-category.
8. Menu (**model-B: assignment per region×locale + builder**) + Banner/zone + Media (R2 + imgproxy + images.bsweb.vn).
9. Settings + site_profiles (NAP + footer pháp lý + Bộ Công Thương) + cụm liên hệ nhanh (Zalo/hotline/Messenger facade).
10. RBAC 3 tầng + cổng-đăng-theo-quyền (workflow Tầng 1); SEO/AEO + CWV gate; form báo giá/gọi lại.

**Phase 2:** commerce đầy đủ (products/cart/orders/VietQR/coupons/reviews), thêm locale, workflow Tầng 2 (editorial) cho site báo chí, tối ưu theo số đo.

**Phase 3:** Standalone Export + License (WHMCS), marketplace theme, headless/API, migrate dữ liệu core CMS cũ (5.000 site — schema t5g map sạch).

## 33. Câu hỏi mở cần chốt để ĐÓNG YÊU CẦU

| # | Câu hỏi | Trạng thái | Chặn? |
|---|---|---|---|
| 1 | Quan hệ với core CMS 5.000 site: greenfield + importer hay kế thừa? (schema t5g đã xác nhận map sạch → import khả thi) | **CHƯA** | Chặn kickoff |
| 2 | ~~Hạ tầng + ngân sách~~ | ✅ **ĐÃ CHỐT (ADR-15):** Laravel Cloud (Singapore) + Cloudflare + R2 cho Phase 0-1 (zero-ops, rẻ ở quy mô nhỏ), giữ portable → chuyển CloudFly.vn tự quản khi scale | — |
| 3 | Luật sư: nghĩa vụ TMĐT + mẫu license agreement + e-invoice + Bộ Công Thương | **CHƯA** | Chặn kickoff |
| 4 | Tên thương mại + domain (placeholder "NovaSite"; image CDN dự kiến images.bsweb.vn) | CHƯA | Không chặn |
| 5 | Khung giá per-site + gói Agency | 🟢 **ĐỊNH HƯỚNG (giá cụ thể tính sau — KHÔNG chặn):** **rẻ hơn Sapo / Haravan + đối thủ**, khớp khả năng chi trả khách VN. Khung Free/Pro/Business/Enterprise (Phụ lục E1) chỉ là điểm khởi đầu tham khảo; chốt số khi gần ra mắt | Không chặn |
| 6 | Mô hình phát triển | ✅ Solo + AI (Claude Code) | — |
| 7 | Ngôn ngữ | ✅ **Locale theo site** (An Vinh vi/en/ja, default vi; lõi locale-agnostic — thêm locale khác khi khách cần) | — |
| 8 | Standalone Export | ✅ định giá per khách + WHMCS licensing (mẫu agreement gộp #3) | — |

**Còn lại để đóng yêu cầu:** #1 (core CMS — schema t5g đã xác nhận import khả thi) và #3 (luật sư) — **đều xử lý SONG SONG được khi đã bắt đầu code** (không chặn dựng local + An Vinh). Câu #2 (hạ tầng) đã chốt (ADR-15). #4, #5 không chặn.

---

# PHỤ LỤC

## A. Chỉ mục 18 ADR (trạng thái cuối)

| ADR | Quyết định | Trạng thái |
|---|---|---|
| 01 | Modular Monolith (không microservices) trên Laravel 13 + PHP 8.5 + Octane | Chốt |
| 02 | Storefront **Blade SSR + Alpine islands** (JS ≤30KB); Admin **Livewire 4/Volt** + React island (chỉ builder, Inertia v2); headless-ready | Chốt (đã tiến hóa) |
| 03 | PostgreSQL 17 shared schema + site_id + **RLS 2 lớp**; PgBouncer transaction + SET LOCAL; escape-hatch tách DB | Chốt |
| 04 | Theme = Section Library + Page JSON; KHÔNG chạy code khách. **Trình bày per-section = style-props token/enum allow-list (fragment §E7 chung, leaf `enum`, validate ValidPageJson, 18 tên reserved) + preset registry platform-curated (CODE — `SectionPresetRegistry`, lệ §15.2, KHÔNG bảng) — CẤM raw CSS/class tự nhập; expand-on-apply, KHÔNG lưu tên preset. Chi tiết: `docs/notes/section-style-system.md` (v2.15)** | Chốt (mở rộng) |
| 05 | Builder section-based + iframe preview thật + postMessage | Chốt |
| 06 | Cache 5 tầng (OPcache→Valkey→fragment→full-page→CDN) + tag invalidation | Chốt |
| 07 | Subdomain wildcard (1 wildcard cert) + custom domain. **Bọc sau interface `DomainProvisioner`** để đổi **Caddy on-demand TLS (≈0đ, tự host) ↔ Cloudflare for SaaS (~$0.10/hostname, zero-ops)** không sửa code nghiệp vụ (nối Điểm 2: nếu không đặt CF trước storefront thì cần đường SSL không-qua-CF) | Chốt (interface-wrapped) |
| 08 | AI sinh JSON theo schema, **validate server-side bằng PHP** (không Zod nguồn sự thật) | Chốt |
| 09 | Octane + **FrankenPHP** worker mode chính, **RoadRunner fallback** | Chốt |
| 10 | Meilisearch + Scout, index theo (loại × locale), filter site_id | Chốt |
| 11 | Chuỗi UI lai (file ⊕ DB override); **nội dung dịch = bảng dịch `*_translations`** (không JSONB/không nhân bản); URL + slug bản địa hóa qua routes. **Menu = model-B bật từ Phase 1** (menu riêng mỗi (region×locale) qua `menu_assignments`, resolve theo assignment + fallback default-locale) — yêu cầu thật của site tham chiếu (An Vinh), không đầu cơ. **URL i18n:** default-locale (per-site, **mutable**) unprefixed + non-default `/{locale}`; **PathBuilder duy nhất** + canonical-guard đọc default động (`/{default}/..→301`, không cần migration URL) | Chốt (revise) |
| 12 | Standalone Export — package-first monorepo, exporter, single-tenant = N=1, driver-swap | Chốt |
| 13 | License bản export = **WHMCS Software Licensing addon** (368 license, bản quyền hợp lệ); bind domain, không phép → trang trắng | Chốt |
| 14 | **Mô hình Hybrid:** `entries` + type + registry (không migration) + `meta JSONB` (không EAV); taxonomy dùng chung (ltree + typed pivots); i18n bảng dịch; commerce typed riêng. Cột entries.type ∈ {page, post}; product = bảng riêng (commerce typed); khác biệt loại nội dung qua category + template (Mục 8) | Chốt (mở rộng) |
| 15 | **Mục tiêu hạ tầng/triển khai:** Phase 0–1 deploy trên **Laravel Cloud (region Singapore)** + **Cloudflare** (CDN/WAF/edge VN Hà Nội+HCM) + **R2** (media). Lý do: **zero-ops** + sinh ra cho Laravel/Octane/FrankenPHP + **rẻ ở quy mô nhỏ** nhờ scale-to-zero (dev ≈ $0-10/th, +An Vinh ≈ $15-30/th) → tập trung dựng sản phẩm. Giữ code **portable** (driver-swap, không phụ thuộc primitive độc quyền) để **chuyển sang CloudFly.vn (VPS VN tự quản + Laravel Forge) khi lên quy mô** (rẻ hơn ở scale lớn, đổi lại nhận ops). Dev local Docker; CI GitHub Actions. Đánh đổi: origin Singapore (bù bằng Cloudflare edge VN); lock-in (bù bằng portability); tự quản Postgres hoãn tới giai đoạn scale | Chốt (v2.2) |
| 16 | **ID & key strategy:** PK = **`bigint` identity** khắp nơi (FK 8-byte → index composite `(site_id,…)` gọn/nhanh); thực thể lộ ra ngoài thêm cột **`ulid`** (public id, chống enumerate, sortable). **KHÔNG lộ bigint id thô** ra URL/API (public qua slug/code/ulid). Bảng pivot chỉ bigint. UUIDv7 là lựa chọn thay thế nếu ưu tiên đơn giản hóa export hơn độ gọn index (Mục 17 D2) | Chốt (v2.5) |
| 17 | **Media — Storage + Serving (supersede §12):** R2 **private** (bucket `baoson-media`, chỉ Worker đọc qua binding), 1 master WebP/ảnh; key content-addressed `{tenant}/{collection}/{slug}-{hash}.{ext}` (`tenant` = **`Site.slug`** citext-unique sinh-từ-name lúc provision, bất biến — thêm ở PR-1; `hash` = SHA-256[:12] nội dung, tính 1 lần lúc upload → dedup + cache-bust) thay `sites/{site_id}/media/{ulid}`; serving qua **Cloudflare Worker** (cf.image format=auto + width-whitelist + raw-master fallback) thay imgproxy-primary (imgproxy → **L4 cold-standby**, ngoài path chính); **resolver đa-driver** `media.driver ∈ {cloudflare, imgproxy, static}` (switch = 1 env), **KHÔNG cột `url`** (derive lúc render); lib **custom** `App\Modules\Media` thay spatie/medialibrary; domain `files.baoson.net`; resilience L0–L4 (bỏ L5). Media công khai (không token); cô lập tenant qua prefix `{tenant}` + RLS `media.site_id` FORCE fail-closed (tên bảng = `media`, nhất quán §12/§16 — reconcile 29/06). ⚠ PR-1 upload-safety BẮT BUỘC §2.5 (sniff magic-bytes + MIME whitelist + sanitize SVG). Epic: `docs/notes/media-system-epic.md` | Chốt (supersede §12) |
| 18 | **Data & Performance Standards (scale nền multi-tenant, greenfield):** cô lập theo **`site_id`**. **Partition = measure-first CÓ TRIGGER** (bảng ≥30M rows HOẶC p99 breach → mới partition; ứng viên HASH-`site_id`: entries/products/media/mediables/orders · RANGE-time+**pg_partman**: form_submissions/audit_logs; KHÔNG partition config/RBAC/lookup/tenants/domains; whale=tách-DB ADR-03). **PK giữ `bigint identity + ulid` (ADR-16), single-PK now**; retrofit→composite `(site_id,id)` khi chạm trigger = 1-lần-có-kế-hoạch (playbook pg_partman, cửa-sổ-bảo-trì). **Read/write split** = design-intent, KHÔNG enforce (chưa replica). **Tenant-ctx** = codify bất-biến (explicit txn + `site_id=?` tường minh — đã có) + prod `assert=sample` + `READ ONLY` cho GET + **cache domain→site_id + negative-cache**. Chuẩn: index-dẫn-`site_id`+partial/BRIN · keyset+tie-break · rollup (không COUNT(*) live) · COPY-seed-lệch. **Loại:** schema/db-per-tenant · UUIDv4/v7 · partition-now · composite-PK-now. Chi tiết: `docs/notes/data-platform-standards.md` | Chốt (v2.12) |

**ADR ĐỀ XUẤT (Draft — CHƯA Accepted; ghi 11/07 sau audit hội đồng, chờ chốt-build. KHÔNG tính vào "18 ADR" ở trên):**

| ADR | Quyết định | Trạng thái |
|---|---|---|
| 19 | **Page Builder — section kind `html` (khối rich-HTML):** tái dùng `RichTextSanitizer::full` **sanitize-KHI-GHI ở MỌI đường ghi** (editor người **+ AI-gen Page-JSON ADR-08 + import** — nếu bỏ sót AI/import = stored-XSS) + phủ `ValidPageJson`; render qua `BlockRenderer`. Khác kind `richtext` hiện có (`paragraphs` plain-text). | **Đề xuất (Draft)** |
| 20 | **Khối tái dùng (reusable blocks):** bảng **typed riêng `content_blocks`(+`content_block_translations`)** — KHÔNG mở `entries.type='block'` (giữ entries thuần `page\|post` theo §9); Page-JSON tham chiếu `reusable_ref{block_id}` + guard **đệ quy/độ sâu/ngân sách**; `block_usages` back-ref + **GIN `entry_translations.body`** (tra "trang nào dùng block X"). **Build HOÃN** (YAGNI — An Vinh brochure chưa cần). *Loại:* `entries.type='block'` (buộc chặn block khỏi route/sitemap/listing/menu-target ở N nơi = bất biến dễ vỡ cho sản phẩm bán ra). | **Đề xuất (Draft)** |
| 21 | **Tracking — seam khung (near-term):** **CSP nonce ĐỘNG per-request** (hiện thực §29 đã đặc tả, qua `spatie/laravel-csp`; vá CSP static + admin-plane hở hiện tại) + **analytics cookieless `page_views`** (đã intent §27/§29). Kích hoạt khi Script-Manager / tag 3rd-party xuất hiện. | **Đề xuất (Draft)** |
| 22 | **Tracking — 3rd-party & lead (pha thương mại, DEFER):** `site_integrations` (secret **mã hoá** + `site_id`+RLS) + consent record + collector/queue fan-out + attribution lead (gclid/fbclid/utm trên `form_submissions`) + offline-conversion export; tracking-domain suy từ `config('tenancy.base_domains')`. YAGNI cho An Vinh; council chốt scope pha thương mại. | **Đề xuất (Draft)** |

> **Ghi chú Revisions (audit 11/07):** GIỮ `entry_revisions` **typed** (đã đặc tả §8/§16/§17/D4a) — **loại** đề xuất `content_revisions` polymorphic (tránh lặp lỗi orphan-sweep đã dính ở `mediables`; FK-cascade toàn vẹn; KISS>DRY cho solo). Products/blocks cần history → thêm `product_revisions`/`block_revisions` typed khi tới. An toàn draft↔published (§8) = build snapshot-on-publish vào `entry_revisions` khi ưu tiên safe-edit. **Index** `(site_id,type,status,published_at DESC)` = REVERSIBLE → measure-first (§17), chưa thêm; `products` bỏ `type` (không có cột).

## B. Sổ rủi ro R1–R24 (chính)

| R | Rủi ro | Giảm thiểu |
|---|---|---|
| R1 | Lộ dữ liệu chéo tenant | 2 lớp scope + RLS (lưới số 1) |
| R6 | Duplicate content (demo) | Demo ảo + noindex tới khi thật |
| R13 | Nhân bản export trái license | WHMCS licensing + bind domain |
| R14 | Khóa nhầm site | Quy trình + audit + grace |
| R15 | Coupling WHMCS | Bọc interface |
| R16 | Vibe coding solo không guardrail | Phòng thủ nhiều lớp + RLS + Query Budget + arch test |
| R17 | SEO spam-network 15k subdomain | noindex subdomain demo, index khi domain thật |
| R18 | Email rớt spam | SPF/DKIM/DMARC + provider giao dịch |
| R19 | Liên tục kinh doanh solo | Backup + runbook + monitoring tự động + **test RESTORE ra staging hằng tháng** (backup chưa từng restore = chưa có backup) |
| R20 | Form công khai bị spam bot | Cloudflare Turnstile (ẩn) + honeypot + rate-limit (Mục 23) |
| R21 | Lộ kết quả search chéo tenant | Meilisearch **tenant tokens** (engine tự ép site_id), không chỉ filter ở app (Mục 26) |
| R22 | Lộ dữ liệu chéo do site_id set sai dưới Octane | **Gate #1 Phase 0**: SET LOCAL + assertion + reset singleton + concurrency test (Mục 5) |
| R23 | AI sinh nội dung hàng loạt 10k site → Google phạt (thin/spam/duplicate) | AI anti-abuse rules + publish-gate đầy đủ (summary ≤3 câu + ALT hero + schema) + demo ảo noindex (Phụ lục E4, Mục 18/20) |
| R24 | Tấn công qua file upload (RCE/stored-XSS) | R2 ngoài web-root (chặn thực thi) + whitelist MIME + blocklist đuôi + sniff magic-bytes + **sanitize SVG** + security headers/CSP (Mục 12, 29) |

## C. Khảo sát 5 website + schema t5g (cô đọng)

| Nguồn | Loại hình | Bài học chính |
|---|---|---|
| **An Vinh** (sẽ làm đầu) | B2B cơ khí, link từ Zalo | **Client-render → HTML rỗng → SEO/Zalo-preview yếu**; dựng lại SSR = giá trị Diamond++ |
| sonha.baoson.net | B2B dịch vụ/dự án | services/projects/news/gallery/recruitment; noindex subdomain (đúng pattern) |
| batxephanoi.com | E-commerce (Woo) | danh mục SP 3 cấp, cart, giá gốc/KM, cờ nổi bật/bán chạy, tra cứu đơn theo mã, trang chính sách |
| iaps.org.vn | Tổ chức/nghiên cứu | taxonomy 3 cấp, sự kiện, ấn phẩm, tạp chí theo số, hồ sơ lãnh đạo, newsletter |
| **t5g schema** (SQL Server) | CMS Bảo Sơn | **Xác nhận lõi:** Posts.ObjectType≈entries.type, Posts.ParentId, Category.ObjectType≈vocabulary, **SeoDescription per LangCode≈bảng dịch**, Settings(Name,Value,LangCode)≈site_settings, Modules tree+AclGrant≈module/RBAC động, Posts.ObjectFormat≈template. **Cải tiến:** Metas EAV→meta JSONB; cột cứng L1/L2→workflow data-driven |

**Kết luận khảo sát:** mô hình ADR-14 **tổng quát hóa xuất sắc** qua mọi loại hình; mọi "loại nội dung" quy về `post` + category + template, **không bảng mới/không migration**. Mô hình mình chọn trùng với hệ 5.000 site đã chạy thực tế → vừa đúng, vừa **migrate khả thi**.

## E. SPEC BỔ SUNG — KÉO TỪ DOCS CŨ + PLATFORM-CORE (v2.4)

> Từ review đối chiếu docs cũ (Next.js/Payload — chỉ lấy ý tưởng/business, KHÔNG lấy code) + **platform-core (Laravel cùng stack — code tái dùng trực tiếp)**. Đây là phần v2.x **cố tình nén hoặc chưa chạm** (đa số business + ops + auth-plumbing). Không lật đổ thiết kế — chỉ bổ sung. Nhãn: 🟢 kéo ngay · 🟡 theo giai đoạn.

### E1. Business (docs cũ HƠN HẲN — v2.x ≈ 0)
- 🟢 **Định hướng giá (chốt số sau):** **rẻ hơn Sapo/Haravan + đối thủ**, khớp khả năng chi trả khách VN. Khung Free / Pro 499k / Business 1.99tr / Enterprise 4.99tr+ /tháng + giới hạn site/page/user chỉ là **điểm khởi đầu tham khảo**. **Cần điền khi chốt: storage GB + AI-token/tháng mỗi tier** (gap cả 2 bên đều thiếu).
- 🟢 **8 personas + WTP/tháng** (P1 SME 0.5-1tr … P5 BĐS 2-10tr, JTBD) — tài sản discovery, cơ sở validate giá.
- 🟢 **Market/OKR số:** 590K DN addressable / 190K ngách; conversion ≥5%, churn <5%, NPS ≥40, MRR target — mục tiêu định lượng.
- **Quota enforcement** (đã đưa Mục 27): soft 80% / hard + grace 48h + metrics + incrementUsage + retention.

### E2. Forms — spec đầy đủ (🟢 lỗ feature LỚN NHẤT; An Vinh dùng form làm kênh Lead chính)
- **7 field types:** text, textarea, email, select, checkbox, file, date.
- **Validation:** required, email, MIME + size (file).
- **Anti-spam đa lớp:** honeypot + **Cloudflare Turnstile** (ẩn) + rate-limit/IP (đã vào Mục 23, R20).
- **Lead status enum:** new → processing → completed → failed.
- **`recipients[]`** (gửi nhiều người) + lưu `form_submissions` + thông báo.
→ Mở rộng 3 bảng trống `forms/form_fields/form_submissions` thành spec này.

### E3. Port từ platform-core (Laravel — code chạy được, tiết kiệm nhiều ngày)
*(Đã đưa thiết kế vào Mục 29; đây là inventory + trạng thái thật theo code.)*
- 🟢 **C1 2FA TOTP** — secret + recovery codes encrypt at-rest, challenge, QR → Phase 1 cho platform-admin.
- 🟢 **C2 Audit service** — `Audit::record` best-effort + taxonomy `domain.entity.action` + retention + X-Request-Id (chênh lệch lớn nhất với v2.x).
- 🟢 **C3 RBAC tooling** — `rbac:sync/grant/bootstrap-super-admin/export-permissions→type-safe FE` + `<Can>`/`useCan`.
- 🟡 **C4 Global pepper + rotation** — đã chốt Argon2id + global pepper (Mục 29); ⚠ KHÔNG pepper theo email.
- **Đính chính (agent verify code):** platform-core dùng **bcrypt** (v2.x nâng **Argon2id**); **~62 permission** (không phải 68); **KHÔNG có `role.level`** (giữ thiết kế v2.x — tốt hơn). Bỏ: feature-flag tự viết (giữ **Pennant**), tenancy/RLS (platform-core skeleton SQLite không RLS — v2.x RLS Postgres hơn hẳn).

### E4. AI anti-abuse + Publish-gate (🟢 chống Google phạt khi sinh AI hàng loạt 10k site)
- **AI anti-abuse rules:** cấm mass-AI-pages chưa review / thin content / hidden text / keyword stuffing.
- **Content quality:** 1 trang 1 chủ đề; đoạn quote-ready 1-3 câu (tốt cho AEO).
- **Publish-gate đầy đủ** (mở rộng "bắt buộc summary" của v2.x): summary ≤ 3 câu + **ALT hero bắt buộc** + **schema type defined**. (Rủi ro R23.)

### E5. Cache — bảng quy ước tag + invalidation (🟢 biến "tag invalidation" trừu tượng thành cấu hình được)
- **Tag-format per content-type:** `site:{id}:{locale}:{collection}:{ident}`.
- **Bảng invalidation event → tags** (publish/update/delete → tag nào bị bust).
- **Rule cô lập cache đa tenant:** không cache chéo tenant; KHÔNG cache auth/token. (nối Mục 25)

### E6. Monitoring — ngưỡng alert cụ thể (🟢 biến "SLO" trừu tượng thành spec)
- **Ngưỡng:** error > 5%/5m → critical; cpu > 90%/10m; db_fail > 0; response > 2s; redis < 50ms.
- **Restore-test định kỳ** (vd Chủ nhật) — đã vào R19.
- **SLA-per-plan:** 95% / 99% / 99.9% theo tier. (nối Mục 31)

### E7. Theme — schema khai báo section (🟢)
- **Section metadata schema:** props **typed** + cờ **`translatable`** per prop.
- **Palette generator:** 1 màu brand → scale 50-900.
- **Per-device visibility toggle** (đã có ở menu meta — đồng bộ cho section). (nối Mục 19)
- **Style-props fragment chuẩn (ADR-04 mở rộng v2.15):** leaf mới `enum(values)` + `list(enum)`; **18 tên prop trình bày reserved toàn cục** (`bg, bg_image, overlay, overlay_tone, spacing_top, spacing_bottom, width, align, corner, shadow, border, divider_top, divider_bottom, hide_on, anchor_id, motion, motion_stagger, extra_style`) — schema section KHÔNG khai trùng (drift-sentinel test, cùng lệ sink-test). Per-device visibility hiện thực bằng `hide_on`; menu `css_class`/điều-kiện-hiển-thị (§13) khi build cũng theo allow-list — KHÔNG free-text. Spec: `docs/notes/section-style-system.md`.

### E8. Testing — danh mục E2E flow nghiệp vụ (🟢; bỏ config Vitest/Playwright)
- form public → lead hiện trong admin; tạo tenant → site → domain; export → job → download.
- **Billing-limit tests:** vượt số site → block. (nối Mục 30)

### E9. Automation — QUYẾT ĐỊNH SCOPE (v2.x đã CẮT event-bus engine có chủ đích)
> Content approval (Mục 15.4) = state-machine 1 entity — KHÁC event-bus `trigger→condition→action`.
- 🟢 **MVP GĐ1 — kéo:** chỉ `form.submit → email + webhook + notify` (trùng Forms E2, gần bắt buộc cho site bán hàng). **Hard-code 1 trigger, KHÔNG engine.**
- 🟡 **GĐ2:** engine cấu hình (`lead.created`, `post.published`) + condition evaluator + template `{{lead.email}}` + action `ai.*`. **Bắt buộc loop protection + rate limit.**
- ⚪ **Sau:** visual workflow builder, branching/loop.

---

## F. Changelog (v1.0 → hiện tại)


- **v1.0–v1.16:** khởi tạo — 16 mục lõi + 13 ADR (kiến trúc, multi-tenant, theme, AI, SEO, stack, chất lượng, lộ trình, rủi ro, i18n, standalone export); audit công nghệ (FrankenPHP/PHP8.5/Laravel13/Livewire/Go-Python/Puck); mô hình solo+AI + guardrail; CWV; UX vô hình; audit gap vận hành; demo ảo chống phình DB.
- **v1.17 (ADR-14):** mô hình dữ liệu Hybrid — entries + taxonomy + meta JSONB + bảng dịch (revise ADR-11).
- **v1.18:** audit DB — ltree, typed pivots, kỷ luật meta + quy tắc tốt nghiệp, hiển thị theo locale, routes/slug, revisions.
- **v1.19:** hiệu năng DB — index, Query Budget CI, partition/BRIN, zero-downtime migration.
- **v1.20:** kiểm kê thực thể đầy đủ + menu/contact; bổ sung reviews/coupons/payments/subscribers/notifications/analytics/settings.
- **v1.21:** yếu tố VN + An Vinh (cụm liên hệ nhanh, footer pháp lý, Bộ Công Thương, trang chính sách, OG/Zalo).
- **v1.22:** Settings + Script Manager (3 nhà; 2 chế độ; SMTP mã hóa).
- **v1.23:** khảo sát 4 site Bảo Sơn — mở rộng registry; entries.parent_id + product flags.
- **v1.24:** schema t5g + RBAC (spatie+teams, phân cấp level) + workflow động.
- **v1.25:** 3 trục Type × Category × Template (giảm type về page/post/product).
- **v1.26:** Quyền × Workflow (đăng thẳng theo quyền; admin phân theo nhóm/user).
- **v1.27:** module động + entitlement + phân cấp 3 tầng (Bảo Sơn→khách→nhân viên).
- **v1.28:** menu region + banner/zone + ảnh tập trung (Media Library); logo=brand asset.
- **v1.29:** menu hoàn chỉnh kiểu WordPress (i18n linh hoạt + builder + link mọi loại).
- **v1.30:** media đa tenant + image CDN images.bsweb.vn (ulid + imgproxy ký + seo-slug; cô lập 3 lớp).
- **v2.0:** **hợp nhất & tổ chức lại** — cấu trúc logic 5 phần + phụ lục; gập mọi tinh chỉnh vào dạng quyết định cuối; bỏ phần thăm dò trùng lặp; chuẩn bị đóng yêu cầu.
- **v2.1:** **audit nhất quán** — sửa 4 điểm phát hiện khi soi v2.0: (1) thêm `slug` vào `entry_translations` (nhất quán với products) + làm rõ quan hệ **slug ↔ routes**; (2) bỏ mâu thuẫn **bảng `pages` riêng** — trang bố cục = `entries` type `page`, Page-JSON layout lưu ở `entry_translations.body`; (3) nêu rõ **cardinality** tenant 1—N site, user toàn cục + role per site; (4) **quy ước tham chiếu** (annotation tiêu đề = nguồn v1.30, "(Mục N)" thân bài = v2.0). Không thay đổi quyết định thiết kế.
- **v2.2:** **chốt câu #2 (hạ tầng) → ADR-15.** Sau khảo sát hiện hành (Laravel Cloud Singapore + giá; Fly.io; CloudFly.vn; Cloudflare PoP Hà Nội+HCM): **Phase 0-1 = Laravel Cloud (Singapore) + Cloudflare + R2** (zero-ops + scale-to-zero rẻ ở quy mô nhỏ: dev ≈$0-10/th, +An Vinh ≈$15-30/th); giữ code **portable** để **chuyển CloudFly.vn (VPS VN tự quản + Forge) khi lên quy mô**. Đánh dấu câu #2 ĐÃ CHỐT; còn #1 (core CMS) + #3 (luật sư) xử lý song song.
- **v2.3:** **tích hợp review kỹ thuật ngoài (7 điểm + greens)** — hội đồng kiểm & chấp nhận phần lớn: 🔴 (1) **`entries.type` ∈ {page, post}**, product = bảng riêng (hết mâu thuẫn); 🔴 (3) nâng cảnh báo RLS+Octane+PgBouncer thành **GATE #1 Phase 0** với 4 lớp (SET LOCAL + assertion + reset singleton Octane + concurrency test); 🔴 (2) định tính lại **SLO TTFB-VN = mục tiêu cần BENCHMARK 3 ISP** (Anycast VN route sang SG), nếu fail → nhánh ADR-15 origin-VN; 🟡 (4) search **tenant tokens** (engine ép site_id); 🟡 (5) ADR-07 bọc **`DomainProvisioner`** (Caddy↔CF); 🟡 (6) **chống spam form** (Turnstile+honeypot+rate-limit, R20); 🟡 (7) cache **long-tail multi-tenant** + tag-invalidation + đo cache-hit-ratio; 🟢 hoãn **Reverb** khỏi GĐ1, **pin version**, R19 **test restore hằng tháng**, nâng **luồng AI onboarding** hạng nhất. Thêm R20-R22. *(Sắc thái hội đồng bổ sung: ADR-15 origin Singapore tránh được "vòng vèo CF+origin-VN"; cache long-tail giảm bằng invalidate-on-publish.)*

---

*Hết v2.3 — Thiết kế đã chốt + đã qua review kỹ thuật độc lập (gập 7 điểm + greens vào dạng quyết định/gate). Hạ tầng chốt (ADR-15). **Gate #1 Phase 0** (cô lập tenant dưới Octane) là việc phải chứng minh đầu tiên khi code. Câu hỏi còn lại #1 + #3 xử lý song song. **Sẵn sàng khởi công An Vinh** — bước tiếp: CLAUDE.md + skeleton + issues Phase 0. Bản lịch sử: `ke-hoach-tong-the-saas-website-platform.md` (v1.30).*

- **v2.4:** **tích hợp review đối chiếu docs cũ + platform-core (Laravel cùng stack).** Hội đồng kiểm: không gì lật đổ thiết kế — tất cả là **bổ sung mảng v2.x cố tình nén/chưa chạm** (business + ops + auth-plumbing). **Inline:** Mục 29 chốt **Argon2id + global pepper + rotation** (⚠ không pepper theo email) + **2FA TOTP** + **Audit service** + **RBAC tooling** (port platform-core), **giữ `role.level`** v2.x (tốt hơn platform-core); Mục 27 thêm **Quota enforcement** (soft/hard + grace 48h); Q#5 **đề xuất khung giá VND** (Free/Pro 499k/Business 1.99tr/Enterprise 4.99tr+ — chờ xác nhận). **Phụ lục E mới** (E1 business/personas/OKR · E2 Forms spec đầy đủ · E3 port platform-core + đính chính bcrypt→Argon2id, ~62 permission, không role.level · E4 AI anti-abuse + publish-gate · E5 cache tag tables · E6 monitoring thresholds · E7 theme section schema · E8 testing flows · E9 automation scope: MVP `form.submit→email/webhook` GĐ1, engine GĐ2). Thêm **R23** (AI mass-gen → Google phạt).

---

*Hết v2.4 — Đã gập 2 review độc lập (kỹ thuật + đối chiếu docs cũ/platform-core) vào thiết kế. Thiết kế chín, hạ tầng chốt (ADR-15), 4 subsystem auth/audit có code Laravel tái dùng (platform-core), business assets + Forms/Quota/AI-anti-abuse đã spec (Phụ lục E). **Gate #1 Phase 0** là việc phải chứng minh đầu tiên khi code. Còn chờ anh: xác nhận khung giá (Q#5) + scope automation (E9) — không chặn. **Sẵn sàng khởi công An Vinh.** Bản lịch sử: `ke-hoach-tong-the-saas-website-platform.md` (v1.30).*

- **v2.5:** **audit database chuyên sâu trước khi đóng băng schema** (hội đồng kiểm như DBA cao cấp). Lõi thiết kế DB xác nhận TỐT (Hybrid/typed-pivot/ltree/RLS/graduation) — không lật đổ; lấp các **đặc tả nền tảng** còn thiếu: **D1** định nghĩa 3 bảng tenancy lõi (`tenants`/`sites`/`domains`); **D2/ADR-16** chốt **ID strategy** (bigint PK + ulid public, không lộ id thô); **D3** giải mâu thuẫn polymorphic (typed cho content-graph + `routes`, polymorphic+cleanup cho đính-kèm-mềm); **D4** quy tắc FK cascade + soft-delete thống nhất (`deleted_at` + RLS lọc); **D5** kiểu tiền integer VND; **D6** danh mục bảng miễn RLS (global); **D7** đổi reserved word `"order"`→`sort_order`; **D8** position per-category lên pivot; **D9** quy ước timestamps; **D10** partition = tối ưu khi-đo (không vào schema khởi đầu). Gom thành **"Quy ước nền tảng schema"** (Mục 17, normative). Tổ chức tài liệu: **giữ nguyên** — đã mạch lạc, không cần sắp lại.
- **v2.6:** **bổ sung an toàn upload + HTTP headers/CSP (review bảo mật).** Verdict hội đồng: vector "upload `.php`→RCE" **đã bị kiến trúc R2 chặn** (file ngoài web-root, R2 không chạy PHP — báo cáo hơi quá ở mức "server takeover"), NHƯNG các lớp hardening vẫn đáng làm và **SVG XSS là rủi ro thật còn sót**: thêm whitelist MIME + blocklist đuôi nguy hiểm + **sniff magic-bytes** (không tin đuôi/header) + **sanitize SVG** (Mục 12); thêm **security headers** (HSTS/nosniff/Referrer/Permissions/frame-ancestors) + **CSP động per-site** với sắc thái Script Manager + iframe builder (không "9 directive cứng" được vì nền tảng cho tenant nhúng script) (Mục 29). Thêm **R24**.

---

*Hết v2.6 — Thiết kế ĐÃ ĐÓNG BĂNG sẵn sàng xây dựng: kiến trúc + hạ tầng (ADR-15) + ID/schema conventions (ADR-16) + an toàn upload/headers/CSP chốt, 16 ADR, R1–R24, lược đồ DB đầy đủ + quy ước nền tảng normative, đã qua 3 vòng phản biện + audit DB + review bảo mật. Hai việc chờ anh (Q#5 giá, E9 automation) không chặn. **ĐÓNG YÊU CẦU — khởi công An Vinh** (CLAUDE.md + skeleton + issues Phase 0, issue #1 = Gate #1). Bản lịch sử: `ke-hoach-tong-the-saas-website-platform.md` (v1.30).*

- **v2.7:** **audit DB lượt 2 (góc nhìn data-type/locale/vận hành/concurrency).** Lõi & cấu trúc đã vững (lượt 1/v2.5) — lượt này tìm nhóm vấn đề thật ở tầng kiểu-dữ-liệu, trực tiếp phục vụ "nhanh+chuẩn+dễ mở rộng": **D11** `timestamptz` lưu UTC; **D12** collation tiếng Việt (`citext`/ICU) cho sort/unique; **D13** enum = `varchar`+`CHECK` (không native ENUM → dễ mở rộng); **D14** PgBouncer transaction-mode + prepared-statements (kiểm ở Gate #1); **D15** chống oversell (`CHECK stock≥0` + decrement nguyên tử) + unique completeness (sku/slug/redemption). Sửa TOC (bổ sung Phụ lục E đang thiếu; thứ tự nhãn A,B,C,E,F). **Hội đồng tuyên bố: đây là ĐÁY của audit thiết kế — lượt sau sẽ là nitpick/over-engineer, không thêm giá trị. Rủi ro thật từ đây CHỈ kiểm chứng được bằng code (Gate #1, 30KB JS, AI JSON, TTFB-VN).**

---

*Hết v2.7 — Thiết kế ĐÓNG BĂNG HOÀN TOÀN. 16 ADR, R1–R24, lược đồ DB đầy đủ + quy ước nền tảng normative (D1–D15), qua 3 vòng phản biện + 2 audit DB + review bảo mật. Mọi rủi ro thiết kế đã được xử lý; rủi ro còn lại thuộc về khâu DỰNG. **ĐÓNG YÊU CẦU — khởi công An Vinh ngay** (CLAUDE.md + skeleton + Phase 0, issue #1 = Gate #1). Bản lịch sử: `ke-hoach-tong-the-saas-website-platform.md` (v1.30).*

- **v2.8:** **chốt cấu trúc thư mục (Mục 22.1, normative) sau 3 vòng audit hội đồng cấp cao.** Diễn giải ADR-12 "package-first" = **ranh-giới-module-first** (Modular Monolith `app/Modules/<Context>/`), **KHÔNG** tách Composer package ngày-1 — đóng-gói hoãn tới **Phase 3** (exporter). Ranh giới ép bằng **arch test** (hướng phụ thuộc acyclic; `Tenancy` là nền, phụ thuộc 0 module) + **interface contract CHỈ ở điểm swap/external** (không trừu-tượng-sớm). Định khung lại **eject ≈ 80% = data ETL per-tenant + driver-swap + hạ RLS→N=1**, ~20% di chuyển code. Skeleton: `app/Modules/` + `Shared/Contracts/` + `tests/Arch/ModuleBoundariesTest.php` (3 assertion thật, xanh vacuous, cắn khi class vi phạm). **Vẫn DỪNG trước Gate #1.**

---

*Hết v2.8 — Bổ sung tầng "cấu trúc code" (Mục 22.1) lên trên thiết kế dữ liệu đã đóng băng. Không lật bất kỳ quyết định v2.7 nào. Modular Monolith + arch-enforced boundaries; đóng-gói/eject thuộc Phase 3. Sẵn sàng cho prompt Gate #1.*

- **v2.9:** **chốt scope khi dựng An Vinh thật — KHÔNG mở lại thiết kế** (chốt phạm vi + sửa lệch locale phát hiện khi dựng An Vinh thật; không lật quyết định nào của v2.7/v2.8). (1) **Menu = model-B bật từ Phase 1** (Mục 13): An Vinh là site tham chiếu — admin tạo nhiều menu kiểu WordPress, gán per (region × locale) qua `menu_assignments`; **resolver theo assignment** (KHÔNG theo `handle`), thiếu (region, locale) → **fallback menu default-locale** (không nav rỗng). Bật sớm vì là yêu cầu thật của site tham chiếu, không đầu cơ. (2) **Locale theo site** — sống ở `site_locales`, i18n lõi giữ **locale-agnostic** (không pin bộ locale cứng vào phase): **An Vinh = vi/en/ja, default vi** (Phase 1 chỉ vi; en/ja giai đoạn sau). (3) **Ràng buộc toàn vẹn `menu_assignments`:** `UNIQUE(site_id, region_key, locale)` + `menu_id` FK→`menus` ON DELETE CASCADE + `menu_id` phải cùng `site_id` với assignment. Cập nhật ADR-11 (Phụ lục A) + Mục 32 Phase 1.

---

*Hết v2.9 — Chốt phạm vi khi khởi dựng An Vinh: menu model-B từ Phase 1 + locale theo site (An Vinh vi/en/ja, default vi). Không lật bất kỳ quyết định thiết kế nào của v2.7/v2.8.*

- **v2.10:** **tinh chỉnh ADR-11 — URL i18n + default-locale mutable. KHÔNG mở lại thiết kế lõi** (lõi i18n bảng-dịch + `routes` typed + RLS giữ nguyên; đây là tinh chỉnh chiến lược URL phát hiện khi nối storefront đa-locale). (1) **Prefix CÓ ĐIỀU KIỆN:** locale default-hiện-tại của site KHÔNG chèn langcode (`/gioi-thieu`), non-default prefix `/{locale}`. "Default" = per-site (`site_locales.is_default`), **MUTABLE** — không hardcode `vi`, không đông cứng default vào URL. (2) **Canonical-guard đọc `is_default` động** (`/{default}/path → 301 /path`, `/{default} → 301 /`) → đổi default tự sinh 301 đúng, **KHÔNG cần migration URL**. **PathBuilder duy nhất** cho mọi URL (menu/breadcrumb/sitemap/hreflang/canonical/301) + arch-test cấm ghép prefix tay; `routes.path` vẫn lưu path không-prefix; prefix locale lạ/chưa enable → **404**; **hreflang** default-unprefixed + non-default-prefixed + `x-default→default`. (3) **Default mutable** (quyền `locales.manage`): đúng 1 default/site (partial unique), default mới phải `enabled`, cấm tắt/xóa default hiện tại khi chưa có default thay thế; **đổi default = high-impact** (mass 301 + recrawl → regenerate sitemap + bump `lastmod` + tag-bust cache, cảnh báo admin). Cập nhật §10 + ADR-11 (Phụ lục A) + registry quyền (RBAC).

---

*Hết v2.10 — Tinh chỉnh chiến lược URL i18n (default unprefixed/mutable, non-default prefixed) + vòng đời default-locale. Lõi thiết kế (bảng-dịch, routes typed, RLS) KHÔNG đổi.*

- **v2.11:** **hardening auth/mail/hạ-tầng khi vận hành An Vinh prod — KHÔNG mở lại thiết kế lõi** (bổ sung tầng vận-hành/bảo-mật phát hiện khi chạy thật; không lật quyết định v2.7–v2.10). (1) **Mail: transport ≠ identity** (Mục 27) — SMTP-riêng-hay-chung chỉ là *transport*; *identity* tách riêng: From-name = thương hiệu tenant, **Reply-To = "Email nhận phản hồi" per-site** (khách nhận trả lời), **From luôn khớp domain của transport gửi** (shared-SMTP → baoson.net; KHÔNG dùng domain khách) cho deliverability; SMTP tenant thành **tùy chọn all-or-nothing** (bỏ trống → dùng transport chung + identity riêng). Chi tiết `docs/notes/tenant-email-sender-identity.md`. (2) **IP khách thật sau Cloudflare** (mở rộng topology CF của ADR-07) — host qua CF-proxy chạm app bằng IP edge-CF; **edge Caddy giải IP thật từ `CF-Connecting-IP`** (tin CHỈ từ dải CF publish) rồi set `X-Forwarded-For` → `request()->ip()` đúng, **app KHÔNG đổi**; host DNS-only (gray, vd av1) dùng peer trực tiếp. **Bất biến: KHÔNG re-add app-level CF trust ở `bootstrap/app.php`** (đã cân nhắc & loại — rủi ro gãy signed-URL trustProxies). (3) **Auth hardening — bất biến bảo mật** — **mọi thử-thách 2FA phải rate-limit**: email-OTP **và TOTP đều khóa 5-lần** (vá parity — trước đó TOTP không cap → brute-force mã 6-số không chặn); cap gửi OTP 6/giờ-tài-khoản + 20/giờ-IP + presign 300/phút; **Audit service (Mục 29) ghi sự kiện auth** (`auth.login.failed/throttled` · `auth.otp.locked/throttled` · `auth.2fa.locked` · `auth.login.succeeded`, kèm IP thật). Đã deploy prod + §6 xanh (Pest/Larastan/Pint).

---

*Hết v2.11 — Tầng hardening vận-hành (auth rate-limit + parity cap 2FA + mail sender-identity + IP-thật-sau-CF + audit auth) chốt khi chạy An Vinh prod. Lõi thiết kế (schema/RLS/ADR/i18n) KHÔNG đổi; chi tiết impl ở `docs/notes/` + code, đây là bản ghi normative.*

---

- **v2.12:** **chuẩn hóa nền dữ liệu multi-tenant khi scale (ADR-18 mới) — greenfield, KHÔNG lật lõi.** Sau GATE-0 khảo sát thực địa (bigint+ulid giữ nguyên · RLS FORCE + `site_id` tường minh · read-path đã trong explicit txn · CHƯA partition · CI thực = Pint/Larastan/Pest, KHÔNG chạy Infection/Rector/Lighthouse/axe). Council 4 Trục: **(B) partition = measure-first CÓ TRIGGER** (bảng ≥30M rows HOẶC p99 breach; ứng viên HASH-`site_id`: entries/products/media/mediables/orders · RANGE-time+pg_partman: form_submissions/audit_logs; KHÔNG partition config/RBAC/lookup/tenants/domains; whale=tách-DB ADR-03). **(A) giữ `bigint identity + ulid` single-PK now (ADR-16)**; retrofit→composite `(site_id,id)` khi chạm trigger = 1-lần-có-kế-hoạch (playbook, cửa-sổ-bảo-trì) — chi phí ghi RÕ, không giấu. **(C) read/write split = design-intent**, không enforce tới khi có replica. **(D) tenant-ctx = codify bất-biến** (explicit txn + `site_id=?` tường minh, đã có) + prod `assert=sample` + `READ ONLY` cho GET + cache domain→site_id + negative-cache. Loại: schema/db-per-tenant · UUIDv4/v7 · partition-now · composite-PK-now. Chi tiết why/trade-off/số: `docs/notes/data-platform-standards.md`; index Phụ lục A cập nhật 16/17→**18 ADR**.

- **v2.13:** **URL permalink flat-default + Products = catalog-canonical khi lên An Vinh thật — mở rộng ADR-11, KHÔNG lật lõi.** (i18n bảng-dịch + `routes` typed + RLS + PathBuilder-duy-nhất giữ nguyên; đây là chiến lược permalink + commerce-canonical + timezone phát hiện khi nối An Vinh storefront thật). **(1) Permalink flat-default per-SECTION:** mặc định mọi section = flat `/{slug}`; prefix/nesting/category = **OPT-IN** (5 preset `flat|nested|base|category|category_path`, ma trận hợp-lệ per-type); config **per-site** `site_settings[permalinks]`, admin đổi được; `PermalinkConfig` + `SectionResolver` (post→section qua primary-taxonomy→`ContentSection`) + PathBuilder compose theo config. **Guard THẬT = `routes UNIQUE(site_id,locale,path)`** — KHÔNG phải slug-taxonomy-unique (slug per-type được phép trùng; đính chính claim cũ). An Vinh **pin `category`** giữ `/{cat}/{slug}` (zero-flip: seed-pins TRƯỚC route-sync). **(2) Products = bảng `products` CANONICAL** (bỏ kiểu `entries type=post`): An Vinh 6 product-post **migrate→Product** giữ `/san-pham/{slug}` **BẤT BIẾN (0 301)** — atomic per-post (create Product→gỡ post-route→sync product-route) + idempotent + reversible (post→Trash); storefront = product detail + `/san-pham` product-index + home `product_grid`. Cms↔Catalog acyclic qua **PRIMITIVES** (`?Taxonomy+slug+productId`, Cms KHÔNG import Product). **(3) Timezone per-site** (lưu UTC D11, hiển thị tz site qua funnel `SiteTime`, admin-override). **Đã deploy An Vinh prod + §6 xanh + data-fidelity verified** (6 product đủ name/desc/img/cat). Spec `plans/260706-1116-url-permalinks-standardization/` + `plans/260710-1207-anvinh-product-migration/`.

---

*Hết v2.13 — URL permalink flat-default (opt-in prefix, per-site, guard = routes-UNIQUE) + Products catalog-canonical (An Vinh 6-post migrate giữ `/san-pham/{slug}`, 0 301) + timezone per-site (SiteTime). Mở rộng ADR-11; lõi i18n/RLS/PathBuilder KHÔNG đổi. Deployed An Vinh prod + data-verified.*

- **v2.14 (doc-sync as-built + 4 ADR đề xuất — audit hội đồng 11/07; KHÔNG lật lõi):** đối chiếu code→doc (verify first-hand migrations 2000/2006/2012). **Sửa doc lệch as-built:** (a) §8 status enum `entries` = draft|pending|published|**scheduled|private**|archived (worker `entries:publish-due` mỗi phút — đã chạy); (b) §11 sanitizer catalog: `description`=**full** (img library + iframe youtube/vimeo + table/heading + CSS allowlist rộng) vs `summary`=**basic** (KHÔNG img/iframe) — đính chính claim "KHÔNG img/iframe" cũ. **Ghi 4 ADR ĐỀ XUẤT (Draft, chưa build, Phụ lục A):** 19 section-kind `html` (sanitize MỌI write-path gồm AI-gen); 20 reusable blocks = bảng typed `content_blocks` riêng (KHÔNG `entries.type='block'`), build hoãn; 21 tracking-seam (CSP nonce động + cookieless page_views); 22 3rd-party+attribution(gclid/fbclid/utm)+offline-conv (pha thương mại). **Quyết định kèm:** GIỮ `entry_revisions` typed (loại polymorphic `content_revisions`); **Puck** = roadmap chưa build (editor hiện tại Livewire MVP); index `(site_id,type,status,published_at)` reversible → measure-first (chưa thêm; `products` bỏ `type`). **Đính chính "còn thiếu":** redirects+auto-301 (`RouteSync`) + scheduled-publish = ĐÃ build; `media.alt`=jsonb per-locale đã có; `cover_alt` không tồn tại (dùng `media.alt[locale]`).

*Hết v2.14 — doc khớp as-built (status enum + sanitizer profile) + 4 ADR đề xuất Draft (page-builder html-block · reusable `content_blocks` · tracking 2-tier) chờ chốt-build; revisions giữ typed. Lõi thiết kế KHÔNG đổi; chưa migrate gì.*

- **v2.15:** **mở rộng ADR-04 — style-props token hoá + preset registry platform-curated (2 council hội tụ 12/07: audit ADR-04 style-props + audit catalog builder v3; contract-first, build theo wave).** **18 prop trình bày reserved** (Đợt 1a build 12: bg/bg_image/overlay/spacing×2/width/align/corner/shadow/hide_on/anchor_id/motion · Đợt 2: overlay_tone/divider×2/border/motion_stagger/extra_style) = fragment §E7 chung merge tại `ComponentRegistry` (leaf `enum`+`list(enum)` mới trong ValidPageJson — đường validate thật, KHÔNG Rule::in trong body), bảng tra token→class **fail-closed** (cấm nội suy chuỗi đã lưu), enum **append-only**, sparse storage. **Preset per-section** (3–6/loại, vet AA) = CODE registry `SectionPresetRegistry` platform-only (lệ §15.2, KHÔNG bảng — preset phải deploy atomic cùng theme CSS), **expand-on-apply**; AI-gen (ADR-08) sinh preset key → server expand. **Ràng buộc CWV/a11y enforce:** bg-image=`<img>` · eager-theo-vị-trí-block · motion transform-only + strip-block-đầu + reduced-motion (IO `sections.js`, fail-safe tĩnh) · hide_on = trình-bày-không-phải-visibility · overlay AA qua preset. **Tiền đề Đợt 1a:** token hóa theme CSS 2 PR (PR1 visual-no-op gỡ hardcode An Vinh khỏi storefront.css — trả nợ white-label; PR2 style-props plumbing). **Biên CẤM:** KHÔNG color-picker/typography/px-tự-do/breakpoint/position per-section, KHÔNG raw class/CSS (mọi tầng tenant). Spec đầy đủ + tầng token + danh sách extra_style: `docs/notes/section-style-system.md`. *Loại:* Elementor raw-CSS per element · bảng `section_presets` · lưu preset-name trong body · style=object sibling ngoài props (mất batch-resolve media sẵn có).

*Hết v2.15 — ADR-04 "Chốt (mở rộng)": trình bày section = token/enum allow-list + preset curated, contract chốt trước wave build (1a nền → 1b section → 1c template — theo council catalog 12/07). KHÔNG code/migrate trong bản này.*

- **v2.16 (đính chính pin Build — doc-only):** Vite 7→8 — repo dùng `^8.0.0` từ scaffold đầu, CI xanh; CLAUDE.md §1 sửa theo thực tế. *(Mục bổ sung 24/09 — trước đó v2.16 chỉ được nhắc ở dòng Trạng thái.)*

- **v2.17 (workflow tầng-2 as-built + đính chính tên bảng — doc + 1 migration expand-only):** (a) **ĐÍNH CHÍNH:** bảng log tên thật là **`workflow_log`** (migration 2015), doc cũ ghi `content_workflow_log` ở §15.4 + §16 — sửa cả hai; tên chuẩn hóa theo tiền tố module `workflow_*` (workflows/workflow_states/workflow_transitions/workflow_log). (b) **Trail tự mô tả (migration 2017, expand-only):** `workflow_id` nullable SET NULL (soft-ref như `actor_id` — một site chạy NHIỀU workflow, mỗi thể loại một cái; xóa workflow không được xóa lịch sử) + `labels` jsonb `{transition, from, to}` chụp nguyên văn hiển thị lúc ghi, vì áp/đổi template rebuild states-transitions nên join sống sẽ in key thô ra timeline. Không thêm index (measure-first §3.1 — đọc đi qua `(site_id, entry_id, created_at)` sẵn có); bảng rỗng ở mọi môi trường lúc migrate. (c) **As-built lớp bật/tắt (§15.4):** gate `platform.workflows.manage` (không role tenant nào được cấp — chỉ operator qua `Gate::before`), template `editorial` khóa sau entitlement `workflow.editorial`, phạm vi govern = post-collection có taxonomy (page không chạm engine), đổi template ⇒ reset `workflow_state` của entry in-flight về bước đầu. *Loại:* versioning workflow theo bản (bỏ `UNIQUE(site_id, applies_to_type)` + partial-unique `WHERE is_active`) — mạnh hơn nhưng phải sửa lại InstantiateWorkflow + W1 đã ship, trong khi `labels` đã giải quyết đúng nhu cầu đọc lịch sử; nút "Nâng cấp gói" trong app — billing hoãn, nút không làm gì = giao diện dối.

*Hết v2.17 — workflow tầng-2 có mặt tiền vận hành + trail bất biến đọc được vĩnh viễn. Lõi thiết kế KHÔNG đổi; migration 2017 expand-only, chưa deploy prod.*

- **v2.18 (workflow tầng-2 as-built phía soạn thảo — doc-only, KHÔNG migration):** (a) **Đóng đường vòng đặt lịch:** `scheduled` là trạng thái duy nhất về sau KẾT THÚC ở `published` (qua `entries:publish-due`) mà không qua engine → từ chối cho bài governed, và scheduler bỏ qua bài đã đặt lịch trước khi bật (fail-closed, đếm + báo). Câu hỏi "workflow nào quản bài này" gom về `EntryWorkflowResolver` để không đường nào quên hỏi. (b) **Khối "Trạng thái duyệt" trong Editor** thay nút Đăng (design handoff màn 2, port 1:1 lên token admin): stepper N bước từ rows, action-bar = `allowedTransitions` tại đúng origin engine dùng, modal ghi chú bắt buộc + giao việc validate server, trail đọc `labels` keyset, lỗi đời thường thay 500 (tab cũ, thiếu lý do, không quyền, trùng slug). Cố ý KHÔNG port skeleton/thẻ "Thử lại" (render đồng bộ, không fetch nào fail). *Loại:* map bài public pre-workflow sang bước `is_published` khi đọc — phải đổi cả `ApplyEntryWorkflowTransition` (fromState) và luật "vào lại bước đầu" đã chốt; bài public gửi duyệt sẽ tạm ẩn là hệ quả có chủ đích của "chỉ `is_published` mới public" cho tới khi có revision.

*Hết v2.18 — tier-2 dùng được trọn vòng từ editor: gửi → duyệt/trả → đăng/gỡ, không còn đường vòng nào ra public. Lõi thiết kế KHÔNG đổi; chưa deploy prod (vẫn cần migration 2017 + `rbac:sync`).*

- **v2.19 (ĐẢO 1 điểm của v2.18 + audit trạng thái — doc + code, KHÔNG migration):** (a) **Bài public tạo trước khi bật workflow đứng ở bước `is_published`**, không phải bước đầu — v2.18 ghi "gửi duyệt ⇒ tạm ẩn" là hệ quả xấu cho site đang chạy (An Vinh: mọi bài đang lên đều có thể bị ẩn bởi một cú bấm của tác giả). Nguyên tắc chốt: *bật workflow không bao giờ làm khách mất bài đang xem*; một định nghĩa origin duy nhất (`originFor`) cho engine-consumer và editor nên nút nhìn thấy = nút engine chấp nhận. Luật "vào lại bước đầu" của v2.17 GIỮ cho bài chưa public. (b) Row-menu gỡ bài governed ⇒ reset `workflow_state` (stepper không được nói "Đã đăng" trên bài đang ẩn; lên lại = duyệt). (c) **Audit `entries.status.changed` mọi đường** — sự cố 02/08 (trang chủ bị đặt private qua link auto-login TEMP scan-access, 25 lượt dùng 12/07→03/08 từ nhiều IP kể cả bot Telegram/Google) không truy được thủ phạm vì tier-1 không để dấu; nay 1 truy vấn `audit_logs` trả lời "ai, bằng đường nào". *Loại:* chặn hẳn `draft/private/archived` cho bài governed (mất bulk-draft của admin, không tăng an toàn vì không đường nào ra public); revision/draft-of-live (đúng hướng lâu dài, nhưng là schema + ADR — council riêng khi tới lượt).

*Hết v2.19 — bật workflow trên site đang chạy là an toàn cho nội dung đang public; mọi thay đổi trạng thái đều có dấu. **Đã deploy prod 19/09** (`6c8031a`).*

- **v2.20 (Hộp duyệt — đóng Phase 1 Workflow UI; doc + code, KHÔNG migration):** bề mặt thứ hai của tier-2: người DUYỆT làm việc ở `ReviewInbox`, người VIẾT ở `Editor`. Lý do tách: editor đòi `entries.update` nên vai trò chỉ-duyệt (mục đích của template `editorial`) trước đây không có chỗ làm việc — nay `EntryPolicy::review` mở màn cho `entries.review||entries.approve` mà không cần quyền sửa (test chứng minh: approve-only đưa bài lên public từ Hộp duyệt trong khi Editor vẫn 403 với chính họ). Hàng chờ định nghĩa bằng **tập stage** (`ReviewQueue`) thay vì duyệt từng bài; **loại** stage đầu (nháp của tác giả) và stage public (không chờ ai) ⇒ danh sách là *việc phải làm*, không phải *mọi thứ có thể đụng*. Nội dung bài trong khung đọc render dạng **text** (không chạy HTML của tenant trong khung admin). *Loại:* mở editor ở chế độ chỉ-đọc cho người duyệt (phải nới `authorize('update')` — sai ranh giới, và vẫn là màn soạn thảo cho việc đọc-duyệt); badge đếm sống mỗi request (đặt truy vấn lên mọi trang admin chỉ vì một con số).

*Hết v2.20 — Phase 1 Workflow UI đủ 3 màn: bật/tắt (operator) · trạng thái duyệt (người viết) · hộp duyệt (người duyệt). **Đã deploy prod 23/09** (`ca62f01`).*

- **v2.21 (23/09 — token as-built + CWV CI + vá màn Thành viên; doc + code, KHÔNG migration):** (a) **Token 2 tầng** (§19, spec `section-style-system.md` §1): `storefront.css` hết hex brand hardcode — 15 màu (101 lần) → `var()`/`color-mix()` từ seed per-site; `ThemeResolver::DEFAULTS` thêm seed `ink`/`body`; An Vinh giữ nguyên diện mạo nhờ seed `{primary,ink,body}` trên prod; default `primary` `#0a66c2→#3b82f6` vì default phải tự đạt AA khi `ink` nằm trên nó (axe CI bắt `.cta__button` 3.32:1). Seed là contract LƯU ⇒ giữ tên as-built; tên SSOT `--color-*`/`--brand-*` là tầng role SINH từ seed. (b) **CI job `cwv`** (Lighthouse LCP ≤1.5s / JS ≤30KB + axe WCAG2AA) chạy mỗi push, **informational** tới khi hiệu chỉnh ngưỡng bằng số đo thật; gate thủ công §6 tầng 2 giữ nguyên, số đọc từ CI. (c) **Bảo mật — màn Thành viên:** `users` là bảng global (D6, không RLS) nên đổi vai trò / gỡ thành viên phải tìm người **trong thành viên của site hiện tại, theo ulid**. Trước đây nhận id bigint từ client ⇒ owner site bất kỳ kéo được tài khoản bất kỳ vào site mình rồi đọc tên + email (§2 #3/#4); `RoleHierarchyGuard` chỉ kiểm role, không kiểm người nhận. Luật cho RBAC Console: mọi đường ghi vào bảng RBAC global phải resolve đối tượng trong phạm vi site TRƯỚC khi gọi guard. *Loại:* đổi seed sang tên SSOT ngay (migrate data prod + ~50 chỗ CSS, trong khi tầng role đạt cùng mục tiêu không đụng data); cho `cwv` chặn merge ngay (ngưỡng chưa có số thật ⇒ đỏ giả).

*Hết v2.21 — màu storefront cấu hình được per-site (white-label), CWV/a11y đo tự động mỗi push, đường gán vai trò không còn với ra ngoài site. Lõi thiết kế KHÔNG đổi.*

- **v2.22 (23/09 — nhóm quyền tùy chỉnh + bất biến pivot taxonomy; doc + code, KHÔNG migration):** (a) **Nhóm quyền tùy chỉnh** (§15.3, mô hình 2 cấp owner chốt 06/07): `Create/Update/DeleteCustomRole` chung 1 bộ luật `CustomRoleRules` — R1 trần theo module site được entitle (`RoleCeiling`; thêm module ẩn `taxonomies`/`domains` vì 2 quyền đó chưa module nào sở hữu) · R2 trần theo người sửa · R3 cấp · R4 nhóm hệ thống khóa + xóa khi hết người · R5 `IMPLIES` · R6 audit diff; bật bằng feature `rbac.custom_roles` (mặc định tắt); arch chokepoint cho mọi thay đổi quyền của role. UI chờ design (D2-1). (b) **Pivot taxonomy** (§9 as-built): `position 0` = chuyên mục chính cho MỌI chỗ đọc (`primaryCategory()` thay `taxonomies()->first()` — trước đây lấy hàng đầu của BẤT KỲ vocabulary: bài chỉ có sector bị nạp sector vào ô chuyên mục và lưu thì báo lỗi); term phụ ≥1000 và `assignCategories()` giữ nguyên chúng (trước đây re-file bài xóa mất sector). ⚠ Nghĩa `position` as-built lệch D8 — chờ owner re-chốt trước khi xây sắp-xếp-trong-chuyên-mục. *Loại:* phân biệt chuyên mục/term phụ theo danh sách vocabulary (vẫn cần luật vị trí vì 4 query SQL đọc `position = 0`, lại thêm danh sách phải bảo trì); thêm cột `is_primary` ngay (migration + đổi 4 query, trong khi chưa có facet writer nào — để cho quyết định D8).

*Hết v2.22 — phân quyền động có backend đủ bất biến; một định nghĩa duy nhất cho "chuyên mục chính". Lõi thiết kế KHÔNG đổi; chưa deploy (đi cùng UI).*

- **v2.23 (24/09 — 14 quyết định cho kế hoạch thiết kế-trước SaaS Starter; owner chốt theo khuyến nghị council; doc + 2 sửa code nhỏ, CHƯA migration):** (Q1) NAP = `site_profiles` (+ bản dịch) như §14 đã chốt — storefront đang đọc `general.brand` là LỆCH, sửa về §14; cột §14 còn thiếu (giờ làm việc, khẩu hiệu, tên pháp lý theo ngôn ngữ, mạng xã hội, Bộ Công Thương) + danh mục đơn vị hành chính 2 cấp = spec COUNCIL riêng · (Q2) `site_locales.is_public` (mặc định true, expand-only) tách "đang soạn" khỏi "hiển thị cho khách" + feature `locales.max` · (Q3) `menu_items.link_type` += `product`; alias morph do Catalog đăng ký (giữ Cms→Catalog = 0, không vòng phụ thuộc); URL qua `routes.product_id` · (Q4) bảng `invitations` theo site (RLS) + màn hình trả lời đồng nhất — vá việc quản trị website dò được email nào đã có tài khoản · (Q5) bảng thông báo riêng theo D2/D11/D13 + `site_id`/RLS, KHÔNG dùng bảng uuid mặc định của Laravel · (Q6) ngôn ngữ/cỡ chữ/múi giờ theo người = `users.preferences` (không thêm cột — không bao giờ lọc trong SQL) · (Q7) "Tài khoản của tôi" = trang riêng từ menu người dùng, cho cả vận hành viên nền tảng · (Q8) một component Shell cho 2 mặt; KHÔNG chuyển mặt trong cùng phiên — vào website khách chỉ qua đăng nhập hỗ trợ (§LR B6) · (Q9) màn mới của khu đăng nhập dựng theo khung login hiện có · (Q10) màn đăng nhập hiện flash `status` sau đặt lại mật khẩu / xác minh email · (Q11) palette An Vinh v2 chưa áp (vàng trên trắng 2,28:1 — chờ bộ sinh bảng màu) · (Q12) bộ AI = module sau core v1 (kéo theo billing) · (Q13) nơi bền là `docs/` (`roadmap.md`, `saas-starter-screen-map.md`, `design-briefs/`, `archive/`); `plans/` = nháp cục bộ · (Q14) luật arch "module tính năng không đọc `Tenancy\Models`" từng vô hiệu (mảng ở vế `expect()` của Pest chỉ đỏ khi MỌI phần tử vi phạm) → 1 luật/module + danh sách nợ 8 class chỉ được giảm; bề mặt công khai mới `Tenancy\Context\SiteDirectory`. Đi kèm: trang 404 dùng chung hiện đúng tên website theo tên miền (white-label). Kế hoạch: `docs/design-briefs/saas-starter-design-first/`.

*Hết v2.23 — kế hoạch thiết kế-trước có đủ quyết định dữ liệu; schema chỉ đổi qua spec COUNCIL từng migration. Lõi thiết kế KHÔNG đổi.*
