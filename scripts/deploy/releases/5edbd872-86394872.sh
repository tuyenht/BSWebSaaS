# Release file: prod 5edbd872 → 86394872 (main on 11/11, after #521–#524). Data and one-off commands only;
# the engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/5edbd872-86394872.sh
# Range: #521 (stats numbers count up from 0 when 40% in view, the first screen too), #522 (the lead
# form's Cloudflare check is fetched only when a visitor starts on the form; new Vite entry
# sf-turnstile.js), #523 (cross-document view transitions, CSS only; no smooth scroll under reduced
# motion), #524 (partner logo srcset, rail reads layout once, head: theme-color, share image size/alt,
# og:locale:alternate, article times, media preconnect on a post cover). Code, views, CSS and JS: no
# migration, no new job, no .env key, no library change.
# shellcheck shell=bash disable=SC2034
OLD=5edbd872d347747fc864458ca96ba84e0797ca69
TARGET=86394872b8a9f03cbb1babd685d4022c1c51d120
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

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
BUILD_MARKERS=("resources/css/storefront.css @view-transition" "resources/css/storefront.css sf-stat-show" "resources/js/sf-turnstile.js render=explicit" "resources/js/sf-stats.js is-counting")

ONEOFFS_PRE=()
ONEOFFS=()
release_smoke() {
    # #522: Cloudflare's script is no longer in the contact page's HTML; the loader is
    expect "trang liên hệ không còn tải sẵn mã Cloudflare (#522)" "$(has /lien-he 'turnstile/v0/api.js')" "0"
    expect "trang liên hệ có bộ tải chống spam khi chạm (#522)" "$([ "$(has /lien-he 'sf-turnstile')" -gt 0 ] && echo 1 || echo 0)" "1"
    # #524: theme-color on every page
    expect "trang chủ có màu thanh trình duyệt (#524)" "$(has / 'name="theme-color"')" "1"
    # #521: the stats numbers still print their final value in the HTML
    expect "trang giới thiệu vẫn có số liệu đếm (#521)" "$([ "$(has /gioi-thieu 'data-count')" -gt 0 ] && echo 1 || echo 0)" "1"
}
release_rollback() { # data the old code (5edbd872) cannot read
    echo "     Không có: bản này không đổi bảng, không thêm khóa cài đặt và không đổi gói nội dung. Lùi là về cách tải chống spam, số liệu và thẻ đầu trang cũ. Không mất dữ liệu."
}
