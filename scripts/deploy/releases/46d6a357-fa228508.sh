# Release file: prod 46d6a357 → fa228508 (main on 18/11). Data and one-off commands only; the engine
# (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/46d6a357-fa228508.sh
# Range (admin «Quản lý bài viết», posts and pages lists):
#   #771 the guide offers itself on a first visit; the bubble dims the page; role card in its tour; pages guide.
#   #772 list headers put «Hướng dẫn» beside the main button; columns fold by measured room («Người tạo»).
#   #773 each row offers only what the member may do there, locked items say why.
#   #774 «Chuyển vào Thùng rác» at once with «Hoàn tác»; counted bulk bar; the Trash empties after 30 days.
#   #775 list states: nothing yet, no match, loading, did not load (with «Thử lại»).
#   #776 publishing or taking an entry offline needs the right to edit it.
#   #777 the pages list's phone line; #778 status changes judge the row under a lock; #779 test only.
# No migration, no library change. New schedule: entries:purge-trash (daily; counts from 18/11, so nothing
# already in the Trash is removed before 18/12). New config key with a default: CMS_TRASH_RETENTION_DAYS (30).
# Admin JS/CSS changed: step 6's build and its manifest check are the evidence.
# shellcheck shell=bash disable=SC2034
OLD=46d6a3570f69c02ed2755402b0a56e88ec208532
TARGET=fa228508fa5c1fcc4a60437520ae09a7d20db06f
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /ho-so-nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

DECLARED=(schedule)

MIGRATIONS=()
declare -gA MIG_OK=()
declare -gA PGB_SKIP=()
SCHEDULE_ADDED=(entries:purge-trash)
SCHEDULE_REMOVED=()
ENV_DEFAULT_OK=(CMS_TRASH_RETENTION_DAYS)
SCHEMA_REMOVED_OK=()
BUILD_DYNAMIC=()
BUILD_MARKERS=()

ONEOFFS_PRE=()
ONEOFFS=()
release_smoke() {
    local html code ua n asset js
    ua='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
    html=$(curl -s --compressed -m 20 -A "$ua" "https://$HOST/admin/login")
    # #775: the admin script carries the list-state handler (did not load → «Thử lại»)
    asset=$(grep -o 'build/assets/app-[A-Za-z0-9_-]*\.js' <<< "$html" | head -1)
    n=$(curl -s --compressed -m 20 -A "$ua" "https://$HOST/$asset" | grep -c 'bs-list-failed' || true)
    expect "danh sách bài có trạng thái tải lỗi (#775)" "$([ "$n" -ge 1 ] && echo 1 || echo 0)" "1"
    # earlier: #767 the suggestions answer carries the result count fields
    n=$(curl -s --compressed -m 20 -A "$ua" "https://$HOST/tim-kiem/goi-y?q=jig$RANDOM" | grep -c '"total_more"' || true)
    expect "gợi ý tìm kiếm có số kết quả (#767)" "$([ "$n" -ge 1 ] && echo 1 || echo 0)" "1"
    # earlier: #765 the admin script loads the guide module on the first press of «Hướng dẫn» (a lazy chunk)
    asset=$(grep -o 'build/assets/app-[A-Za-z0-9_-]*\.js' <<< "$html" | head -1)
    n=$(curl -s --compressed -m 20 -A "$ua" "https://$HOST/$asset" | grep -c 'admin-guide' || true)
    expect "nút Hướng dẫn tải được phần hướng dẫn (#765)" "$([ "$n" -ge 1 ] && echo 1 || echo 0)" "1"
    html=$(curl -s --compressed -m 20 -A "$ua" -H 'accept: text/html' "https://$HOST/lien-he?m16=$RANDOM")
    # earlier: #762 the footer's factory cards turn by swipe and every 5 s (the old script said 3e3, no touchcancel)
    asset=$(grep -o 'build/assets/sf-footer-rot-[A-Za-z0-9_-]*\.js' <<< "$html" | head -1)
    js=$(curl -s --compressed -m 20 -A "$ua" "https://$HOST/$asset")
    n=$(grep -c 'touchcancel' <<< "$js" || true)
    expect "thẻ Nhà máy vuốt được (#762)" "$([ "$n" -ge 1 ] && echo 1 || echo 0)" "1"
    n=$(grep -c '5e3' <<< "$js" || true)
    expect "thẻ Nhà máy tự đổi mỗi 5 giây (#762)" "$([ "$n" -ge 1 ] && echo 1 || echo 0)" "1"
    html=$(curl -s --compressed -m 20 -A "$ua" -H 'accept: text/html' "https://$HOST/jig-kiem-tap-lo-vf7?m16=$RANDOM")
    asset=$(grep -o 'build/assets/sf-gallery-[A-Za-z0-9_-]*\.js' <<< "$html" | head -1)
    n=$(curl -s --compressed -m 20 -A "$ua" "https://$HOST/$asset" | grep -c 'touchend' || true)
    expect "ảnh sản phẩm vuốt được (#762)" "$([ "$n" -ge 1 ] && echo 1 || echo 0)" "1"
    html=$(curl -s --compressed -m 20 -A "$ua" -H 'accept: text/html' "https://$HOST/?m16=$RANDOM")
    n=$(grep -c 'sf-fc-bar__label">Gửi email' <<< "$html" || true)
    expect "thanh đáy hiện cả Gửi email (#758)" "$([ "$n" -ge 1 ] && echo 1 || echo 0)" "1"
    # earlier releases stay as they were (status codes by -w, never the first header line: 103 comes first)
    expect "trang chủ có đúng một chỗ giữ beacon (#688)" "$(grep -o 'data-sf-beacon=' <<< "$html" | wc -l | tr -d ' ')" "1"
    read -r code < <(curl -s -o /dev/null -m 20 -A "$ua" -w '%{http_code}\n' "https://$HOST/tim-kiem/goi-y?q=jig")
    expect "gợi ý khi gõ trả lời (#752)" "$code" "200"
    read -r code < <(curl -s -o /dev/null -m 20 -A "$ua" -H 'accept: text/html' -w '%{http_code}\n' "https://$HOST/tim-kiem?q=jig")
    expect "trang kết quả tìm kiếm mở được (#748)" "$code" "200"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/khong-co-trang-nay-$RANDOM")
    expect "địa chỉ không có trả 404 (giữ như cũ)" "$code" "404"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/admin/login")
    expect "trang đăng nhập quản trị mở được" "$code" "200"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/admin")
    expect "trang quản trị chuyển người chưa đăng nhập đi đăng nhập" "$code" "302"
}
