# Release file: prod 4bd80c14 → 75cef6ae (main on 14/11, after #648). Data and one-off commands only;
# the engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/4bd80c14-75cef6ae.sh
# Range: #644 (release file of the previous deploy), #640 (carousel arrows float at the sides, centred on the
# cards or the slide), #642 (sliders and rails start rotating at the visitor's first scroll, touch, mouse move
# or key, so an untouched page is still), #645 (a lighter phone menu: 16/15px rows of max(48px, 3rem), a 40px
# close button with a 48px tap area), #646 (tests only), #647 (partner logos at most 2.75rem tall and 8.5rem
# wide, names 1–1.125rem), #648 (phone contact bar: the call button raised in the middle; a built-in "Chỉ đường"
# directions channel; settings stay in the JSON settings row). CSS, JS, Blade, Livewire settings, lang and tests:
# no migration, no schedule, no env. (#640 changes one Blade partial; #646 and #642 also touch CI scripts.)
# shellcheck shell=bash disable=SC2034
OLD=4bd80c144d313a4aacf44d6066fcf1aa0910e824
TARGET=75cef6aea3b455ee2dff3115a67c713f42267e66
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
    local html rail slcss sljs sfcss
    html=$(curl -sS -m 20 "https://$HOST/?bp=$RANDOM")
    rail=$({ grep -oE '/build/assets/sf-rail-[A-Za-z0-9_-]+\.css' <<< "$html" || true; } | head -1)
    slcss=$({ grep -oE '/build/assets/sf-slider-[A-Za-z0-9_-]+\.css' <<< "$html" || true; } | head -1)
    sljs=$({ grep -oE '/build/assets/sf-slider-[A-Za-z0-9_-]+\.js' <<< "$html" || true; } | head -1)
    sfcss=$({ grep -oE '/build/assets/storefront-[A-Za-z0-9_-]+\.css' <<< "$html" || true; } | head -1)
    # Without all four files every count below would be taken from the wrong page: a check of its own first.
    expect "tìm thấy CSS/JS dải thẻ, ảnh chạy và website trên trang chủ av1" \
        "$([ -n "$rail" ] && [ -n "$slcss" ] && [ -n "$sljs" ] && [ -n "$sfcss" ] && echo 1 || echo 0)" "1"
    # #640: the partner arrows dissolve into the row's grid; the slide text keeps room for the side arrows
    expect "CSS dải thẻ av1: mũi tên đối tác đứng hai bên (#640)" \
        "$(curl -fsS -m 20 "https://$HOST$rail" | { grep -cE '[{}]\.sf-rail__controls:not\(\[hidden\]\)\{display:contents\}' || true; })" "1"
    expect "CSS ảnh chạy av1: chữ trên ảnh chừa chỗ mũi tên (#640)" \
        "$(curl -fsS -m 20 "https://$HOST$slcss" | { grep -cF 'sf-slide__content{padding:2.5rem 3rem 4.5rem}' || true; })" "1"
    # #642: rotation waits for the visitor (the arrival list includes a mouse move)
    expect "JS ảnh chạy av1 chờ khách thao tác mới chạy (#642)" \
        "$(curl -fsS -m 20 "https://$HOST$sljs" | { grep -c 'pointermove' || true; } | awk '{print ($1 > 0)}')" "1"
    # #645: the phone menu's close button has its 48px tap area
    expect "CSS website av1: nút đóng menu điện thoại có vùng bấm riêng (#645)" \
        "$(curl -fsS -m 20 "https://$HOST$sfcss" | { grep -cE '\.sf-drawer__close:{1,2}after\{' || true; } | awk '{print ($1 > 0)}')" "1"
    # #647: partner logos capped at 8.5rem wide in the plain list too
    expect "CSS dải thẻ av1: logo đối tác có giới hạn rộng (#647)" \
        "$(curl -fsS -m 20 "https://$HOST$rail" | { grep -cF 'partners__list .partners__logo{max-width:8.5rem}' || true; })" "1"
    expect "trang chủ av1 vẫn có thanh liên hệ dưới" \
        "$({ grep -oF 'class="sf-fc-bar__dot"' <<< "$html" || true; } | wc -l | awk '{print ($1 > 0)}')" "1"
    # #648: the phone bar's call button is raised in the middle (style present, and av1's bar uses it)
    local fc
    fc=$({ grep -oE '/build/assets/sf-floating-[A-Za-z0-9_-]+\.css' <<< "$html" || true; } | head -1)
    expect "CSS nút liên hệ av1 có nút Gọi nổi giữa thanh (#648)" \
        "$([ -n "$fc" ] && curl -fsS -m 20 "https://$HOST$fc" | { grep -c 'sf-fc-bar__item--up' || true; } | awk '{print ($1 > 0)}' || echo 0)" "1"
    expect "trang chủ av1 có nút Gọi nổi giữa thanh điện thoại (#648)" \
        "$({ grep -oF 'sf-fc-bar__item--up' <<< "$html" || true; } | wc -l | awk '{print ($1 > 0)}')" "1"
}
# Run 14/11/2026 00:37 (tmux deploy-75cef6ae-14003749, launched by the AI): XONG, prod at 75cef6ae; 106 OK, 0 LỖI/LỆCH.
# Log: plans/reports/deploy-runs/4bd80c1-75cef6ae-run-20261114-003749.log
