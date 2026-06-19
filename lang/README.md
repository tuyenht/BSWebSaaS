# Admin i18n — language files

One flat JSON per language: `lang/{code}.json` (`en.json`, `vi.json`, `ja.json`, `zh.json`, …).
Dropping a new `{code}.json` makes that language appear in the admin language switcher
automatically (it scans `lang/*.json`).

## Conventions
- **`en.json` is the canonical source of truth** for the SET of keys. Every other language
  must contain the same keys.
- **Keys are namespaced slugs**: `group.section.key` — e.g. `auth.login.title`,
  `admin.users.create`, `common.actions.save`. Lower-case, dot-separated. A key is a machine
  identifier; the VALUE is the displayed text — never put display text into a key.
- **Group by prefix and keep the same order** in every file so diffs line up.
- **Never hardcode UI text** — every visible string uses `__('group.key')`.
- The brand NAME is config (`config('admin.brand.name')`), not a translation.

## Tooling
- `php artisan lang:check` — verifies every `{code}.json` has the same keys as `en.json`;
  reports missing / extra / duplicate keys and values identical to en (likely untranslated).
  Run it in CI to block drift.
- `php artisan lang:add {code}` — clones `en.json` to a new `{code}.json` (same keys), ready
  to translate (`--blank` for empty values). Then add a native label in `config/locales.php`.
