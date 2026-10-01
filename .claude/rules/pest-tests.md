---
paths:
  - "tests/**"
  - "phpunit.xml"
---

# Pest on the shared, never-rolled-back test database

- The dev DB is the test DB and nothing rolls back.
  - Truncate the GLOBAL tables you write or assert on (e.g. `plans`, `plan_features`, settings, `audit_logs`) in `beforeEach` via `DB::connection('pgsql_admin')->statement('truncate <table> restart identity cascade')`. `seedTenancy`'s cascade does not reach them.
  - Never hardcode a UNIQUE value; factories use random/ULID values.
- A TestCase advisory lock serializes Pest runs; still, don't start a full run while a subagent is running Pest.
- `phpunit.xml`: every value is a forced `<env>` + `<server>` twin (Laravel reads `$_SERVER` first) — keep new values in that form.
- Red first: read the failure and confirm it shows the bug's symptom — a TypeError or harness error is not a valid red.
  - New features too: run the new tests red before writing the implementation. Tests first run only after the code was written get checked by hand-made mutations instead.
- Before adding tests, grep by module/directory name, not only the class name: HTTP-level tests exercise classes without importing them.
- Assertions:
  - jsonb read back → compare by content (`toMatchArray`), not `toBe` (jsonb normalizes key order).
  - One printed line → one `expectsOutputToContain` holding the whole substring.
  - Query budgets across two renders → warm up after any grant/role change (Spatie flushes its cache: +2 queries).
- Arch tests: never put an ARRAY on the expectation side under negation — `expect([A, B])->not->toUse(X)` passes as soon as ONE namespace is clean. Loop one rule per namespace; arrays on the target side are fine.
- Environment-guard tests (e.g. PHP upload ceiling ≥ media cap) fail on runners not configured like dev/prod — fix the runner config, never weaken the guard.
- The full suite takes ~5–6 minutes.
  - `composer qa` disables the process timeout; for `composer exec pest` pass `-e COMPOSER_PROCESS_TIMEOUT=0`.
  - A piped run's exit code is the last stage's — read the `Tests:` line.
