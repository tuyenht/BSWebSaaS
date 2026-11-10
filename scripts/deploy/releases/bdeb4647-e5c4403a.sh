# Release file: prod bdeb4647 → e5c4403a (main on 10/11, after #470, #471). Data and one-off commands only;
# the engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/bdeb4647-e5c4403a.sh
# Range: #470 (site:load-content lets a pack redirect take the address of a post the same load unpublishes;
# the An Vinh pack keeps the site's four social links), #471 (the release file of the 10:00 deploy, not run,
# and its test). Code and the pack only: no migration, no build, no new job, no library change. The pack is
# shipped, NOT loaded: the owner loads it with plans/tools/anvinh-demo-load-1110.sh after this deploy.
# shellcheck shell=bash disable=SC2034
OLD=bdeb46477b655a60d7544c7901dbc2a2b6bfcc31
TARGET=e5c4403a4e52b7ef6851d624dbbaf181ffdbd0dd
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /san-pham /du-an /tin-tuc /doi-tac /lien-he /chinh-sach-bao-mat /nang-luc /admin/login)

# Classes that need a human look and are in this range (the engine stops if this differs from what it finds).
DECLARED=(content-pack)

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
    # #470: the container runs the new planner and serves the pack with the social links
    expect "bộ nạp mới trong container" \
        "$(docker exec "${PREFIX}app" grep -c 'private function stillServed' app/Modules/Provisioning/ContentPack/Planner.php || true)" "1"
    expect "gói An Vinh có Zalo trong hồ sơ" \
        "$(docker exec "${PREFIX}app" grep -c '"zalo": "https://zalo.me/0915668383"' database/content-packs/an-vinh/settings.json || true)" "1"
    # what the owner's load would do now (a dry run: writes nothing); the load script refuses on any conflict
    note "kế hoạch nạp gói An Vinh (xem trước, không ghi): $({ art site:load-content an-vinh --host="$HOST" 2>&1 || true; } | grep -E '^Totals:' || echo 'không đọc được dòng Totals')"
}
release_rollback() { # data the old code (bdeb4647) cannot read
    echo "     Không có: bản này không đổi bảng; chuyển hướng do gói ghi là hàng redirects thường, bản cũ đọc được."
}
