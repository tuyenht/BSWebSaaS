# Prod DB Backup & Restore Runbook

**Stack:** PostgreSQL 17 (`bsweb_prod_pg` container) → gzip → Cloudflare R2 (`baoson-db-backups/db-backups/` — PRIVATE bucket; never `baoson-media`, which is public via `files.baoson.net`)

**Design principle (R19):** an untested backup is not a backup. This procedure was tested end-to-end on 2026-06-27 (on the since-corrected mechanism; re-test pending).

---

## 1. Mechanism

| Step | Detail |
|------|--------|
| Dump format | Plain SQL (`pg_dump --no-password`), piped through `gzip -9` |
| Filename | `db-bswebsaas-YYYYMMDD-HHMMSS.sql.gz` (UTC timestamp) |
| Destination | Cloudflare R2, bucket `baoson-db-backups` (PRIVATE — NOT `baoson-media`, which is publicly served), prefix `db-backups/` |
| Upload tool | `npx wrangler r2 object put … --remote` |
| Retention | 7 daily + 4 weekly (via `r2_prune_backups.py`) |
| Secrets | `CLOUDFLARE_API_TOKEN`, `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY` — sourced from `/opt/bswebsaas/dev/.env` (gitignored). No secrets are echoed or logged. |

---

## 2. Files

```
/opt/bswebsaas/dev/scripts/
  backup-prod-db.sh      # host cron script — orchestrates dump + upload + prune
  r2_prune_backups.py    # retention logic (stdlib only, no boto3)
```

---

## 3. Cron Installation

Install as root on the host (NOT inside Docker):

```bash
crontab -e
```

Add:
```
15 3 * * * /opt/bswebsaas/dev/scripts/backup-prod-db.sh >> /var/log/anvinh-db-backup.log 2>&1
```

- Runs daily at **03:15 UTC** (quiet traffic window).
- Log rotated manually or via `logrotate`; see §7.

---

## 4. Secret Handling

The backup script sources `/opt/bswebsaas/dev/.env` at runtime with `set -a; source .env; set +a`.

Only one secret is needed at runtime:

| Variable | Purpose |
|----------|---------|
| `CLOUDFLARE_API_TOKEN` | R2 scope — used by wrangler (upload, delete) **and** the REST API (object listing in `r2_prune_backups.py`) |

`R2_ACCESS_KEY_ID` / `R2_SECRET_ACCESS_KEY` (S3-compatible API) are **no longer used** by backup or prune: the access key lacked List/Delete on `baoson-db-backups` (HTTP 403 → backups accumulated), so listing was moved to the REST API under `CLOUDFLARE_API_TOKEN`. `R2_ENDPOINT` is still read — the account id is parsed from it (or from `CLOUDFLARE_ACCOUNT_ID` if set).

The `.env` file is gitignored and stays on the host only. The cron job inherits no shell environment, so sourcing `.env` is the only way the script obtains credentials. `BACKUP_ENV_FILE` points the script at a different `.env`.

**The token carries an expiry date.** Before uploading, the script asks Cloudflare for the token's status
(`/user/tokens/verify`, then `/accounts/{id}/tokens/verify`). Not `active` ⇒ the run fails with the reason and the
expiry date; within 14 days of expiry ⇒ a `WARN` line (and a failed healthcheck, see §8). Renewing: Cloudflare
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
set -a && source /opt/bswebsaas/dev/.env && set +a
python3 /opt/bswebsaas/dev/scripts/r2_prune_backups.py --dry-run
```

---

## 6. Manual Backup (on demand)

```bash
/opt/bswebsaas/dev/scripts/backup-prod-db.sh
```

The script is idempotent — safe to run multiple times (each produces a separate timestamped file).

---

## 7. Restore Procedure

### 7.1 Download a backup from R2

List available backups:
```bash
set -a && source /opt/bswebsaas/dev/.env && set +a
python3 /opt/bswebsaas/dev/scripts/r2_prune_backups.py --dry-run
# lists all objects retained; pick the one you want
```

Download a specific backup:
```bash
set -a && source /opt/bswebsaas/dev/.env && set +a

BACKUP_FILE="db-bswebsaas-YYYYMMDD-HHMMSS.sql.gz"  # replace with actual filename
DEST="/tmp/${BACKUP_FILE}"

CLOUDFLARE_API_TOKEN="$CLOUDFLARE_API_TOKEN" \
  npx --yes wrangler r2 object get \
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

```bash
# Create scratch DB (uses prod container's superuser)
docker exec bsweb_prod_pg sh -c \
  'PGPASSWORD="$POSTGRES_PASSWORD" psql -U "$POSTGRES_USER" \
   -c "CREATE DATABASE restore_test;" postgres'

# Restore
zcat "$DEST" | docker exec -i bsweb_prod_pg sh -c \
  'PGPASSWORD="$POSTGRES_PASSWORD" psql -U "$POSTGRES_USER" -d restore_test --quiet'

# Spot-check
docker exec bsweb_prod_pg sh -c \
  'PGPASSWORD="$POSTGRES_PASSWORD" psql -U "$POSTGRES_USER" -d restore_test -c "
  SELECT relname, n_live_tup FROM pg_stat_user_tables
  ORDER BY n_live_tup DESC LIMIT 15;"'

# Drop when done
docker exec bsweb_prod_pg sh -c \
  'PGPASSWORD="$POSTGRES_PASSWORD" psql -U "$POSTGRES_USER" \
   -c "DROP DATABASE restore_test;" postgres'
```

### 7.3 Restore over prod (disaster recovery)

> **Warning:** this replaces all prod data. Confirm the backup is good first (§7.2).

```bash
# 1. Stop app + horizon to prevent writes
docker compose -p bsweb-prod stop app horizon

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

# 4. Restore
zcat "$DEST" | docker exec -i bsweb_prod_pg sh -c \
  'PGPASSWORD="$POSTGRES_PASSWORD" psql -U "$POSTGRES_USER" -d bswebsaas --quiet'

# 5. Re-apply RLS policies (migration role — see CLAUDE.md §7)
docker compose -p bsweb-prod run --rm --no-deps app \
  php artisan migrate --database=pgsql_admin --force

# 6. Restart services
docker compose -p bsweb-prod start app horizon

# 7. Verify
docker compose -p bsweb-prod exec app php artisan tinker --execute="echo App\Modules\Cms\Models\Entry::count();"
```

---

## 8. Monitoring & Alerting

| Mechanism | Behaviour |
|-----------|-----------|
| Healthcheck (dead-man switch) | Set `BACKUP_HEALTHCHECK_URL` (healthchecks.io-style check URL, schedule daily 03:15 +07, grace ~2h) in the `.env`. The script pings `/start`, the bare URL on success, `/fail` on any error — and `/fail` after a successful upload when the token expires within 14 days. A night that never pings (cron not firing, host down) alerts through the check's own schedule. Unset = no pings. |
| Local fallback | When the upload cannot happen (token not active, wrangler error) the dump is kept as `not-uploaded-*.sql.gz` in `BACKUP_LOCAL_FALLBACK_DIR` (default `/opt/bswebsaas/db-backups`, mode 600) — upload it by hand once the token works. |
| Size floor | The dump must be ≥ 5000 bytes before upload (catches empty/partial dumps). |

Still open:

| Gap | Suggested fix |
|-----|---------------|
| Log rotation | Add `/etc/logrotate.d/anvinh-db-backup` (daily, 30d, compress) |
| No offsite replication of `.env` | Store `CLOUDFLARE_API_TOKEN` in a secrets manager or encrypted vault |

---

## 9. Test Log (2026-06-27)

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
