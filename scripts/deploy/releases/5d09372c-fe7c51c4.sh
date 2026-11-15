# Release file: prod 5d09372c → fe7c51c4 (main on 15/11). Data and one-off commands only; the engine
# (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/5d09372c-fe7c51c4.sh
# Range:
#   #712 release file of the previous deploy;
#   #713 Storefront › list rails: `layout: slider_small` (a grid from 1024px, a row that scrolls on a tablet)
#        and the display prop `advance` (auto|item|view: move one card or every card in view). Blocks saved
#        without `advance` move one card as before, so av1 renders as it did; the rail prints
#        data-advance="view" only when it applies. A row of more than 24 cards no longer loops.
#        sf-slider.js and sf-rail.css grow; PHP view data gains `advance`.
# No migration, no library change, no .env key, no schedule change.
# shellcheck shell=bash disable=SC2034
OLD=5d09372c701e9c1dfe66b691fb9ed50b89638cd9
TARGET=fe7c51c430d0f2be30402c13ffa4fc679c6af574
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
BUILD_MARKERS=("resources/js/sf-slider.js data-sf-page" "resources/css/sf-rail.css is-paged")

ONEOFFS_PRE=()
ONEOFFS=()
release_smoke() {
    local html code js ua
    ua='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
    html=$(curl -s --compressed -m 20 -A "$ua" -H 'accept: text/html' "https://$HOST/?m13=$RANDOM")
    # #713: the saved rails are drawn as before (no block of av1 carries `advance`), and the rail script is the new one
    expect "trang chủ vẫn có 6 dải thẻ (#713)" "$(grep -o 'data-sf-rail ' <<< "$html" | wc -l | tr -d ' ')" "6"
    expect "khối đã lưu vẫn chuyển từng thẻ (#713)" "$(grep -c 'data-advance=' <<< "$html" || true)" "0"
    expect "máy chủ không in bản sao thẻ (#709)" "$(grep -c 'data-sf-copy' <<< "$html" || true)" "0"
    js=$(grep -oE '/build/assets/sf-slider-[A-Za-z0-9_-]+\.js' <<< "$html" | head -1 || true)
    expect "trang chủ nạp tệp sf-slider (#713)" "$([ -n "$js" ] && echo 1 || echo 0)" "1"
    expect "tệp sf-slider mới chuyển được theo nhóm (#713)" "$(curl -s --compressed -m 20 "https://$HOST$js" | grep -c 'data-sf-page' || true)" "1"
    # earlier releases stay as they were
    expect "beacon của trang vẫn còn đúng một (#688)" "$(grep -o 'data-sf-beacon=' <<< "$html" | wc -l | tr -d ' ')" "1"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/admin/login")
    expect "trang đăng nhập quản trị mở được" "$code" "200"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/khong-co-trang-nay-$RANDOM")
    expect "địa chỉ không có trả 404 (giữ như cũ)" "$code" "404"
}
