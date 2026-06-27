#!/usr/bin/env bash
# backup-prod-db.sh — Offsite PostgreSQL backup to Cloudflare R2
#
# Usage: /opt/bswebsaas/dev/scripts/backup-prod-db.sh
# Cron:  15 3 * * * /opt/bswebsaas/dev/scripts/backup-prod-db.sh >> /var/log/anvinh-db-backup.log 2>&1
#
# Requires:
#   - Docker (bsweb_prod_pg container running)
#   - Node.js / npx (for wrangler)
#   - Python 3 (stdlib only — no boto3 required)
#   - /opt/bswebsaas/dev/.env with CLOUDFLARE_API_TOKEN, R2_* vars

set -euo pipefail

# ── Config ──────────────────────────────────────────────────────────────────
ENV_FILE="/opt/bswebsaas/dev/.env"
SCRIPTS_DIR="/opt/bswebsaas/dev/scripts"
PG_CONTAINER="bsweb_prod_pg"
# PRIVATE bucket — NOT baoson-media (that one is served publicly by the media Worker, so a dump
# there would be downloadable at files.baoson.net/db-backups/*). This bucket has no Worker/route.
R2_BUCKET_NAME="baoson-db-backups"
R2_PREFIX="db-backups"
SCRATCHPAD_BASE="/tmp/bsweb-db-backup"

# ── Timestamp & filename ─────────────────────────────────────────────────────
TIMESTAMP=$(date -u +%Y%m%d-%H%M%S)
FILENAME="db-bswebsaas-${TIMESTAMP}.sql.gz"

# ── Helpers ──────────────────────────────────────────────────────────────────
log() { echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] $*"; }
die() { log "ERROR: $*" >&2; exit 1; }

# ── Source .env (never echo values) ─────────────────────────────────────────
[[ -f "$ENV_FILE" ]] || die ".env not found at $ENV_FILE"
set -a
# shellcheck disable=SC1090
source "$ENV_FILE"
set +a

# Validate required vars are set (do NOT print values)
for var in CLOUDFLARE_API_TOKEN R2_ACCESS_KEY_ID R2_SECRET_ACCESS_KEY R2_BUCKET R2_ENDPOINT R2_REGION; do
    [[ -n "${!var:-}" ]] || die "Missing required env var: $var"
done

# ── Temp dir with guaranteed cleanup ─────────────────────────────────────────
TMPDIR=$(mktemp -d "${SCRATCHPAD_BASE}.XXXXXX")
TMPFILE="${TMPDIR}/${FILENAME}"

cleanup() {
    local exit_code=$?
    rm -rf "$TMPDIR"
    [[ $exit_code -eq 0 ]] && log "Temp files cleaned up." || log "Exiting with error $exit_code — temp files cleaned up."
}
trap cleanup EXIT

# ── Verify prod container is running ─────────────────────────────────────────
docker inspect --format='{{.State.Status}}' "$PG_CONTAINER" 2>/dev/null | grep -q running \
    || die "Container $PG_CONTAINER is not running"

# ── Dump: plain SQL piped through gzip ───────────────────────────────────────
log "Starting dump → ${FILENAME}"
docker exec "$PG_CONTAINER" sh -c \
    'PGPASSWORD="$POSTGRES_PASSWORD" pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" --no-password' \
    | gzip -9 > "$TMPFILE"

[[ -s "$TMPFILE" ]] || die "Dump file is empty — aborting upload"

# Guard against a truncated/partial dump silently overwriting a good backup slot. The current
# full dump is ~20KB gzip; a floor well below that but above any error-message gzip rejects a
# corrupt dump while staying valid as the DB grows.
MIN_BYTES=5000
ACTUAL_BYTES=$(stat -c%s "$TMPFILE")
[[ "$ACTUAL_BYTES" -ge "$MIN_BYTES" ]] || die "Dump suspiciously small (${ACTUAL_BYTES}B < ${MIN_BYTES}B) — aborting upload"

DUMP_SIZE=$(du -sh "$TMPFILE" | cut -f1)
log "Dump size: ${DUMP_SIZE} (${ACTUAL_BYTES}B)"

# ── Upload to R2 via wrangler ─────────────────────────────────────────────────
log "Uploading to R2: ${R2_BUCKET_NAME}/${R2_PREFIX}/${FILENAME}"

# wrangler picks up CLOUDFLARE_API_TOKEN from env automatically
CLOUDFLARE_API_TOKEN="$CLOUDFLARE_API_TOKEN" \
    npx --yes wrangler r2 object put \
    "${R2_BUCKET_NAME}/${R2_PREFIX}/${FILENAME}" \
    --file "$TMPFILE" \
    --remote

log "Upload complete: ${R2_BUCKET_NAME}/${R2_PREFIX}/${FILENAME}"

# ── Retention pruning ─────────────────────────────────────────────────────────
log "Running retention pruning..."
CLOUDFLARE_API_TOKEN="$CLOUDFLARE_API_TOKEN" \
R2_ACCESS_KEY_ID="$R2_ACCESS_KEY_ID" \
R2_SECRET_ACCESS_KEY="$R2_SECRET_ACCESS_KEY" \
R2_BUCKET="$R2_BUCKET_NAME" \
R2_ENDPOINT="$R2_ENDPOINT" \
R2_REGION="$R2_REGION" \
    python3 "${SCRIPTS_DIR}/r2_prune_backups.py" \
    || log "WARN: retention prune failed (non-fatal) — the dump uploaded fine; backups will accumulate until the bucket's lifecycle/S3-access is fixed"

log "Backup finished: ${FILENAME}"
