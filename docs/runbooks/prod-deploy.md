# Runbook — deploy the app to prod

Prod is a separate git checkout, `/opt/bswebsaas/prod` (branch `main`), on the same host as dev. It runs as compose project `bsweb-prod` (`docker-compose.prod.yml`), with containers `bsweb_prod_app`, `bsweb_prod_horizon`, `bsweb_prod_scheduler`, `bsweb_prod_pg`, `bsweb_prod_pgbouncer` and `bsweb_prod_valkey`. There is no CI deploy job — deploys are manual.

## Before

1. Merge to `origin/main` first, with **the commit's CI green**. Deploy a pinned SHA — `main` moves fast because parallel sessions push to it.
2. If another session may deploy too, tell it first so two deploys don't overlap.
3. Read the delta: `git -C /opt/bswebsaas/prod fetch origin && git -C /opt/bswebsaas/prod diff --stat HEAD <sha>`. Look for migrations, routes/middleware, config, events, Blade views and front-end assets.
4. Migrations in the delta → dump first:
   ```bash
   docker exec bsweb_prod_pg sh -c 'PGPASSWORD="$POSTGRES_PASSWORD" pg_dump --no-password -U "$POSTGRES_USER" bswebsaas' \
     | gzip -9 > /opt/bswebsaas/db-backups/prod-predeploy-<sha>-<timestamp>.sql.gz
   chmod 600 /opt/bswebsaas/db-backups/prod-predeploy-<sha>-<timestamp>.sql.gz
   ```
   Nightly backups and restore: `prod-db-backup-restore.md`.

## Deploy

1. `git -C /opt/bswebsaas/prod merge --ff-only <sha>`. Prod carries only untracked files, never commits; if it is not a fast-forward, stop.
2. Migrations: `docker exec bsweb_prod_app php artisan migrate --database=pgsql_admin --force`.
3. Front-end: in `/opt/bswebsaas/prod`, run `pnpm install --frozen-lockfile` (only if the lockfile changed), then `pnpm run build`.
   - `public/build` is gitignored, and Tailwind emits only the classes it saw at build time.
   - Skip this step and new screens ship with stale CSS — silently.
4. Caches: prod caches config, routes, views and events.
   - Blade changed → `docker exec bsweb_prod_app php artisan view:clear`.
   - Routes/middleware/config/events changed → `docker exec bsweb_prod_app php artisan optimize`.
   - `octane:reload` alone does NOT pick up a cached route or middleware change.
5. `docker exec bsweb_prod_app php artisan octane:reload`, then `docker restart bsweb_prod_horizon` (mail and jobs run on the queue).
   - From the merge until this reload, the old workers serve old PHP against the new files on disk (Blade compiles on demand; a class moved in the delta is gone). Run steps 2–5 back to back.
   - Commands that write data only the new code understands (a new field type, a new setting) run **after** the reload — never before — and the rollback must remove that data.
6. If a migration changed a column type on a table with prepared statements in flight, the logs may show "cached plan must not change result type". In that case run `docker restart bsweb_prod_pgbouncer` to drop the server connections.

## `.env` changes and the image

- The containers get `.env` through `env_file` **when they are created**. Laravel never overrides a variable the process already has, so after an `.env` edit `optimize` re-caches the old value (26/09: the Cloudflare token replaced in `.env` is still the old one inside `bsweb_prod_app`). Only a recreate (`docker compose up -d app horizon scheduler` in `/opt/bswebsaas/prod`) applies it — a few seconds of downtime.
- Before recreating, check the image: dev and prod both run the tag `bsweb/app:dev`, and a dev rebuild moves it. On 26/09 the running prod containers use builds that are no longer tagged, so a recreate would start prod on a different build. Compare `docker inspect bsweb_prod_app --format '{{.Image}}'` with `docker image inspect bsweb/app:dev --format '{{.Id}}'`; if they differ, compare what matters before recreating: `php -v`, `php -m` and `frankenphp version` inside both. On 27/09 the tagged build had the same PHP 8.5.7 and FrankenPHP 1.12.4 as prod, plus the `pcov` extension (coverage for the mutation gate; idle unless a coverage run starts it).
- PgBouncer is pinned by digest (1.25.2), not `:latest`. Changing that line changes the service's config, so the next `docker compose up -d` recreates the pgbouncer container once — a few seconds without database connections.
- Scripts that read `.env` themselves (the backup and the restore drill) see an edit at their next run.

## After

- Check from the host: `docker exec bsweb_prod_app curl -sI -H 'Host: <domain>' http://localhost:80/<path>`. Octane listens on :80.
- Errors go to the container's stderr: `docker logs --since 10m bsweb_prod_app`. `storage/logs/laravel.log` is not written.
- Rollback:
  1. `git -C /opt/bswebsaas/prod reset --hard <previous-sha>`.
  2. `pnpm run build` → `optimize` → `octane:reload` → restart horizon.
  3. Remove data the old code cannot render (whatever the deploy's post-reload commands added).
  4. If a migration broke data, restore the pre-deploy dump.
