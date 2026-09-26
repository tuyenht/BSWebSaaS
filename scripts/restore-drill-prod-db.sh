#!/usr/bin/env bash
# restore-drill-prod-db.sh — prove that the newest offsite backup actually restores
#
# A backup that has never been restored is not a backup (design.md R19: monthly restore test).
# Downloads the newest db-bswebsaas-*.sql.gz from the private R2 bucket, restores it with
# ON_ERROR_STOP into a throwaway database on the prod PostgreSQL server (same version and
# extensions a real recovery would use), compares it with the live database, then drops it.
# The live database is only read.
#
# Usage: <checkout>/scripts/restore-drill-prod-db.sh
# Cron:  45 4 1 * * /opt/bswebsaas/prod/scripts/restore-drill-prod-db.sh >> /var/log/bsweb-restore-drill.log 2>&1
#        (host time, +07 — the 1st of each month, after the nightly backup)
#
# Optional env (process env or the .env file):
#   BACKUP_ENV_FILE           .env to source (default: the .env of the checkout holding this script)
#   RESTORE_HEALTHCHECK_URL   healthchecks.io-style check on a monthly schedule: pinged /start, on
#                             success, and /fail — a month without a drill alerts too
#   RESTORE_MAX_AGE_HOURS     the newest backup must be younger than this (default 36)
#   RESTORE_FROM_FILE         drill a local .sql.gz instead of the newest R2 object (a pre-deploy
#                             dump, a not-uploaded-* copy); skips the download and the age check
#   RESTORE_PG_CONTAINER      PostgreSQL container to drill on (default bsweb_prod_pg)
#   BACKUP_WRANGLER_PKG       wrangler package spec for npx (default: the pinned version below)

set -euo pipefail

# ── Config ──────────────────────────────────────────────────────────────────
SCRIPTS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="${BACKUP_ENV_FILE:-$(dirname "$SCRIPTS_DIR")/.env}"
PG_CONTAINER="${RESTORE_PG_CONTAINER:-bsweb_prod_pg}"
R2_BUCKET_NAME="baoson-db-backups"
export WRANGLER_PKG="${BACKUP_WRANGLER_PKG:-wrangler@4.101.0}"
DRILL_DB="restore_drill_$(date -u +%Y%m%d%H%M%S)"

log() { echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] $*"; }
die() { log "ERROR: $*" >&2; exit 1; }

# ── Source .env (never echo values) ─────────────────────────────────────────
[[ -f "$ENV_FILE" ]] || die ".env not found at $ENV_FILE"
set -a
# shellcheck disable=SC1090
source "$ENV_FILE"
set +a

HC_URL="${RESTORE_HEALTHCHECK_URL:-}"
MAX_AGE_HOURS="${RESTORE_MAX_AGE_HOURS:-36}"
HC_REPORTED=0
DRILL_CREATED=0

hc_ping() {
    [[ -n "$HC_URL" ]] || return 0
    curl -fsS -m 10 --retry 3 --data-raw "${2:-}" "${HC_URL}${1}" >/dev/null 2>&1 \
        || log "WARN: healthcheck ping ${1:-/} failed"
}

# psql as the server's superuser inside the PG container: pg <database> [psql args…]
pg() {
    local db="$1"
    shift
    docker exec -i "$PG_CONTAINER" sh -c \
        'PGPASSWORD="$POSTGRES_PASSWORD" exec psql --no-password -U "$POSTGRES_USER" -X -v ON_ERROR_STOP=1 -d "$0" "$@"' \
        "$db" "$@"
}

# ── Temp dir + drill database, always cleaned up ────────────────────────────
TMPDIR=$(mktemp -d /tmp/bsweb-restore-drill.XXXXXX)
TMPFILE="${TMPDIR}/backup.sql.gz"

cleanup() {
    local exit_code=$?
    if [[ $DRILL_CREATED -eq 1 ]]; then
        pg postgres -qc "DROP DATABASE IF EXISTS \"${DRILL_DB}\" WITH (FORCE)" >/dev/null \
            && log "Dropped ${DRILL_DB}." \
            || log "WARN: could not drop ${DRILL_DB} — drop it by hand"
    fi
    rm -rf "$TMPDIR"
    if [[ $exit_code -ne 0 && $HC_REPORTED -eq 0 ]]; then
        hc_ping /fail "restore-drill-prod-db.sh exited with $exit_code — see /var/log/bsweb-restore-drill.log"
    fi
}
trap cleanup EXIT

hc_ping /start

docker inspect --format='{{.State.Status}}' "$PG_CONTAINER" 2>/dev/null | grep -q running \
    || die "Container $PG_CONTAINER is not running"
LIVE_DB=$(docker exec "$PG_CONTAINER" printenv POSTGRES_DB)
[[ "$DRILL_DB" == restore_drill_* && "$DRILL_DB" != "$LIVE_DB" ]] || die "Refusing drill database name ${DRILL_DB}"

# ── Newest backup in the bucket ─────────────────────────────────────────────
# Prints "<key> <age in hours>", or "- -" when the bucket holds none. Lists through the same helper
# the retention prune uses.
newest_backup() {
    R2_BUCKET="$R2_BUCKET_NAME" python3 - "$SCRIPTS_DIR" <<'PY'
import datetime, sys
sys.path.insert(0, sys.argv[1])
import r2_prune_backups as r2
dated = [(r2.parse_backup_dt(o["key"]), o["key"]) for o in r2.list_objects(r2.R2_PREFIX)]
dated = [pair for pair in dated if pair[0] is not None]
if not dated:
    print("- -")
else:
    taken, key = max(dated)
    age = datetime.datetime.now(datetime.timezone.utc) - taken
    print(key, int(age.total_seconds() // 3600))
PY
}

STALE=""
if [[ -n "${RESTORE_FROM_FILE:-}" ]]; then
    [[ -f "$RESTORE_FROM_FILE" ]] || die "No such file: ${RESTORE_FROM_FILE}"
    BACKUP_KEY=$(basename "$RESTORE_FROM_FILE")
    cp "$RESTORE_FROM_FILE" "$TMPFILE"
    log "Drilling local file ${RESTORE_FROM_FILE}"
else
    for var in CLOUDFLARE_API_TOKEN R2_ENDPOINT; do
        [[ -n "${!var:-}" ]] || die "Missing required env var: $var"
    done
    read -r BACKUP_KEY AGE_HOURS < <(newest_backup || echo "")
    [[ -n "${BACKUP_KEY:-}" ]] || die "Could not list ${R2_BUCKET_NAME} (Cloudflare API unreachable or token rejected)"
    [[ "$BACKUP_KEY" != "-" ]] || die "No backup found in ${R2_BUCKET_NAME}"
    log "Newest backup: ${BACKUP_KEY} (${AGE_HOURS}h old)"
    if [[ "$AGE_HOURS" -gt "$MAX_AGE_HOURS" ]]; then
        # Still drill it — a stale backup that restores beats not knowing — but the run fails below.
        STALE="the newest backup is ${AGE_HOURS}h old (limit ${MAX_AGE_HOURS}h) — nightly backups are not landing"
        log "WARN: ${STALE}"
    fi
    npx --yes "$WRANGLER_PKG" r2 object get "${R2_BUCKET_NAME}/${BACKUP_KEY}" --file "$TMPFILE" --remote >/dev/null
fi

# ── Restore ─────────────────────────────────────────────────────────────────
gzip -t "$TMPFILE" || die "${BACKUP_KEY} is not a valid gzip"
log "Backup file: $(stat -c%s "$TMPFILE") bytes."

pg postgres -qc "CREATE DATABASE \"${DRILL_DB}\"" >/dev/null
DRILL_CREATED=1
# VERBOSITY=terse: an error prints its one-line message only — psql's CONTEXT would quote the
# failing row of the dump (customers' personal data) into the log.
gunzip -c "$TMPFILE" | pg "$DRILL_DB" --quiet --single-transaction -v VERBOSITY=terse >/dev/null \
    || die "Restore into ${DRILL_DB} failed — the backup does not restore cleanly"
log "Restored into ${DRILL_DB} (single transaction, ON_ERROR_STOP)."

# ── Compare with the live database ──────────────────────────────────────────
# Structure must match exactly (a deploy with a migration between the backup and the drill is the
# one legitimate exception — rerun the drill after the next backup). Row counts only have to be
# plausible: the live database has moved on since the backup.
METRICS_SQL="
SELECT 'tables', count(*) FROM pg_tables WHERE schemaname = 'public'
UNION ALL SELECT 'rls_policies', count(*) FROM pg_policies WHERE schemaname = 'public'
UNION ALL SELECT 'rls_forced_tables', count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
          WHERE n.nspname = 'public' AND c.relkind = 'r' AND c.relforcerowsecurity
UNION ALL SELECT 'migrations', count(*) FROM migrations
UNION ALL SELECT 'sites', count(*) FROM sites
UNION ALL SELECT 'domains', count(*) FROM domains
UNION ALL SELECT 'users', count(*) FROM users
UNION ALL SELECT 'entries', count(*) FROM entries"

declare -A LIVE RESTORED
while read -r name value; do LIVE[$name]=$value; done < <(pg "$LIVE_DB" -At -F ' ' -c "$METRICS_SQL")
while read -r name value; do RESTORED[$name]=$value; done < <(pg "$DRILL_DB" -At -F ' ' -c "$METRICS_SQL")

PROBLEMS=()
SUMMARY=()
for name in tables rls_policies rls_forced_tables migrations sites domains users entries; do
    SUMMARY+=("${name}=${RESTORED[$name]:-?}/${LIVE[$name]:-?}")
done
log "restored/live: ${SUMMARY[*]}"
for name in tables rls_policies rls_forced_tables migrations; do
    [[ "${RESTORED[$name]:-x}" == "${LIVE[$name]:-y}" ]] || PROBLEMS+=("${name} differs")
done
for name in sites domains; do
    [[ "${RESTORED[$name]:-0}" -ge 1 ]] || PROBLEMS+=("no ${name} restored")
done
[[ -z "$STALE" ]] || PROBLEMS+=("$STALE")

if [[ ${#PROBLEMS[@]} -gt 0 ]]; then
    MESSAGE="Restore drill of ${BACKUP_KEY} found problems: $(IFS=';'; echo "${PROBLEMS[*]}")"
    hc_ping /fail "$MESSAGE"
    HC_REPORTED=1
    die "$MESSAGE"
fi

log "Restore drill passed: ${BACKUP_KEY}"
hc_ping "" "Restore drill passed: ${BACKUP_KEY} — ${SUMMARY[*]}"
HC_REPORTED=1
