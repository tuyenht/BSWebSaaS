# Prod DB Backup & Restore Runbook

**Stack:** PostgreSQL 17 (`bsweb_prod_pg` container) → gzip → Cloudflare R2 (`baoson-db-backups/db-backups/` — PRIVATE bucket; never `baoson-media`, which is public via `files.baoson.net`)

**Design principle (R19):** an untested backup is not a backup. `scripts/restore-drill-prod-db.sh` restores the newest offsite backup into a throwaway database on the 1st of every month (§7.2, §9).

---

## 1. Mechanism

| Step | Detail |
|------|--------|
| Dump format | Plain SQL (`pg_dump --no-password`), piped through `gzip -9` |
| Filename | `db-bswebsaas-YYYYMMDD-HHMMSS.sql.gz` (UTC timestamp) |
| Destination | Cloudflare R2, bucket `baoson-db-backups` (PRIVATE — NOT `baoson-media`, which is publicly served), prefix `db-backups/` |
| Upload tool | `npx wrangler@<pinned> r2 object put … --remote` — the version is pinned in the script (`BACKUP_WRANGLER_PKG` overrides); an unpinned `npx wrangler` would install whatever npm serves the day its cache is empty, with the token in its environment |
| Retention | 7 daily + 4 weekly (via `r2_prune_backups.py`) |
| Secrets | `CLOUDFLARE_API_TOKEN`, `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY` — sourced from the `.env` of the checkout that holds the script — `/opt/bswebsaas/prod/.env` for the cron (gitignored). No secrets are echoed or logged. |

---

## 2. Files

```
/opt/bswebsaas/prod/scripts/          # the cron runs the PROD checkout's copy (reviewed + deployed code)
  backup-prod-db.sh          # nightly: dump + upload + prune
  r2_prune_backups.py        # retention logic + object listing (stdlib only, no boto3)
  restore-drill-prod-db.sh   # monthly: restore the newest backup into a throwaway DB and check it
```

The scripts find their `.env` next to their checkout, so the dev checkout's copy can be run by hand
(`BACKUP_ENV_FILE=/opt/bswebsaas/prod/.env …`) but the cron never depends on the dev tree, whose
branch changes with the work in progress.

---

## 3. Cron Installation

Install as root on the host (NOT inside Docker):

```bash
crontab -e
```

Add:
```
15 3 * * * /opt/bswebsaas/prod/scripts/backup-prod-db.sh >> /var/log/anvinh-db-backup.log 2>&1
45 4 1 * * /opt/bswebsaas/prod/scripts/restore-drill-prod-db.sh >> /var/log/bsweb-restore-drill.log 2>&1
```

- Cron uses host time, **+07**: the backup runs daily at 03:15 (20:15 UTC), the drill on the 1st
  of each month at 04:45, after that night's backup.
- Create the drill log once with `install -m 600 /dev/null /var/log/bsweb-restore-drill.log` — it
  names tables and row counts, never row contents.
- Log rotated manually or via `logrotate`; see §8.

---

## 4. Secret Handling

The backup script sources its checkout's `.env` at runtime with `set -a; source .env; set +a`.

Only one secret is needed at runtime:

| Variable | Purpose |
|----------|---------|
| `CLOUDFLARE_API_TOKEN` | R2 scope — used by wrangler (upload, delete) **and** the REST API (object listing in `r2_prune_backups.py`) |

`R2_ACCESS_KEY_ID` / `R2_SECRET_ACCESS_KEY` (S3-compatible API) are **no longer used** by backup or prune: the access key lacked List/Delete on `baoson-db-backups` (HTTP 403 → backups accumulated), so listing was moved to the REST API under `CLOUDFLARE_API_TOKEN`. `R2_ENDPOINT` is still read — the account id is parsed from it (or from `CLOUDFLARE_ACCOUNT_ID` if set).

The `.env` file is gitignored and stays on the host only. The cron job inherits no shell environment, so sourcing `.env` is the only way the script obtains credentials. `BACKUP_ENV_FILE` points the script at a different `.env`.

**The token carries an expiry date.** Before uploading, the script asks Cloudflare for the token's status
(`/user/tokens/verify`, then `/accounts/{id}/tokens/verify`). Not `active` ⇒ the run fails with the reason and the
expiry date; within 14 days of expiry ⇒ a `WARN` line (and a failed healthcheck, see §8). If the API does not
answer at all (network or Cloudflare outage) the status is `unreachable`: that says nothing about the token, so
the script logs a `WARN` and tries the upload anyway — a failed upload keeps the dump locally (§8). Renewing: Cloudflare
dashboard → My Profile → API Tokens → the backup token → edit its TTL (the value stays the same, `.env` untouched),
or create a new token with R2 edit rights on `baoson-db-backups` and replace the `.env` line.

---

## 5. Retention Policy

```
DAILY  : keep ALL backups newer than 7 days
WEEKLY : keep newest backup per ISO week for the 4 weeks before the daily window
PURGE  : delete everything else (> 35 days old, or extras within a week)
```

The Python script (`r2_prune_backups.py`) lists objects via the Cloudflare REST API (`GET /accounts/{account_id}/r2/buckets/{bucket}/objects`, Bearer `CLOUDFLARE_API_TOKEN`, stdlib only), computes the keep set, and calls `wrangler r2 object delete` for each stale object. (Listing previously used the S3 access key, which lacked List/Delete on the bucket → HTTP 403 and unbounded backup growth; moving it to the token fixed that. Manual fallback for a single object: `npx wrangler r2 object delete baoson-db-backups/db-backups/<file> --remote`.)

Dry-run (no deletions):
```bash
set -a && source /opt/bswebsaas/prod/.env && set +a
R2_BUCKET=baoson-db-backups python3 /opt/bswebsaas/prod/scripts/r2_prune_backups.py --dry-run
```

---

## 6. Manual Backup (on demand)

```bash
/opt/bswebsaas/prod/scripts/backup-prod-db.sh
```

The script is idempotent — safe to run multiple times (each produces a separate timestamped file).

---

## 7. Restore Procedure

### 7.1 Download a backup from R2

List available backups:
```bash
set -a && source /opt/bswebsaas/prod/.env && set +a
R2_BUCKET=baoson-db-backups python3 /opt/bswebsaas/prod/scripts/r2_prune_backups.py --dry-run
# lists all objects retained; pick the one you want
```

Download a specific backup:
```bash
set -a && source /opt/bswebsaas/prod/.env && set +a

BACKUP_FILE="db-bswebsaas-YYYYMMDD-HHMMSS.sql.gz"  # replace with actual filename
DEST="/tmp/${BACKUP_FILE}"

CLOUDFLARE_API_TOKEN="$CLOUDFLARE_API_TOKEN" \
  npx --yes wrangler@4.101.0 r2 object get \
  "baoson-db-backups/db-backups/${BACKUP_FILE}" \
  --file "$DEST" \
  --remote

ls -lh "$DEST"
```

Verify the download is a valid gzip:
```bash
file "$DEST"        # should say "gzip compressed data"
zcat "$DEST" | head -5  # should print SQL header
```

### 7.2 Restore into a scratch DB (validation / forensics)

The monthly drill does this end to end — download the newest backup, restore it with
`ON_ERROR_STOP` in one transaction into `restore_drill_<timestamp>`, compare tables, RLS policies,
forced-RLS tables and migrations with the live database (must match) plus sites/domains/users/entries
row counts (must be plausible), then drop it:

```bash
/opt/bswebsaas/prod/scripts/restore-drill-prod-db.sh                          # newest R2 backup
RESTORE_FROM_FILE=/opt/bswebsaas/db-backups/<file>.sql.gz \
  /opt/bswebsaas/prod/scripts/restore-drill-prod-db.sh                        # a local dump
```

By hand (forensics — keep the scratch DB to query it):

```bash
# Create scratch DB (uses prod container's superuser)
docker exec bsweb_prod_pg sh -c \
  'PGPASSWORD="$POSTGRES_PASSWORD" psql -U "$POSTGRES_USER" \
   -c "CREATE DATABASE restore_test;" postgres'

# Restore — ON_ERROR_STOP: without it psql reports success after a failed statement
zcat "$DEST" | docker exec -i bsweb_prod_pg sh -c \
  'PGPASSWORD="$POSTGRES_PASSWORD" psql -U "$POSTGRES_USER" -d restore_test --quiet -v ON_ERROR_STOP=1 --single-transaction'

# Spot-check with real counts (pg_stat_user_tables.n_live_tup stays 0 until the stats catch up)
docker exec bsweb_prod_pg sh -c \
  'PGPASSWORD="$POSTGRES_PASSWORD" psql -U "$POSTGRES_USER" -d restore_test -c "
  SELECT (SELECT count(*) FROM sites) AS sites, (SELECT count(*) FROM entries) AS entries,
         (SELECT count(*) FROM pg_policies) AS rls_policies;"'

# Drop when done
docker exec bsweb_prod_pg sh -c \
  'PGPASSWORD="$POSTGRES_PASSWORD" psql -U "$POSTGRES_USER" \
   -c "DROP DATABASE restore_test;" postgres'
```

### 7.3 Restore over prod (disaster recovery)

> **Warning:** this replaces all prod data. Confirm the backup is good first (§7.2).

```bash
cd /opt/bswebsaas/prod   # compose reads docker-compose.prod.yml via COMPOSE_FILE in this .env

# 1. Stop everything that writes
docker compose -p bsweb-prod stop app horizon scheduler

# 2. Terminate active connections to prod DB
docker exec bsweb_prod_pg sh -c '
  PGPASSWORD="$POSTGRES_PASSWORD" psql -U "$POSTGRES_USER" -d postgres -c "
  SELECT pg_terminate_backend(pid)
  FROM pg_stat_activity
  WHERE datname = '"'"'bswebsaas'"'"' AND pid <> pg_backend_pid();"'

# 3. Drop + recreate prod DB (uses pgsql_admin per CLAUDE.md §7)
docker exec bsweb_prod_pg sh -c '
  PGPASSWORD="$POSTGRES_PASSWORD" psql -U "$POSTGRES_USER" -d postgres \
  -c "DROP DATABASE bswebsaas;" \
  -c "CREATE DATABASE bswebsaas;"'

# 4. Restore (stops at the first error; one transaction, so a failure leaves an empty DB, not half a one)
zcat "$DEST" | docker exec -i bsweb_prod_pg sh -c \
  'PGPASSWORD="$POSTGRES_PASSWORD" psql -U "$POSTGRES_USER" -d bswebsaas --quiet -v ON_ERROR_STOP=1 --single-transaction'

# 5. Re-apply RLS policies (migration role — see CLAUDE.md §7)
docker compose -p bsweb-prod run --rm --no-deps app \
  php artisan migrate --database=pgsql_admin --force

# 6. Restart services
docker compose -p bsweb-prod start app horizon scheduler

# 7. Verify — as the superuser: the app role sees 0 rows without a tenant context (RLS fail-closed),
#    so a tinker count would read 0 even after a good restore
docker exec bsweb_prod_pg sh -c \
  'PGPASSWORD="$POSTGRES_PASSWORD" psql -U "$POSTGRES_USER" -d bswebsaas -c "SELECT count(*) AS entries FROM entries;"'
docker exec bsweb_prod_app curl -s -o /dev/null -w '%{http_code}\n' -H 'Host: <domain>' http://localhost:80/
```

---

## 8. Monitoring & Alerting

| Mechanism | Behaviour |
|-----------|-----------|
| Healthcheck (dead-man switch) | Set `BACKUP_HEALTHCHECK_URL` (healthchecks.io-style check URL, schedule daily 03:15 +07, grace ~2h) in the `.env`. The script pings `/start`, the bare URL on success, `/fail` on any error — and `/fail` after a successful upload when the token expires within 14 days. A night that never pings (cron not firing, host down) alerts through the check's own schedule. Unset = no pings. |
| Local fallback | When the upload cannot happen (token not active, wrangler error) the dump is kept as `not-uploaded-*.sql.gz` in `BACKUP_LOCAL_FALLBACK_DIR` (default `/opt/bswebsaas/db-backups`, mode 600) — upload it by hand once the token works. |
| Size floor | The dump must be ≥ 5000 bytes before upload (catches empty/partial dumps). |
| Restore drill | `restore-drill-prod-db.sh` monthly (§3). Fails when the restore errors, when tables / RLS policies / forced-RLS tables / migrations differ from live, when no site or domain came back, or when the newest backup is older than 36 h (`RESTORE_MAX_AGE_HOURS`) — so a month of silently missing backups is caught even without the nightly check. Set `RESTORE_HEALTHCHECK_URL` (a second check, monthly schedule, grace ~1 day) to get the result by alert. Errors print psql's one-line message only (`VERBOSITY=terse`), never the failing row. |

Still open:

| Gap | Suggested fix |
|-----|---------------|
| Log rotation | Add `/etc/logrotate.d/anvinh-db-backup` (daily, 30d, compress) |
| No offsite replication of `.env` | Store `CLOUDFLARE_API_TOKEN` in a secrets manager or encrypted vault |

---

## 9. Test Log

### 2026-09-26 — restore drill script

```
dev cluster, a dev dump:        tables=62/62 rls_policies=36/36 rls_forced_tables=36/36 migrations=40/40
                                sites=2/2 domains=2/2 users=32/32 entries=1/1 → passed, drill DB dropped
dev cluster, a truncated dump:  ERROR: missing data for column "failed_at" → failed (exit 1), drill DB dropped
```

First prod drill (newest R2 backup on `bsweb_prod_pg`): pending — run by the owner, then record the
summary line here.

### 2026-06-27

```
[2026-06-27T10:06:52Z] pg_dump via docker exec bsweb_prod_pg → 19 KB gzip (152 KB raw SQL)
[2026-06-27T10:06:52Z] wrangler upload → baoson-media/db-backups/db-bswebsaas-20260627-100652.sql.gz — Upload complete
```

> NOTE: this 06-27 test uploaded to the PUBLIC `baoson-media` bucket — that object was the CRITICAL exposure, since PURGED (HTTP 404 @ 28/06); the corrected procedure (private `baoson-db-backups` + token-prune, §1/§4/§5) should be re-tested end-to-end.

```
[2026-06-27T10:07:xx Z] r2_prune_backups.py --dry-run → Found 1 object, Keeping 1, Deleting 0
[2026-06-27T10:08:xx Z] wrangler download → restore-test.sql.gz (19 KB) — Download complete
[2026-06-27T10:08:xx Z] zcat | psql into restore_test — Exit 0
[2026-06-27T10:08:xx Z] pg_stat_user_tables spot-check:
  role_has_permissions=111, permissions=32, routes=30,
  entries=26, entry_translations=26, migrations=22 (15 tables total)
[2026-06-27T10:08:xx Z] DROP DATABASE restore_test — clean
```
