# Release file: prod eb85f226 → d9fceed4 (main on 15/11). Data and one-off commands only; the engine
# (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/eb85f226-d9fceed4.sh
# Range:
#   #727 release file of the previous Design deploy (no code);
#   #725 Admin › settings: new «Giao diện website» screen (/settings/appearance: logo, logo nền tối, favicon,
#        saved through SetBrandImages; route can:settings.manage, every public method authorized, picker gated
#        by media.view). The admin-panel look moves to /settings/admin-ui «Giao diện trang quản trị» (same
#        admin_theme.config). «Đăng nhập Social» now reads «Đăng nhập nhanh». Media usage rows of layout-slot
#        and branch photos carry no link (url null) instead of a wrong page.
# No migration, no library change, no .env key, no schedule change. CSS changes are Tailwind classes written
# in app/Modules views only (no file under resources/ changed), so no build string is possible; step 6's
# build and its manifest check of every input are the evidence.
# shellcheck shell=bash disable=SC2034
OLD=eb85f226957242de420e3b06980de216c19b0c20
TARGET=d9fceed45adf8ebc5c6ecf5bcb245951f1a293e5
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
    # #725: both settings screens are routed at their new addresses
    # the old address already existed (it opened the admin-panel look), so check which screen it opens now
    r=$(art tinker --execute='echo str_contains(\Illuminate\Support\Facades\Route::getRoutes()->getByName("admin.settings.appearance")?->getActionName() ?? "", "WebsiteAppearanceSettings") ? 1 : 0;' 2>&1 | tail -1 || true)
    expect "địa chỉ /settings/appearance mở «Giao diện website» (#725)" "$r" "1"
    expect "đường «Giao diện trang quản trị» đã có (#725)" "$(art route:list --name=admin.settings.admin-ui 2>&1 | grep -c 'settings/admin-ui' || true)" "1"
    r=$(art tinker --execute='app()->setLocale("vi"); echo __("admin.admin_ui") === "Giao diện trang quản trị" && __("admin.social_login") === "Đăng nhập nhanh" && __("admin.website_look") === "Giao diện website" && __("admin.look.toast_saved") !== "admin.look.toast_saved" ? 1 : 0;' 2>&1 | tail -1 || true)
    expect "tên mới của ba mục cài đặt đã có trong bản dịch (#725)" "$r" "1"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/admin/settings/appearance")
    expect "trang «Giao diện website» chuyển người chưa đăng nhập đi đăng nhập (#725)" "$code" "302"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/admin/login")
    expect "trang đăng nhập quản trị mở được" "$code" "200"
    # earlier releases stay as they were
    r=$(art tinker --execute='echo __("entries.ed.title_post") !== "entries.ed.title_post" && __("entries.ed.title_page") !== "entries.ed.title_page" ? 1 : 0;' 2>&1 | tail -1 || true)
    expect "khung soạn bài và trang còn bản dịch (#722)" "$r" "1"
    html=$(curl -s --compressed -m 20 -A "$ua" -H 'accept: text/html' "https://$HOST/?m15=$RANDOM")
    expect "trang chủ có đúng một chỗ giữ beacon (#688)" "$(grep -o 'data-sf-beacon=' <<< "$html" | wc -l | tr -d ' ')" "1"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/khong-co-trang-nay-$RANDOM")
    expect "địa chỉ không có trả 404 (giữ như cũ)" "$code" "404"
}
