---
paths:
  - ".github/workflows/**"
  - ".github/dependabot.yml"
  - "package.json"
  - "pnpm-workspace.yaml"
  - "composer.json"
  - ".lighthouserc.cjs"
  - "scripts/**"
  - "tests/**"
---

# CI workflows

- Runners are pinned (`ubuntu-24.04`); move the pin deliberately, in its own commit.
- A job that calls `artisan` directly (not through Pest) must set every fail-closed env var `phpunit.xml` provides (`AUTH_PASSWORD_PEPPER`, `SETTINGS_ENCRYPTION_KEY`).
- `setup-php` mirrors `docker/frankenphp/conf.d/app.ini` (upload limits) — environment-guard tests depend on it.
- CommonJS config files are named `.cjs`: `package.json` is `"type": "module"`, so a `.js` `module.exports` silently loads as `{}`.
- `actions/upload-artifact@v4` skips dot-prefixed paths unless `include-hidden-files: true`. Optional steps (reports, uploads) get `continue-on-error: true`.
- Workflow changes are proven on the PR's own run; never merge before it is green.
- Pest runs in six shards (`--exclude-testsuite=Arch --shard=N/6`, split by the class times in `tests/.pest/shards.json`, `fail-fast: false`, 20 minutes each, 1G) plus the Arch suite as a job of its own (2G: its rules parse and keep the whole code base, 861M measured 15/11/2026), beside a `static` job (pint, phpstan, lang:check). The job named `Guardrails (pint · phpstan · pest · migrate)` only gathers their results: keep that name: branch protection may require it (our token cannot read the protection settings), and deploy scripts read the run-level conclusion. When a shard log prints the WARN that `shards.json` is out of date, or a shard nears its limit, rebuild the file from green full runs on `main` (`python3 scripts/ci-shard-timings.py <run-id> <run-id>`) in its own commit; when the slowest shard still passes 12 minutes, add a shard. Never raise one job's limit to hold the whole suite again. A shard that shows "cancelled" with `Tests: N passed` in its log ran out of time: compare the runner `Image` line before hunting a regression.
- When CI runs: on a pull request (jobs chosen by the `changes` job from the changed paths), every night on the head of `main` (every job) and by hand (`gh workflow run ci.yml --ref <branch>`, every job). A merge to `main` starts no run; a deploy needs a full run on the exact commit (`docs/runbooks/prod-deploy.md`).
- The `changes` job skips work only for paths it names: documents skip every job; the four Pest suites, their shard times (`tests/.pest/*`) and the host-side `scripts/*.py` / `scripts/*.sh` skip `cwv` only. Any other path runs everything. Before a job, script or test starts reading a path on that list (a test that reads `docs/`, a CI step that runs a `scripts/*.sh`), take the path off the list in the same commit.
- A new job gets `needs: changes`, an `if:` that skips only on a literal `'false'` output (`!cancelled() && needs.changes.outputs.<name> != 'false'`) and a `timeout-minutes`.

## Dependabot (`.github/dependabot.yml`)

- npm and composer ignore every semver-major. Expect an advisory fixed only in a new major to stay an alert with no pull request (the docs mark `ignore` as applying to security updates too; not yet seen on a real run). Do that major as planned work. GitHub Actions take majors, one pull request each.
- Every Dependabot pull request runs all three jobs (lockfiles, manifests and workflows fall under the `changes` catch-all). Never add a lockfile or manifest to the skip list to save minutes.
- Dependabot never edits `overrides:` in `pnpm-workspace.yaml` (dependabot-core#16529). Remove an override by hand once its parent ships the fix, and check that no override pins a direct dependency a Dependabot pull request bumps.
- Keep `pnpm-lock.yaml` a single YAML document. Adding `devEngines.packageManager` or `configDependencies` makes pnpm 11 write a second document, and the dependency graph then reads only the first: alerts disappear without a warning (dependabot-core#14919).
