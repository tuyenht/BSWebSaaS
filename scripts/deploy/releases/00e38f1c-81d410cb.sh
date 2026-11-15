# Release file: prod 00e38f1c → 81d410cb (main on 16/11). Data and one-off commands only; the engine
# (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/00e38f1c-81d410cb.sh
# Range:
#   #734 phone menu: a level-1 row with no icon chosen gets one automatically (link kind, then label
#        keywords in any language); "Không biểu tượng" turns it off; the menus screen shows it.
#   #735 phone menu accent (Trung tính default · Màu thương hiệu) and the "Đầu trang & menu điện thoại"
#        card on Cài đặt › Giao diện website. Neutral renders as before.
#   #730 admin post/page/product editor body: one rich-text document, two tabs; table toolbar (app.css + editor JS).
#   #733 chore only.
# No migration, no library change, no .env key, no schedule change.
# shellcheck shell=bash disable=SC2034
OLD=00e38f1cb8b9fa7b248bd8af39adc41951200efe
TARGET=81d410cb16aa1521efe1e7965c68dc9671313df0
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
BUILD_MARKERS=("resources/css/storefront.css --sf-dw-accent")

ONEOFFS_PRE=()
ONEOFFS=()
release_smoke() {
    local html code drawer ua r
    ua='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
    # #734, #735: the new words exist in every language
    r=$(art tinker --execute='$ok = true; foreach (["vi", "en", "ja", "zh"] as $l) { foreach (["menus.ui_icon_auto", "menus.ui_icon_off", "admin.look.f.accent", "admin.look.err_menu_gone"] as $k) { $ok = $ok && __($k, [], $l) !== $k; } } echo $ok ? 1 : 0;' 2>&1 | tail -1 || true)
    expect "chữ mới của biểu tượng tự động và màu nhấn có trong bốn bản dịch (#734, #735)" "$r" "1"
    # #730: the editor's words still load
    r=$(art tinker --execute='app()->setLocale("vi"); echo __("entries.ed.title_post") !== "entries.ed.title_post" ? 1 : 0;' 2>&1 | tail -1 || true)
    expect "chữ của trình sửa bài viết có bản dịch (#730)" "$r" "1"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/admin/settings/appearance")
    expect "trang «Giao diện website» chuyển người chưa đăng nhập đi đăng nhập (#735)" "$code" "302"
    html=$(curl -s --compressed -m 20 -A "$ua" -H 'accept: text/html' "https://$HOST/gioi-thieu?m16c=$RANDOM")
    drawer=${html#*<div id=\"mobile-menu\"}
    drawer=${drawer%%<noscript>*}
    # #734: av1 chose no icons; level-1 rows such as "Trang chủ" now get one (before: 0)
    expect "menu điện thoại: có biểu tượng tự động ở dòng cấp 1 (#734)" "$(grep -o 'sf-nav__ic' <<< "$drawer" | wc -l | awk '{print ($1>0)}')" "1"
    expect "menu điện thoại: bật kiểu có biểu tượng (#734)" "$(grep -c 'sf-nav--icons' <<< "$drawer" | awk '{print ($1>0)}')" "1"
    # #735: av1 keeps the default neutral accent, so nothing new is printed
    expect "menu điện thoại: màu nhấn mặc định không in biến mới (#735)" "$({ grep -o -- '--sf-dw-accent:' <<< "$html" || true; } | wc -l | tr -d ' ')" "0"
    # earlier releases stay as they were
    expect "menu điện thoại: nhóm cha vẫn là nút bấm cả dòng (#726)" "$(grep -c 'sf-nav__toggle' <<< "$drawer" | awk '{print ($1>0)}')" "1"
    expect "menu điện thoại: đúng một dòng đang xem (#718)" "$(grep -o 'aria-current="page"' <<< "$drawer" | wc -l | tr -d ' ')" "1"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/admin/login")
    expect "trang đăng nhập quản trị mở được" "$code" "200"
    html=$(curl -s --compressed -m 20 -A "$ua" -H 'accept: text/html' "https://$HOST/?m16c=$RANDOM")
    expect "trang chủ vẫn có 6 dải thẻ (#713)" "$(grep -o 'data-sf-rail ' <<< "$html" | wc -l | tr -d ' ')" "6"
    expect "trang chủ có đúng một chỗ giữ beacon (#688)" "$(grep -o 'data-sf-beacon=' <<< "$html" | wc -l | tr -d ' ')" "1"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/khong-co-trang-nay-$RANDOM")
    expect "địa chỉ không có trả 404 (giữ như cũ)" "$code" "404"
}
