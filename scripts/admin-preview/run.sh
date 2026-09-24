#!/usr/bin/env bash
#
# Screenshot the admin screens (light + dark) with no login, no 2FA, no real environment.
#
# Pipeline: build the front end → render the admin HTML as a seeded owner in the test database
# (tests/Tools/RenderAdminPreviewTest.php, actingAs, GET-only) → serve it with its real assets →
# shoot it with a headless browser (agent-browser). Output: storage/app/admin-preview/shots/.
#
# Usage:  scripts/admin-preview/run.sh [--no-build] [--out DIR] [--port N]
# Needs:  the dev Docker stack up, a headless browser via `agent-browser`, node, pnpm.
set -euo pipefail
cd "$(dirname "$0")/../.."

BUILD=1 PORT="${PORT:-8098}" OUT=""
while [ $# -gt 0 ]; do
    case "$1" in
        --no-build) BUILD=0 ;;
        --out) OUT="$2"; shift ;;
        --port) PORT="$2"; shift ;;
        *) echo "unknown arg: $1" >&2; exit 2 ;;
    esac
    shift
done
SHOTS="${OUT:-storage/app/admin-preview/shots}"
BASE="http://127.0.0.1:$PORT/screens"

command -v agent-browser >/dev/null || { echo "agent-browser not found — install it or screenshot storage/app/admin-preview/*.html yourself" >&2; exit 3; }

[ "$BUILD" = 1 ] && pnpm run build >/dev/null
docker compose run --rm --no-deps -T app vendor/bin/pest tests/Tools/RenderAdminPreviewTest.php >/dev/null

mkdir -p "$SHOTS"
PORT="$PORT" node scripts/admin-preview/serve.mjs &
SERVER=$!
trap 'kill "$SERVER" 2>/dev/null || true' EXIT
sleep 1

theme() { echo "eval \"document.documentElement.setAttribute('data-bs-theme','$1')\""; }

for page in dashboard list form; do
    for scheme in light dark; do
        agent-browser batch --bail "set viewport 1440 900" "open $BASE/$page.html" "$(theme "$scheme")" \
            "wait 800" "screenshot --full $SHOTS/$page-$scheme.png" >/dev/null
    done
done

# The kit gallery carries the modal, inputs (focus ring) and every component — shoot it both schemes,
# with the delete-confirm modal open.
for scheme in light dark; do
    agent-browser batch --bail "set viewport 1440 900" "open $BASE/gallery.html" "$(theme "$scheme")" "wait 600" \
        "eval \"[...document.querySelectorAll('button')].find(b => b.textContent.trim() === 'Mở modal')?.scrollIntoView({block:'center'})\"" \
        "find text \"Mở modal\" click" "wait 500" "press Tab" "wait 300" "screenshot $SHOTS/modal-$scheme.png" >/dev/null
done

# Keyboard focus ring on a form field, and a gradient sidebar with the menu still readable.
agent-browser batch --bail "set viewport 1440 900" "open $BASE/form.html" "wait 600" \
    "eval \"document.querySelector('main input:not([type=hidden]), main select, main textarea')?.focus({focusVisible:true})\"" \
    "wait 300" "screenshot $SHOTS/form-focus.png" >/dev/null
agent-browser batch --bail "set viewport 1440 900" "open $BASE/form.html" \
    "eval \"document.documentElement.setAttribute('data-sidebar','gradient-2')\"" "wait 500" \
    "screenshot $SHOTS/sidebar-gradient.png" >/dev/null

echo "admin preview shots → $SHOTS"
ls -1 "$SHOTS"
