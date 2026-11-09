# Runbook — deploy the app to prod

Prod is a separate git checkout, `/opt/bswebsaas/prod` (branch `main`), on the same host as dev. It runs as compose project `bsweb-prod` (`docker-compose.prod.yml`), with containers `bsweb_prod_app`, `bsweb_prod_horizon`, `bsweb_prod_scheduler`, `bsweb_prod_pg`, `bsweb_prod_pgbouncer` and `bsweb_prod_valkey`. There is no CI deploy job — deploys are manual.

The steps below run through one shared engine, `scripts/deploy/deploy.sh`. Each deploy adds only a small
release file, and the owner starts the run with a single tmux line that pins the engine and the release file
by hash. How to write a release file, print the plan and run the tests: `scripts/deploy/README.md`. This
runbook stays the reference for what each step does and why.

## Before

1. Merge to `origin/main` first. Deploy a pinned SHA — `main` moves fast because parallel sessions push to it — and only one with **a full CI run green on that exact commit**.
   - A merge starts no run. A pull request's own run does not count: it ran on the branch's commits (a rebase merge gives `main` new SHAs) and may have skipped jobs. Full runs are the nightly one (`schedule`, 02:30 Vietnam time, on the head of `main` at that time) and the ones started by hand (`workflow_dispatch`).
   - Check the exact commit:
     ```bash
     gh run list --workflow ci.yml --commit <full sha> --json event,status,conclusion,url
     ```
     It must list at least one run with `event` `schedule` or `workflow_dispatch` — or `push`, for a commit from before 10/2026, when every merge still started a full run on `main` — and every run listed must be `completed` + `success`. An empty list, or a run that failed, was cancelled or is still going, means not deployable yet.
   - No run, and the commit is the head of `main` — start one and wait for it:
     ```bash
     gh workflow run ci.yml --ref main
     gh run list --workflow ci.yml --event workflow_dispatch --limit 1 --json databaseId,headSha,status
     gh run watch <databaseId> --exit-status
     ```
     `headSha` must be the SHA you mean to deploy. If `main` moved in between, the run is on the newer head: deploy that one, or use the next step.
   - No run, and the commit is behind the head of `main` — deploy the head instead when you can. Otherwise run CI on a throwaway branch that points at the commit, then remove it (`gh workflow run` takes a branch or a tag, not a SHA):
     ```bash
     git push origin <full sha>:refs/heads/ci-check-<short sha>
     gh workflow run ci.yml --ref ci-check-<short sha>
     gh run list --workflow ci.yml --commit <full sha> --json databaseId,event,status,conclusion
     gh run watch <databaseId> --exit-status
     git push origin --delete ci-check-<short sha>
     ```
     This needs a commit whose own `ci.yml` has the manual trigger (any commit from 10/2026 on).
   - A full run takes about ten minutes. Start it before the other steps here, not after.
2. If another session may deploy too, tell it first so two deploys don't overlap.
3. Read the delta: `git -C /opt/bswebsaas/prod fetch origin && git -C /opt/bswebsaas/prod diff --stat HEAD <sha>`. Look for migrations, routes/middleware, config, events, Blade views and front-end assets.
4. Migrations in the delta → dump first:
   ```bash
   docker exec bsweb_prod_pg sh -c 'PGPASSWORD="$POSTGRES_PASSWORD" pg_dump --no-password -U "$POSTGRES_USER" bswebsaas' \
     | gzip -9 > /opt/bswebsaas/db-backups/prod-predeploy-<sha>-<timestamp>.sql.gz
   chmod 600 /opt/bswebsaas/db-backups/prod-predeploy-<sha>-<timestamp>.sql.gz
   ```
   Nightly backups and restore: `prod-db-backup-restore.md`.
5. A migration that makes a column NOT NULL fails while a row still holds NULL. It runs outside a transaction, so it may leave its unvalidated check constraint behind; that is harmless, and the next run reuses it. Count those rows on prod before step 2 of the deploy; any above 0 stops the deploy. Read-only, for example `audit_logs.created_at`:
   ```bash
   docker exec bsweb_prod_pg sh -c 'psql -U "$POSTGRES_USER" -d bswebsaas -tAc "SELECT count(*) FROM audit_logs WHERE created_at IS NULL"'
   ```
6. The migration that splits `audit_logs` by month (`2046_01_01_000000`) copies every row and refuses rows dated more than 10 years back or 1 year ahead. Before step 2, record the row count and the oldest and newest `created_at` (read-only), and compare the count after:
   ```bash
   docker exec bsweb_prod_pg sh -c 'psql -U "$POSTGRES_USER" -d bswebsaas -tAc "SELECT count(*), min(created_at), max(created_at) FROM audit_logs"'
   ```

## Deploy

1. `git -C /opt/bswebsaas/prod merge --ff-only <sha>`. Prod carries only untracked files, never commits; if it is not a fast-forward, stop.
   - `composer.lock` in the delta → `docker exec bsweb_prod_app composer install --no-dev --optimize-autoloader --no-interaction`, then `docker exec bsweb_prod_app php artisan --version` shows the locked framework version. The checkout is mounted at `/app`, so its packages live there, not in the image; prod has no dev packages (30/10: `composer show` lists no `pestphp/pest`). Skip it and the new code runs on the old packages until the next install.
2. Migrations, from `/opt/bswebsaas/prod`: `docker compose --profile ops run --rm --no-deps migrate`.
   - This one-off container is the only app process that receives the superuser password (from `/opt/bswebsaas/secrets/db-admin.env`); the app, the queue and the scheduler never hold it.
   - Any other superuser-only artisan command runs the same way: `docker compose --profile ops run --rm --no-deps migrate php artisan <command> --database=pgsql_admin`.
   - After the migration that splits `audit_logs` by month (Before step 6): compare the row count, restart PgBouncer (`docker restart bsweb_prod_pgbouncer`; its pooled server connections still hold prepared statements planned for the old table), then run `docker exec bsweb_prod_app php artisan audit:months` once; it must report at least 2 months ahead.
   - A migration that builds an index CONCURRENTLY waits for every open transaction to finish. If the step seems stuck, look for a session left idle in a transaction (`SELECT pid, state, xact_start FROM pg_stat_activity WHERE state LIKE 'idle in transaction%'`) before anything else; the build carries on by itself once that session ends.
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
   - Idempotent data commands, safe to run on every deploy: `forms:sync-contact-fields`, `cms:rebuild-taxonomy-paths`, `cms:snapshot-published` (a baseline revision for each public post/page with none — needed once after `entry_revisions` first lands).
6. A migration that adds, drops or retypes a column changes what `select *` on that table returns. PgBouncer keeps prepared statements per server connection (`MAX_PREPARED_STATEMENTS`), so an old plan fails with "cached plan must not change result type" until that connection closes (08/07: the scheduler failed every minute for 10 minutes).
   - After such a migration, run `docker restart bsweb_prod_pgbouncer` right after step 5 — a few seconds without database connections.
   - The same applies to the shared dev database: `docker compose restart pgbouncer` after migrating from a worktree, before running tests.
7. The delta changes `PermissionRegistry.php` (a new permission or role grant) → `docker exec bsweb_prod_app php artisan rbac:sync` right after the migrations. It is idempotent. Without it, a new permission does not exist on prod and every check for it is denied.
8. The delta narrows what formatted text may hold or patches the sanitizer (`RichTextSanitizer.php`, or `ezyang/htmlpurifier` in `composer.lock`) → after step 5, `docker exec bsweb_prod_app php artisan cms:resanitize-bodies --dry-run` (prints per site how many bodies would change), then the same without `--dry-run`. Add `--site=<hostname>` for one site. It cleans stored page and post bodies and pending changes through the same cleaning every save uses, keeps their edit times and records no revision; it is idempotent (a second run changes 0) and keeps an editor's save made while it runs. A row it cannot clean is named (site, column, row id) and skipped; the others are still cleaned and the command ends with an error, so look at those rows and run it again. Then `docker exec bsweb_prod_app php artisan media:reconcile-content-usage`, so a picture the cleaning removed is no longer listed as used. Without it, the old markup stays stored until each body's next save (the storefront still checks it before printing).
9. The delta adds the product page's quote button (the `catalog` / `contact_page` setting) → after step 5, `docker exec bsweb_prod_app php artisan site:link-contact-page` once; add `--site=<id>` for one site. It records each existing site's contact page (the published page whose path in the default language, `vi` if none is set, is `/lien-he`) and relinks a setting whose page is gone; it never replaces one that points at a live page, and a second run changes nothing. Without it the button is missing on every site made before the deploy. A site whose contact page was renamed gets no link and needs the page chosen by hand.

### The deploy that carries migration 2054 (the product search words)

From this deploy on, the admin products list searches the word table `product_search_words`, and a product with no rows there is not found. The migration creates the table empty, and there is no switch that keeps the old search on, so the table is filled right after the code switch and before the reload. Prod mounts the checkout at `/app` and starts Octane with `--max-requests=500`: a worker that has served 500 requests restarts and loads whatever code is on disk, so from the merge on some workers already run the new code (Deploy step 5). Run these steps in this order, back to back:

1. Migrate 2054 BEFORE the code switch, while the checkout is still at the old commit (as the deploy scripts do: the target's migration file goes into a folder mounted into the `migrate` one-off container).
   ```bash
   mkdir -m 700 /tmp/mig-2054
   git -C /opt/bswebsaas/prod fetch origin
   git -C /opt/bswebsaas/prod show <sha>:database/migrations/2054_01_01_000000_create_product_search_words_table.php \
     > /tmp/mig-2054/2054_01_01_000000_create_product_search_words_table.php
   ( cd /opt/bswebsaas/prod && docker compose --profile ops run --rm --no-deps -v /tmp/mig-2054:/deploy-migrations:ro \
     migrate php artisan migrate --database=pgsql_admin --force --path=/deploy-migrations --realpath )
   rm -r /tmp/mig-2054
   ```
   - The old code never reads the table, and no existing column changes, so this step needs no PgBouncer restart.
   - Adding the foreign keys briefly locks `sites` and `products` against writes; the migration gives up after 5 s (`lock_timeout`) if a long transaction holds them. Run it again.
   - Laravel records the file under its name, so Deploy step 2 after the merge finds it done.
   - From here a product saved by a worker on the new code (after the merge below) gets its words; one saved by a worker on the old code does not (step 4 heals it).
2. Switch the code: Deploy step 1 (merge, `composer install`), step 2 (any other migration; 2054 is done), step 3 (front-end build). The image stays `bsweb/app:prod` and is not rebuilt.
3. Fill the table at once, after `optimize` and BEFORE `octane:reload`: Deploy step 4 (`view:clear`, `optimize`), then `docker exec bsweb_prod_app php artisan catalog:search-words`. A fresh `artisan` process loads the new code and the new cached config from disk; the running workers keep serving while it runs. Its writes are harmless to the old code, which ignores the table.
   - It works site by site, as the app user under each site's tenant context, and checks every product (the Trash too).
   - It must exit 0 and print `Checked N product(s) on M site(s); corrected K.`, where N is the number of products on prod and K the number of those with a name or a SKU. A site that fails is named, and the command exits 1; stop and look at it before the reload.
   - Measured: 2.4 ms a product (200,000 products in 485 s). A re-run must print `corrected 0.`
4. Reload: Deploy step 5 (`octane:reload`, restart horizon). From here every worker searches the words. Then heal the products saved by workers on the old code between step 1 and the reload, which got no words: `docker exec bsweb_prod_app php artisan catalog:search-words --recent=500` (they are among the 500 edited last). The nightly `catalog:search-words --recent=500 --rolling=10000` (20:45 UTC) does the same if this is skipped.

The short window: from the merge in step 2 until the fill in step 3 ends, a worker that has restarted after its 500 requests already runs the new search, so the admin products search on it may show "nothing found" for products whose words are not written yet. It lasts about the build plus the fill (2.4 ms a product); no product is lost or changed, and the storefront is not affected.

Rollback: the old code ignores the table, so the table can stay. `down()` drops it, and the source data is not touched.

## `.env` changes and the image

- The containers get `.env` through `env_file` **when they are created**. Laravel never overrides a variable the process already has, so after an `.env` edit `optimize` re-caches the old value (26/09: the Cloudflare token replaced in `.env` is still the old one inside `bsweb_prod_app`). Only a recreate (`docker compose up -d app horizon scheduler` in `/opt/bswebsaas/prod`) applies it — a few seconds of downtime.
- The app containers run their own tag, `bsweb/app:prod` (since 27/09 it points at the same build as `bsweb/app:dev`, `e00aba…`: PHP 8.5.7, FrankenPHP 1.12.4, plus the idle `pcov`). A dev rebuild moves only `bsweb/app:dev`. The tag must exist on the host before this compose file is used: `docker image inspect bsweb/app:prod`.
- Changing the prod runtime is a deliberate step, never a side effect: build from the prod checkout (`docker build -f docker/frankenphp/Dockerfile -t bsweb/app:prod-next .`), compare `php -v`, `php -m` and `frankenphp version` with the running app, run the test suite in it, then `docker tag bsweb/app:prod-next bsweb/app:prod` and recreate at a quiet hour. Keep the old image under another tag until the new one has run for a day.
- The first `docker compose up -d` after the switch to `bsweb/app:prod` recreates app, horizon and scheduler (a few seconds of 502) — which is also what applies an edited `.env`.
- PgBouncer is pinned by digest (1.25.2), not `:latest`. Changing that line changes the service's config, so the next `docker compose up -d` recreates the pgbouncer container once — a few seconds without database connections.
- Scripts that read `.env` themselves (the backup and the restore drill) see an edit at their next run.
- The superuser password is **not** in `.env`. It lives in `/opt/bswebsaas/secrets/db-admin.env` (`DB_ADMIN_PASSWORD` and `POSTGRES_PASSWORD`, the same value; root only), read by the `postgres` and `migrate` services alone.
  - `DB_ADMIN_USERNAME` stays in `.env`: the postgres service, its healthcheck and every `psql -U "$POSTGRES_USER"` in the backup scripts need it.
  - A production app process that loads the password anyway logs a critical line on boot: `docker logs --since 10m bsweb_prod_app 2>&1 | grep -c 'superuser database password'` should print 0.
  - Postgres reads the file only to initialise an empty data directory, so a host without it keeps running; only `migrate` fails (it cannot sign in).

### Moving the superuser password out of the app (once per host)

Until this is done, `.env` holds `DB_ADMIN_PASSWORD` and the app containers can read it.

1. Before pulling the commit that adds the `migrate` service: create the secret file and give the superuser a new password (the old one has been inside the app containers, so moving it without changing it keeps any past leak valid).
   ```bash
   install -d -m 700 /opt/bswebsaas/secrets
   export NEWPW="$(openssl rand -hex 32)"
   ( umask 077 && printf 'DB_ADMIN_PASSWORD=%s\nPOSTGRES_PASSWORD=%s\n' "$NEWPW" "$NEWPW" > /opt/bswebsaas/secrets/db-admin.env )
   printf '%s\n' "SET log_statement = 'none';" "SET log_min_error_statement = 'panic';" \
     '\set pw `printenv NEWPW`' "ALTER ROLE CURRENT_USER PASSWORD :'pw';" \
     | docker exec -i -e NEWPW bsweb_prod_pg sh -c 'psql -U "$POSTGRES_USER" -d postgres -X -q -v ON_ERROR_STOP=1'
   unset NEWPW
   ```
   If `psql` prints an error, the password was not changed and the file holds one the database never received: `rm /opt/bswebsaas/secrets/db-admin.env` and stop.
   The password never appears in a process list, on screen or in the Postgres log (the two `SET` lines keep this one statement out of it). Nothing running uses the superuser over the network, so the site is unaffected; the backup scripts sign in over the container's local socket.
2. Run Deploy steps 1–3 only (merge, migrations through the `migrate` service, front-end build); steps 4–5 wait for step 4 here. Then remove the line from `.env` (`sed -i '/^DB_ADMIN_PASSWORD=/d' /opt/bswebsaas/prod/.env`) and drop the config cache built with it: `docker exec bsweb_prod_app php artisan config:clear`. Without that, the containers recreated in step 3 boot from a cache that still holds the old password.
3. `docker compose up -d` in `/opt/bswebsaas/prod`: recreates `postgres` (it now reads the secret file) and app, horizon and scheduler (they now start without the password). A few seconds without database connections.
4. Only now: `docker exec bsweb_prod_app php artisan optimize`, `docker exec bsweb_prod_app php artisan octane:reload`, `docker restart bsweb_prod_horizon bsweb_prod_scheduler`. Running `optimize` before step 3 would copy the password back into the config cache: the old containers still carry it in their environment. Then Deploy steps 6–7 if the delta calls for them.
5. Prove the superuser still works where it should: `docker compose --profile ops run --rm --no-deps migrate php artisan migrate:status --database=pgsql_admin`.
6. Run the check under "After", then a restore drill (`prod-db-backup-restore.md`) to confirm the backups still sign in. Record the new secret file in `docs/ops-log.md`.

Rollback (any step): `sed -i '/^DB_ADMIN_PASSWORD=/d' /opt/bswebsaas/prod/.env && printf '\n' >> /opt/bswebsaas/prod/.env && grep '^DB_ADMIN_PASSWORD=' /opt/bswebsaas/secrets/db-admin.env >> /opt/bswebsaas/prod/.env`, check out the previous commit, `docker compose up -d`, then `optimize` → `octane:reload` → `docker restart bsweb_prod_horizon bsweb_prod_scheduler` (the cache from step 4 has no password, so the old in-container migrate command would fail to sign in). Use the NEW password: the old one no longer works.

## After

- Check from the host: `docker exec bsweb_prod_app curl -sI -H 'Host: <domain>' http://localhost:80/<path>`. Octane listens on :80.
- Errors go to the container's stderr: `docker logs --since 10m bsweb_prod_app`. `storage/logs/laravel.log` is not written.
- No app process holds the superuser password: in the process environment, `/app/.env` or the config cache. The check prints only SET or nothing, never a value; expect `env= dotenv-lines=0` and `config-cache=unset` for each container:
  ```bash
  for c in bsweb_prod_app bsweb_prod_horizon bsweb_prod_scheduler; do
    printf '%s: ' "$c"
    docker exec "$c" sh -c 'echo "env=${DB_ADMIN_PASSWORD:+SET} dotenv-lines=$(grep -c "^DB_ADMIN_PASSWORD=." /app/.env)"'
    docker exec "$c" php -r '$c = @include "/app/bootstrap/cache/config.php"; echo "config-cache=", empty($c["database"]["connections"]["pgsql_admin"]["password"]) ? "unset" : "SET", PHP_EOL;'
  done
  ```
- Rollback:
  1. `git -C /opt/bswebsaas/prod reset --hard <previous-sha>`.
  2. `pnpm run build` → `optimize` → `octane:reload` → restart horizon.
  3. Remove data the old code cannot render (whatever the deploy's post-reload commands added).
  4. If a migration broke data, restore the pre-deploy dump.

## Loading a site's content pack

`site:load-content` writes a pack from `database/content-packs/<pack>/` into one site. It runs as the app user inside that site's tenant context, never as the superuser. It never deletes anything, and it skips what staff changed since the last load unless an item is named with `--force-item=<key>`. The owner runs every step.

1. Take the pre-deploy dump (Before, step 4), even when the delta has no migration.
2. Dry run, which writes nothing: `docker exec bsweb_prod_app php artisan site:load-content an-vinh --host=av1.baoson.net -v`. Read the unpublish notes, the `skip:*` lines and the warnings. Any `conflict` line stops the load: the command exits 1 and refuses to apply. This output is what you read before `--apply`. On the first load into a site whose products the loader did not write, every product shows `skip:edited`, because catalog edits leave no history. Check each one, then name it with `--force-item=product:<slug>` in both the dry run and the apply.
3. Apply exactly that plan: `docker exec bsweb_prod_app php artisan site:load-content an-vinh --host=av1.baoson.net --apply --plan=<fingerprint>`. If anything changed after the dry run (a staff edit, a pack change, or a post or product filed under a category the load renames while it runs), the command refuses with "plan changed" and writes nothing. Run step 2 again.
4. Before writing, the apply saves a backup and prints its path (`Backup: …`). The file holds every row it is about to change, plus the previous ledger. Prod mounts the checkout, so it is on the host under `/opt/bswebsaas/prod/storage/app/private/content-pack-backups/<site-ulid>/`, mode 0600. Keep it with the dump.
5. Resubmit the sitemap in Search Console.
6. `--claims` prints every unverified claim the pack holds. Those items load as drafts until the facts are confirmed.
