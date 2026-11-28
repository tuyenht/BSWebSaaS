---
paths:
  - "app/Modules/Tenancy/**"
  - "app/Modules/*/Console/**"
  - "app/Providers/**"
  - "config/octane.php"
  - "config/tenancy.php"
  - "tests/Concurrency/**"
  - "app/Modules/Identity/Support/SupportSession.php"
  - "app/Modules/Admin/Http/Middleware/EnforceSupportSession.php"
---

# Tenancy + Octane

- Loops over tenants/rows inside `Tenancy::enter` catch `Throwable` per row. `enter` wraps the loop in one transaction, so an escaped exception rolls back that tenant and aborts every later one — forever, for a cron.
  - A catch alone keeps later rows working only when each row also runs in its own savepoint (`DB::transaction` per row). In PostgreSQL the first failed statement aborts the whole transaction: without the savepoint every later row fails (25P02), and `enter` rolls the whole site back at the end, with a warning in the log.
- `Tenancy::enter` notices a transaction PostgreSQL aborted (a failed statement the code caught and swallowed) only through the failed-statement count of the pgsql connection (`TrackedPostgresConnection`); it then rolls back the Laravel way, so after-commit work is dropped and after-rollback callbacks run. Never send a statement on the raw PDO handle (`getPdo()`) in `app/`: it would go uncounted (`tests/Arch/DatabaseConnectionTrackingTest.php`).
- Octane keeps singletons between requests.
  - No request state in singletons or static properties.
  - A per-request `config([...])` change to an already-built service (e.g. `session.cookie`) is a no-op. Act on the resolved object and verify with a live request, not a config-value test.
- CI's `ContextIsolationTest` only interleaves sites over one pooled connection. When changing tenancy middleware, Octane listeners or site resolution, also run the real worker-pool hammer: `docker compose run --rm --no-deps app bash tests/Concurrency/octane-hammer.sh`.
  - ⚠ It runs `migrate:fresh` on the shared dev/test DB and takes no Pest lock.
  - Run it only when no Pest run or other session is using the DB, and with the owner's OK.
  - It covers the storefront, support sign-in (one staff member per site, signed in as its owner, two cookie jars) and a permission taken away by another process (every worker must refuse it at its next request). It reports FAIL (server error) apart from LEAK (wrong site's content) and STALE (a removed permission still worked).
  - Run it too when changing the permission cache (`VersionedPermissionRegistrar`, `SyncPermissionCache`).
  - It needs `public/build/manifest.json`: in a worktree, copy the dev tree's built assets in first. Afterwards run `docker compose restart pgbouncer`, because `migrate:fresh` leaves stale prepared plans.
  - Restart PgBouncer BEFORE it too when Pest or a migration ran since the last restart: twice on 02–03/10 the hammer stopped right after its `migrate:fresh` (no sites, no permissions) and passed on the next run after a restart. A failing setup step now prints its error.
  - Also run it when changing support sign-in (`SupportSession`, `EnforceSupportSession`).
- D14 (PgBouncer transaction mode + prepared statements) is exercised only by local Pest runs (dev `DB_HOST=pgbouncer`); CI talks to Postgres directly.
