# Release file: prod 96477302 → 9ee7030c (main on 16/11). Data and one-off commands only; the engine
# (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/96477302-9ee7030c.sh
# Range:
#   #749 release file of the previous deploy (no code);
#   #751 a storefront test only;
#   #750 storefront touch targets stay 44px when a theme sets a 14 or 15px root (CSS);
#   #747 one shared save bar for every admin settings section («Chưa có thay đổi» / «Bỏ thay đổi» / «Lưu»,
#        a lazy admin-save-bar.js chunk, Enter in a one-line field saves through the bar, the quiet outline
#        button shared by the dashboard, notifications and the bell).
# No migration, no library change, no schedule change, no new config key. Admin and storefront CSS/JS
# changed: step 6's build and its manifest check are the evidence.
# shellcheck shell=bash disable=SC2034
OLD=96477302f8ffdb108271c17194482d150a4c7de5
TARGET=9ee7030c1a2b894479c4f73664fb679db6ef824c
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
    local html code r ua
    ua='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
    # #747: the save bar's words are translated
    r=$(art tinker --execute='app()->setLocale("vi"); echo __("admin.savebar.clean") === "Chưa có thay đổi" && __("admin.savebar.region") === "Lưu thay đổi" ? 1 : 0;' 2>&1 | tail -1 || true)
    expect "chữ của thanh lưu có bản dịch (#747)" "$r" "1"
    # earlier releases stay as they were
    r=$(art tinker --execute='app()->setLocale("vi"); echo __("admin.nav_group.content") === "Nội dung" ? 1 : 0;' 2>&1 | tail -1 || true)
    expect "nhóm menu quản trị còn bản dịch (#741)" "$r" "1"
    html=$(curl -s --compressed -m 20 -A "$ua" -H 'accept: text/html' "https://$HOST/?m16b=$RANDOM")
    expect "đầu trang có nút kính lúp máy tính (#748)" "$(grep -c 'sf-sbtn--desk' <<< "$html" | tr -d ' ')" "1"
    expect "trang chủ có đúng một chỗ giữ beacon (#688)" "$(grep -o 'data-sf-beacon=' <<< "$html" | wc -l | tr -d ' ')" "1"
    read -r code < <(curl -s -o /dev/null -m 20 -A "$ua" -w '%{http_code}\n' "https://$HOST/tim-kiem?q=jig")
    expect "trang kết quả tìm kiếm mở được (#748)" "$code" "200"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/khong-co-trang-nay-$RANDOM")
    expect "địa chỉ không có trả 404 (giữ như cũ)" "$code" "404"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/admin/login")
    expect "trang đăng nhập quản trị mở được" "$code" "200"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/admin")
    expect "trang quản trị chuyển người chưa đăng nhập đi đăng nhập" "$code" "302"
}
