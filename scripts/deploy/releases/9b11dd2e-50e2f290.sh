# Release file: prod 9b11dd2e → 50e2f290 (main on 13/11, after #582, #583, #584, #585, #586, #588, #589). Data and
# one-off commands only; the engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/9b11dd2e-50e2f290.sh
# Range: #582 (release file kept), #583 (operator console: "Gói & tính năng" screens, routes /plans, /plans/new,
# /plans/{key} on the platform host; no migration), #584 (theme: only entry_grid / product_grid offer "view all"),
# #585 (admin "Tổng quan" dashboard cards from existing data; no migration), #586 (floating contact setting learns its
# v2 fields, no visible change), #588 (contact button tap counter: new tenant table contact_click_daily (migration
# 2056, RLS + FORCE), public POST /_click (no session, no cookie, crawlers not counted), schedules
# contact:flush-clicks every 5 minutes and contact:prune-clicks daily), #589 (floating contact widget's new look:
# the root is now <div class="sf-fc sf-fc--combo" data-fc-root>, a bottom sheet, and the "Chép số" chunk
# resources/js/sf-fc-copy.js, a Vite input the widget imports on first touch). No library change, no env key.
# Run 13/11 10:06 (log plans/reports/deploy-runs/9b11dd2-50e2f290-run-20261113-100656.log): migration 2056 ran in
# 27.51 ms, every step and every release_smoke check passed (156 OK), 0 app error lines.
# shellcheck shell=bash disable=SC2034
OLD=9b11dd2e676a525a1714214e96d8361e3cd39c36
TARGET=50e2f290c765b3583d8b799ab83c47d9c11414dd
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /ho-so-nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

# Classes that need a human look and are in this range (the engine stops if this differs from what it finds).
DECLARED=(migration schedule)

MIGRATIONS=(
    2056_01_01_000000_create_contact_click_daily_table
)
# Raw SQL (lock_timeout 5s, CREATE TABLE … NOT NULL columns of a NEW table, CHECKs, RLS + FORCE, policy, grant to app_user).
declare -gA MIG_OK=(
    [2056_01_01_000000_create_contact_click_daily_table]="creates the empty table contact_click_daily (site_id FK CASCADE to sites, day, channel, placement, clicks; CHECKs on channel/placement/clicks; UNIQUE (site_id, day, channel, placement); RLS + FORCE fail closed; grant to app_user); NOT NULL is on the new table's own columns; no existing column, index or row changes; the old code never reads it"
)
# PgBouncer restarts after 2056 as the engine does for every table file (the migration says it is not needed: no
# existing column changes; the restart costs about 3 seconds and is kept as the safe default).
declare -gA PGB_SKIP=()
# routes/console.php (#588): contact:flush-clicks every five minutes, contact:prune-clicks daily.
SCHEDULE_ADDED=(contact:flush-clicks contact:prune-clicks)
SCHEDULE_REMOVED=()
ENV_DEFAULT_OK=()
# Expand-only: no column, index or constraint line of the schema snapshot may go or change.
SCHEMA_REMOVED_OK=()
# sf-fc-copy.js is a plain Vite input (the engine checks every input is in the manifest), not a dynamic import.
BUILD_DYNAMIC=()
BUILD_MARKERS=("resources/css/sf-floating.css sf-fc--combo" "resources/css/sf-floating.css sf-fc-sheet__row")

ONEOFFS_PRE=()
ONEOFFS=()
release_smoke() {
    local p html code loc hdr out
    out=$(mktemp)
    # #589: av1's home draws the new widget root once, keeps Email as a bar cell, the round buttons and the wide ghost
    html=$(curl -s -m 20 "https://$HOST/?fc=$RANDOM")
    expect "trang chủ có đúng 1 gốc nút liên hệ mới (#589)" "$({ grep -oF '<div class="sf-fc sf-fc--combo" data-fc-root' <<< "$html" || true; } | wc -l)" "1"
    expect "thanh dưới trang chủ có cả nút email (#563, giữ như cũ)" "$({ grep -oE '<a class="sf-fc-bar__link" href="mailto:' <<< "$html" || true; } | wc -l)" "1"
    expect "trang chủ có cụm nút tròn cho máy tính (#589, thay kiểm #563)" "$({ grep -oE '<nav class="sf-fc__float sf-fc__float--' <<< "$html" || true; } | wc -l)" "1"
    expect "trang chủ giữ chỗ cho thanh rộng (#589)" "$({ grep -oE 'sf-fc-ghost--wide' <<< "$html" || true; } | wc -l)" "1"
    # #589: the "Chép số" chunk is in the running build
    expect "phần Chép số có trong bản build (#589)" \
        "$(docker exec "${PREFIX}app" grep -c '"src": "resources/js/sf-fc-copy.js"' public/build/manifest.json || true)" "1"
    # #588: a tap from a crawler answers 204, counts nothing and sets no cookie
    hdr=$(curl -s -o "$out" -D - -m 20 -X POST -A 'bswebsaas-deploy-smoke-crawler' -H 'Content-Type: text/plain' \
        --data '{"channel":"zalo","placement":"bar"}' "https://$HOST/_click")
    rm -f "$out"
    expect "POST /_click của máy dò trả 204, không đặt cookie (#588)" \
        "$(grep -E "^HTTP/" <<< "$hdr" | tail -1 | grep -c ' 204' || true) $(grep -ci '^set-cookie:' <<< "$hdr" || true)" "1 0"
    # #583: the operator console's plan screens are in the cached routes
    expect "ba địa chỉ Gói & tính năng có trong bản đang chạy (#583)" \
        "$(docker exec "${PREFIX}app" php artisan route:list --name=platform.plans --json | jq length)" "3"
    # #585: the running code has the dashboard cards' texts
    expect "chữ của thẻ Tổng quan có trong bản đang chạy (#585)" \
        "$(docker exec "${PREFIX}app" grep -c '"dash.retry_done"' lang/vi.json || true)" "1"
    # earlier releases stay as they were
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/khong-co-trang-nay-$RANDOM")
    expect "địa chỉ không có trả 404 (giữ như cũ)" "$code" "404"
    expect "trang cho tên miền chưa gắn website có trong bản đang chạy (#579)" \
        "$(docker exec "${PREFIX}app" grep -c '"storefront.site_state.unknown_title"' lang/vi.json || true)" "1"
    html=$(curl -s -m 20 "https://$HOST/khong-co-trang-nay-$RANDOM")
    expect "trang 404 là trang đã thiết kế (giữ như cũ)" "$(grep -cE '<main class="sf-404( |")' <<< "$html" || true)" "1"
    hdr=$(curl -sI -m 20 "https://$HOST/khong-co-trang-nay-$RANDOM")
    expect "trang 404 không cho Cloudflare đệm lâu (giữ như cũ)" \
        "$(grep -E "^HTTP/" <<< "$hdr" | tail -1 | grep -c ' 404' || true) $(grep -i '^cache-control:' <<< "$hdr" | grep -c 's-maxage' || true)" "1 0"
    hdr=$(curl -sI -m 20 "https://$HOST/admin/session-expired")
    expect "trang hết phiên trả 200 và không được đệm (#577)" \
        "$(grep -E "^HTTP/" <<< "$hdr" | tail -1 | grep -c ' 200' || true) $(grep -i '^cache-control:' <<< "$hdr" | grep -c 'no-store' || true)" "1 1"
    html=$(curl -s -m 20 "https://$HOST/admin/session-expired")
    expect "trang Phiên làm việc đã hết có trong quản trị (#577)" "$([ "$(grep -c 'Phiên làm việc đã hết' <<< "$html" || true)" -ge 1 ] && echo có || echo không)" "có"
    expect "phần Trình đơn là gói tải riêng (#581)" \
        "$(docker exec "${PREFIX}app" grep -c '"src": "resources/js/admin-menus.js"' public/build/manifest.json || true)" "1"
    expect "khối dịch vụ có đường dẫn thẻ trong bản đang chạy (#580)" \
        "$(docker exec "${PREFIX}app" grep -c 'where the card leads' app/Modules/Theme/Providers/ThemeServiceProvider.php || true)" "1"
    html=$(curl -s -m 20 "https://$HOST/nha-xuong-may-moc?lg=$RANDOM")
    expect "chân trang ghi Mã số doanh nghiệp (#576)" "$(grep -c 'Mã số doanh nghiệp: 0108649706' <<< "$html" || true)" "1"
    expect "trang có biến màu thương hiệu trên nền tối" "$(grep -c -- '--color-primary-on-dark:' <<< "$html" || true)" "1"
    for p in /admin/forms /admin; do
        read -r code loc < <(curl -s -o /dev/null -m 20 -w '%{http_code} %{redirect_url}\n' "https://$HOST$p")
        expect "$p chuyển khách chưa đăng nhập sang trang đăng nhập (#541, #585)" "$code ${loc##*/admin/}" "302 login"
    done
    for p in / /tin-tuc /jig-kiem-tap-lo-vf7; do
        html=$(curl -s -m 20 "https://$HOST$p?img=$RANDOM" | tr '\n' ' ')
        expect "trang $p có đúng 1 ảnh ưu tiên cao (#558)" "$({ grep -o 'fetchpriority="high"' <<< "$html" || true; } | wc -l)" "1"
    done
    html=$(curl -s -m 20 "https://$HOST/?css=$RANDOM")
    expect "trang chủ gắn đúng 1 mã đo Cloudflare (#548, #549)" "$({ grep -oF 'src="https://static.cloudflareinsights.com/beacon.min.js' <<< "$html" || true; } | wc -l)" "1"
    hdr=$(curl -sI -m 20 "https://$HOST/")
    expect "trang chủ trả 200, cho phép đệm ở Cloudflare, không đặt cookie (#532)" \
        "$(grep -E "^HTTP/" <<< "$hdr" | tail -1 | grep -c ' 200' || true) $(grep -i '^cache-control:' <<< "$hdr" | grep -c 's-maxage=300' || true) $(grep -ci '^set-cookie:' <<< "$hdr" || true)" "1 1 0"
    expect "giả tên miền qua X-Forwarded-Host không đổi được website (#533)" "$(curl -s -m 20 -H 'X-Forwarded-Host: website.baoson.net' "https://$HOST/?xfh=$RANDOM" | grep -c 'website.baoson.net/login' || true)" "0"
}
release_rollback() { # data the old code (9b11dd2e) cannot read
    echo "     Bảng mới contact_click_daily (#588): bản cũ không đọc nó, để nguyên được; down() của 2056 xoá bảng (chỉ mất số lượt bấm)."
    echo "     Lượt bấm còn đệm trong Valkey vô hại với bản cũ. Trang Cloudflare đã đệm (≤ 5 phút) có thể gửi POST /_click vào bản cũ: trả 405, không hại."
}
