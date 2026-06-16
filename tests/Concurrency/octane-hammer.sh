#!/usr/bin/env bash
#
# Gate #1 — real-Octane HTTP concurrency hammer (the strongest tenant-isolation
# proof: a real worker pool, not a single-process simulation). Provisions two sites
# and fires many INTERLEAVED requests across both hostnames through one Octane
# worker pool, asserting every response carries ONLY its own site's content.
#
# Run on-demand inside the app container (NOT part of the default CI suite, which
# runs against Postgres directly in one process):
#   docker compose run --rm --no-deps app bash tests/Concurrency/octane-hammer.sh
#
set -euo pipefail

PORT="${HAMMER_PORT:-8088}"
REQUESTS="${HAMMER_REQUESTS:-200}"
CONCURRENCY="${HAMMER_CONCURRENCY:-24}"
BASE="http://127.0.0.1:${PORT}"

echo "▶ migrate:fresh (privileged role) + provision two sites…"
php artisan migrate:fresh --database=pgsql_admin --force >/dev/null

php artisan tinker --execute '
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
app(ProvisionSite::class)->handle(new ProvisionSiteData(tenantName:"An Vinh",siteName:"An Vinh",hostname:"anvinh.test",ownerEmail:"o@anvinh.test",ownerName:"O",ownerPassword:"x"));
app(ProvisionSite::class)->handle(new ProvisionSiteData(tenantName:"Other",siteName:"Other Co",hostname:"other.test",ownerEmail:"o@other.test",ownerName:"O",ownerPassword:"x"));
'

echo "▶ booting Octane (frankenphp) on :${PORT}…"
php artisan octane:start --server=frankenphp --host=127.0.0.1 --port="${PORT}" &>/tmp/octane-hammer.log &
OCT=$!
trap 'kill "${OCT}" 2>/dev/null || true' EXIT

for _ in $(seq 1 30); do
    curl -fsS "${BASE}/up" >/dev/null 2>&1 && break
    sleep 1
done

echo "▶ hammering ${REQUESTS} interleaved requests at ${CONCURRENCY}-way concurrency…"
LEAKS="$(mktemp)"
seq 1 "${REQUESTS}" | xargs -P "${CONCURRENCY}" -I{} bash -c '
    n={}
    if (( n % 2 == 0 )); then host=anvinh.test; want="An Vinh"; bad="Other Co"
    else                       host=other.test;  want="Other Co"; bad="An Vinh"; fi
    body=$(curl -fsS "'"${BASE}"'/" -H "Host: ${host}" || echo "__CURL_FAIL__")
    if ! grep -q "${want}" <<<"${body}" || grep -q "${bad}" <<<"${body}"; then
        echo "LEAK host=${host} n=${n}" >> "'"${LEAKS}"'"
    fi
'

if [[ -s "${LEAKS}" ]]; then
    echo "❌ TENANT LEAK DETECTED across interleaved requests:"
    cat "${LEAKS}"
    exit 1
fi

echo "✅ No tenant leak across ${REQUESTS} interleaved requests on a real Octane worker pool."
