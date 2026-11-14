# Release file: prod 16ad0ff8 → 84076164 (main on 14/11, after #656). Data and one-off commands only;
# the engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/16ad0ff8-84076164.sh
# Range: #655 (release file and result of the previous deploy), #656 (the round contact buttons on a computer are a
# fixed 50px, so a site's larger root text no longer swells the column; phones unchanged).
# CSS and tests: no migration, no schedule, no env.
# shellcheck shell=bash disable=SC2034
OLD=16ad0ff838b91042f62db8a1cd1849404e6674c2
TARGET=84076164884b2f50484946ee331f96267daee4ba
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
    local html fc
    html=$(curl -sS -m 20 "https://$HOST/?bp=$RANDOM")
    fc=$({ grep -oE '/build/assets/sf-floating-[A-Za-z0-9_-]+\.css' <<< "$html" || true; } | head -1)
    expect "trang chủ av1 có CSS nút liên hệ nổi" "$([ -n "$fc" ] && echo 1 || echo 0)" "1"
    # #656: the computer size of the round buttons is a fixed 50px (no max() with rem any more)
    expect "CSS nút liên hệ nổi av1: nút tròn máy tính cố định 50px (#656)" \
        "$(curl -fsS -m 20 "https://$HOST$fc" | { grep -oF -- '--fc-size:50px' || true; } | wc -l | awk '{print ($1 > 0)}')" "1"
    expect "CSS nút liên hệ nổi av1 không còn max(50px, 3.125rem) (#656)" \
        "$(curl -fsS -m 20 "https://$HOST$fc" | { grep -cF '3.125rem' || true; } | awk '{print ($1 > 0)}')" "0"
    expect "trang chủ av1 vẫn có nút Gọi nổi giữa thanh điện thoại" \
        "$({ grep -oF 'sf-fc-bar__item--up' <<< "$html" || true; } | wc -l | awk '{print ($1 > 0)}')" "1"
}
# Log: plans/reports/deploy-runs/16ad0ff-84076164-run-20261114-095209.log (XONG, 101 OK, release smoke 4/4; full CI on main 43546929221 green). Live after: round buttons 50x50 at 1280x720, 1366x768 and 1920x1080 (root text 17px), column 238px.
