---
paths:
  - ".github/workflows/**"
  - ".lighthouserc.cjs"
  - "scripts/**"
---

# CI workflows

- Runners are pinned (`ubuntu-24.04`); move the pin deliberately, in its own commit.
- A job that calls `artisan` directly (not through Pest) must set every fail-closed env var `phpunit.xml` provides (`AUTH_PASSWORD_PEPPER`, `SETTINGS_ENCRYPTION_KEY`).
- `setup-php` mirrors `docker/frankenphp/conf.d/app.ini` (upload limits) — environment-guard tests depend on it.
- CommonJS config files are named `.cjs`: `package.json` is `"type": "module"`, so a `.js` `module.exports` silently loads as `{}`.
- `actions/upload-artifact@v4` skips dot-prefixed paths unless `include-hidden-files: true`. Optional steps (reports, uploads) get `continue-on-error: true`.
- Workflow changes are proven on the PR's own run; never merge before it is green.
