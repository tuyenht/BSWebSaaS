# Release file: prod ba997a2f → 8955ca9c (main on 16/11). Data and one-off commands only; the engine
# (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/ba997a2f-8955ca9c.sh
# Range:
#   #738 / #740 release files of the previous deploys (no code);
#   #741 Admin shell follows design board 01: menu groups «Nội dung / Công cụ / Hệ thống» with the board's
#        labels and icons, a top search field (placeholder fitted to its width; magnifier overlay on phones),
#        the full logo on the phone/tablet topbar, one page trail «⌂ › mục › trang» drawn by the shell,
#        user menu chevron + role, footer text read from config('admin.brand.*') (new keys all have defaults).
# No migration, no library change, no schedule change. Admin CSS/JS changed: step 6's build and its
# manifest check are the evidence.
# shellcheck shell=bash disable=SC2034
OLD=ba997a2fa6b0213998f36e7def0758bd15921d3c
TARGET=8955ca9c2d636f9ab36c3fbb7a2bba82a6d5d60b
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /ho-so-nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

# Classes that need a human look and are in this range (the engine stops if this differs from what it finds).
DECLARED=()

MIGRATIONS=()
declare -gA MIG_OK=()
declare -gA PGB_SKIP=()
SCHEDULE_ADDED=()
SCHEDULE_REMOVED=()
# #741 footer config: every new key has a default that is right for this install (Bảo Sơn, SaaS Starter 1.0).
ENV_DEFAULT_OK=(ADMIN_BRAND_BUILT_BY_NAME ADMIN_BRAND_BUILT_BY_SHOW ADMIN_BRAND_BUILT_BY_URL ADMIN_BRAND_KIT ADMIN_BRAND_KIT_URL ADMIN_BRAND_VERSION)
SCHEMA_REMOVED_OK=()
BUILD_DYNAMIC=()
BUILD_MARKERS=()

ONEOFFS_PRE=()
ONEOFFS=()
release_smoke() {
    local html code r ua
    ua='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
    # #741: menu group names and the footer text are translated, and the footer config has its defaults
    r=$(art tinker --execute='app()->setLocale("vi"); echo __("admin.nav_group.content") === "Nội dung" && __("admin.nav_group.tools") === "Công cụ" && __("admin.nav_group.system") === "Hệ thống" && __("admin.footer_built_by") === "Phát triển bởi" ? 1 : 0;' 2>&1 | tail -1 || true)
    expect "tên ba nhóm menu và chữ chân trang có bản dịch (#741)" "$r" "1"
    r=$(art tinker --execute='echo filled(config("admin.brand.kit")) && filled(config("admin.brand.version")) && is_array(config("admin.brand.built_by")) ? 1 : 0;' 2>&1 | tail -1 || true)
    expect "cấu hình chân trang quản trị có giá trị (#741)" "$r" "1"
    r=$(art tinker --execute='echo method_exists(\App\Modules\Admin\Navigation\AdminNav::class, "trail") ? 1 : 0;' 2>&1 | tail -1 || true)
    expect "khung quản trị có đường dẫn trang chung (#741)" "$r" "1"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/admin/login")
    expect "trang đăng nhập quản trị mở được" "$code" "200"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/admin")
    expect "trang quản trị chuyển người chưa đăng nhập đi đăng nhập" "$code" "302"
    # earlier releases stay as they were
    r=$(art tinker --execute='app()->setLocale("vi"); echo __("entries.ed.title_post") !== "entries.ed.title_post" ? 1 : 0;' 2>&1 | tail -1 || true)
    expect "khung soạn bài còn bản dịch (#730)" "$r" "1"
    html=$(curl -s --compressed -m 20 -A "$ua" -H 'accept: text/html' "https://$HOST/?m16=$RANDOM")
    expect "trang chủ có đúng một chỗ giữ beacon (#688)" "$(grep -o 'data-sf-beacon=' <<< "$html" | wc -l | tr -d ' ')" "1"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/khong-co-trang-nay-$RANDOM")
    expect "địa chỉ không có trả 404 (giữ như cũ)" "$code" "404"
}
