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
  - Exception, foreign keys: deleting a parent row makes Postgres find its children with `WHERE <fk> = $1` (no `site_id`, RLS bypassed). A foreign key whose parent the app hard-deletes needs an index leading with the FK column, partial `WHERE <fk> IS NOT NULL` when nullable.
  - A parent that is never hard-deleted goes on the list in `tests/Feature/Infrastructure/ForeignKeyIndexCoverageTest.php` with its reason; the test fails on any other uncovered foreign key.
  - Re-runnable after a mid-run failure: drop an index an interrupted CONCURRENTLY build left INVALID before `IF NOT EXISTS` (pattern: `2024_01_01_000000_add_foreign_key_lookup_indexes.php`).
- A new column with a constant DEFAULT plus a CHECK tying it to existing columns:
  - Backfill existing rows first (idempotent), then add the CHECK `NOT VALID` → `VALIDATE`.
  - Test the migration's own `down()` → insert the awkward pre-existing row → `up()` (pattern: `tests/Feature/Tenancy/DropDefaultLocaleTest.php`).
  - CI migrates an empty database and cannot catch this.
- Prod deploys with migrations take a `pg_dump` first (`docs/runbooks/prod-deploy.md`).
