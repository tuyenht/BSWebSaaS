# Release file: prod 8955ca9c → 96477302 (main on 16/11). Data and one-off commands only; the engine
# (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/8955ca9c-96477302.sh
# Range:
#   #742 release file of the previous deploy (no code);
#   #748 Storefront site search: results page /tim-kiem (noindex, private no-store, rate limited per site + IP),
#        a magnifier in the header with a drop-down panel, a search box on the 404 page, the admin switch
#        «Có tìm kiếm» (absent = on), and /tim-kiem reserved for new content (content already there keeps it).
#        Reads the word tables of #678 (already on prod); no migration.
# No migration, no library change, no schedule change, no new config key. Storefront CSS/JS changed: step 6's
# build and its manifest check are the evidence.
# shellcheck shell=bash disable=SC2034
OLD=8955ca9c2d636f9ab36c3fbb7a2bba82a6d5d60b
TARGET=96477302f8ffdb108271c17194482d150a4c7de5
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
    local html code r ua hdr
    ua='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
    # #748: words translated, no content holds the search address, the page answers and stays out of indexes and caches
    r=$(art tinker --execute='app()->setLocale("vi"); echo __("storefront.search.title") === "Kết quả tìm kiếm" && __("admin.look.f.search") === "Có tìm kiếm" && __("entries.slug_reserved") !== "entries.slug_reserved" ? 1 : 0;' 2>&1 | tail -1 || true)
    expect "chữ của tìm kiếm và công tắc quản trị có bản dịch (#748)" "$r" "1"
    expect "không nội dung nào giữ địa chỉ /tim-kiem (#748)" "$(q "SELECT count(*) FROM routes WHERE lower(path) = '/tim-kiem'")" "0"
    hdr=$(curl -s --compressed -m 20 -A "$ua" -H 'accept: text/html' -D - -o /dev/null "https://$HOST/tim-kiem?q=jig")
    expect "trang kết quả tìm kiếm mở được (#748)" "$(head -1 <<< "$hdr" | grep -cE ' 200')" "1"
    expect "trang kết quả không lưu trong bộ đệm (#748)" "$(grep -ciE '^cache-control:.*no-store' <<< "$hdr")" "1"
    html=$(curl -s --compressed -m 20 -A "$ua" -H 'accept: text/html' "https://$HOST/tim-kiem?q=jig")
    expect "trang kết quả có noindex (#748)" "$(grep -cE '<meta name="robots" content="noindex' <<< "$html")" "1"
    html=$(curl -s --compressed -m 20 -A "$ua" -H 'accept: text/html' "https://$HOST/?m16=$RANDOM")
    # the header draws the desktop magnifier and the phone-corner one (CSS shows one); this line first counted
    # every data-search-open (2) and stopped the run here; the rest of step 8 was checked by hand (public curls)
    expect "đầu trang có nút kính lúp máy tính (#748)" "$(grep -c 'sf-sbtn--desk' <<< "$html" | tr -d ' ')" "1"
    expect "trang chủ có đúng một chỗ giữ beacon (#688)" "$(grep -o 'data-sf-beacon=' <<< "$html" | wc -l | tr -d ' ')" "1"
    html=$(curl -s --compressed -m 20 -A "$ua" -H 'accept: text/html' "https://$HOST/khong-co-trang-nay-$RANDOM")
    expect "trang 404 có ô tìm (#748)" "$(grep -c 'id="sf-404-q"' <<< "$html" | tr -d ' ')" "1"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/khong-co-trang-nay-$RANDOM")
    expect "địa chỉ không có trả 404 (giữ như cũ)" "$code" "404"
    # earlier releases stay as they were
    r=$(art tinker --execute='app()->setLocale("vi"); echo __("admin.nav_group.content") === "Nội dung" ? 1 : 0;' 2>&1 | tail -1 || true)
    expect "nhóm menu quản trị còn bản dịch (#741)" "$r" "1"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/admin/login")
    expect "trang đăng nhập quản trị mở được" "$code" "200"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/admin")
    expect "trang quản trị chuyển người chưa đăng nhập đi đăng nhập" "$code" "302"
}
