# Release file: prod 23740125 → e51e04a0 (main on 11/11, after #501–#509). Data and one-off commands only;
# the engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/23740125-e51e04a0.sh
# Range: #501 (the release file of the last deploy), #502 (An Vinh pack: SEO titles, descriptions, internal
# links and profile facts for posts and products), #503 (twitter card, og site name and locale, FAQPage and
# branch locations in structured data, page 2 has no prev link to a ?page=1 URL), #505 (shared chrome per
# board: header, footer with factory cards that turn one at a time below 1024px in sf-footer-rot.js), #506
# (cards and rails per board), #504 (capability, jobs, thank-you and workshop pages assembled per board; a
# category can carry its own layout blocks in its meta, written by the pack), #507 (title bands, breadcrumb
# and section heads per board; a category can carry a cover and a description in the pack), #508 (post page
# per board: right column, article images, FAQ, author line and reading time, share bar and the copy-link
# and reading-progress script sf-post.js; a capability post layout), #509 (An Vinh pack: factory 1 photo,
# commitments and price line beside the quote button, ISO lines, a sixth core value, CV by email/Zalo on the
# jobs list, video titles and credits, product order, SEO titles and descriptions). Code, views, CSS and JS
# (so a front-end build), lang and the pack: no migration, no new job, no .env key, no library change. The
# pack is loaded right after this deploy (no new media file).
# shellcheck shell=bash disable=SC2034
OLD=23740125cefde2933a5757fce1c00110287571c2
TARGET=e51e04a0e5ef7999163aee27377ca29bf691af9f
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
# #507: title band pattern and #508: the post grid with a share rail are new in storefront.css; #508 and #505
# add two scripts
BUILD_MARKERS=("resources/css/storefront.css page-band--pattern" "resources/css/storefront.css post-columns--share" "resources/js/sf-post.js post-progress__bar" "resources/js/sf-footer-rot.js data-sf-rot")

ONEOFFS_PRE=()
ONEOFFS=()
release_smoke() {
    local x
    # #504: the thank-you pages helper and the capability list layout
    expect "trang cảm ơn và lưới năng lực theo bảng thiết kế (#504)" \
        "$(docker exec "${PREFIX}app" grep -cF 'final class ThankYouPages' app/Modules/Storefront/Support/ThankYouPages.php || true)" "1"
    # #508: reading time and the share bar
    expect "trang bài viết có thời gian đọc (#508)" \
        "$(docker exec "${PREFIX}app" grep -cF 'final class ReadingTime' app/Modules/Theme/Components/ReadingTime.php || true)" "1"
    expect "trang bài viết có thanh chia sẻ (#508)" \
        "$(docker exec "${PREFIX}app" test -f themes/default/partials/post-share.blade.php && echo 1 || echo 0)" "1"
    # #509: the shipped pack carries the two product slots beside the quote button
    expect "gói An Vinh: hai ô quanh nút báo giá của trang sản phẩm (#509)" \
        "$(docker exec "${PREFIX}app" grep -cF '"product_after_action": {' database/content-packs/an-vinh/settings.json || true)" "1"
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
release_rollback() { # data the old code (23740125) cannot read
    echo "     Không có: bản này không đổi bảng. Nếu gói đã nạp rồi mới lùi: bản cũ vẫn vẽ khối riêng, ảnh bìa, mô tả chuyên mục và hai ô quanh nút báo giá; bản cũ bỏ qua thẻ tài liệu (file_card) ở cột phải chuyên mục Năng lực và các tuỳ chọn khối mới (nguồn/số bài của danh sách chuyên mục, hoa văn/dấu tích của dải tiêu đề, kiểu hỏi đáp, độ rộng/chú thích video, kiểu chú thích ảnh, màu nút 2, ghi chú đối tác). Nạp lại gói bằng bản cũ có thể báo các chuyên mục đó là nhân viên đã sửa. Không mất dữ liệu."
}
