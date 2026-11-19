# Release file: prod bb9a78ef → 4c28da63 (main on 19/11). Data and one-off commands only; the engine
# (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/bb9a78ef-4c28da63.sh
# Range:
#   #804 the post and page editor asks before a save writes over a newer version another member saved
#        («Xem khác biệt», «Lấy bản mới», «Giữ bản của tôi»); unsaved text is kept on the device and offered
#        back («Khôi phục»); the shared dialog gains a narrow, compact alert form; texts in four languages.
#   #806 content packs and demo seeds name the website's first owner as creator («Người tạo»); the list shows
#        no avatar for an empty creator; one-off `site:fill-entry-authors` fills the empty creators already on
#        prod with each website's first owner (trashed entries too, updated_at untouched, one audit row per site).
#   #803 the previous release file.
# No migration, no library change, no new schedule, no new .env key. CSS/JS change (admin bundle).
# shellcheck shell=bash disable=SC2034
OLD=bb9a78efb3a2d64078637b9e49d72e3f230111ab
TARGET=4c28da6380461614146babe3d3bb8e43636e560d
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /ho-so-nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

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
ONEOFFS=(fill_entry_authors)
# posts and pages with no creator on a website that has an owner (what the command fills); trashed ones count
AUTHORLESS_SQL="SELECT count(*) FROM entries e WHERE e.author_id IS NULL AND EXISTS (SELECT 1 FROM model_has_roles m JOIN roles r ON r.id = m.role_id WHERE m.site_id = e.site_id AND r.site_id = e.site_id AND r.name = 'owner' AND m.model_type = 'App\\Modules\\Identity\\Models\\User')"
oneoff_fill_entry_authors() {
    local x n
    case "$1" in
        preview) q "$AUTHORLESS_SQL" ;;
        apply) x=$(art site:fill-entry-authors --dry-run 2>&1) || { printf '%s\n' "$x"; die "site:fill-entry-authors --dry-run lỗi: chưa ghi gì, nhắn AI"; }
            printf '%s\n' "$x"
            x=$(art site:fill-entry-authors 2>&1) || { printf '%s\n' "$x"; die "site:fill-entry-authors lỗi (website hỏng được ghi ở trên): chạy lại an toàn, nhắn AI"; }
            printf '%s\n' "$x"
            n=$(printf '%s\n' "$x" | sed -nE 's/^Filled ([0-9]+) creator\(s\) on [0-9]+ site\(s\)\.$/\1/p')
            [[ "$n" =~ ^[0-9]+$ ]] || die "site:fill-entry-authors không in dòng \"Filled … creator(s) …\""
            echo "OK   site:fill-entry-authors đã ghi người tạo cho $n bài (xem trước: $2)" ;;
        verify) x=$(art site:fill-entry-authors --dry-run 2>&1) || { printf '%s\n' "$x"; die "site:fill-entry-authors --dry-run (kiểm lại) lỗi"; }
            printf '%s\n' "$x" | tail -n 1
            expect "bài còn trống người tạo ở website có chủ" "$(q "$AUTHORLESS_SQL")" "0"
            # the owner lookup matched someone (a stored model_type other than the User class would make it find none)
            x=$(q "SELECT count(*) FROM model_has_roles m JOIN roles r ON r.id = m.role_id WHERE r.site_id = m.site_id AND r.name = 'owner' AND m.model_type = 'App\\Modules\\Identity\\Models\\User'")
            expect "tìm được chủ website" "$([ "${x:-0}" -ge 1 ] && echo 1 || echo 0)" "1"
            note "bài trống người tạo trên mọi website, kể cả website chưa có chủ: $(q "SELECT count(*) FROM entries WHERE author_id IS NULL")" ;;
    esac
}
release_smoke() {
    local html code ua n asset js
    ua='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
    # #806: the creator fill command is in the running code
    read -r code < <(docker exec "${PREFIX}app" php artisan list --raw 2>/dev/null | grep -c '^site:fill-entry-authors ' || true)
    expect "có lệnh điền người tạo cho bài (#806)" "$code" "1"
    # #804: the editor script keeps unsaved text on the device (a lazy chunk named in the admin script)
    html=$(curl -s --compressed -m 20 -A "$ua" "https://$HOST/admin/login")
    asset=$(grep -o 'build/assets/app-[A-Za-z0-9_-]*\.js' <<< "$html" | head -1)
    js=$(grep -o 'admin-editor-[A-Za-z0-9_-]*\.js' <<< "$(curl -s --compressed -m 20 -A "$ua" "https://$HOST/$asset")" | head -1)
    n=$(curl -s --compressed -m 20 -A "$ua" "https://$HOST/build/assets/$js" | grep -c 'bs-entry-kept:' || true)
    expect "trình sửa giữ chữ chưa lưu trên máy (#804)" "$([ -n "$js" ] && [ "$n" -ge 1 ] && echo 1 || echo 0)" "1"
    # app errors since the reload (stderr log; not a stop: printed for the report, the lines shown when any)
    n=$(docker logs --since 20m "${PREFIX}app" 2>&1 | grep -cE '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' || true)
    note "lỗi trong nhật ký ứng dụng 20 phút qua: ${n:-0}"
    [ "${n:-0}" = 0 ] || docker logs --since 20m "${PREFIX}app" 2>&1 | grep -E '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' | tail -n 5 | cut -c1-300
    # earlier: #799 the admin preview route exists (a visitor without login is sent to sign in)
    read -r code < <(curl -s -o /dev/null -m 20 -A "$ua" -w '%{http_code}\n' "https://$HOST/admin/content/01JZZZZZZZZZZZZZZZZZZZZZZZ/preview")
    expect "đường Xem trước bài viết có trong quản trị (#799)" "$code" "302"
    html=$(curl -s --compressed -m 20 -A "$ua" "https://$HOST/admin/login")
    asset=$(grep -o 'build/assets/app-[A-Za-z0-9_-]*\.js' <<< "$html" | head -1)
    n=$(curl -s --compressed -m 20 -A "$ua" "https://$HOST/$asset" | grep -c 'bsListFocus' || true)
    expect "chuyển ô chọn về dòng sau thao tác menu ⋮ (#799)" "$([ "$n" -ge 1 ] && echo 1 || echo 0)" "1"
    # earlier: #801 the disk warning level is read from config
    # (rewritten after the run: the `read -r code < <(… | tr -d '[:space:]')` form stopped step 8, since tr strips the
    # newline and read returns 1 at EOF; take a value with $(…). Rest of step 8 checked by hand, see the PR.)
    code=$(docker exec "${PREFIX}app" php artisan tinker --execute="echo config('monitoring.disk.warn_percent');" 2>/dev/null | tr -d '[:space:]' || true)
    expect "thư tổng hợp cảnh báo ổ đĩa từ 85% (#801)" "$code" "85"
    # earlier: #794 the stale window is a minute
    n=$(curl -s -o /dev/null -D - --compressed -m 20 -A "$ua" -H 'accept: text/html' "https://$HOST/gioi-thieu" | tr -d '\r' | grep -i '^cache-control:' | grep -o 'stale-while-revalidate=[0-9]*' | cut -d= -f2)
    expect "thời gian dùng bản cũ khi làm mới là 60 giây (#794)" "${n:-0}" "60"
    # earlier: #787 a public page is shared at the edge for longer than the old five minutes (cut at midnight)
    n=$(curl -s -o /dev/null -D - --compressed -m 20 -A "$ua" -H 'accept: text/html' "https://$HOST/gioi-thieu" | tr -d '\r' | grep -i '^cache-control:' | grep -o 's-maxage=[0-9]*' | cut -d= -f2)
    expect "trang công khai lưu ở Cloudflare lâu hơn 5 phút (#787)" "$([ "${n:-0}" -gt 300 ] && echo 1 || echo 0)" "1"
    read -r code < <(docker exec "${PREFIX}app" php artisan list --raw 2>/dev/null | grep -c '^storefront:purge-edge ' || true)
    expect "có lệnh xoá trang lưu Cloudflare (#787)" "$code" "1"
    html=$(curl -s --compressed -m 20 -A "$ua" "https://$HOST/admin/login")
    # #782: the admin stylesheet carries the row «Xem» room rule
    asset=$(grep -o 'build/assets/app-[A-Za-z0-9_-]*\.css' <<< "$html" | head -1)
    n=$(curl -s --compressed -m 20 -A "$ua" "https://$HOST/$asset" | grep -o '\[data-x=xv\]' | wc -l | tr -d ' ')
    expect "nút Xem trên dòng theo chỗ trống (#782)" "$([ "$n" -ge 1 ] && echo 1 || echo 0)" "1"
    # earlier: #775 the admin script carries the list-state handler (did not load → «Thử lại»)
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
    # #784: the search tip's hover rule sits behind (hover: hover)
    asset=$(grep -o 'build/assets/storefront-[A-Za-z0-9_-]*\.css' <<< "$html" | head -1)
    n=$(curl -s --compressed -m 20 -A "$ua" "https://$HOST/$asset" | grep -c 'hover:hover){.sf-sbtn:hover' || true)
    expect "chú thích nút tìm kiếm chỉ hiện khi rê chuột thật (#784)" "$([ "$n" -ge 1 ] && echo 1 || echo 0)" "1"
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
