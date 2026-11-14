# Release file: prod 84076164 → 62d37b49 (main on 14/11, after #658). Data and one-off commands only;
# the engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/84076164-62d37b49.sh
# Range: #659 (release file and result of the previous deploy), #657 (the storefront's early hints and Link header
# send the media host's preconnect first, so the edge can hint it in the 103), #658 (the view-count beacon waits
# for prerenderingchange when the page was prerendered, so a page prepared ahead of a click counts only when shown).
# PHP middleware, JS and tests: no migration, no schedule, no env.
# shellcheck shell=bash disable=SC2034
OLD=84076164884b2f50484946ee331f96267daee4ba
TARGET=62d37b4968eb73f894f9a9892067925f42944ef0
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /ho-so-nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

# Classes that need a human look and are in this range (the engine stops if this differs from what it finds).
DECLARED=()

MIGRATIONS=()
declare -gA MIG_OK=()
declare -gA PGB_SKIP=()
SCHEDULE_ADDED=()
SCHEDULE_REMOVED=()
ENV_DEFAULT_OK=()
SCHEMA_REMOVED_OK=()
BUILD_DYNAMIC=()
BUILD_MARKERS=()

ONEOFFS_PRE=()
ONEOFFS=()
release_smoke() {
    local link html sv
    # #657: the app's Link header (quoted form; Cloudflare adds a second, unquoted one) starts with the media preconnect
    link=$(curl -sS -m 20 -o /dev/null -D - "https://$HOST/?bp=$RANDOM" | tr -d '\r' | grep -i '^link:' || true)
    expect "trang chủ av1: mục Link đầu tiên là preconnect tới máy chủ ảnh (#657)" \
        "$(sed -E 's/^[Ll]ink: *//' <<< "$link" | grep -cE '^<https://files\.baoson\.net>; rel="preconnect"' | awk '{print ($1 > 0)}')" "1"
    # #658: the view script waits for prerenderingchange
    html=$(curl -sS -m 20 "https://$HOST/khanh-thanh-nha-may-so-3?bp=$RANDOM")
    sv=$({ grep -oE '/build/assets/sf-view-[A-Za-z0-9_-]+\.js' <<< "$html" || true; } | head -1)
    expect "bài tin av1 có script đếm lượt xem" "$([ -n "$sv" ] && echo 1 || echo 0)" "1"
    expect "script đếm lượt xem chờ prerenderingchange (#658)" \
        "$(curl -fsS -m 20 "https://$HOST$sv" | { grep -cF 'prerenderingchange' || true; } | awk '{print ($1 > 0)}')" "1"
}
# Log: plans/reports/deploy-runs/8407616-62d37b49-run-20261114-111332.log (XONG, 100 OK, release smoke 3/3; full CI on main 43554307432 green). Live after: the home page Link header starts with the files.baoson.net preconnect; sf-view waits for prerenderingchange.
