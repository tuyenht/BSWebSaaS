# Release file: prod ae1e7310 → 23740125 (main on 11/11, after #498–#500). Data and one-off commands only;
# the engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/ae1e7310-23740125.sh
# Range: #498 (the release file of the last deploy), #499 (job posts draw as cards: a job look for the post
# grid and the list page with facts read from the post's short facts, filter buttons that hide the cards of the
# other groups in sf-tabs.js, a job page layout; the content pack can carry facts and a badge per post and the
# planner adds them to a post's state only when they are set, so ledgers of posts without them keep their
# hash; new lang keys; the An Vinh job posts get facts and stay draft), #500 (the shared post side box of the
# An Vinh pack is titled "Chuyên mục"). Code, views, CSS and JS (so a front-end build), lang and the pack: no
# migration, no new job, no .env key, no library change. The pack is loaded right after this deploy (no new media).
# shellcheck shell=bash disable=SC2034
OLD=ae1e7310532f0fc60c5dffb03c4ac58c535f4980
TARGET=23740125cefde2933a5757fce1c00110287571c2
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /tuyen-dung /admin/login)

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
# #499: the job card look is new in storefront.css and the filter buttons in sf-tabs.js
BUILD_MARKERS=("resources/css/storefront.css sf-card--job" "resources/js/sf-tabs.js data-sf-pick")

ONEOFFS_PRE=()
ONEOFFS=()
release_smoke() {
    local x
    # #499: the container runs the job facts reader and the post grid that draws the job look
    expect "bài tuyển dụng vẽ dạng thẻ có thông tin ngắn (#499)" \
        "$(docker exec "${PREFIX}app" grep -cF 'final class JobFacts' app/Modules/Cms/Support/JobFacts.php || true)" "1"
    expect "lưới bài có nút lọc nhóm (#499)" \
        "$(docker exec "${PREFIX}app" grep -cF 'data-sf-filter' themes/default/sections/entry_grid.blade.php || true)" "1"
    # #500: the shipped pack titles the shared post side box "Chuyên mục"
    expect "gói An Vinh: cột phải bài viết có tiêu đề Chuyên mục (#500)" \
        "$(docker exec "${PREFIX}app" grep -cF '"heading": "Chuyên mục"' database/content-packs/an-vinh/settings.json || true)" "1"
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
release_rollback() { # data the old code (ae1e7310) cannot read
    echo "     Không có: bản này không đổi bảng. Nếu gói đã nạp rồi mới lùi: danh sách Thông tin chung (nơi làm việc, loại, lương, hạn nộp) của 3 bài tuyển dụng đã chuyển từ thân bài sang thông tin ngắn mà bản cũ không vẽ, nên khi xem trước các bài nháp này mất phần đó trên màn hình cho đến khi deploy lại (không xoá gì, bài vẫn nháp nên không ai ngoài thấy); nạp lại gói bằng bản cũ có thể báo 3 bài đó là nhân viên đã sửa (bản cũ không tính thông tin ngắn vào dấu). Cột phải bài viết giữ tiêu đề Chuyên mục (bản cũ vẽ được)."
}
