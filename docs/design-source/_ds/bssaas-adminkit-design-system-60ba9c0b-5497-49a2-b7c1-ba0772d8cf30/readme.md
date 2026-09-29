# BSSaaS AdminKit — Design System

A reusable, **white-label, multi-tenant** design system for the BSWebSaaS platform (Laravel 13 · Livewire 4 · Alpine · Tailwind 4, server-rendered Blade). It covers **two distinct surfaces**:

1. **Admin Console** — the priority. A standard, config-driven back-office shell that any future project adopts by configuration, not a one-off. It mirrors the **Velzon v4.4.4** admin theme: a fixed 250px dark-navy sidebar, a 70px white topbar, and white cards on a light lavender-gray canvas. Full light + dark mode, plus an 8-axis theme Customizer.
2. **Storefront** — secondary. Fast, SEO-first public sites, **one per tenant**, each with its own brand. The first live tenant is **Cơ Khí Chính Xác An Vinh** (precision machining), styled slate + amber.

Brand (name, logo, tagline) is never hardcoded — it comes from config (`config/admin.php` → `admin.brand.*`). The default platform brand is **Bảo Sơn** (“Giúp bạn thành công”, BaoSon Ads).

## Sources (ground truth)

Everything here was rebuilt from the platform codebase — not from memory. Explore these to build more accurately:

- **GitHub:** `tuyenht/BSWebSaaS` (private) — the Laravel monorepo. Key paths:
  - `resources/css/{fonts,admin-chrome,app,storefront}.css` — token + theme-flip source
  - `app/Modules/Admin/resources/views/components/*.blade.php` — the primitive library (rebuilt here as React)
  - `app/Modules/Admin/resources/views/layouts/app.blade.php` + `partials/{customizer,topbar-*}.blade.php` — the shell
  - `app/Modules/Admin/resources/views/livewire/**` — real screens (catalog editor, users, login, component gallery)
  - `themes/default/**` + `resources/css/storefront.css` — the storefront theme
  - `lang/{vi,en,ja,zh}.json` — UI copy (Vietnamese-first)
- **Related tenant repo:** `tuyenht/anvinh-website`.
- **Live references:** `https://default.baoson.net/index` (shell + dashboard density), `https://default.baoson.net/apps-ecommerce-add-product` (form layout), `av1.baoson.net/admin` (the running Vietnamese build).
- **Uploaded assets:** the Bảo Sơn logo set (`logo-dark/light/sm.png`) and self-hosted **Inter** woff2 (roman + italic, full Vietnamese diacritics).

---

## Content Fundamentals

- **Vietnamese-first.** Every string is authored in Vietnamese, then EN + JA (+ ZH roadmap). Copy in this system is Vietnamese.
- **Audience:** non-technical Vietnamese SME owners. Tone is **clear, calm, professional** — plain language, never jargon or stack traces.
- **Second person, warm but respectful.** “Bạn” (you). Greetings like “Xin chào, :name!”, “Chào mừng trở lại!”.
- **Casing:** sentence case for UI copy; micro-labels (sidebar section titles, fieldset legends) are UPPERCASE 11px with wide tracking. No ALL-CAPS shouting elsewhere.
- **Errors are human and actionable.** “Không kết nối được máy chủ email.” · “Đường dẫn chỉ gồm chữ thường, số và dấu gạch nối.” · “Vui lòng để lại email hoặc số điện thoại để chúng tôi liên hệ.” Never a raw error code.
- **Empty & loading states always exist** — no dead ends. “Bạn chưa có thông báo mới.” · “Thư viện chưa có ảnh. Hãy tải ảnh lên ở mục Thư viện trước.” · “Trang đang được xây dựng.”
- **Buttons are verbs:** Lưu · Thêm sản phẩm · Đăng · Gỡ · Khôi phục · Xem trước.
- **No emoji** in admin chrome (a couple appear only as media-role glyphs deep in the media picker). Numbers use Vietnamese formatting (`4.200.000 ₫`, decimal comma).

## Visual Foundations

- **Colors.** Admin brand primary is **navy `#405189`** (buttons, links, active nav, focus rings) with 5 semantic states (secondary `#3577f1`, success `#0ab39c`, info `#299cdb`, warning `#f7b84b`, danger `#f06548`). A 9-step Velzon gray ramp (`#f3f6f9 → #212529`) drives every neutral role. Page canvas is `#f3f3f9`; cards are pure white; borders are `#eff2f7`/`#e9ebec`. The storefront is a **separate** palette — slate `#0f172a`/`#1e293b` + amber `#f59e0b`/`#d97706` on `#f8fafc` — namespaced `--color-sf-*` so it never leaks into admin. **Max 1–2 background colors per surface.** Full dark mode exists (`[data-bs-theme=dark]`: body `#1a1d21`, surface `#292e33`), AA-tuned.
- **Type.** **Inter** everywhere, self-hosted with full Vietnamese diacritics. Base **14px / line-height 1.5**. Headings weight 500–600 (page h1 20px, card title 16px). Meta/dense data 12–13px (`fs-12`/`fs-13`). No display or serif faces.
- **Shape.** Small radii only — **4px on controls, 6px on cards**, pills on avatars/badges. **No large rounding.**
- **Elevation.** Exactly **one** card shadow: `0 1px 2px rgba(56,65,74,.15)`. Popovers/modals get a slightly deeper `shadow-pop`. **No heavy elevation, no gradients on chrome** (gradients appear only as opt-in Customizer sidebar themes).
- **Spacing.** 0.25rem step (4 · 8 · 12 · 16 · 24 · 32). Content gutter 24px; cards pad 16px. Dense, data-first, desktop-first.
- **Layout.** Fixed 250px sidebar (collapses to a 70px icon rail with hover flyout, or a mobile off-canvas drawer) + fixed 70px topbar. Every page = breadcrumb + page-title header, then cards.
- **Motion.** Subtle and functional: 0.15–0.2s color/background transitions, a 4s continuous spin on the Customizer gear. Reduced-motion respected. No bounces.
- **States.** Hover = darken solid buttons (brightness .95) / fill soft & outline buttons / tint ghost & rows. Focus = primary border + soft `20%` ring. Active nav = white text on a `15%`-white fill (dark sidebar). Disabled = 60% opacity.
- **Borders over shadow** for structure: cards, inputs, tables all lean on 1px hairlines. Transparency/blur is used sparingly (the login glass card; overlay scrims at ~40–55%).

## Iconography

- **Inline SVG only — never an icon font.** Two sources, both currentColor on a 24×24 viewBox:
  - **Heroicons (outline)** — stroke 1.5, the default UI glyph style.
  - **Remix Icon (fill)** — prefixed `ri-*`, used for toolbar/action glyphs (add, search, pencil, delete-bin, eye, save…).
- Consume via the **`Icon`** component (`<Icon name="dashboard" />`). Unknown names fall back to a generic document glyph. The full name list is in `Icon.prompt.md`.
- Brand/social marks (Google, Facebook) and the sidebar image textures are the only raster/multicolor exceptions. Emoji is not used as iconography.

---

## Index / Manifest

**Root**
- `styles.css` — the single entry point consumers link. `@import`s the token + font closure only.
- `tokens/` — `colors.css`, `typography.css`, `shape.css` (CSS custom properties; dark + sidebar-light scopes live in `colors.css`).
- `fonts/` — `inter.css` (`@font-face`) + the Inter woff2 binaries.
- `assets/` — Bảo Sơn logos (`logo-dark/light/sm.png`).
- `thumbnail.html` — the design-system homepage tile.

**Components** (React primitives — `components/<group>/`; each has `.jsx` + `.d.ts` + `.prompt.md`, one card HTML per group). Namespace: `window.BSSaaSAdminKitDesignSystem_60ba9c`.
- **core/** — `Button`, `Badge`, `Alert`, `Avatar`, `Card`, `Icon`
- **forms/** — `Input`, `Select`, `Textarea`
- **data/** — `Table`, `Pagination`
- **overlays/** — `Dropdown` (+ `DropdownItem`), `Modal`, `Tabs`, `Tooltip`
- **layout/** — `PageHeader` (+ `Breadcrumb`)

**Guidelines** (`guidelines/`) — foundation specimen cards: color (brand, gray ramp, surfaces, storefront), type (family, scale, weights, text roles), spacing (scale, layout dims), shape (radius, elevation), brand (logo lockups).

**UI kits** (`ui_kits/`)
- `admin-blade/` — the **Admin Console shell + dashboard as production Blade · Livewire 4 · Alpine · Tailwind 4** (server-rendered), matching the Velzon Default layout from `Velzon_Defult.fig` and reusing the `x-admin::*` primitives + our tokens. Files: `layouts/app.blade.php`, `partials/{sidebar,topbar,customizer,command-palette}.blade.php`, `components/{customizer-field,customizer-seg}.blade.php`, `livewire/dashboard.blade.php`, `Dashboard.php`, and a `README.md` install map. The **8-axis theme Customizer** (Layout · Color Scheme · Layout Width · Layout Position · Topbar · Sidebar Size · Sidebar Color · Sidebar Images) and a **⌘K command palette** are included. `preview.html` is an interactive visual mock of the whole thing (dashboard stat cards, recent activity, quick-create, empty states; collapsible/rail/off-canvas sidebar; light/dark; all 8 customizer axes incl. vertical/horizontal/two-column). Login is excluded (already built).
- `storefront/` — the An Vinh public homepage on the real `storefront.css` (hero, about, services, features, partners, contact, CTA, footer). Photo areas use `<image-slot>` placeholders.

## Intentional additions

> **Source of the component inventory.** Every component here (`Button`, `Card`, `Alert`,
> `Avatar`, `Badge`, `Icon`, `Input`, `Select`, `Textarea`, `Table`, `Pagination`,
> `Dropdown`/`DropdownItem`, `Modal`, `Tabs`, `Tooltip`, `PageHeader`/`Breadcrumb`) is a
> faithful rebuild of the platform's **Blade `x-admin::*` primitive library** in the
> BSWebSaaS codebase — **not** from `Velzon_Defult.fig`. That `.fig` is a full-page Velzon
> mockup (no reusable component families — only 3 standalone symbols: `Sidebar`,
> `arrow-up-line`, `delete`), so it defines no component vocabulary to match against; it is
> used purely as the **pixel/geometry reference** for the shell + dashboard. All of the
> above are therefore intentional and correct — they are the design system's real primitives.

- **`Select` / `Textarea`** — the Blade library exposes only `input`; the product's forms (catalog editor) style native `<select>`/`<textarea>` inline with the same `.form-control` look. They are packaged here as components so kits don't re-implement them.
- **`Breadcrumb`** — a small helper for the `PageHeader` breadcrumbs slot (the Blade version passes breadcrumbs as free markup).
- **Dashboard + command palette** were designed here (the brief flagged them as current gaps): the live dashboard is a stub, and there is no command pattern yet.

## Caveats

- Not built (no source counterpart / out of scope): the rich-text editor (Jodit island), the full media-picker modal, the platform (super-admin) console screens, and the horizontal / two-column layout variants (roadmap).
- Icon set is a **curated subset** of the platform's inline-SVG map — the most-used glyphs. Add more paths to `components/core/Icon.jsx` from the source `icon.blade.php` as needed.
- The storefront kit renders one tenant (An Vinh) with no supplied logo — the brand is set in plain type. Storefront photos are empty `<image-slot>`s awaiting real imagery.
