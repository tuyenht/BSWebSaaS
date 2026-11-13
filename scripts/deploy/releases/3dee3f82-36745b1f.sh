# Release file: prod 3dee3f82 → 36745b1f (main on 13/11, after #640). Data and one-off commands only;
# the engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/3dee3f82-36745b1f.sh
# Range: #636 and #639 (release files of earlier deploys), #637 (storefront: every page has a meta description, even on a
# site that has not described itself), #638 (phone contact bar: the wide-cell 13px label rule that never applied is
# removed; labels stay 12px), #641 (storefront: secondary text stays readable whatever body colour a site picks),
# #640 (carousel arrows of the hero slider and the card rails float at the left/right edges, centred on the slide or
# the cards, below 1440px; the hero shows its arrows on phones again). PHP rendering, CSS, Blade partials, tests and
# CI only: no migration, no schedule, no env.
# shellcheck shell=bash disable=SC2034
OLD=3dee3f829827edcb5883915be8300d0d79e14e76
TARGET=36745b1f50f023404795b08460d3bac308169028
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
    local html rail slider floating
    html=$(curl -s -m 20 "https://$HOST/?bp=$RANDOM")
    rail=$({ grep -oE '/build/assets/sf-rail-[A-Za-z0-9_-]+\.css' <<< "$html" || true; } | head -1)
    slider=$({ grep -oE '/build/assets/sf-slider-[A-Za-z0-9_-]+\.css' <<< "$html" || true; } | head -1)
    floating=$({ grep -oE '/build/assets/sf-floating-[A-Za-z0-9_-]+\.css' <<< "$html" || true; } | head -1)
    # Without the three files every count below would be taken from the wrong page: a check of its own first.
    expect "tìm thấy CSS dãy thẻ, ảnh trượt và nút liên hệ của trang chủ av1" \
        "$([ -n "$rail" ] && [ -n "$slider" ] && [ -n "$floating" ] && echo 1 || echo 0)" "1"
    # #640: below 1440px the rail's controls dissolve into the rail's grid, so the arrows float over the cards' edges
    expect "CSS dãy thẻ av1 đặt nút trái/phải nổi trên mép thẻ (#640)" \
        "$(curl -fsS -m 20 "https://$HOST$rail" | { grep -c 'sf-rail__controls:not(\[hidden\]){display:contents}' || true; } | awk '{print ($1 > 0)}')" "1"
    # #640: on a phone the hero's text keeps 3rem each side for the side arrows
    expect "CSS ảnh trượt av1 chừa 3rem hai bên chữ cho nút trái/phải (#640)" \
        "$(curl -fsS -m 20 "https://$HOST$slider" | { grep -c 'sf-slide__content{padding:2.5rem 3rem 4.5rem}' || true; } | awk '{print ($1 > 0)}')" "1"
    # #638: no 13px label rule left in the contact bar's stylesheet
    expect "CSS nút liên hệ av1 không còn cỡ chữ nhãn 13px (#638)" \
        "$(curl -fsS -m 20 "https://$HOST$floating" | { grep -c 'sf-fc-bar__label{font-size:.8125rem}' || true; })" "0"
    expect "trang chủ av1 vẫn có thanh liên hệ dưới" \
        "$({ grep -oF 'class="sf-fc-bar__dot"' <<< "$html" || true; } | wc -l | awk '{print ($1 > 0)}')" "1"
    # #637: the home page carries a meta description
    expect "trang chủ av1 có mô tả trang (#637)" \
        "$({ grep -oE '<meta name="description" content="[^"]+' <<< "$html" || true; } | wc -l | awk '{print ($1 > 0)}')" "1"
}
