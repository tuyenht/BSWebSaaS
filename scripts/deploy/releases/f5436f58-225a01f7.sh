# Release file: prod f5436f58 → 225a01f7 (main on 15/11). Data and one-off commands only; the engine
# (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/f5436f58-225a01f7.sh
# Range:
#   #717 release file of the previous deploy;
#   #718 storefront phone menu on the approved grid: whole-row toggles with a "Tổng quan" row, one current
#        row by aria-current (no check mark), a lean footer (language, text size as boxed segments, socials
#        as discs; no email or hours), colours derived per site (DrawerTones), light tone by default
#        ("Theo đầu trang" stays dark like the header bar), a 44px close button.
# No migration, no library change, no .env key, no schedule change.
# shellcheck shell=bash disable=SC2034
OLD=f5436f58d4c2bf029063034cae0a0f3afcf0db47
TARGET=225a01f77d3483a154b47207d7725dbb4a8fd83b
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
BUILD_MARKERS=("resources/css/storefront.css padding-inline: 40px 24px")

ONEOFFS_PRE=()
ONEOFFS=()
release_smoke() {
    local html code drawer ua
    ua='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
    html=$(curl -s --compressed -m 20 -A "$ua" -H 'accept: text/html' "https://$HOST/gioi-thieu?m15b=$RANDOM")
    drawer=${html#*<div id=\"mobile-menu\"}
    drawer=${drawer%%<noscript>*}
    # #718: the phone menu's new markup
    expect "menu điện thoại: hàng cha là một nút bấm cả dòng (#718)" "$(grep -c 'sf-nav__toggle' <<< "$drawer" | awk '{print ($1>0)}')" "1"
    expect "menu điện thoại: không còn dấu ✓ (#718)" "$(grep -c 'sf-nav__check' <<< "$html")" "0"
    expect "menu điện thoại: đúng một dòng đang xem (#718)" "$(grep -o 'aria-current="page"' <<< "$drawer" | wc -l | tr -d ' ')" "1"
    expect "menu điện thoại: cỡ chữ là ô bấm (#718)" "$(grep -c 'sf-seg sf-seg--fs' <<< "$drawer" | awk '{print ($1>0)}')" "1"
    expect "menu điện thoại: màu riêng của website (#718)" "$(grep -c -- '--sf-dw-bg' <<< "$drawer" | awk '{print ($1>0)}')" "1"
    # earlier releases stay as they were
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/admin/login")
    expect "trang đăng nhập quản trị mở được" "$code" "200"
    html=$(curl -s --compressed -m 20 -A "$ua" -H 'accept: text/html' "https://$HOST/?m15b=$RANDOM")
    expect "trang chủ vẫn có 6 dải thẻ (#713)" "$(grep -o 'data-sf-rail ' <<< "$html" | wc -l | tr -d ' ')" "6"
    expect "trang chủ có đúng một chỗ giữ beacon (#688)" "$(grep -o 'data-sf-beacon=' <<< "$html" | wc -l | tr -d ' ')" "1"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/khong-co-trang-nay-$RANDOM")
    expect "địa chỉ không có trả 404 (giữ như cũ)" "$code" "404"
}
