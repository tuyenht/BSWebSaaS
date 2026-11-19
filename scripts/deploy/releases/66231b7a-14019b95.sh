# Release file: prod 66231b7a → 14019b95 (main on 20/11). Data and one-off commands only; the engine
# (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/66231b7a-14019b95.sh
# Range:
#   #811 a row's ⋮ menu opens as a bottom sheet on narrow lists, with «Đóng».
#   #812 «Thêm trang con» from the Trang list and «Trang cha» in the page editor.
#   #813 length meter under the post and page title.
#   #814 posts and pages keep four statuses; «Không công khai» retired (one-off below moves old rows to
#        «Gỡ khỏi website» through the workflow engine, with an audit row each).
#   #816 per-language public item counts on categories for the search suggestions (migration 2077, a
#        nightly reconcile, and the first fill below).
#   #817 «Đăng nhập lại» in the ended-session dialog opens the sign-in at once.
#   #815 price, sale, stock and labels in the product editor.
#   #810 the previous release file.
# One migration (2077, PgBouncer restart), one new schedule, no library change, no new .env key.
# CSS/JS change (admin bundle).
# shellcheck shell=bash disable=SC2034
OLD=66231b7a69a1b00be00df27c1837132a05fb4b51
TARGET=14019b95511c5bbf5fb49df7a9de4b7a722d5705
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /ho-so-nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

# Classes that need a human look and are in this range (the engine stops if this differs from what it finds).
DECLARED=(migration schedule)

MIGRATIONS=(2077_01_01_000000_add_public_counts_to_taxonomy_translations)
declare -gA MIG_OK=(
    [2077_01_01_000000_add_public_counts_to_taxonomy_translations]="ALTER TABLE taxonomy_translations ADD COLUMN IF NOT EXISTS public_own_count / public_tree_count integer NOT NULL DEFAULT 0 (metadata only, no rewrite) and two CHECK (>= 0) NOT VALID under a short lock_timeout, then VALIDATE each outside that transaction (a scan, no ACCESS EXCLUSIVE held through it); re-runnable; down() drops both CHECKs and both columns"
)
declare -gA PGB_SKIP=()
SCHEDULE_ADDED=("cms:reconcile-category-public-counts")
SCHEDULE_REMOVED=()
ENV_DEFAULT_OK=()
SCHEMA_REMOVED_OK=()
BUILD_DYNAMIC=()
BUILD_MARKERS=()

ONEOFFS_PRE=()
ONEOFFS=(retire_private fill_counts)

# #814: old «Không công khai» rows → «Gỡ khỏi website». Preview prints the count the dry run reports.
oneoff_retire_private() {
    local out n
    case "$1" in
        preview) out=$(art cms:retire-private-status --dry-run 2>&1) || die "cms:retire-private-status --dry-run thất bại: $(tail -2 <<< "$out")"
            grep -E '^site ' <<< "$out" | sed 's/^/     /' >&2 || true
            n=$(grep -oE 'would move [0-9]+' <<< "$(tail -1 <<< "$out")" | grep -oE '[0-9]+' || true)
            echo "${n:-0}" ;;
        apply) out=$(art cms:retire-private-status 2>&1) || die "cms:retire-private-status thất bại: $(tail -3 <<< "$out")"
            echo "     $(tail -1 <<< "$out")" ;;
        verify) out=$(art cms:retire-private-status --dry-run 2>&1 | tail -1)
            expect "không còn bài «Không công khai» (#814)" "$(grep -c 'would move 0 ' <<< "$out" || true)" "1" ;;
    esac
}

# #816: the first fill of the new counters (small steps, one line per site); later runs keep them right.
oneoff_fill_counts() {
    local out
    case "$1" in
        preview) if [ -s "$STATE/category-counts-filled" ]; then echo 0; else echo 1; fi ;;
        apply) out=$(art cms:reconcile-category-public-counts --fill 2>&1) || die "cms:reconcile-category-public-counts --fill thất bại: $(tail -3 <<< "$out")"
            grep -E '^Site ' <<< "$out" | sed 's/^/     /' || true
            echo "     $(tail -1 <<< "$out")"
            date '+%F %T' > "$STATE/category-counts-filled" ;;
        verify) out=$(art cms:reconcile-category-public-counts 2>&1 | tail -1)
            expect "đếm lại số mục theo chuyên mục không còn gì để sửa (#816)" "$(grep -c 'corrected 0 row' <<< "$out" || true)" "1" ;;
    esac
}

release_smoke() {
    local n
    # app errors since the reload (not a stop: printed for the report, the lines shown when any)
    n=$(docker logs --since 20m "${PREFIX}app" 2>&1 | grep -cE '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' || true)
    note "lỗi trong nhật ký ứng dụng 20 phút qua: ${n:-0}"
    [ "${n:-0}" = 0 ] || docker logs --since 20m "${PREFIX}app" 2>&1 | grep -E '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' | tail -n 5 | cut -c1-300
    # #814 and #816: both commands are in the running code
    n=$(docker exec "${PREFIX}app" php artisan list --raw 2>&1 | grep -cE '^cms:(retire-private-status|reconcile-category-public-counts) ' || true)
    expect "có lệnh chuyển «Không công khai» và lệnh đếm lại chuyên mục (#814, #816)" "${n:-0}" "2"
}
