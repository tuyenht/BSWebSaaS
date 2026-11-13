# Release file: prod 75cef6ae → 353dc9f9 (main on 14/11, after #651). Data and one-off commands only;
# the engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/75cef6ae-353dc9f9.sh
# Range: #650 (release file and result of the previous deploy), #649 (on wide screens the rail arrows centre on
# the cards, not on cards and dots), #651 (the raised call stays in the middle of a narrow phone bar too: flex
# orders per width band as inline custom properties). CSS, PHP, Blade and tests: no migration, no schedule, no env.
# shellcheck shell=bash disable=SC2034
OLD=75cef6aea3b455ee2dff3115a67c713f42267e66
TARGET=353dc9f9ebea462ea952676627736536894ff295
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
    local html rail fc
    html=$(curl -sS -m 20 "https://$HOST/?bp=$RANDOM")
    rail=$({ grep -oE '/build/assets/sf-rail-[A-Za-z0-9_-]+\.css' <<< "$html" || true; } | head -1)
    fc=$({ grep -oE '/build/assets/sf-floating-[A-Za-z0-9_-]+\.css' <<< "$html" || true; } | head -1)
    expect "tìm thấy CSS dải thẻ và nút liên hệ trên trang chủ av1" \
        "$([ -n "$rail" ] && [ -n "$fc" ] && echo 1 || echo 0)" "1"
    # #649: wide-screen rail arrows sit at the middle of the cards (the minifier folds the two rem terms)
    expect "CSS dải thẻ av1: mũi tên màn rộng giữa các thẻ (#649)" \
        "$(curl -fsS -m 20 "https://$HOST$rail" | { grep -cF 'top:calc(50% - 2.5rem)' || true; } | awk '{print ($1 > 0)}')" "1"
    # #651: narrow bars reorder the cells so the raised call stays in the middle
    expect "CSS nút liên hệ av1: thanh hẹp xếp lại thứ tự ô (#651)" \
        "$(curl -fsS -m 20 "https://$HOST$fc" | { grep -cF 'order:var(--fc-o4,0)' || true; } | awk '{print ($1 > 0)}')" "1"
    expect "trang chủ av1: các ô thanh điện thoại mang thứ tự cho thanh hẹp (#651)" \
        "$({ grep -oF -- '--fc-o4:' <<< "$html" || true; } | wc -l | awk '{print ($1 > 0)}')" "1"
    expect "trang chủ av1 vẫn có nút Gọi nổi giữa thanh điện thoại" \
        "$({ grep -oF 'sf-fc-bar__item--up' <<< "$html" || true; } | wc -l | awk '{print ($1 > 0)}')" "1"
}
# Log: plans/reports/deploy-runs/75cef6a-353dc9f9-run-20261114-014805.log (XONG, 102 OK; full CI on main 43481956168 green after one rerun of an axe step whose first page load hit a browser error page)
