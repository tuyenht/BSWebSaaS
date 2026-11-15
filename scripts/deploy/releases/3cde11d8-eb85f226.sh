# Release file: prod 3cde11d8 → eb85f226 (main on 15/11). Data and one-off commands only; the engine
# (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/3cde11d8-eb85f226.sh
# Range:
#   #726 storefront phone menu behaviour: one first-level group open at a time (the tapped row keeps its
#        place), the current row scrolled into view on open, a page from the back/forward cache never shows
#        an open menu, three levels at most in the drawer (deeper rows follow their level-3 row), menu
#        "active" and the trail compare normalised paths (Shared\Support\MenuPath), and the language
#        control by count (4–7 a list under a row, 8+ a screen with search).
# No migration, no library change, no .env key, no schedule change.
# shellcheck shell=bash disable=SC2034
OLD=3cde11d8405d56d3f650eb451e41fa14e9527996
TARGET=eb85f226957242de420e3b06980de216c19b0c20
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
BUILD_MARKERS=("resources/css/storefront.css sf-lang-sub")

ONEOFFS_PRE=()
ONEOFFS=()
release_smoke() {
    local html code drawer ua
    ua='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
    html=$(curl -s --compressed -m 20 -A "$ua" -H 'accept: text/html' "https://$HOST/gioi-thieu?m15b=$RANDOM")
    drawer=${html#*<div id=\"mobile-menu\"}
    drawer=${drawer%%<noscript>*}
    # #718: the phone menu's new markup
    expect "menu điện thoại: hàng cha là một nút bấm cả dòng (#718)" "$(grep -c 'sf-nav__toggle' <<< "$drawer" | awk '{print ($1>0)}')" "1"
    expect "menu điện thoại: không còn dấu ✓ (#718)" "$(grep -c 'sf-nav__check' <<< "$html" || true)" "0"
    expect "menu điện thoại: đúng một dòng đang xem (#718)" "$(grep -o 'aria-current="page"' <<< "$drawer" | wc -l | tr -d ' ')" "1"
    expect "menu điện thoại: cỡ chữ là ô bấm (#718)" "$(grep -c 'sf-seg sf-seg--fs' <<< "$drawer" | awk '{print ($1>0)}')" "1"
    expect "menu điện thoại: màu riêng của website (#718)" "$(grep -c -- '--sf-dw-bg' <<< "$drawer" | awk '{print ($1>0)}')" "1"
    # #719: the restore notice text and the content route are there
    r=$(art tinker --execute='echo __("entries.restored", ["noun" => "x"]) !== "entries.restored" ? 1 : 0;' 2>&1 | tail -1 || true)
    expect "chữ «đã khôi phục» có trong bản dịch (#719)" "$r" "1"
    # #720: the settings screen edits Instagram and LinkedIn; Zalo sits in the contact group
    r=$(art tinker --execute='echo (in_array("instagram", App\Modules\Admin\Support\GeneralSettingsStore::SOCIAL, true) && in_array("linkedin", App\Modules\Admin\Support\GeneralSettingsStore::SOCIAL, true) && array_column(App\Modules\Admin\Livewire\GeneralSettings::GROUPS["contact"], 0)[1] === "zalo") ? 1 : 0;' 2>&1 | tail -1 || true)
    expect "Instagram, LinkedIn trong cài đặt; Zalo ở nhóm Liên hệ (#720)" "$r" "1"
    # #726: menu paths are compared normalised (same host, no query, no trailing slash; another host never matches)
    r=$(art tinker --execute='use App\Modules\Shared\Support\MenuPath; echo (MenuPath::normalize("https://av1.baoson.net/du-an/?a=1#x", "av1.baoson.net") === "/du-an" && MenuPath::normalize("https://example.com/du-an", "av1.baoson.net") === null) ? 1 : 0;' 2>&1 | tail -1 || true)
    expect "menu so đường dẫn đã chuẩn hoá (#726)" "$r" "1"
    expect "menu điện thoại: nhóm cha vẫn là nút bấm cả dòng (#726)" "$(grep -c 'sf-nav__toggle' <<< "$drawer" | awk '{print ($1>0)}')" "1"
    # earlier releases stay as they were
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/admin/login")
    expect "trang đăng nhập quản trị mở được" "$code" "200"
    html=$(curl -s --compressed -m 20 -A "$ua" -H 'accept: text/html' "https://$HOST/?m15b=$RANDOM")
    expect "trang chủ vẫn có 6 dải thẻ (#713)" "$(grep -o 'data-sf-rail ' <<< "$html" | wc -l | tr -d ' ')" "6"
    expect "trang chủ có đúng một chỗ giữ beacon (#688)" "$(grep -o 'data-sf-beacon=' <<< "$html" | wc -l | tr -d ' ')" "1"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/khong-co-trang-nay-$RANDOM")
    expect "địa chỉ không có trả 404 (giữ như cũ)" "$code" "404"
}
