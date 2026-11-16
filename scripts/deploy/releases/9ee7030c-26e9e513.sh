# Release file: prod 9ee7030c → 26e9e513 (main on 16/11). Data and one-off commands only; the engine
# (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/9ee7030c-26e9e513.sh
# Range:
#   #753 release file of the previous deploy (no code);
#   #752 Storefront search suggestions while typing: JSON endpoint /tim-kiem/goi-y (private no-store, noindex,
#        rate limited per site + IP; /tim-kiem/goi-y reserved for new content), a lazy script loaded on the first
#        focus of a search box, and its styles. Reads the word tables already on prod; no migration.
# No migration, no library change, no schedule change, no new config key. Storefront CSS/JS changed: step 6's
# build and its manifest check are the evidence.
# shellcheck shell=bash disable=SC2034
OLD=9ee7030c1a2b894479c4f73664fb679db6ef824c
TARGET=26e9e513d6fd49a5969cb2af8187eea0a478567d
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
    local html code r ua hdr n
    ua='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
    # #752: words translated, no content holds the suggestions address, the endpoint answers JSON and is never cached
    r=$(art tinker --execute='app()->setLocale("vi"); echo __("storefront.search.suggest_none") !== "storefront.search.suggest_none" ? 1 : 0;' 2>&1 | tail -1 || true)
    expect "chữ của gợi ý khi gõ có bản dịch (#752)" "$r" "1"
    expect "không nội dung nào giữ địa chỉ /tim-kiem/goi-y (#752)" "$(q "SELECT count(*) FROM routes WHERE lower(path) = '/tim-kiem/goi-y'")" "0"
    hdr=$(curl -s -m 20 -A "$ua" -D - -o /dev/null "https://$HOST/tim-kiem/goi-y?q=jig")
    expect "gợi ý khi gõ trả lời (#752)" "$(grep -cE '^HTTP/[0-9.]+ 200' <<< "$hdr")" "1"
    expect "gợi ý khi gõ là JSON (#752)" "$(grep -ciE '^content-type: *application/json' <<< "$hdr")" "1"
    expect "gợi ý khi gõ không lưu trong bộ đệm (#752)" "$(grep -ciE '^cache-control:.*no-store' <<< "$hdr")" "1"
    r=$(curl -s -m 20 -A "$ua" "https://$HOST/tim-kiem/goi-y?q=jig" | grep -c '"items":\[' || true)
    expect "gợi ý khi gõ có danh sách (#752)" "$r" "1"
    html=$(curl -s --compressed -m 20 -A "$ua" -H 'accept: text/html' "https://$HOST/?m16=$RANDOM")
    n=$(grep -c 'data-suggest="' <<< "$html" || true)
    expect "ô tìm ở đầu trang gọi gợi ý (#752)" "$([ "$n" -ge 1 ] && echo 1 || echo 0)" "1"
    # earlier releases stay as they were
    expect "trang chủ có đúng một chỗ giữ beacon (#688)" "$(grep -o 'data-sf-beacon=' <<< "$html" | wc -l | tr -d ' ')" "1"
    hdr=$(curl -s --compressed -m 20 -A "$ua" -H 'accept: text/html' -D - -o /dev/null "https://$HOST/tim-kiem?q=jig")
    # an HTML page may answer 103 Early Hints first: look for the final 200 status line (this line first read
    # only the first status line, got 103 and stopped the run; the rest of step 8 was checked by hand)
    expect "trang kết quả tìm kiếm mở được (#748)" "$(grep -cE '^HTTP/[0-9.]+ 200' <<< "$hdr")" "1"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/khong-co-trang-nay-$RANDOM")
    expect "địa chỉ không có trả 404 (giữ như cũ)" "$code" "404"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/admin/login")
    expect "trang đăng nhập quản trị mở được" "$code" "200"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/admin")
    expect "trang quản trị chuyển người chưa đăng nhập đi đăng nhập" "$code" "302"
}
