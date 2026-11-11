# Release file: prod e51e04a0 → f8b89442 (main on 11/11, after #510–#517). Data and one-off commands only;
# the engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/e51e04a0-f8b89442.sh
# Range: #510 (the release file of the last deploy), #512 (SEO rules, sources and a live audit script; docs
# and tooling only), #513 (text on the brand colour is white when it reads, else a deep shade of the brand
# hue), #511 (sliders pause while hovered or keyboard-focused, loop both ways, no pause button; the slide
# maths in sf-slider-math.js; rail dots), #514 (breadcrumbs: detail pages end at their category, list and
# info pages show the current page; BreadcrumbList needs two items; An Vinh pack: sentence-case title
# bands), #515 (phone menu v4: drawer with languages by native name, og:locale per language, an optional
# header corner key read with a default), #516 (sitemap image:image per URL; ETag and 304 on storefront
# pages, sitemaps and robots.txt), #517 (more checks in the audit script; tooling only). Code, views, CSS and
# JS (so a front-end build), lang and the pack: no migration, no new job, no .env key, no library change.
# The pack is loaded right after this deploy (no new media file).
# shellcheck shell=bash disable=SC2034
OLD=e51e04a0e5ef7999163aee27377ca29bf691af9f
TARGET=f8b894422613461a319ec045174c97a1efb93e2b
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

# Classes that need a human look and are in this range (the engine stops if this differs from what it finds).
DECLARED=(content-pack)

MIGRATIONS=()
declare -gA MIG_OK=()
declare -gA PGB_SKIP=()
SCHEDULE_ADDED=()
SCHEDULE_REMOVED=()
ENV_DEFAULT_OK=()
SCHEMA_REMOVED_OK=()
BUILD_DYNAMIC=()
# #511: rail dots in the slider script; #515: the phone call link hides when a bottom bar is shown
BUILD_MARKERS=("resources/js/sf-slider.js data-sf-rail-dots" "resources/css/storefront.css sf-bar__call--hasbar")

ONEOFFS_PRE=()
ONEOFFS=()
release_smoke() {
    local x
    # #516: a real 304 through the app's own Caddy with compression on (the edge Caddy and Cloudflare are
    # checked by hand after the deploy), and the image namespace in the live sitemap
    local p tag
    for p in /robots.txt /; do
        tag=$(docker exec "${PREFIX}app" curl -s -m 10 -o /dev/null -D - -H "Host: $HOST" -H 'Accept-Encoding: gzip' "http://localhost:80$p" | tr -d '\r' | awk 'tolower($1) == "etag:" { print $2 }')
        expect "trang $p có ETag và trả 304 khi gửi lại ETag (#516)" \
            "$(docker exec "${PREFIX}app" curl -s -m 10 -o /dev/null -w '%{http_code}' -H "Host: $HOST" -H 'Accept-Encoding: gzip' -H "If-None-Match: ${tag:-none}" "http://localhost:80$p")" "304"
    done
    expect "sitemap có ảnh của từng trang (#516)" "$(has /sitemap.xml 'sitemap-image/1.1')" "1"
    # #514: the breadcrumb helper decides what is shown
    expect "đường dẫn theo loại trang (#514)" \
        "$(docker exec "${PREFIX}app" grep -cF 'function visible(' app/Modules/Storefront/Support/Breadcrumbs.php || true)" "1"
    # what the load would do now (a dry run: writes nothing); every skip/conflict line, so the AI can choose
    # the FORCE keys before the load
    x=$({ art site:load-content an-vinh --host="$HOST" -v 2>&1 || true; })
    note "kế hoạch nạp gói An Vinh (xem trước, không ghi): $(printf '%s\n' "$x" | grep -E '^Totals:' || echo 'không đọc được dòng Totals')"
    note "mục gói sẽ bỏ qua hoặc xung đột (nhân viên đã sửa; AI chọn khóa FORCE từ đây):"
    printf '%s\n' "$x" | awk '
        /^.{11}(skip:|conflict)/ { on = 1; n++; print "       " $0; next }
        on && /^ {31}/ { print "       " $0; next }
        { on = 0 }
        END { if (!n) print "       (không có)" }'
}
release_rollback() { # data the old code (e51e04a0) cannot read
    echo "     Không có: bản này không đổi bảng và không thêm khóa cài đặt bắt buộc. Nếu gói đã nạp rồi mới lùi: tiêu đề dải đầu trang viết hoa chữ đầu vẫn hiện đúng ở bản cũ; khóa góc đầu trang điện thoại (nếu có) bản cũ bỏ qua; nạp lại gói bằng bản cũ có thể báo 5 tiêu đề dải đầu trang là nhân viên đã sửa. ETag/304 chỉ là mã, lùi là hết. Không mất dữ liệu."
}
