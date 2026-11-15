# Release file: prod 5f238fb6 → f4fbd59d (main on 15/11). Data and one-off commands only; the engine
# (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/5f238fb6-f4fbd59d.sh
# Range:
#   #708 release file of the previous deploy;
#   #709 Storefront › card rails go round: a swipe past the last card runs on to the first, a swipe back
#        past the first to the last, and the arrows and rotation do the same. The script copies the cards
#        before and after the real ones (aria-hidden, no ids, not reachable by Tab) on rows that scroll, and
#        jumps one copy width once the row is still. New block display prop `loop` (auto|off, default auto;
#        the rail prints data-loop="off" only when off). sf-slider.js and sf-rail.css grow; PHP view data
#        gains `loop`. No schema, no library, no .env key, no schedule change.
# shellcheck shell=bash disable=SC2034
OLD=5f238fb6676d7ccd578343a075fee8443ccc66ab
TARGET=f4fbd59d70ac5bbdf105ae145d5a17c4abe77722
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
BUILD_MARKERS=("resources/js/sf-slider.js data-sf-copy" "resources/css/sf-rail.css data-sf-copy")

ONEOFFS_PRE=()
ONEOFFS=()
release_smoke() {
    local html code js ua
    ua='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
    html=$(curl -s --compressed -m 20 -A "$ua" -H 'accept: text/html' "https://$HOST/?m13=$RANDOM")
    # #709: the rails are drawn as before (copies are made in the browser, never by the server), none is
    # turned off, and the page's rail script is the new one
    expect "trang chủ vẫn có 6 dải thẻ (#709)" "$(grep -o 'data-sf-rail ' <<< "$html" | wc -l | tr -d ' ')" "6"
    expect "máy chủ không in bản sao thẻ (#709)" "$(grep -c 'data-sf-copy' <<< "$html" || true)" "0"
    expect "không dải nào tắt vòng tròn (#709)" "$(grep -c 'data-loop="off"' <<< "$html" || true)" "0"
    js=$(grep -oE '/build/assets/sf-slider-[A-Za-z0-9_-]+\.js' <<< "$html" | head -1 || true)
    expect "trang chủ nạp tệp sf-slider (#709)" "$([ -n "$js" ] && echo 1 || echo 0)" "1"
    expect "tệp sf-slider mới có vòng tròn (#709)" "$(curl -s --compressed -m 20 "https://$HOST$js" | grep -c 'data-sf-copy' || true)" "1"
    # earlier releases stay as they were
    expect "beacon của trang vẫn còn đúng một (#688)" "$(grep -o 'data-sf-beacon=' <<< "$html" | wc -l | tr -d ' ')" "1"
    expect "Cloudflare không chèn beacon thứ hai (#707)" "$(grep -c 'data-cf-beacon' <<< "$html" || true)" "0"
    expect "chân trang: các chấm nhà máy nằm chung một hàng riêng (#700)" "$(grep -c 'class="sf-rot__dots"' <<< "$html" || true)" "1"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/admin/login")
    expect "trang đăng nhập quản trị mở được" "$code" "200"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/khong-co-trang-nay-$RANDOM")
    expect "địa chỉ không có trả 404 (giữ như cũ)" "$code" "404"
}
