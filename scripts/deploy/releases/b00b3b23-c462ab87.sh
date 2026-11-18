# Release file: prod b00b3b23 → c462ab87 (main on 18/11). Data and one-off commands only; the engine
# (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/b00b3b23-c462ab87.sh
# Range:
#   #791 the previous release file.
#   #792 admin lists: filter buttons per section from a 900 screen; the tools row wraps while the list is below 900.
# No migration, no library change, no new schedule, no new config key.
# Admin CSS/JS changed: step 6's build and its manifest check are the evidence.
# shellcheck shell=bash disable=SC2034
OLD=b00b3b23f991c0ea0e37529701c4a8705b974821
TARGET=c462ab87a22e38c684ed6e8982a8d98f33036650
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
ONEOFFS=()
release_smoke() {
    local html code ua n asset hdr cc css
    ua='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
    # earlier: #787 av1 is in the platform zone, so the home page is kept longer than the old 300 s (a day, cut to
    # the site's next midnight; at least 60 s), still revalidated by browsers, no cookie
    hdr=$(curl -sI -m 20 -A "$ua" "https://$HOST/")
    cc=$(grep -i '^cache-control:' <<< "$hdr" | tr -d '\r')
    n=$(grep -o 's-maxage=[0-9]*' <<< "$cc" | cut -d= -f2)
    expect "trang chủ lưu ở Cloudflare lâu hơn 5 phút, tới nửa đêm hoặc một ngày (#787)" "$([ -n "$n" ] && [ "$n" -gt 300 ] && [ "$n" -le 86400 ] && echo 1 || echo "0: $cc")" "1"
    expect "trang chủ vẫn bắt trình duyệt hỏi lại, không đặt cookie (#787)" "$(grep -c 'max-age=0' <<< "$cc" || true) $(grep -ci '^set-cookie:' <<< "$hdr" || true)" "1 0"
    # earlier: #787 what stays private stays private; the suggestions keep their own minute
    read -r cc < <(curl -sI -m 20 -A "$ua" "https://$HOST/admin/login" | grep -i '^cache-control:' | tr -d '\r')
    expect "trang đăng nhập quản trị không được lưu (#787)" "$(grep -c 'no-store' <<< "$cc" || true)" "1"
    read -r cc < <(curl -sI -m 20 -A "$ua" "https://$HOST/tim-kiem/goi-y?q=jig" | grep -i '^cache-control:' | tr -d '\r')
    expect "gợi ý tìm kiếm giữ thời gian lưu một phút (#787)" "$(grep -c 's-maxage=60,' <<< "$cc" || true)" "1"
    read -r cc < <(curl -sI -m 20 -A "$ua" "https://$HOST/lien-he?zz=$RANDOM" | grep -i '^cache-control:' | tr -d '\r')
    expect "địa chỉ có tham số lạ không được lưu chung (#787)" "$(grep -c 'no-store' <<< "$cc" || true)" "1"
    html=$(curl -s --compressed -m 20 -A "$ua" "https://$HOST/admin/login")
    asset=$(grep -o 'build/assets/app-[A-Za-z0-9_-]*\.css' <<< "$html" | head -1)
    css=$(curl -s --compressed -m 20 -A "$ua" "https://$HOST/$asset")
    # #792: the tools row wraps with the filters first while the list is below 900
    n=$(grep -o 'list-tools-row>.list-filters{' <<< "$css" | wc -l | tr -d ' ')
    expect "hàng công cụ xuống dòng, nút lọc lên trước khi danh sách hẹp (#792)" "$([ "$n" -ge 1 ] && echo 1 || echo 0)" "1"
    # earlier: #789 the Sắp xếp rule for a tools row under the search
    n=$(grep -o 'in-data-\\\[below' <<< "$css" | wc -l | tr -d ' ')
    expect "nút Sắp xếp đứng liền các nút lọc khi xuống dòng (#789)" "$([ "$n" -ge 1 ] && echo 1 || echo 0)" "1"
    # earlier: #782 the row «Xem» room rule
    n=$(curl -s --compressed -m 20 -A "$ua" "https://$HOST/$asset" | grep -o '\[data-x=xv\]' | wc -l | tr -d ' ')
    expect "nút Xem trên dòng theo chỗ trống (#782)" "$([ "$n" -ge 1 ] && echo 1 || echo 0)" "1"
    html=$(curl -s --compressed -m 20 -A "$ua" -H 'accept: text/html' "https://$HOST/lien-he")
    asset=$(grep -o 'build/assets/storefront-[A-Za-z0-9_-]*\.css' <<< "$html" | head -1)
    n=$(curl -s --compressed -m 20 -A "$ua" "https://$HOST/$asset" | grep -c 'hover:hover){.sf-sbtn:hover' || true)
    expect "chú thích nút tìm kiếm chỉ hiện khi rê chuột thật (#784)" "$([ "$n" -ge 1 ] && echo 1 || echo 0)" "1"
    html=$(curl -s --compressed -m 20 -A "$ua" -H 'accept: text/html' "https://$HOST/")
    n=$(grep -c 'sf-fc-bar__label">Gửi email' <<< "$html" || true)
    expect "thanh đáy hiện cả Gửi email (#758)" "$([ "$n" -ge 1 ] && echo 1 || echo 0)" "1"
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
