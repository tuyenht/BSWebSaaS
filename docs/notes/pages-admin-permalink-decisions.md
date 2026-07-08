# Pages Admin (Quản lý Trang) — build state + pending permalink/nesting decisions

> Git-durable continuity note (the plans/ + Claude memory that also hold this are **local-gitignored**,
> so this file is the box-independent copy). Doc-only; does **not** amend design.md SSOT.
> Written 2026-07-08 at session close.

## 1. Build state — DONE, not merged

Branch **`feat/pages-admin-fidelity`** = **12 commits ahead of `main`** (`main` tip unchanged at `593b5c5`
→ clean rebase-merge / fast-forward). Full **Admin+Cms+Storefront suite: 475 passed, 0 failed**.
Guardrails green each phase (Pest · Larastan max · Pint · Query-Budget).

The design is `docs/design_handoff_pages_admin/` §3a, rebuilt faithfully as the shared
`admin.entries.index?section=pages` screen (`App\Modules\Admin\Livewire\Entries\Index`).

Commits (reconstruct context via `git log 593b5c5..84becae`):

| Phase | Commit | What |
|---|---|---|
| P1 | `22d5f8e` | schema: `entries.status` +`scheduled`+`private`; `entries.view_count`; `EntryStatus` enum |
| P2 | `323c322` | `TransitionEntryStatus` + `DuplicateEntry` actions |
| P3 | `9912a7c` | rich list: status tabs+counts, search, sortable **tree**, paginate |
| P4a | `900688c` | language column + client column-picker |
| P4b | `a3defbf` | facet filters (lang/author/template) + chips + grouped-FILTER counts |
| P5a | `f8e3273` | row status-menu, duplicate, trash/restore/purge + `$bsToast` |
| P5b | `cf1532e` | bulk selection + action bar |
| P6a | `97a5617` | **nested page paths** (PathBuilder parent-prefix) + `RouteSync::syncDescendants` cascade 301 |
| P6b | `d954d57` | inline edit title/slug (VN slugify server-side, home-slug locked) |
| P7 | `bdf43f0` | per-locale edit (`Editor ?locale=`) + language-column Sửa/Thêm |
| P8a | `81a5fde` | scheduled auto-publish: `entries:publish-due` + `PublishScheduledEntry` |
| P8b | `84becae` | Valkey view counter: `EntryViewCounter` + `entries:flush-views` + storefront hook + admin "N lượt xem" |

## 2. PENDING — owner chốt needed before/around merge

### G1 — gate P6a nesting to **default FLAT** (owner: "mặc định không lồng nhau")
P6a currently nests a page's URL **automatically** whenever `parent_id` is set. Owner wants nesting to be
a **per-site config, default flat** (`parent_id` = admin tree grouping only; URL flat unless opted in).

**Exact next step (COUNCIL — routing core; propose-first):**
1. Add a **memoized, request-scoped** `PermalinkConfig` reader (mirror `App\Modules\Tenancy\Context\SiteLocale`
   — do NOT read `site_settings` per PathBuilder call = N+1). Config shape (forward-compatible with U1,
   plan `260706-1116`): `site_settings` `group_name='permalinks'`, `key='pages'`, `value={pattern}`,
   default `pattern='flat'`.
2. `App\Modules\Cms\Routing\PathBuilder::pagePath` nests only when `pattern==='nested'`; else flat.
3. Tests: reuse `tests/Feature/Cms/NestedPageRoutingTest.php` — default flat does NOT nest; nested-when-enabled still nests + cascades.
Keeps P6a's tested `pagePath`/`syncDescendants` code (dormant until opted in).

### Merge — SAFE regardless (audit-verified)
An Vinh pages are **flat** (`SeedAnVinhContentCommand` sets no `parent_id`) AND `RouteSync` only re-syncs a
route on **edit/publish, never on deploy** → P6a changes **zero** An Vinh URLs. So merge does not depend on G1.
Owner options: (a) do G1 then merge · (b) merge now, G1 after · (c) review diff first. Recommended: **(a)**.
Merge mechanics: rebase-merge feat→main (memory `gh-pr-automation`: push→pr→merge --rebase).

### Permalink model (my recommendation, pending)
- **Category = per-type vocabulary** (`post_cat`/`product_cat`/`project_cat`) — drives template + URL prefix per type.
- **Tag = ONE shared site-wide vocabulary** (`tag`), attachable to both entries + products via typed pivots.
  **Data model NOW** (schema already supports it); **cross-content tag archive PAGE = future** (see §3 E3).
- Config = per-**section** in `site_settings[permalinks]` + 4 presets: `flat` `/slug` · `base` `/{base}/slug` ·
  `category` `/{cat}/slug` · `category_path` `/{cat1}/{cat2}/…/slug`. Defaults = current behaviour
  (pages flat · posts category · products base:'san-pham'). This IS the pending plan `260706-1116` (FU1–FU4).

## 3. Audit corrections (verified against code — trust these over earlier prose)

- **E1:** design.md:197 & :278 say taxonomy slug is UNIQUE per (site, vocabulary, locale). **As-built
  (`migration 2002`) it is NOT** — only `unique(taxonomy_id, locale)` + a non-unique `index(site_id,locale,slug)`.
  The real one-path-one-target guard is the **`routes`** table (`unique site+locale+path`). Doc↔code mismatch.
- **E2:** merge is An-Vinh-safe (see §2).
- **E3:** a shared-tag archive showing **both posts + products** does NOT exist — `RouteResolver::resolveCategory`
  → `renderCategory` is **post-only** (queries `entry_taxonomies`, orders by `entries.published_at`). The tag
  *data model* is fine; the cross-content archive *page* is future work (build with the tag UI).

## 4. Deploy checklist
1. Run migration **`2012`** on prod (`--database=pgsql_admin`).
2. Rebuild FE — `resources/js/sweet-toast.js` (public/build is gitignored → toast/menus only appear after build).
3. Ensure the **`scheduler` container** runs the two new crons (`entries:publish-due` every-min,
   `entries:flush-views` every-5-min) — else scheduled pages never auto-publish & views never flush.
4. **Pixel pass at deploy** — dev has no browser; visual fidelity verified only by render tests so far.

## 5. Traps for the next session
- Guardrails via `docker compose run --rm --no-deps app composer exec pest|phpstan|pint -- …`
  (NOT `exec`; a hook blocks literal `vendor/bin/*` → use `composer exec`).
- New lang key → seed all four `lang/{vi,en,ja,zh}.json` (lang:check parity). ja/zh already miss ~186
  pre-existing `admin.*` keys — not ours.
- Larastan max: `(int)/(string)/intval(mixed)` = error → narrow with `is_numeric`, or raw SQL literal + bindings.
  `$x?->prop ?? d` trips `nullsafe.neverNull` → narrow with `!== null`.
- Entries-list query budget ceiling = **22** (`AdminListQueryBudgetTest`) — keep the list flat.
- `$bsToast` = SweetAlert2 toast fed by `$this->dispatch('bs-toast', text:)` (LW4 dispatch = bubbling DOM event).
- Tenant-scoped cron pattern = `Site::all()` + `Tenancy::enter()` (per `media:purge-trash`). Redis/Valkey facade works in tests.

## 6. Parked (not now)
Full permalink U1/U2/U3 (plan `260706-1116`) · cross-content tag archive + tag UI · Taxonomy UI (5-fork, pending) ·
media picker Phase 3/4 · catalog P2 storefront (must route THROUGH the U1 permalink engine).
