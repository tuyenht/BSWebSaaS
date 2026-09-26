---
paths:
  - "app/Modules/Tenancy/**"
  - "app/Modules/*/Console/**"
  - "app/Providers/**"
  - "config/octane.php"
  - "config/tenancy.php"
  - "tests/Concurrency/**"
---

# Tenancy + Octane

- Loops over tenants/rows inside `Tenancy::enter` catch `Throwable` per row. `enter` wraps the loop in one transaction, so an escaped exception rolls back that tenant and aborts every later one — forever, for a cron.
- Octane keeps singletons between requests.
  - No request state in singletons or static properties.
  - A per-request `config([...])` change to an already-built service (e.g. `session.cookie`) is a no-op. Act on the resolved object and verify with a live request, not a config-value test.
- CI's `ContextIsolationTest` only interleaves sites over one pooled connection. When changing tenancy middleware, Octane listeners or site resolution, also run the real worker-pool hammer: `docker compose run --rm --no-deps app bash tests/Concurrency/octane-hammer.sh`.
  - ⚠ It runs `migrate:fresh` on the shared dev/test DB and takes no Pest lock.
  - Run it only when no Pest run or other session is using the DB, and with the owner's OK.
- D14 (PgBouncer transaction mode + prepared statements) is exercised only by local Pest runs (dev `DB_HOST=pgbouncer`); CI talks to Postgres directly.
