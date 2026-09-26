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
- A new column with a constant DEFAULT plus a CHECK tying it to existing columns:
  - Backfill existing rows first (idempotent), then add the CHECK `NOT VALID` → `VALIDATE`.
  - Test the migration's own `down()` → insert the awkward pre-existing row → `up()` (pattern: `tests/Feature/Tenancy/DropDefaultLocaleTest.php`).
  - CI migrates an empty database and cannot catch this.
- Prod deploys with migrations take a `pg_dump` first (`docs/runbooks/prod-deploy.md`).
