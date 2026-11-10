# Release file: prod e5c4403a → 77992f34 (main on 10/11, after #472–#478 and #297). Data and one-off commands
# only; the engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/e5c4403a-77992f34.sh
# Range: #472 (the release file of the last deploy), #473 (both Inter faces on the weight range 400 900, so
# Inter is painted), #474 (a pack item's publication date replaces the live one), #476 + #478 (the An Vinh
# pack: pages, posts, settings, eight new partner/certificate images), #477 (a pack may write
# theme.heading_decor, storefront.floating_contact and storefront.layout_blocks; the Applier syncs the block
# media usage), #475 (xl header menu with section trail, floating-contact spacer, prose rules kept to written
# text: Blade, PHP, CSS), #297 (media: server-set object headers on upload; plus the media Worker under
# deploy/media-worker, which the owner deploys separately: this deploy never touches it).
# Code, views, CSS (so a front-end build) and the pack: no migration, no new job, no .env key, no library
# change. The pack is shipped, NOT loaded: the owner loads it after this deploy.
# shellcheck shell=bash disable=SC2034
OLD=e5c4403a4e52b7ef6851d624dbbaf181ffdbd0dd
TARGET=77992f34f95ff83b50d95abe94b10cd71f21812f
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /san-pham /du-an /tin-tuc /doi-tac /lien-he /chinh-sach-bao-mat /nang-luc /admin/login)

# Classes that need a human look and are in this range (the engine stops if this differs from what it finds).
DECLARED=(content-pack worker)

MIGRATIONS=()
declare -gA MIG_OK=()
declare -gA PGB_SKIP=()
SCHEDULE_ADDED=()
SCHEDULE_REMOVED=()
ENV_DEFAULT_OK=()
SCHEMA_REMOVED_OK=()
BUILD_DYNAMIC=()
# #475: the xl header menu class is new in storefront.css (kept by the minifier): proves the new build
BUILD_MARKERS=("resources/css/storefront.css sf-header--menu-xl")

ONEOFFS_PRE=()
ONEOFFS=()
release_smoke() {
    local css faces x
    # #473: the built storefront CSS has exactly two Inter faces, both on 400 900
    css=$PROD/public/build/$(jq -er '."resources/css/storefront.css".file' "$PROD/public/build/manifest.json")
    faces=$(grep -o '@font-face{[^}]*}' "$css" | grep -F 'font-family:Inter;' || true)
    expect "CSS mới: số mặt chữ Inter" "$(printf '%s\n' "$faces" | grep -c . || true)" "2"
    expect "CSS mới: mặt chữ Inter cùng độ đậm 400 900" "$(printf '%s\n' "$faces" | grep -cF 'font-weight:400 900' || true)" "2"
    # #475: An Vinh's menu is wide, so the header keeps the menu button up to 1280px
    expect "trang chủ có thanh menu rộng (sf-header--menu-xl)" "$(has / 'sf-header--menu-xl')" "1"
    # #474 + #477: the container runs the new loader
    expect "bộ nạp mới: ngày đăng của gói (#474)" \
        "$(docker exec "${PREFIX}app" grep -cF 'private static function packDate(PackItem $item)' app/Modules/Provisioning/ContentPack/Planner.php || true)" "1"
    expect "bộ nạp mới: gói ghi được kiểu trang trí tiêu đề (#477)" \
        "$(docker exec "${PREFIX}app" grep -cF "'theme' => ['tokens', 'heading_decor']," app/Modules/Provisioning/ContentPack/PackValidator.php || true)" "1"
    # #478: the shipped pack sets the flourish heading decoration
    expect "gói An Vinh có kiểu tiêu đề flourish" \
        "$(docker exec "${PREFIX}app" grep -cF '"style": "flourish"' database/content-packs/an-vinh/settings.json || true)" "1"
    # what the owner's load would do now (a dry run: writes nothing); the load script refuses on any conflict.
    # Every skip line (an item staff edited, deleted or left a draft on) with its changed fields, so the AI can
    # choose the FORCE keys before the load.
    x=$({ art site:load-content an-vinh --host="$HOST" -v 2>&1 || true; })
    note "kế hoạch nạp gói An Vinh (xem trước, không ghi): $(printf '%s\n' "$x" | grep -E '^Totals:' || echo 'không đọc được dòng Totals')"
    note "mục gói sẽ bỏ qua hoặc xung đột (nhân viên đã sửa; AI chọn khóa FORCE từ đây):"
    printf '%s\n' "$x" | awk '
        /^.{11}(skip:|conflict)/ { on = 1; n++; print "       " $0; next }
        on && /^ {31}/ { print "       " $0; next }
        { on = 0 }
        END { if (!n) print "       (không có)" }'
}
release_rollback() { # data the old code (e5c4403a) cannot read
    echo "     Không có: bản này không đổi bảng và không nạp gói. Nếu gói đã nạp rồi mới lùi: kiểu tiêu đề flourish, nút liên hệ nổi và khối của gói được lưu đúng dạng trang quản trị bản cũ vẫn lưu, ngày đăng là cột thường; bản cũ đọc được. Ảnh tải lên chỉ khác phần đầu (header) của tệp trên R2, khóa ảnh không đổi."
}
