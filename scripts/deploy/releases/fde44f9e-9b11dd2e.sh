# Release file: prod fde44f9e → 9b11dd2e (main on 13/11, after #577, #578, #579, #580, #581). Data and one-off commands
# only; the engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/fde44f9e-9b11dd2e.sh
# Range: #577 (admin: designed error pages, the "Phiên làm việc đã hết" page + session-ending dialog, routes
# /admin/session-expired and /session/ping on both planes, config/support.php reads SUPPORT_PHONE / SUPPORT_ZALO —
# blank = that channel is not offered, so no .env change is needed), #578 (release file kept), #579 (storefront:
# designed 404 with suggestions, pages for a site that is building / paused / closed (503/410/403, `private, no-store`)
# and for a host no site owns), #580 (features cards take an optional link; An Vinh's pages.json home services follow
# the board — loaded after the deploy by its own script, plans/tools/anvinh-*-load-*.sh, preview then apply),
# #581 (admin: the Trình đơn screen's part is a lazy chunk). Code, lang, CSS/JS and the An Vinh pack: no migration,
# no new job or schedule, no library change.
# Run 13/11 05:46 (log plans/reports/deploy-runs/fde44f9-9b11dd2e-run-20261113-054646.log): every step and every
# release_smoke check passed, 0 app error lines. Then the An Vinh pack was loaded (home page block updated; checks all passed).
# shellcheck shell=bash disable=SC2034
OLD=fde44f9e136eb6683f5bd4da8bc446409ec054d0
TARGET=9b11dd2e676a525a1714214e96d8361e3cd39c36
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /ho-so-nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

# Classes that need a human look and are in this range (the engine stops if this differs from what it finds).
DECLARED=(content-pack)

MIGRATIONS=()
declare -gA MIG_OK=()
declare -gA PGB_SKIP=()
SCHEDULE_ADDED=()
SCHEDULE_REMOVED=()
# #577: both keys are optional; unset = the error pages offer only the support mailbox.
ENV_DEFAULT_OK=(SUPPORT_PHONE SUPPORT_ZALO)
SCHEMA_REMOVED_OK=()
BUILD_DYNAMIC=(resources/js/admin-menus.js)
BUILD_MARKERS=()

ONEOFFS_PRE=()
ONEOFFS=()
release_smoke() {
    local p html code loc hdr
    # #579: an unknown address answers 404 with the designed page (not a 200, not the framework page)
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/khong-co-trang-nay-$RANDOM")
    expect "địa chỉ không có trả 404 (giữ như cũ)" "$code" "404"
    # #579: the running code has the neutral page for a host no site owns (not reachable from av1 itself)
    expect "trang cho tên miền chưa gắn website có trong bản đang chạy (#579)" \
        "$(docker exec "${PREFIX}app" grep -c '"storefront.site_state.unknown_title"' lang/vi.json || true)" "1"
    html=$(curl -s -m 20 "https://$HOST/khong-co-trang-nay-$RANDOM")
    expect "trang 404 là trang đã thiết kế (giữ như cũ)" "$(grep -cE '<main class="sf-404( |")' <<< "$html" || true)" "1"
    hdr=$(curl -sI -m 20 "https://$HOST/khong-co-trang-nay-$RANDOM")
    expect "trang 404 không cho Cloudflare đệm lâu (giữ như cũ)" \
        "$(grep -E "^HTTP/" <<< "$hdr" | tail -1 | grep -c ' 404' || true) $(grep -i '^cache-control:' <<< "$hdr" | grep -c 's-maxage' || true)" "1 0"
    # #577: the session-ended page is served under the site's admin
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/admin/session-expired")
    expect "địa chỉ trang hết phiên trả 200 (#577)" "$code" "200"
    html=$(curl -s -m 20 "https://$HOST/admin/session-expired")
    expect "trang Phiên làm việc đã hết có trong quản trị (#577)" "$([ "$(grep -c 'Phiên làm việc đã hết' <<< "$html" || true)" -ge 1 ] && echo có || echo không)" "có"
    hdr=$(curl -sI -m 20 "https://$HOST/admin/session-expired")
    expect "trang hết phiên trả 200 và không được đệm (#577)" \
        "$(grep -E "^HTTP/" <<< "$hdr" | tail -1 | grep -c ' 200' || true) $(grep -i '^cache-control:' <<< "$hdr" | grep -c 'no-store' || true)" "1 1"
    # #581: the Trình đơn part is its own chunk in the running build
    expect "phần Trình đơn là gói tải riêng (#581)" \
        "$(docker exec "${PREFIX}app" grep -c '"src": "resources/js/admin-menus.js"' public/build/manifest.json || true)" "1"
    # #580: the features block can draw a card link (the pack that uses it is loaded after the deploy)
    expect "khối dịch vụ có đường dẫn thẻ trong bản đang chạy (#580)" \
        "$(docker exec "${PREFIX}app" grep -c 'where the card leads' app/Modules/Theme/Providers/ThemeServiceProvider.php || true)" "1"
    # earlier releases stay as they were
    html=$(curl -s -m 20 "https://$HOST/nha-xuong-may-moc?lg=$RANDOM")
    expect "chân trang ghi Mã số doanh nghiệp (#576)" "$(grep -c 'Mã số doanh nghiệp: 0108649706' <<< "$html" || true)" "1"
    expect "trang có biến màu thương hiệu trên nền tối" "$(grep -c -- '--color-primary-on-dark:' <<< "$html" || true)" "1"
    for p in /admin/forms; do
        read -r code loc < <(curl -s -o /dev/null -m 20 -w '%{http_code} %{redirect_url}\n' "https://$HOST$p")
        expect "$p chuyển khách chưa đăng nhập sang trang đăng nhập (#541)" "$code ${loc##*/admin/}" "302 login"
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
release_rollback() { # data the old code (fde44f9e) cannot read
    echo "     Không có bảng hay cột mới. Nếu gói An Vinh (#580) đã nạp: khối Dịch vụ trang chủ là khối thẻ kiểu icon-cards có đường dẫn; bản cũ vẽ nó bằng kiểu mặc định và bỏ đường dẫn thẻ."
    echo "     Trả lại khối cũ sau khi lùi code: FORCE=\"page:/\" bash /opt/bswebsaas/dev/plans/tools/anvinh-products-load-261113.sh (xem trước), rồi thêm apply."
}
