# Release file: prod ad65c8d3 → 73557cdb (main on 11/11, after #492–#495). Data and one-off commands only;
# the engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/ad65c8d3-73557cdb.sh
# Range: #492 (the release file of the last deploy), #493 (hero and partner slider controls per design; an
# article's contents list also lists block headings and marks the one being read: new sf-toc.js entry),
# #494 (branches and factories as one checked list in storefront.footer.factories[]: key, full name,
# locality, map query, coordinates, approximate flag and a photo recorded in mediables as "in use"; the
# An Vinh pack fills them for its three factories; the media library counts those photos as in use, with a
# "Chi nhánh / Nhà máy" label and new lang keys; the footer draws exactly as before), #495 (product pages
# per design: lower list photos, the description in a wide sheet with a sticky section bar, the slots by the
# order buttons; new lang keys). Code, views, CSS and a new JS entry (so a front-end build), lang and the
# pack: no migration, no new job, no .env key, no library change. The pack is loaded right after this deploy
# (no new media: the factory photos were uploaded by the last load).
# shellcheck shell=bash disable=SC2034
OLD=ad65c8d3cfd51bdfa2ef2e19f866521efdbe7712
TARGET=73557cdb2d169d5c64d6017efc20721a41f8f5f9
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /admin/login)

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
# #495: the product section bar is new in storefront.css and in the new sf-toc.js entry (#493)
BUILD_MARKERS=("resources/css/storefront.css product-toc__list" "resources/js/sf-toc.js .product-toc__list")

ONEOFFS_PRE=()
ONEOFFS=()
release_smoke() {
    local x
    # #494: the container runs the branch list checker the loader uses
    expect "bộ nạp mới: danh sách chi nhánh / nhà máy (#494)" \
        "$(docker exec "${PREFIX}app" grep -cF 'final class BranchList' app/Modules/Admin/Support/Settings/BranchList.php || true)" "1"
    # #495: the product page draws the section bar from the description's own headings
    expect "trang sản phẩm có thanh mục theo thiết kế (#495)" \
        "$(docker exec "${PREFIX}app" grep -cF 'class="product-toc"' themes/default/layouts/product.blade.php || true)" "1"
    expect "thanh mục lấy cấp tiêu đề từ phần mô tả (#495)" \
        "$(docker exec "${PREFIX}app" grep -cF 'public static function parts(string $description, string $shared' app/Modules/Theme/Rendering/ArticleToc.php || true)" "1"
    # #494: the shipped pack carries the branch keys
    expect "gói An Vinh có 3 nhà máy với khóa chi nhánh" \
        "$(docker exec "${PREFIX}app" grep -cF '"key": "nha-may-' database/content-packs/an-vinh/settings.json || true)" "3"
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
release_rollback() { # data the old code (ad65c8d3) cannot read
    echo "     Không có: bản này không đổi bảng. Nếu gói đã nạp rồi mới lùi: các khóa chi nhánh mới (key, full_name, locality, map_query, lat, lng, approximate, image_media_id) nằm trong JSON cài đặt storefront.footer, bản cũ chỉ đọc khóa cũ nên chân trang vẫn như trước; dòng mediables (site_setting, collection branch) bản cũ không đọc, nên ảnh nhà máy hiện \"chưa dùng\" trong thư viện cho đến khi deploy lại bản mới. Khi đã lùi, đừng xóa hẳn ảnh nhà máy trong thư viện (bản cũ thấy \"chưa dùng\"): JSON cài đặt sẽ giữ mã ảnh đã mất, bản mới bỏ mã đó ở lần lưu sau. Trang sản phẩm về dạng cũ, nội dung không đổi."
}
