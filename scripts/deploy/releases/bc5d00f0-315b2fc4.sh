# Release file: prod bc5d00f0 → 315b2fc4 (main on 15/11). Data and one-off commands only; the engine
# (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/bc5d00f0-315b2fc4.sh
# Range:
#   #696 release file of the previous deploy;
#   #697 a content-pack menu item may name its icon (validator + planner + applier; nothing runs on deploy,
#        a pack load is a separate step);
#   #698 Trình đơn › icon picker with the board's seven groups and 95 website glyphs (StorefrontIcons,
#        lang keys in all four languages, the lazy admin-menus chunk);
#   #699 the phone menu marks the current page with a brand-tinted pill and a bar, no check mark
#        (storefront.css + two theme partials).
# No migration, no library change, no .env key, no schedule change.
# shellcheck shell=bash disable=SC2034
OLD=bc5d00f0420f06ad5a1f800fd8a3f5714f6e1f09
TARGET=315b2fc48f9888ddd8c549194325a487ae668984
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
BUILD_MARKERS=("resources/css/storefront.css --sf-dw-here-bg")

ONEOFFS_PRE=()
ONEOFFS=()
release_smoke() {
    local html code r
    # #699: the current page in the phone menu is marked by aria-current alone, with no check mark
    html=$(curl -s -m 20 "https://$HOST/nang-luc?m11=$RANDOM")
    expect "menu điện thoại không còn dấu tích ở trang hiện tại (#699)" "$(grep -c 'sf-nav__check' <<< "$html" || true)" "0"
    expect "menu điện thoại đánh dấu đúng một trang hiện tại (#699)" \
        "$(sed -n '/<nav class="sf-nav sf-nav--mob"/,/<\/nav>/p' <<< "$html" | grep -o 'aria-current="page"' | wc -l | tr -d ' ')" "1"
    # #698: the new glyphs and their labels are deployed (the screen itself needs a member)
    r=$(art tinker --execute='echo (isset(\App\Modules\Theme\Chrome\StorefrontIcons::GLYPHS["home"]) && __("menus.icon_home") !== "menus.icon_home") ? 1 : 0;' 2>&1 | tail -1 || true)
    expect "bộ biểu tượng trình đơn mới có glyph và nhãn (#698)" "$r" "1"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/admin/menus")
    expect "màn Trình đơn chuyển người chưa đăng nhập đi đăng nhập (giữ như cũ)" "$code" "302"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/admin/login")
    expect "trang đăng nhập quản trị mở được" "$code" "200"
    # earlier releases stay as they were
    html=$(curl -s -m 20 "https://$HOST/?m11=$RANDOM")
    expect "menu điện thoại nền tối theo thanh đầu trang (#690)" "$(grep -c 'class="sf-drawer sf-drawer--dark"' <<< "$html" || true)" "1"
    expect "trang chủ có đúng một chỗ giữ beacon (#688)" "$(grep -o 'data-sf-beacon=' <<< "$html" | wc -l | tr -d ' ')" "1"
    expect "bảng giới hạn chuyên mục của lời mời bật RLS + FORCE (#694)" \
        "$(q "SELECT count(*) FROM pg_class WHERE relname = 'invitation_category_scopes' AND relrowsecurity AND relforcerowsecurity")" "1"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/khong-co-trang-nay-$RANDOM")
    expect "địa chỉ không có trả 404 (giữ như cũ)" "$code" "404"
}
