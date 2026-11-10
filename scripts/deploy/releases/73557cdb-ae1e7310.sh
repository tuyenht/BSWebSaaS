# Release file: prod 73557cdb → ae1e7310 (main on 11/11, after #496–#497). Data and one-off commands only;
# the engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/73557cdb-ae1e7310.sh
# Range: #496 (a form block can draw the site's branches beside it instead of one embedded map: a chooser
# of the branches, the map swapped among server-built Google Maps addresses, a card with four buttons and
# an approximate-location line, new sf-branch-map.js entry; a new "branches" block draws the branch cards
# with their photos; new lang keys; the An Vinh contact form uses the branches and its factory page the new
# block), #497 (the release file of the last deploy). Code, views, CSS and a new JS entry (so a front-end
# build), lang and the pack: no migration, no new job, no .env key, no library change. The pack is loaded
# right after this deploy (no new media).
# shellcheck shell=bash disable=SC2034
OLD=73557cdb2d169d5c64d6017efc20721a41f8f5f9
TARGET=ae1e7310532f0fc60c5dffb03c4ac58c535f4980
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
# #496: the branch chooser is new in storefront.css and in the new sf-branch-map.js entry
BUILD_MARKERS=("resources/css/storefront.css sf-bmap__tabs" "resources/js/sf-branch-map.js [data-sf-bmap]")

ONEOFFS_PRE=()
ONEOFFS=()
release_smoke() {
    local x
    # #496: the container runs the branch map reader and the form view that draws it
    expect "trang Liên hệ vẽ bản đồ các nhà máy (#496)" \
        "$(docker exec "${PREFIX}app" grep -cF 'final readonly class BranchMap' app/Modules/Theme/Chrome/BranchMap.php || true)" "1"
    expect "khối biểu mẫu gọi phần bản đồ chi nhánh (#496)" \
        "$(docker exec "${PREFIX}app" grep -cF "@include('theme::partials.branch-map'" themes/default/sections/form.blade.php || true)" "1"
    # #496: the shipped pack puts the branches beside the contact form and on the factory page
    expect "gói An Vinh: biểu mẫu Liên hệ dùng bản đồ các nhà máy" \
        "$(docker exec "${PREFIX}app" grep -cF '"map_source": "branches"' database/content-packs/an-vinh/pages.json || true)" "1"
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
release_rollback() { # data the old code (73557cdb) cannot read
    echo "     Không có: bản này không đổi bảng. Nếu gói đã nạp rồi mới lùi: khối biểu mẫu ở /lien-he có thêm thuộc tính map_source (bản cũ bỏ qua và vẽ lại bản đồ nhúng map_embed vẫn còn trong khối); khối branches ở trang Nhà xưởng là kiểu khối bản cũ không biết, nên trang đó mất phần thẻ 3 nhà máy cho đến khi deploy lại bản mới, và sửa trang Nhà xưởng trong quản trị sẽ bị từ chối (Khối \"branches\" không được hỗ trợ) (nội dung không mất; muốn hiện lại ngay thì nạp lại gói bản cũ với FORCE=page:/nha-xuong-may-moc)."
}
