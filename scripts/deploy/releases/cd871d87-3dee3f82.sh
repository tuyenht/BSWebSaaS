# Release file: prod cd871d87 → 3dee3f82 (main on 13/11, after #635 and #633). Data and one-off commands only;
# the engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/cd871d87-3dee3f82.sh
# Range: #635 (release file of the previous deploy), #633 (storefront: no block and no footer is drawn on demand any
# more — `content-visibility: auto` and its scroll-arrival script are gone, so PageSpeed's accessibility check sees the
# real backgrounds; the factories' title in the rich footer is an h2 when the site has no legal name; CI: Lighthouse
# accessibility 100 is a gate). CSS, JS, one Blade partial, tests and CI only: no migration, no schedule, no env.
# shellcheck shell=bash disable=SC2034
OLD=cd871d876e9c80101ef7b4e9184e94b08583263f
TARGET=3dee3f829827edcb5883915be8300d0d79e14e76
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
    local html css js
    html=$(curl -s -m 20 "https://$HOST/?bp=$RANDOM")
    css=$({ grep -oE '/build/assets/storefront-[A-Za-z0-9_-]+\.css' <<< "$html" || true; } | head -1)
    js=$({ grep -oE '/build/assets/storefront-[A-Za-z0-9_-]+\.js' <<< "$html" || true; } | head -1)
    # Without both files every count below would be taken from the wrong page: a check of its own first.
    expect "tìm thấy CSS và JS của trang chủ av1" "$([ -n "$css" ] && [ -n "$js" ] && echo 1 || echo 0)" "1"
    # #633: the page's stylesheet draws every block at once (no on-demand drawing) and still styles the blocks
    expect "CSS website av1 không còn vẽ khối theo nhu cầu (#633)" \
        "$(curl -fsS -m 20 "https://$HOST$css" | { grep -c 'content-visibility' || true; })" "0"
    expect "CSS website av1 vẫn có kiểu khối (#633)" \
        "$(curl -fsS -m 20 "https://$HOST$css" | { grep -c 'sf-band' || true; } | awk '{print ($1 > 0)}')" "1"
    # #633: the page's script no longer carries the scroll-arrival code, and the floating contact code is still there
    expect "JS website av1 không còn mã cuộn tới khối vẽ sau (#633)" \
        "$(curl -fsS -m 20 "https://$HOST$js" | { grep -c 'sf-anchored' || true; })" "0"
    expect "trang chủ av1 vẫn có thanh liên hệ dưới (#633 không đụng nút liên hệ)" \
        "$({ grep -oF 'class="sf-fc-bar__dot"' <<< "$html" || true; } | wc -l | awk '{print ($1 > 0)}')" "1"
}
# Run 13/11/2026 22:11 (tmux deploy-3dee3f82-13221149, launched by the AI): XONG, prod at 3dee3f82; 102 OK, 0 LỖI/LỆCH.
# Log: plans/reports/deploy-runs/cd871d8-3dee3f82-run-20261113-221149.log
