# Release file: prod f5bd1f23 → b8ff5246 (main on 20/11). Data and one-off commands only; the engine
# (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/f5bd1f23-b8ff5246.sh
# Range:
#   #839 post editor: file a post under several categories from the «Chuyên mục» card.
#   #840 «Mô tả ảnh» under a post's or page's featured image, per language (entry_translations.cover_alt).
#   #841 delete a form with its leads (large forms: status `deleting` + queued DeleteLargeForm job,
#        hourly forms:resume-deletes).
#   #842 which pages show which form: new tenant table form_placements (RLS FORCE), kept by every page save,
#        nightly forms:placements reconcile; one-off fill below.
#   #843 the previous release file.
# Five migrations. No permission change. Two new .env keys with config defaults (below). CSS/JS change (admin).
# shellcheck shell=bash disable=SC2034
OLD=f5bd1f23bea9e3ba034331f21080f4537f0d7028
TARGET=b8ff5246b8f5b14bab1276dade314a82e4eccd4f
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /ho-so-nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

# Classes that need a human look and are in this range (the engine stops if this differs from what it finds).
DECLARED=(migration schedule)

MIGRATIONS=(2081_01_01_000000_add_form_id_index_to_form_submissions 2081_01_01_000100_add_deleting_status_to_forms 2081_01_01_000200_add_site_id_id_unique_to_forms 2082_01_01_000000_create_form_placements_table 2084_01_01_000000_add_cover_alt_to_entry_translations)
declare -gA MIG_OK=(
    [2081_01_01_000000_add_form_id_index_to_form_submissions]="index only, CREATE INDEX CONCURRENTLY outside a transaction; the DROP in up() only removes this same index when an interrupted build left it INVALID; no data written"
    [2081_01_01_000100_add_deleting_status_to_forms]="swaps forms_status_check to add deleting: DROP + ADD NOT VALID + VALIDATE in one transaction under lock_timeout 5s; forms is a small table; no data written"
    [2081_01_01_000200_add_site_id_id_unique_to_forms]="CREATE UNIQUE INDEX CONCURRENTLY forms (site_id, id) outside a transaction (drops only its own INVALID leftover first), then ADD CONSTRAINT … UNIQUE USING INDEX (lock_timeout 5s); no data written"
    [2082_01_01_000000_create_form_placements_table]="new empty tenant table form_placements + composite FK (site_id, form_id) -> forms(site_id, id); raw SQL only for RLS ENABLE/FORCE, the policy and the grant; no data written"
    [2084_01_01_000000_add_cover_alt_to_entry_translations]="one nullable column entry_translations.cover_alt, ADD COLUMN IF NOT EXISTS under lock_timeout 5s; metadata-only on PG17, no rewrite, no index; down() refuses while any row holds text"
)
declare -gA PGB_SKIP=(
    [2081_01_01_000000_add_form_id_index_to_form_submissions]="index only, no column changes"
)
SCHEDULE_ADDED=(forms:placements forms:resume-deletes)
SCHEDULE_REMOVED=()
# #841: inline form delete up to 20000 leads, job batch 2000 (config/forms.php defaults).
ENV_DEFAULT_OK=(FORMS_DELETE_BATCH_LEADS FORMS_DELETE_INLINE_MAX_LEADS)
SCHEMA_REMOVED_OK=()
BUILD_DYNAMIC=()
BUILD_MARKERS=()

ONEOFFS_PRE=()
ONEOFFS=(form_placements)

# #842: fill form_placements from every page body once; the nightly run keeps it after that.
oneoff_form_placements() {
    local out
    case "$1" in
        preview) if [ -s "$STATE/form-placements-filled" ]; then echo 0; else echo 1; fi ;;
        apply) out=$(art forms:placements 2>&1) || die "forms:placements thất bại: $(tail -2 <<< "$out")"
            echo "     $(tail -1 <<< "$out")"
            date '+%F %T' > "$STATE/form-placements-filled" ;;
        verify) out=$(art forms:placements 2>&1 | tail -1)
            expect "chạy lại lệnh ghi trang đặt biểu mẫu không còn gì để sửa (#842)" "$(grep -c 'corrected 0\.' <<< "$out" || true)" "1" ;;
    esac
}

release_smoke() {
    local n
    # app errors since the reload (not a stop: printed for the report, the lines shown when any)
    n=$(docker logs --since 20m "${PREFIX}app" 2>&1 | grep -cE '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' || true)
    note "lỗi trong nhật ký ứng dụng 20 phút qua: ${n:-0}"
    [ "${n:-0}" = 0 ] || docker logs --since 20m "${PREFIX}app" 2>&1 | grep -E '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' | tail -n 5 | cut -c1-300
    # #841/#842: the contact page still shows its form (the deleting scope must not hide a live form)
    n=$(curl -s "https://${HOST}/lien-he" | grep -c 'wire:submit\|<form' || true)
    expect "trang liên hệ vẫn có biểu mẫu (#841/#842)" "$([ "${n:-0}" -gt 0 ] && echo 1 || echo 0)" "1"
}
