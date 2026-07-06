# BSWebSaaS Environment Overrides

These rules WIN over any generic guidance in this block. The full project contract lives in
CLAUDE.md §0–§10 above — when anything here or below conflicts with it, CLAUDE.md wins.

- There is NO PHP on the host. Every `php`, `artisan`, `composer`, `pest`, `phpstan`, `pint`
  command runs as: `docker compose run --rm --no-deps app <command>`.
- Migrations ALWAYS run with the privileged role: `docker compose run --rm --no-deps app php artisan migrate --database=pgsql_admin --force`. Never plain `artisan migrate` (runtime role `app_user` cannot DDL and must stay NOSUPERUSER/NOBYPASSRLS).
- Boost MCP tools run INSIDE the app container: file paths they return under `/app/...` map to `/opt/bswebsaas/dev/...` on the host.
- Database tools connect as the runtime `app_user` with row-level security enforced and no tenant context set — tenant tables legitimately return zero rows; that is RLS fail-closed, not missing data.
- Every tenant table/model carries `site_id` + RLS; money is integer VND; time is `timestamptz`; enums are varchar+CHECK. Details: CLAUDE.md §2–§3.
