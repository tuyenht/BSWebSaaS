# Plan — i18n URL chokepoint + default-locale source-of-truth

**Status:** APPROVED — baseline menu đã commit (`aa90f99`); **sẵn sàng khởi C1** (Pest đỏ trước). Điểm khởi C1 = `aa90f99`.
**Branch:** main · **Owner:** Tuyền (đạo diễn/reviewer); agent thực thi · **Created:** 2026-06-17
**Design nguồn:** `docs/design.md` §10 (ADR-11, v2.10) + `CLAUDE.md` §4. Verdict trước: cụm menu model-B = **GIỮ** (20/20 Pest xanh); cụm i18n = **GIỮ nền + BUILD lớp URL**.

## Mục tiêu
GIỮ nền i18n single-locale đang đúng, **DỰNG lớp URL i18n** theo chiến lược v2.10 (default-locale unprefixed/mutable, non-default `/{locale}`), kiểu **chokepoint-first**. Nguồn sự thật default = `site_locales.is_default` (bỏ `sites.default_locale`).

## Hướng tiếp cận
- **1 nguồn sự thật:** `site_locales.is_default` (partial-unique 1/site). `sites.default_locale` → mirror tạm, drop ở Commit 6 (gated).
- **1 builder render-side:** `PathBuilder::publicUrl(unprefixed_path, locale)` chồng prefix theo default ĐỘNG. Mọi nơi phát URL đi qua nó; arch-test cấm ghép `/{locale}` thủ công.
- **TDD:** Pest đỏ trước mỗi commit · migration expand-contract + `down()` · StorefrontRenderTest cũ GIỮ xanh · Query Budget không tăng (default memoized) · Diamond++ dán Lighthouse/axe khi báo done · RLS + BelongsToSite.

## 4 quyết định (đã chốt + rider)
1. **Fallback** khi site mất `is_default` → enabled-đầu-theo-id, else `'vi'`. **Rider:** log **warning** mỗi lần fallback chạy (báo invariant vỡ — site mất default).
2. **An Vinh locales** = `--locales` trên `site:provision` (KHÔNG seeder riêng); lời gọi dựng An Vinh truyền `vi,en,ja` (default vi).
3. **`PathBuilder::publicUrl(path, locale)`** (mở rộng `Cms\Routing\PathBuilder`, cùng class content-path). **Rider:** document input `path` là **UNPREFIXED**; `publicUrl` CHỈ chồng prefix.
4. **Memo default** ở `Context\SiteLocale`, key theo `CurrentSite`, **reset-safe Octane**. **Rider:** request admin đổi `is_default` phải **clear memo sau write**.

## Commit sequence (mỗi commit 1 việc; Pest đỏ trước)

| # | Việc | Pest (red→green) | File chạm | Gate |
|---|---|---|---|---|
| 1 | Source-of-truth = `site_locales.is_default` + partial-unique + memoize | (a) 2 hàng `is_default=true`/site → QueryException; (b) provision đúng 1 default; (c) `SiteLocale::default()` đọc `site_locales.is_default` (seed en → trả 'en'); (d) thiếu is_default → fallback enabled-đầu→'vi' + **log warning**; (e) Query Budget 1 query/request + Octane-reset không rò A→B; (f) `down()` revert | `Context/SiteLocale.php` (repoint+memo), migration **expand** (partial-unique `CREATE UNIQUE INDEX CONCURRENTLY … WHERE is_default` + down), `EntryEditorTest:209` + fixtures (`Pest.php`, `DatabaseRlsTest`) | giữ `sites.default_locale` làm mirror, **KHÔNG drop** |
| 2 | `PathBuilder.publicUrl(path, locale)` chokepoint + arch-test | (a) default→giữ nguyên (`/tin-tuc`,`/`→`/`); (b) non-default→`/en/tin-tuc`, `/`→`/en`; (c) đọc default động; (d) arch-test cấm ghép `/{locale}` ngoài PathBuilder; (e) StorefrontRenderTest cũ XANH (default behavior bất biến) | `Cms/Routing/PathBuilder.php` (+publicUrl), `StorefrontController:80,129`, `MenuResolver` (internal path), breadcrumb | **MenuResolver đã wire vào storefront ở baseline `aa90f99`** (URL còn thô) → hook output qua publicUrl; menu model-B GIỮ nguyên |
| 3 | Guard: canonical 301 + 404 locale chưa-enable | (a) `/{default}/tin-tuc`→301 `/tin-tuc`; (b) `/{default}`→301 `/`; (c) prefix locale chưa-enable→404; (d) enable nhưng route-miss→404 (no soft-fallback); (e) default thường→200 | middleware/guard storefront, `RouteResolver`/controller | đọc `is_default` tại request (memoized) |
| 4 | hreflang MỎNG + `<html lang>` động | (a) `<html lang>`=locale active (bỏ hardcode `vi` ở 3 layout); (b) chỉ vi published → self-ref `hreflang=vi` + `x-default`→default; (c) chỉ locale published | `layouts/{category,page,post}.blade.php`, `PageRenderer` | **thêm ADDITIVELY** — layout đã có `@include header` (baseline `aa90f99`), chỉ thêm `lang`/hreflang; **để mỏng** — không test đa-locale dày tới khi có route en/ja thật |
| 5 | **(THU NHỎ)** Provision An Vinh `['vi','en','ja']` | locale list + assert **ĐÃ XONG ở baseline `aa90f99`** (DTO `locales:['vi','en','ja']`; assert site_locales=`['en','ja','vi']`, is_default=`['vi']`). **Còn lại (TÙY CHỌN):** thêm `--locales` cho `site:provision` CLI — bỏ nếu chưa cần override | `ProvisionSiteCommand` (chỉ nếu làm CLI) | — |
| 6 | **DROP `sites.default_locale`** (contract phase) | migration drop + `down()` khôi phục cột (mirror lại từ site_locales) | migration mới, gỡ `@property` Site, gỡ mirror trong ProvisionSite | **PHÁ HỦY — DỪNG xin xác nhận.** Trước đó: grep `default_locale` TOÀN repo (raw SQL/view/job/export/control-plane/import-mapping — không chỉ Eloquent); giữ mirror tới khi xác nhận **zero runtime reader** ở dev |

## Acceptance (Diamond++ §10.3)
- §2 bảo mật: RLS fail-closed giữ nguyên trên site_locales; không lộ chéo tenant.
- §3 DB: partial-unique đúng; migration expand-contract có `down()`; index `CONCURRENTLY`.
- Hiệu năng: default memoized → route hot KHÔNG tăng query (Query Budget xanh).
- SEO: canonical 301 đúng dạng; hreflang chỉ locale published + x-default; SSR Blade thật.
- CI §6 + Lighthouse/axe dán khi báo done.

## 2 cảnh báo
1. **Luồng song song — ĐÃ GIẢI QUYẾT (2026-06-17):** cụm menu (cluster B) + wiring nav + locale An Vinh vi/en/ja đã commit thành **baseline `aa90f99`** (full suite **178✓/1 todo**). Xác nhận đó là việc đã xong (không phiên khác gõ tiếp). Là điểm khởi C1. BƯỚC 0 soi đúng bản này nên không lệch.
2. **Hoãn drop `sites.default_locale`:** Commit 6 phá hủy — gated, grep toàn repo trước, giữ mirror tới khi zero-reader, DỪNG xin xác nhận.

## Gate (pre-code) — GIẢI TỎA 2026-06-17
**UNBLOCKED.** Cụm menu commit thành baseline `aa90f99` (full suite **178 passed / 1 todo**, 624 assertions). Tree sạch (chỉ `plans/` untracked). **Điểm khởi C1 = `aa90f99`.** C1 bắt đầu sau khi Tuyền duyệt rebase này.

## Evidence (BƯỚC 0, file:line)
- Reader default duy nhất: `Context/SiteLocale.php:24`. Writer: `ProvisionSite.php:65`. Schema cột: `migration 0002:40`.
- URL phát: `StorefrontController.php:80,129` (ghép tay) · `MenuResolver.php:110-114,150-153,247` (route.path thô) · `post.blade.php:18` (breadcrumb) · write-side qua `RouteSync.php:30,77`+`PathBuilder`. Sitemap/robots: CHƯA có.
- Call-site An Vinh: `ProvisionSiteCommand.php:40-48` (locales bỏ trống → `['vi']`); `StorefrontRenderTest.php:29-39` helper (→`['vi']`). DTO default `ProvisionSiteData.php:24` = `['vi']`.
- site_locales hiện chỉ `unique(site_id, locale)` (`migration 0004:31`) — THIẾU partial-unique.

## Deferred notes
- **C3 — RỦI RO TƯƠNG LAI THẤP:** slug nội dung trùng langcode chưa-bật (vd category slug `ko`) sẽ đổi nghĩa khi locale đó được bật. Xử lý đúng chỗ = validation lúc tạo/sửa slug (cấm slug trùng langcode), thuộc feature quản-lý-locale (`locales.manage`) đã defer. KHÔNG xử ở guard C3.
- **Locale case-sensitivity:** prefix match hiện lowercase request path → locale dạng có script-subtag hoa (`zh-Hans`) sẽ không match. Xử đúng: chuẩn hóa locale về canonical BCP-47 (language thường + script hoa, vd `zh-Hans`); KHI SO KHỚP prefix thì case-insensitive (lowercase cả 2 vế); KHI LƯU + XUẤT (hreflang, html lang, URL) giữ dạng canonical. Thuộc feature `locales.manage` đã defer. Ngoài scope An Vinh vi/en/ja (toàn thường).
