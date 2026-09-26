#!/usr/bin/env bash
# backup-prod-db.sh — Offsite PostgreSQL backup to Cloudflare R2
#
# Usage: <checkout>/scripts/backup-prod-db.sh
# Cron:  15 3 * * * /opt/bswebsaas/prod/scripts/backup-prod-db.sh >> /var/log/anvinh-db-backup.log 2>&1
#        (host time, +07 — run from the PROD checkout, so prod backups run prod's reviewed copy)
#
# Optional env (process env or the .env file):
#   BACKUP_ENV_FILE            .env to source (default: the .env of the checkout holding this script)
#   BACKUP_WRANGLER_PKG        wrangler package spec for npx (default: the pinned version below)
#   BACKUP_HEALTHCHECK_URL     healthchecks.io-style check URL: pinged /start, on success, and /fail —
#                              a run that never reports success (cron not firing, host down) alerts too
#   BACKUP_LOCAL_FALLBACK_DIR  where a dump is kept when the offsite upload cannot happen
#                              (default /opt/bswebsaas/db-backups)
#
# Requires:
#   - Docker (bsweb_prod_pg container running)
#   - Node.js / npx (for wrangler)
#   - Python 3 (stdlib only — no boto3 required)
#   - the checkout's .env with CLOUDFLARE_API_TOKEN, R2_* vars

set -euo pipefail

# ── Config ──────────────────────────────────────────────────────────────────
SCRIPTS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="${BACKUP_ENV_FILE:-$(dirname "$SCRIPTS_DIR")/.env}"
PG_CONTAINER="bsweb_prod_pg"
# PRIVATE bucket — NOT baoson-media (that one is served publicly by the media Worker, so a dump
# there would be downloadable at files.baoson.net/db-backups/*). This bucket has no Worker/route.
R2_BUCKET_NAME="baoson-db-backups"
R2_PREFIX="db-backups"
SCRATCHPAD_BASE="/tmp/bsweb-db-backup"
# The Cloudflare token behind the upload carries an expiry date; warn this many days ahead.
TOKEN_WARN_DAYS=14
# Pinned: an unpinned `npx wrangler` installs whatever npm serves as latest on the day its cache is
# empty, and runs it with the Cloudflare token in its environment. Bump deliberately.
export WRANGLER_PKG="${BACKUP_WRANGLER_PKG:-wrangler@4.101.0}"

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

HC_URL="${BACKUP_HEALTHCHECK_URL:-}"
LOCAL_FALLBACK_DIR="${BACKUP_LOCAL_FALLBACK_DIR:-/opt/bswebsaas/db-backups}"
HC_REPORTED=0

# Ping the external dead-man check (no-op when unset). Never fails the backup itself.
hc_ping() {
    [[ -n "$HC_URL" ]] || return 0
    curl -fsS -m 10 --retry 3 --data-raw "${2:-}" "${HC_URL}${1}" >/dev/null 2>&1 \
        || log "WARN: healthcheck ping ${1:-/} failed"
}

# Prints "<status> <expires_on|-> <days_left|->" for CLOUDFLARE_API_TOKEN without echoing it.
# Tries the user-token endpoint, then the account-token one (account id from R2_ENDPOINT).
# status: the API's own word ("active", "expired", "disabled"…), "invalid" when the API answered
# but accepted neither form, or "unreachable" when it never answered (network or Cloudflare
# outage — which says nothing about the token itself).
token_state() {
    python3 - <<'PY'
import datetime, json, os, re, urllib.error, urllib.request
token = os.environ["CLOUDFLARE_API_TOKEN"]
account = re.match(r"https://([0-9a-f]+)\.", os.environ.get("R2_ENDPOINT", ""))
urls = ["https://api.cloudflare.com/client/v4/user/tokens/verify"]
if account:
    urls.append(f"https://api.cloudflare.com/client/v4/accounts/{account.group(1)}/tokens/verify")
answered = False
for url in urls:
    request = urllib.request.Request(url, headers={"Authorization": f"Bearer {token}"})
    try:
        with urllib.request.urlopen(request, timeout=15) as response:
            body = json.load(response)
    except urllib.error.HTTPError as error:
        if error.code >= 500:
            continue
        try:
            body = json.load(error)
        except Exception:
            continue
    except Exception:
        continue
    answered = True
    result = body.get("result") or {}
    if body.get("success") and result:
        expires = result.get("expires_on")
        days = "-"
        if expires:
            left = datetime.datetime.fromisoformat(expires.replace("Z", "+00:00")) - datetime.datetime.now(datetime.timezone.utc)
            days = str(left.days)
        print(result.get("status", "unknown"), expires or "-", days)
        break
else:
    print("invalid - -" if answered else "unreachable - -")
PY
}

# Keep the dump on this host when it cannot go offsite, so a failed night still leaves a copy.
keep_local_copy() {
    mkdir -p "$LOCAL_FALLBACK_DIR"
    local target="${LOCAL_FALLBACK_DIR}/not-uploaded-${FILENAME}"
    ( umask 077 && cp "$TMPFILE" "$target" ) && log "Kept a local copy: ${target}"
}

# ── Temp dir with guaranteed cleanup ─────────────────────────────────────────
TMPDIR=$(mktemp -d "${SCRATCHPAD_BASE}.XXXXXX")
TMPFILE="${TMPDIR}/${FILENAME}"

cleanup() {
    local exit_code=$?
    rm -rf "$TMPDIR"
    if [[ $exit_code -eq 0 ]]; then
        log "Temp files cleaned up."
    else
        log "Exiting with error $exit_code — temp files cleaned up."
        [[ $HC_REPORTED -eq 1 ]] || hc_ping /fail "backup-prod-db.sh exited with $exit_code — see /var/log/anvinh-db-backup.log"
    fi
}
trap cleanup EXIT

hc_ping /start

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

# ── Token check: an expired/revoked token must fail loudly, not after a silent 401 ─────────
read -r TOKEN_STATUS TOKEN_EXPIRES TOKEN_DAYS_LEFT < <(token_state)
case "$TOKEN_STATUS" in
    active) ;;
    unreachable)
        # No verdict on the token — try the upload; if that fails too, the dump is kept locally.
        log "WARN: could not reach the Cloudflare API to check the token — trying the upload anyway" ;;
    *)
        keep_local_copy
        die "Cloudflare API token is ${TOKEN_STATUS} (expires_on ${TOKEN_EXPIRES}) — renew it in the Cloudflare dashboard, then rerun" ;;
esac
TOKEN_WARNING=""
if [[ "$TOKEN_DAYS_LEFT" != "-" && "$TOKEN_DAYS_LEFT" -le "$TOKEN_WARN_DAYS" ]]; then
    TOKEN_WARNING="Cloudflare API token expires on ${TOKEN_EXPIRES} (${TOKEN_DAYS_LEFT} days) — renew it before then"
    log "WARN: ${TOKEN_WARNING}"
fi

# ── Upload to R2 via wrangler ─────────────────────────────────────────────────
log "Uploading to R2: ${R2_BUCKET_NAME}/${R2_PREFIX}/${FILENAME}"

# wrangler picks up CLOUDFLARE_API_TOKEN from env automatically
if ! CLOUDFLARE_API_TOKEN="$CLOUDFLARE_API_TOKEN" \
    npx --yes "$WRANGLER_PKG" r2 object put \
    "${R2_BUCKET_NAME}/${R2_PREFIX}/${FILENAME}" \
    --file "$TMPFILE" \
    --remote; then
    keep_local_copy
    die "Upload to R2 failed"
fi

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

# A token about to lapse is reported as a failed check: the backup itself is fine, but the next
# ones will not be unless someone renews it, and a warning in a log file is read too late.
if [[ -n "$TOKEN_WARNING" ]]; then
    hc_ping /fail "Backup ${FILENAME} uploaded. ${TOKEN_WARNING}"
else
    hc_ping "" "Backup ${FILENAME} uploaded"
fi
HC_REPORTED=1
