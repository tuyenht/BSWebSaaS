# An Vinh — IA & Build Spec (Stream 4)

> **STEP 1 freeze.** IA + content model are CHỐT (frozen). Build approach (§4–§5) **CHỐT 24/06**
> (COUNCIL F1–F5; **F3=C**, **F5 DROP**). Tenant: `av1.baoson.net` (prod site #1). Date: 2026-06-24.
> Evidence: `plans/reports/anvinh-ia-capture-260624-1402-demo-and-wp-report.md` (+ `…-shots/`)
> and the GATE-0 content-engine survey.

## 1. Content model — CHỐT 24/06

- **av1 = the REAL An Vinh business**: industrial **thermal insulation / cách nhiệt–bảo ôn**, giàn
  giáo, nhân lực O&M nhà máy, cho thuê xe nâng. B2B brochure. **No commerce, no customer-auth** (deferred).
- **Demo** (`…run.app`, precision-mechanical) = **layout/skin target ONLY**. Its products are sample
  data and are **NOT** used.
- **WordPress** (`anvinh.baoson.net`) = **real content source** (services, projects).
- **No product catalog** (`san-pham` dropped — the real business has none). Can be added later as
  post-by-category if real product content appears.
- **i18n = vi only** for Phase 1 (en/ja deferred → Stream 5), per [[anvinh-i18n-menu-model-b]].

## 2. IA (frozen — layout from demo, content from WP)

**Nav (ordered):** Trang chủ · Giới thiệu · Dịch vụ · Dự án · Đối tác · Tin tức · (Tuyển dụng, later) · Liên hệ.
(Thư viện ảnh = a gallery *page*, later — not a homepage block.)

**Homepage block stack (top → bottom):**

| # | Block | Type | Notes |
|---|---|---|---|
| 1 | hero | exists (+2nd CTA) | mixed; CTAs "Yêu cầu báo giá" + "Xem dịch vụ" |
| 2 | about | NEW | mixed: text + image |
| 3 | services | NEW (repeater) | 4-col; card = icon/title/desc/link |
| 4 | projects (du-an) | NEW `entry_grid` (data-bound) | image-grid by sector; card = image/title/short-outcome |
| 5 | partners | NEW (repeater) | logo grid |
| 6 | latest_posts (news) | exists | img/date/title |
| 7 | cta band | exists | |
| 8 | contact | NEW | info block; form via Forms module later |

**Footer:** brand · services list · quick links · contact NAP · social.

**Card fields (frozen):** service = `icon,title,desc,link` · project = `image,title,short_outcome,sector` · news = `image,date,title` (latest_posts handles).

**Dropped vs brief:** `stats` counter band (no evidence on either site — demo only shows factory-area cards). `gallery` = a page route, not a homepage block.

## 3. Collections / taxonomy

- **du-an** (project = `entries.type=post`; taxonomy `du-an` with sector children: nhiệt điện, lọc
  hóa dầu, xi măng, phân bón, thép). Storefront list `/du-an` + detail `/du-an/{slug}`.
- **tin-tuc** (exists).
- **No san-pham.**

## 4. Block DELTA — CHỐT 24/06 (COUNCIL F1 ✅ · F2 ✅ §E7 · F4 ✅)

Current 5: `hero, cta, richtext, features, latest_posts`.

**Add:**
- `services` — array prop `items[]: {icon,title,text,url}` (SectionComponent + partial).
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

## 6. Seed content (real, from WP)

- **Services (5):** bảo ôn/cách nhiệt vật tư · cách nhiệt + giàn giáo · nhân lực O&M nhà máy ·
  thiết bị/vật tư thay thế · cho thuê xe nâng.
- **Projects (9):** Formosa Hà Tĩnh · Mông Dương 1 · Nghi Sơn (lọc hóa dầu) · Xuân Thành (xi măng) ·
  Sông Hậu 1 · Brunei (phân bón) · Vũng Áng II · Vũng Áng II O&M · Brunei Điện-Đạm. Sectors → taxonomy.
- Fix `/lien-he` dead link → real contact route/page.
- **NEEDED FROM USER** (non-blocking for build; blocking for *final* seed): canonical brand name
  (An Vinh vs "Sơn Hà" title mismatch), canonical NAP (WP shows Hải Phòng + Hà Tĩnh; demo shows Hà Nội),
  logo asset, real images. During build T2–T5: temp WP image URLs → replaced by pre-optimized R2
  images (§5 Option C) before the T8 CWV gate.

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
