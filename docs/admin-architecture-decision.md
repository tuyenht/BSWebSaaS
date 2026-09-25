# Admin Architecture Decision Record

> ADR riêng cho **Admin UI** (Livewire 4 + Tailwind 4 + Blade). Bổ sung cho `design.md`.
> Trạng thái: **LOCKED** = đã chốt (không lật) · **OPEN** = việc còn lại.

---

## ADR-A1 — Stack & nguồn thiết kế (LOCKED)

- Admin UI = **Tailwind 4 + Livewire 4 + Blade**, all-in. **KHÔNG Bootstrap**, KHÔNG trộn 2 CSS framework trong 1 project. Builder = React island (Inertia v2) — theo ADR-02.
- **KHÔNG Flux** (lean-deps, own-code). Primitive tự dựng bằng Tailwind + Blade component.
- **Velzon = design reference READ-ONLY**: lấy visual + token, KHÔNG port Bootstrap/jQuery/SCSS, KHÔNG nạp CSS framework thứ 2.
- Icon = inline-SVG `<x-admin::icon>`, KHÔNG nạp icon webfont.

## ADR-A2 — Token SSOT (LOCKED — đã build GĐ1–GĐ3, CI xanh)

- Typography SSOT = `resources/css/fonts.css` (`--font-stack` + `--vz-*`), **self-host Inter, zero third-party CDN**.
- Body **14px** = `--vz-body-font-size`; Tailwind `@theme` đọc từ fonts.css; `bg-body` ← `--vz-body-bg`.
- Brand admin **namespace `--color-admin-*`** (#405189…) — KHÔNG rò sang storefront `--color-primary` (ThemeResolver per-site). Shell scope `.admin-shell`.
- Layout: **Vertical + Light scheme + dark sidebar**. Theme Customizer (Velzon-style: Layout/Scheme/Position/Topbar/Sidebar) = feature **interactive** → đặc tả đầy đủ ở **ADR-A4**.

## ADR-A3 — i18n / FONT (LOCKED)

### Fact đính chính
- **An Vinh = vi/en/ja, default vi** (KHÔNG zh-Hans). *Verified (live repo, re-check đầy đủ):* fixture `provisionAnVinh()` = `['vi','en','ja']` (assert `['en','ja','vi']`); `SiteLocale` docblock = "vi + en + ja"; `design.md §10` = vi/en/ja. **Fix đã có sẵn trong live** (snapshot cũ từng là `['vi','zh-Hans']` — đã được sửa trước khi clone). Không test i18n/hreflang nào dựa zh-Hans (machinery hreflang dùng vi+en). zh-Hans còn lại đều hợp lệ: roadmap docs, generic `SiteConfigTest` machinery, comment BCP-47 ví dụ.
- `design.md` "vi+zh-Hans" = **i18n-platform** (tầng nền tảng), KHÔNG phải ngôn ngữ client. Không nhầm 2 tầng.

### Hai trục độc lập
- **Display (chrome UI)** = 4 platform langs: **en/vi/ja/zh**.
- **Content (An Vinh)** = **vi** (P1) → **+en+ja** (P2, copy-gốc-vi → dịch).
- 1 trang CÓ THỂ mix nhiều script (UI ja trong khi sửa content vi…) → thiết kế phải chịu được.

### Font = SCRIPT-DRIVEN (KHÔNG locale-gate)
- Giữ `unicode-range` trong fonts.css.
- **Tách family CJK riêng** (kéo JP/KR/SC ra khỏi family `"Inter"` → đặt tên family riêng).
- `:lang()` chọn font CJK, phủ biến thể:
  - `:lang(ja){ … "Noto Sans JP" … }`
  - `:lang(zh), :lang(zh-Hans), :lang(zh-CN){ … "Noto Sans SC" … }`
  - → khử nhập nhằng Hán (JP + SC chồng dải `U+4E00–9FFF`).
- **Phụ thuộc:** `<html lang=display>` + bọc mỗi khối content dịch bằng `lang=content_locale`.

### Font scope
| Font | Dùng cho |
|---|---|
| **Inter** | en/vi (UI + content) |
| **Noto Sans JP** | ja — UI **+** An Vinh-P2 content |
| **Noto Sans SC** | zh — **chỉ UI**, KHÔNG content |
| **Noto Sans KR** | **HOÃN** (không display, không content) |

- SC + JP serve từ **R2/CDN, KHÔNG commit binary**; `font-display:optional` + preload-display-font + CORS.
- Hiện tại: **chỉ Inter self-host** (`public/fonts/`), CJK rơi system-fallback.

### Switcher (OPEN — chốt khi build)
- Reference lệch: dashboard dùng **tên bản ngữ** (日本語/中文) vs login dùng **mã** (EN/VI/JA/ZH).
- **Khuyến nghị:** tên bản ngữ (endonym) — `Tiếng Việt / English / 日本語 / 中文` (chuẩn UX switcher). Chốt khi build auth/shell.

### Thời điểm (OPEN — hoãn sâu)
- CJK chỉ cần khi UI ja/zh bật functional **HOẶC** An Vinh P2 (ja content).
- **Phase 1 + visual-review = vi thuần, Inter-only, 0 dòng CJK.** KHÔNG đụng refactor family / `:lang()` / R2-font lúc này.

---

## ADR-A4 — Theme Customizer + lưu cấu hình theme (LOCKED — chốt 2026-07-01; ĐÃ SHIP 2026-07-03)

> Nâng §A2 (Customizer "phase sau") thành đặc tả đầy đủ. **ĐÃ BUILD + SHIP** (plan `plans/260701-1523-admin-shell-velzon/`, PR #31/#32 → main; chạy trên prod).

- **Phạm vi interactive:** 4 trục **Scheme (light/dark) · Sidebar · Topbar · Position**. **Horizontal Layout = HOÃN** — khác DOM (cần template nav-ngang + JS dropdown) → workstream riêng, KHÔNG phải toggle CSS.

- **Lưu theme = 3 tầng, resolve deep-merge theo TỪNG field** (app-default → site-default → user-override):
  - **Site-default** = trang **Settings** admin → **DB** `site_settings` group **`admin_theme`** (BelongsToSite + RLS, gate `settings.manage`). Per-site.
  - **Per-user override** = **nút Customizer nổi cố định góc dưới-phải MỌI trang admin** → ghi **DB** `users.preferences.theme` (**cột `jsonb` MỚI trên bảng `users` GLOBAL — no-RLS, D6**) **+ localStorage**. Mọi admin tự chỉnh, không cần quyền riêng.
    - **Addendum (as-built, 2026-07-03): localStorage = PENDING-WRITE BUFFER, KHÔNG phải mirror của DB.** Nó chỉ giữ các trục đã đổi mà POST chưa được server xác nhận (bù race đổi-rồi-F5); server trả 2xx/422 → xoá khỏi buffer, net-fail → giữ lại retry khi load. Pre-paint chỉ áp buffer sau allow-list. Hệ quả: thiết bị khác không bao giờ bị localStorage cũ đè lên giá trị DB (multi-device an toàn); DB là nguồn sự thật duy nhất.
  - **Validate khi lưu:** allow-list `Rule::in([...])` cho CẢ 2 tầng — JSONB không CHECK được (D13) mà giá trị theme render vào `class=`/`data-*` → chống stored-injection.

- **Ràng buộc kỹ thuật (prereq):**
  - **Lớp semantic-token là PREREQ:** shell hiện sơn từ palette thô + literal `text-[#abb9e8]` cứng → phải refactor sang token vai trò (`--color-surface/-sidebar/-topbar/…`) + block override NGOÀI `@theme`. Default reproduce nguyên trạng light (0 đổi thị giác tới khi set attribute). *ĐÃ BUILD (P3, 2 lớp):* `resources/css/admin-chrome.css` = `:root --vz-*` mirror tên Velzon v4.4.4 (giá trị = look đã ship, vốn trùng preset `data-sidebar="dark"` của Velzon; block flip P4 append vào đây) + `@theme` app.css = adapter `--color-<role>` mint utility. Attr flip theo Velzon/Bootstrap 5.3: **`data-bs-theme` (scheme) · `data-sidebar` · `data-topbar` · `data-layout-position`** — thay wording `data-scheme/-position` cũ. Bất biến zone-exclusivity: biến zone chỉ tiêu thụ trong zone của trục flip; ngoại lệ chủ đích = dropdown-trong-header + `--color-accent` (theo scheme). Dark P4 phải kèm `color-scheme: dark`.
  - **Dark palette** thiết kế + verify **WCAG AA** (`text-admin-primary`/xám-nhạt/border rớt AA trên nền tối → remap brand-as-text sang tint sáng).
  - **Anti-FOUC:** SSR attrs lên `<html data-*>` (byte đầu) + 1 inline script pre-paint đọc localStorage trước CSS; `<html>` sống qua `wire:navigate` morph.
  - **Alpine = do Livewire bundle** → đăng ký store qua `alpine:init`; **CẤM `import Alpine from 'alpinejs'`** (double-register vỡ Livewire 4). State ở global store, KHÔNG ở `x-data` bị morph.
  - **Resolver** = `App\Modules\Admin\Theme\AdminChromeResolver` (đặt trong **Admin** — được đọc Tenancy `SiteSetting` + Identity `User`; KHÔNG đặt ở Tenancy/Theme). Cache entitlement/theme keyed theo `site_id`/`user_id`, request/Valkey-scoped — **KHÔNG singleton xuyên-request** (Octane, §2/Gate #1).

- 🔴 **BẤT BIẾN BẢO MẬT (§2) — near-miss đã chặn:** storefront `App\Modules\Theme\Theming\ThemeResolver` đọc `site_settings` group **`theme`** key `tokens` rồi in vào `<style>` HTML **CÔNG KHAI** (`themes/default/partials/head.blade.php:125`). ⇒ MỌI cấu hình admin/operator ghi `site_settings` **CẤM tái dùng group `theme`** — theme admin dùng group **`admin_theme`**. Vi phạm = cấu hình chrome operator lọt ra HTML public của tenant. *(Tổng quát: group `site_settings` có thể là public-storefront-emitted hoặc admin-private — xác định TRƯỚC khi ghi.)*

### As-built addendum (2026-07-03, V-A1 axes + V-A2 branding)

Interactive scope shipped BEYOND the original four axes; recorded so this ADR matches production.

- **Eight axes** (V-A1): Scheme · Sidebar-color (dark / light / gradient×4) · Sidebar-image · Sidebar-size
  (lg/sm — absorbed the old Alpine collapse toggle) · Topbar · Layout-width (fluid/boxed) · Position. A
  **Preloader axis was designed then dropped via audit**: under Octane + SPA `wire:navigate` the overlay never
  re-cleared (bricked nav), and it earns nothing in worker-mode SPA (YAGNI). Horizontal / two-column layouts
  stay a separate workstream (different DOM), still deferred.
- **Branding (V-A2):** config-driven logo (`admin.brand.logo` + `<x-admin::brand-logo>` — three variants +
  initials fallback), sized to the Velzon reference (**48px full / 22px collapsed mark**) and centered. The
  default dark sidebar is decoupled from the brand primary (#405189) to variant-one blue, then **darkened
  #3577f1 → #286ef0** so white 14px menu text clears WCAG AA (4.59; #3577f1 capped at 4.15). Opt-in gradient
  sidebars stay grandfathered. AA is gated by `scripts/contrast-check.mjs` (token values, both schemes) +
  the admin-view guard tests + `scripts/admin-preview/axe.mjs` (structural WCAG in CI); the earlier
  `scripts/admin_aa_contrast.py` was retired (P1.7).
- **Velzon-parity topbar chrome:** a shared `partials/topbar-tools` (language switcher with real flag images ·
  fullscreen · light/dark quick-toggle that reuses the Customizer's scheme axis + `adminTheme` store ·
  notifications **placeholder — no fake badge**, GĐ1 has no notification system) plus `partials/topbar-user`
  (avatar + name + primary-role line, account menu wired to **real routes only** — "My Account" points at
  own-account security since no profile route exists). Both planes reuse the partials; the platform console
  omits tenant account links. Primary-role label = `Str::headline()` of the highest-`level` role (no display
  map exists), resolved once per request in the layout view composer.

---

## Admin AA — colour, contrast & focus (P1.7 closeout)
- **Gate** (three layers): `scripts/contrast-check.mjs` measures every admin token pair (text ≥ 4.5,
  border/ring/icon ≥ 3) across light/dark × sidebar (dark/light/gradient 1–4) × topbar (light/dark),
  plus the storefront brand text and placeholder; the admin-view guard tests forbid raw semantic ink,
  navy-as-ink, faint-as-text and focus rings < 3:1 in the templates; `scripts/admin-preview/axe.mjs`
  gates structural WCAG 2A/2AA (labels, names, roles) in CI. Colour is contrast-check's job; structure
  is axe's. Known naming/label debt sits in `scripts/admin-preview/axe-allowlist.json`.
- **Roles**: navy used as ink/border/ring is the `accent` role (flips to #96a1c0 in dark); the semantic
  hues carry `-text`/`-solid` tiers, and secondary + neutral-`dark` gained the same; `--primary-on-ink`
  makes brand text readable on the dark surface (footer/hero/sub-menu) — the companion to
  `--primary-text` (light surface).
- **Placeholder convention**: a placeholder holds only an EXAMPLE value and uses `--color-placeholder`
  (= muted, ≥ 4.5:1); real instructions go in a hint line UNDER the field, never in the placeholder.
- **Dev login link (`admin:login-link`)**: DECIDED — **not built** (deferred). The "see the admin"
  need for a reviewer/agent is met by the no-login render tool (`tests/Tools/RenderAdminPreviewTest.php`
  + `scripts/admin-preview/*` — dev-only, binds 127.0.0.1, no route/cron/service); humans use the normal
  email + 2FA login. Revisit only if 2FA friction becomes real, and then via a trusted-device option,
  not a bypass route (the `/admin/scan-access` incident).

---

## OPEN ITEMS
- [ ] **CJK self-host refactor** (khi UI ja/zh functional / An Vinh P2): tách family CJK + `:lang()` font-family + serve R2 theo ADR-A3.
- [ ] **fonts.css comment** hiện ghi "An Vinh storefront zh-Hans" → SAI theo ADR-A3 (An Vinh không có zh content; zh = UI-only). Sửa comment khi đụng fonts.
- [ ] **CI gate** (GĐ7): thêm `npm ci && npm run build` (hiện CI chỉ pint/phpstan/pest — không build frontend → CSS/Vite không được verify).
- [ ] **Switcher style** — chốt khi build (rec: endonym).
- [x] **Seed An Vinh zh-Hans** — live repo ĐÃ fix sẵn (`provisionAnVinh()`=vi/en/ja; docblock + §10 đồng bộ). Không cần sửa provisioning. (Lần check trước của tôi phán "no bug" là do verify thiếu — may đúng kết quả vì live đã fix.)
