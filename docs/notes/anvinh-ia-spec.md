# An Vinh — IA & Build Spec (Stream 4)

> Reconciled to precision-mechanical 2026-06-28 (precision pivot).

> **STEP 1 freeze.** IA + content model are CHỐT (frozen). Build approach (§4–§5) **CHỐT 24/06**
> (COUNCIL F1–F5; **F3=C**, **F5 DROP**). Tenant: `av1.baoson.net` (prod site #1). Date: 2026-06-24.
> Evidence: `plans/reports/anvinh-ia-capture-260624-1402-demo-and-wp-report.md` (+ `…-shots/`)
> and the GATE-0 content-engine survey.

## 1. Content model — CHỐT 24/06

- **av1 = the REAL An Vinh business**: **gia công cơ khí chính xác** — thiết kế/chế tạo **đồ gá (jig),
  khuôn mẫu, chi tiết máy**. B2B brochure. **No commerce, no customer-auth** (deferred).
  *(CHỐT 27/06 "precision pivot" — thay khung "cách nhiệt/bảo ôn" 24/06; xác nhận bởi Tuyền 28/06.)*
- **Demo** (`…run.app`, precision-mechanical) = **layout/skin + content model** (sản phẩm/dịch vụ/dự án cơ khí chính xác).
- **WordPress** (`anvinh.baoson.net`) = legacy (cách nhiệt) — **không còn là nguồn nội dung** sau precision pivot.
- **Products ARE shipped** (the "add later as post-by-category" escape hatch was exercised): implemented
  as **post-by-category** (taxonomy `san-pham`, vocabulary `post_cat`) — **NOT** the commerce `product`
  module. `SeedAnVinhContent` ships 6 product posts (`entries.type=post`; precision-mechanical — jig /
  đồ gá / khuôn mẫu / chi tiết máy) under `san-pham` + 3 sub-cats (`do-ga`, `khuon-mau`, `chi-tiet-may`,
  vocabulary `product_cat`), a `ContentSection` (key `products`), storefront `/san-pham` (+ detail
  `/san-pham/{slug}`), and a homepage "Sản phẩm tiêu biểu" `entry_grid` (limit 6).
- **i18n = vi only** for Phase 1 (en/ja deferred → Stream 5), per [[anvinh-i18n-menu-model-b]].

> **CHỐT 28/06 (precision pivot):** nội dung shipped (cơ khí chính xác — jig/đồ gá/khuôn mẫu/chi tiết máy) LÀ hướng chính thức của av1; seeder ĐÚNG. Khung "cách nhiệt/bảo ôn" 24/06 đã bị thay — KHÔNG reconcile seeder về cách nhiệt.

## 2. IA (frozen — layout from demo, content from WP)

**Nav (ordered, as shipped):** Trang chủ · Giới thiệu · Dịch vụ · **Sản phẩm** · Dự án · Đối tác · Liên hệ · Tin tức. (Tuyển dụng = later.)
(Thư viện ảnh = a gallery *page*, later — not a homepage block.)

**Homepage block stack (top → bottom):**

| # | Block | Type | Notes |
|---|---|---|---|
| 1 | hero | exists (+2nd CTA) | mixed; CTAs "Yêu cầu báo giá" + "Xem dịch vụ" |
| 2 | about | NEW | mixed: text + image |
| 3 | services | NEW (repeater) | 4-col; card = icon/title/desc/link |
| 4 | projects (du-an) | NEW `entry_grid` (data-bound) | image-grid (entry_grid, taxonomy du-an); card = image/title/excerpt |
| 5 | partners | NEW (repeater) | logo grid |
| 6 | latest_posts (news) | exists | img/date/title |
| 7 | cta band | exists | |
| 8 | contact | NEW | info block; form via Forms module later |

**Footer:** brand · services list · quick links · contact NAP · social.

**Card fields (frozen):** service = `icon,title,desc,link` · project = `image,title,excerpt` · news = `image,date,title` (latest_posts handles).

**Dropped vs brief:** `stats` counter band (no evidence on either site — demo only shows factory-area cards). `gallery` = a page route, not a homepage block.

## 3. Collections / taxonomy

- **du-an** (project = `entries.type=post`; taxonomy `du-an`, vocabulary `post_cat`). **6 projects
  attach directly — no sector sub-categories** (sector taxonomy = DEFERRED, T2 backlog
  [[t2-readiness-audit-findings]]). Storefront list `/du-an` + detail `/du-an/{slug}`.
- **tin-tuc** (exists).
- **san-pham** (product = `entries.type=post`; taxonomy `san-pham`, vocabulary `post_cat`, with sub-cats
  `do-ga` / `khuon-mau` / `chi-tiet-may`, vocabulary `product_cat`). Storefront list `/san-pham` + detail
  `/san-pham/{slug}`. Post-by-category — **NOT** the commerce `product` module.

## 4. Block DELTA — CHỐT 24/06 (COUNCIL F1 ✅ · F2 ✅ §E7 · F4 ✅)

Current 5: `hero, cta, richtext, features, latest_posts`.

**Add:**
- `services` — **data-bound** via `entry_grid` (taxonomy `dich-vu`), NOT a static repeater: each service = a `post` (title + smart `image_url` + excerpt + detail page). Shipped T3.
- `partners` — array prop `logos[]: {image_url,name,url}` (SectionComponent + partial).
- `about` — `heading, paragraphs[], image_url, cta_*` (or compose richtext+image).
- `entry_grid` — **data-bound** (like `latest_posts`, NOT extending it — F1): props `{heading,
  taxonomy_slug, limit}`; queries published posts in a taxonomy under RLS, eager-loads `routes`
  (no N+1). Drives `du-an` (reusable).
- `contact` — scalar props `{heading, address, phone, email, map_embed}` (form = Forms module later).

**Change:** `hero` adds 2nd CTA (`cta2_label/url`) — partial already supports `cta_label/url`.

**Latent prop-bug fixes (GATE-0):** richtext editor `DEFAULT_PROPS['body']` → renders `paragraphs[]`;
`features` default missing `items[]`.

**Prerequisite (F2, per §E7 = `design.md:781`):** **Repeater editor** — the Livewire block editor
currently renders every prop as one `<input type=text>` and cannot author *any* array/object prop;
build the repeater + per-block prop schema (§E7) and extend `ValidPageJson` to **recurse into
array/object props** for URL-safety (closes the XSS gap on array item URLs). Blocks
`services/partners/about/features` depend on this.

## 5. Image strategy — Option C (F3=C; Media module DEFERRED GĐ3)

**Decision 24/06 (F3=C):** the **Media module is NOT built in Stream 4** — no imgproxy, no upload
pipeline, no rewire of image props. `design.md §374` stays **GĐ3** (**F5 DROP** — SSOT unchanged).

- **The only Stream-4 image step:** place brand/site images **pre-optimized** (AVIF + responsive
  widths + explicit `width`/`height` to prevent CLS) **directly on R2** at the §12 key
  `sites/{site_id}/media/{ulid}.{ext}`. Block image props reference these R2 URLs (temp WP image
  URLs used during build are replaced here). Asset placement only — **no module, no rewire**.
- **Constraint (a):** keying at the §12 path means a *future* Media module only **registers**
  records — no re-upload, no rewire.
- **Constraint (b) — tracked-debt exit:** the real Media module (R2 + imgproxy + upload-safety
  R24; `design.md §12` L279-302) **must be built before** any tenant self-service image upload
  **or** before An Vinh needs to self-manage content. R24 opens then.
- **Shipped state (28/06):** above-fold images currently ship as **JPEG (~190KB)** on R2; the
  AVIF/WebP + responsive-widths optimization is **pending debt** (handoff B1 / CWV) — i.e. the §7
  AVIF gate is **NOT yet met**.

## 6. Seed content (shipped — precision-mechanical, từ SeedAnVinhContent)

- **Services (4)** [taxonomy `dich-vu`, vocab post_cat]: Thiết kế Jig & Đồ gá · Gia công Phay & Tiện CNC ·
  Hàn & Lắp ráp cơ khí · Sản xuất chi tiết máy & OEM.
- **Projects (6)** [taxonomy `du-an`, vocab post_cat; attach directly, no sector sub-cats]: Dây chuyền
  lắp ráp tự động · Hệ thống Jig hàn robot · Khuôn mẫu cho sản phẩm gia dụng · Đồ gá kiểm tra thân vỏ xe ·
  Gia công chi tiết hàng không · Cải tiến dây chuyền sản xuất.
- **Products (6)** [taxonomy `san-pham` post_cat; sub-cats `do-ga`/`khuon-mau`/`chi-tiet-may` vocab
  product_cat]: Jig kiểm cụm Taplo cho xe VF7 · Máy tự động nắn sửa sản phẩm · Jig hàn Frame Comp ·
  Khuôn dập bình xăng · Khuôn đúc áp lực cao · Trục máy CNC.
- **News (6):** team building 2024 · xu hướng tự động hóa · khánh thành nhà máy số 3 (Thạch Thất) ·
  vật liệu mới khuôn mẫu · đào tạo ATLĐ · in 3D kim loại.
- Fix `/lien-he` dead link → real contact route/page.
- **NEEDED FROM USER → RESOLVED** (all seeded, canonical): brand **Cơ Khí Chính Xác An Vinh**; legal
  **CÔNG TY TNHH THƯƠNG MẠI VÀ DỊCH VỤ THIẾT KẾ GIA CÔNG CƠ KHÍ AN VINH**; tax **0108649706**;
  phone/hotline **0915 66 83 83**; email **quybv@ckanvinh.vn**; address **Thôn Xuân Sơn, Xã Trung Giã,
  TP Hà Nội**; logo + images placed on R2.

## 7. Diamond++ gates (every storefront PR)

- Real SSR Blade (render path confirmed), semantic HTML, meta title/desc + canonical + OG, JSON-LD
  (Organization/Service/Project as fit), sitemap.xml + robots, breadcrumb.
- LCP mobile p75 ≤1.5s · JS ≤30KB · paste Lighthouse + axe results before "done". Above-fold images
  = pre-optimized AVIF on R2 (§5 Option C); the CWV gate runs at **T8** after temp WP images are
  replaced (not "Media optimize").
- Tenant: `site_id` + RLS on all new tables/pivots; `entry_grid` query RLS-scoped; Query Budget (no N+1).
- Test-first (Pest). Each PR = 1 việc → CI xanh → rebase-merge → deploy prod + verify live.

## 8. Open items

- **COUNCIL — RESOLVED 24/06:** F1 ✅ new `entry_grid` · F2 ✅ repeater per §E7 + ValidPageJson
  array-recursion · **F3=C** pre-optimized R2 images, no Media build · F4 ✅ PR T1→T8 · **F5 DROP**
  Media stays GĐ3.
- **User seed inputs (§6):** brand / NAP / logo / images.
- Tuyển dụng + Thư viện ảnh pages: later (nav placeholders or hidden until built).
