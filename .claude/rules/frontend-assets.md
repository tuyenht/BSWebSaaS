---
paths:
  - "resources/js/**"
  - "resources/css/**"
  - "app/Modules/**/resources/views/**"
  - "themes/**"
  - "vite.config.js"
  - "package.json"
  - "scripts/asset-budget.*"
---

# Shared scripts and styles, small downloads

Goal: one copy of each pattern, and a page downloads only what it uses. The CI `frontend` job enforces the sizes with `scripts/asset-budget.mjs` (gzipped, per page entry and per lazy chunk, +3% allowed over `scripts/asset-budget.json`).

- **One shared part per repeated pattern.** It is a Blade component in `app/Modules/Admin/resources/views/components/`, plus at most one JS module in `resources/js/admin-<part>.js` and one CSS file `resources/css/admin-<part>.css` imported once from `app.js` / `app.css`.
  - A second screen that needs the pattern uses that part (with props or slots). It never copies the markup, the Alpine object or the CSS.
  - Before writing a new part, grep `components/` and `resources/js/` for an existing one.
- **No per-screen `<style>` or `<script>` in Blade.** Classes and tokens go in the shared CSS. Behaviour goes in a registered Alpine data object (`Alpine.data('bsX', …)` in its module), not a long inline `x-data="{…}"`.
  - Allowed exceptions, already in place: the theme boot before first paint, the per-site brand tokens, Turnstile, and the storefront head, header and form partials.
- **Heavy code loads only where a screen needs it.** A library over about 10 KB gzipped, used by some screens only (editor, uploader, charts, dialogs), is reached with `await import('./x.js')` on first use, as `app.js` does for the rich editor, Uppy and SweetAlert2. Never import it statically from `app.js`.
- **Storefront:**
  - the base `storefront.js` stays tiny;
  - each section's script is its own Vite entry (`sf-<section>.js`), loaded only by a page that renders that section;
  - the CWV job keeps the storefront under its 30 KB JS gate.
- **Dependencies:** before adding a package, check its gzipped size and its licence (no GPL), and whether a few lines of our own code do the job. A new entry or lazy chunk needs its budget line: `node scripts/asset-budget.mjs --update`, committed with the change, and the PR says why it grew.
- **CSS:** Tailwind 4 utilities and the design tokens first. A screen-specific CSS file is a smell: keep it only for a shared part's own styles.
