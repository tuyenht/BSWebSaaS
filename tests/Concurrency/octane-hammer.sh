#!/usr/bin/env bash
#
# Gate #1 — real-Octane HTTP concurrency hammer (the strongest tenant-isolation
# proof: a real worker pool, not a single-process simulation). Provisions two sites
# and fires many INTERLEAVED requests across both hostnames through one Octane
# worker pool, asserting every response carries ONLY its own site's content.
# A second leg does the same for support sign-in: platform staff signed in as each
# site's owner from two browsers, interleaved, must each see only their own member.
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

# The storefront renders through Vite: without the built manifest every request is a 500.
# Say so up front instead of reporting 200 failures.
if [[ ! -f public/build/manifest.json ]]; then
    echo "❌ The Vite manifest (public/build/manifest.json) is missing — run 'pnpm run build', or copy the dev tree's built assets into this worktree, first." >&2
    exit 2
fi

echo "▶ migrate:fresh (privileged role) + provision two sites…"
php artisan migrate:fresh --database=pgsql_admin --force >/dev/null
php artisan rbac:sync >/dev/null

php artisan tinker --execute '
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
app(ProvisionSite::class)->handle(new ProvisionSiteData(tenantName:"An Vinh",siteName:"An Vinh",hostname:"anvinh.test",ownerEmail:"o@anvinh.test",ownerName:"Owner-Alpha",ownerPassword:"x"));
app(ProvisionSite::class)->handle(new ProvisionSiteData(tenantName:"Other",siteName:"Other Co",hostname:"other.test",ownerEmail:"o@other.test",ownerName:"Owner-Beta",ownerPassword:"x"));
'

echo "▶ booting Octane (frankenphp) on :${PORT}…"
php artisan octane:start --server=frankenphp --host=127.0.0.1 --port="${PORT}" &>/tmp/octane-hammer.log &
OCT=$!
trap 'kill "${OCT}" 2>/dev/null || true' EXIT

for _ in $(seq 1 30); do
    curl -fsS "${BASE}/up" >/dev/null 2>&1 && break
    sleep 1
done

FAILS="$(mktemp)"
LEAKS="$(mktemp)"

# One request. A failed request is a FAIL (the server broke); a page missing its own marker or
# showing the other site's is a LEAK (isolation broke). They are different problems.
#   probe <n> <host> <path> <want> <bad> [cookie jar]
probe() {
    local n=$1 host=$2 path=$3 want=$4 bad=$5 jar=${6:-}
    local body
    if ! body=$(curl -fsS ${jar:+-b "$jar"} -A octane-hammer "${BASE}${path}" -H "Host: ${host}"); then
        echo "FAIL host=${host} path=${path} n=${n}" >> "${FAILS}"
    elif ! grep -q "${want}" <<<"${body}" || grep -q "${bad}" <<<"${body}"; then
        echo "LEAK host=${host} path=${path} n=${n}" >> "${LEAKS}"
    fi
}
export -f probe
export BASE FAILS LEAKS

echo "▶ storefront: ${REQUESTS} interleaved requests at ${CONCURRENCY}-way concurrency…"
seq 1 "${REQUESTS}" | xargs -P "${CONCURRENCY}" -I{} bash -c '
    if (( {} % 2 == 0 )); then probe {} anvinh.test / "An Vinh" "Other Co"
    else                       probe {} other.test  / "Other Co" "An Vinh"; fi
'

echo "▶ support sign-in: staff enter each site as its owner, two browsers, interleaved…"
LINKS=$(php artisan tinker --execute '
use App\Modules\Admin\Support\SupportSignIn;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Models\Domain;
use App\Modules\Tenancy\Models\Site;
$staff = new User;
$staff->forceFill(["name" => "Hammer Staff", "email" => "staff@platform.test", "password" => "x", "email_verified_at" => now()])->save();
app(PlatformAccess::class)->onPlatform(fn () => $staff->assignRole("support"));
foreach (["anvinh.test" => "o@anvinh.test", "other.test" => "o@other.test"] as $host => $owner) {
    $site = Site::query()->findOrFail(Domain::query()->where("hostname", $host)->value("site_id"));
    $member = User::query()->where("email", $owner)->firstOrFail();
    echo "LINK ".$host." ".app(SupportSignIn::class)->issue($staff, $site, $member, "hammer", 15, "octane-hammer").PHP_EOL;
}
' | grep '^LINK ')

JAR_A="$(mktemp)"
JAR_B="$(mktemp)"
for pair in "anvinh.test:${JAR_A}" "other.test:${JAR_B}"; do
    host=${pair%%:*}
    jar=${pair#*:}
    token=$(awk -v h="${host}" '$2 == h { print $3 }' <<<"${LINKS}")
    code=$(curl -s -o /dev/null -w '%{http_code}' -c "${jar}" -A octane-hammer -H "Host: ${host}" "${BASE}/admin/support/enter?token=${token}")
    if [[ "${code}" != 303 ]]; then
        echo "❌ support sign-in did not open on ${host} (HTTP ${code})"
        exit 1
    fi
done
export JAR_A JAR_B

seq 1 "${REQUESTS}" | xargs -P "${CONCURRENCY}" -I{} bash -c '
    if (( {} % 2 == 0 )); then probe {} anvinh.test /admin Owner-Alpha Owner-Beta "${JAR_A}"
    else                       probe {} other.test  /admin Owner-Beta Owner-Alpha "${JAR_B}"; fi
'

if [[ -s "${FAILS}" ]]; then
    echo "❌ $(wc -l <"${FAILS}") request(s) FAILED (server errors, not isolation) — see /tmp/octane-hammer.log and storage/logs:"
    head -20 "${FAILS}"
fi
if [[ -s "${LEAKS}" ]]; then
    echo "❌ TENANT LEAK DETECTED across interleaved requests:"
    cat "${LEAKS}"
fi
if [[ -s "${FAILS}" || -s "${LEAKS}" ]]; then
    exit 1
fi

echo "✅ No tenant leak across ${REQUESTS} storefront + ${REQUESTS} support-session admin requests, interleaved on a real Octane worker pool."
