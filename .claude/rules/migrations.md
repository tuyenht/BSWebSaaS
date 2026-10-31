---
paths:
  - "database/migrations/**"
  - "database/seeders/**"
---

# Migrations

- COUNCIL-class (CLAUDE.md §10.1): propose first.
- Name by FK tier, `NNNN_01_01_000000_<name>.php` (see the directory) — never a `make:migration` timestamp. Run with `--database=pgsql_admin`.
- Tenant tables, pivots included, carry `site_id` + RLS FORCE fail-closed; soft-deleting tables also filter `deleted_at IS NULL`. Global tables must be on the D6 list.
- Expand-contract. `CREATE INDEX CONCURRENTLY` on existing tables (outside a transaction). Indexes lead with `site_id`.
  - Exception, foreign keys: deleting a parent row makes Postgres find its children with `WHERE <fk> = $1` (no `site_id`, RLS bypassed). A foreign key whose parent the app hard-deletes needs an index leading with the FK column, partial `WHERE <fk> IS NOT NULL` when nullable. A composite `(site_id, x)` key is served by `(site_id, x, …)` or an index led by `x`; any other `site_id`-led index still scans the whole site.
  - A key that needs no index (parent never hard-deleted, or a few child rows per site) goes on a list in `tests/Feature/Infrastructure/ForeignKeyIndexCoverageTest.php` with its reason; the test fails on any other uncovered foreign key. It cannot see delete paths: a hard delete added for a listed parent means indexing its children.
  - Exception, platform-wide tables (signed by the owner 31/10/2026): a platform-wide table (list D6) may lead an index with its main filter column or the expression the query uses, backed by measurements. Write the row count and the before/after timings in the migration's docblock, and pin the definition in a test that also plans the screen's real query against it (pattern: `2038_01_01_000000_add_filter_indexes_to_audit_logs.php` + `tests/Feature/Admin/Platform/AuditLogIndexesTest.php`). Website tables (RLS) still lead with `site_id`.
  - Re-runnable after a mid-run failure: drop an index an interrupted CONCURRENTLY build left INVALID before `IF NOT EXISTS` (pattern: `2024_01_01_000000_add_foreign_key_lookup_indexes.php`).
- A new column with a constant DEFAULT plus a CHECK tying it to existing columns:
  - Backfill existing rows first (idempotent), then add the CHECK `NOT VALID` → `VALIDATE`.
  - Test the migration's own `down()` → insert the awkward pre-existing row → `up()` (pattern: `tests/Feature/Tenancy/DropDefaultLocaleTest.php`).
  - CI migrates an empty database and cannot catch this.
- A migration that changes the `site_notifications.kind` CHECK (a new notification kind): point `latestNotificationKinds()` in `tests/Feature/Notifications/SiteNotificationsTest.php` at it. That file re-runs an older migration's `down()` → `up()`, which puts the old kind list back on the shared dev DB; the helper restores the newest list, and a stale one fails later tests with `violates check constraint "site_notifications_kind_check"`.
- Prod deploys with migrations take a `pg_dump` first (`docs/runbooks/prod-deploy.md`).
- A migration that adds, drops or retypes a column leaves PgBouncer holding plans that no longer fit ("cached plan must not change result type"). After running it on the shared dev DB (from a worktree too), run `docker compose restart pgbouncer` before Pest; on prod, the runbook's PgBouncer step.
