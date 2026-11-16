# Release file: prod ca9d5b41 → 07a05201 (main on 16/11). Data and one-off commands only; the engine
# (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/ca9d5b41-07a05201.sh
# Range:
#   #757 release file of the previous deploy (no code);
#   #756 Storefront search suggestions answer sooner: 130 ms wait, answers kept in the page, request sent
#        while the stylesheet loads, a composing keyboard asks too, the code fetched on the first press;
#        the suggestion route runs without a session and a 200 may be kept 60 s (+60 s stale) at the edge,
#        only `q` varying it; the host purge on every public write clears it. No migration.
# No migration, no library change, no schedule change. Two new env keys (STOREFRONT_SUGGEST_S_MAXAGE,
# STOREFRONT_SUGGEST_SWR) whose defaults (60, 60) are the intended values. Storefront JS changed: step 6's
# build and its manifest check are the evidence.
# shellcheck shell=bash disable=SC2034
OLD=ca9d5b419d88e73ec3617c8412a3830bd3e32fc4
TARGET=07a0520193f2338f139ba23793ddac12bd8b2d43
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /ho-so-nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

# Classes that need a human look and are in this range (the engine stops if this differs from what it finds).
DECLARED=()

MIGRATIONS=()
declare -gA MIG_OK=()
declare -gA PGB_SKIP=()
SCHEDULE_ADDED=()
SCHEDULE_REMOVED=()
ENV_DEFAULT_OK=(STOREFRONT_SUGGEST_S_MAXAGE STOREFRONT_SUGGEST_SWR)
SCHEMA_REMOVED_OK=()
BUILD_DYNAMIC=()
BUILD_MARKERS=()

ONEOFFS_PRE=()
ONEOFFS=()
release_smoke() {
    local html code r ua hdr n
    ua='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
    # #755 (previous deploy): the admin lists read the new words
    r=$(art tinker --execute='app()->setLocale("vi"); echo __("entries.status_published")."|".__("entries.status_archived")."|".(__("admin.list.edited_today", ["time" => "10:20"]));' 2>&1 | tail -1 || true)
    expect "chữ trạng thái và ngày sửa mới của danh sách bài viết (#755)" "$r" "Đã đăng|Đã gỡ khỏi website|Sửa hôm nay, 10:20"
    # earlier releases stay as they were
    # #752: words translated, no content holds the suggestions address, the endpoint answers JSON and is never cached
    r=$(art tinker --execute='app()->setLocale("vi"); echo __("storefront.search.suggest_none") !== "storefront.search.suggest_none" ? 1 : 0;' 2>&1 | tail -1 || true)
    expect "chữ của gợi ý khi gõ có bản dịch (#752)" "$r" "1"
    expect "không nội dung nào giữ địa chỉ /tim-kiem/goi-y (#752)" "$(q "SELECT count(*) FROM routes WHERE lower(path) = '/tim-kiem/goi-y'")" "0"
    hdr=$(curl -s -m 20 -A "$ua" -D - -o /dev/null "https://$HOST/tim-kiem/goi-y?q=jig")
    expect "gợi ý khi gõ trả lời (#752)" "$(grep -cE '^HTTP/[0-9.]+ 200' <<< "$hdr")" "1"
    expect "gợi ý khi gõ là JSON (#752)" "$(grep -ciE '^content-type: *application/json' <<< "$hdr")" "1"
    expect "gợi ý khi gõ được giữ ở biên tối đa 60 giây (#756)" "$(grep -ciE '^cache-control:.*public.*s-maxage=60' <<< "$hdr")" "1"
    expect "gợi ý khi gõ không đặt cookie (#756)" "$(grep -ciE '^set-cookie:' <<< "$hdr")" "0"
    # the edge key keeps the typed words: other words get their own answer, never the cached one
    r=$(curl -s -m 20 -A "$ua" "https://$HOST/tim-kiem/goi-y?q=cnc" | grep -c '"q":"cnc"' || true)
    expect "chữ gõ khác nhận câu trả lời của chính nó (#756)" "$r" "1"
    hdr=$(curl -s -m 20 -A "$ua" -D - -o /dev/null "https://$HOST/tim-kiem/goi-y?q=jig")
    echo "     (chỉ để xem) lần hỏi lại «jig» ở biên: $(grep -iE '^cf-cache-status' <<< "$hdr" | tr -d '\r')"
    r=$(curl -s -m 20 -A "$ua" -D - -o /dev/null "https://$HOST/tim-kiem/goi-y?q=jig&utm_source=x" | grep -ciE '^cache-control:.*(no-store|private)' || true)
    expect "gợi ý có tham số lạ thì không giữ ở biên (#756)" "$r" "1"
    r=$(curl -s -m 20 -A "$ua" "https://$HOST/tim-kiem/goi-y?q=jig" | grep -c '"items":\[' || true)
    expect "gợi ý khi gõ có danh sách (#752)" "$r" "1"
    html=$(curl -s --compressed -m 20 -A "$ua" -H 'accept: text/html' "https://$HOST/?m16=$RANDOM")
    n=$(grep -c 'data-suggest="' <<< "$html" || true)
    expect "ô tìm ở đầu trang gọi gợi ý (#752)" "$([ "$n" -ge 1 ] && echo 1 || echo 0)" "1"
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
