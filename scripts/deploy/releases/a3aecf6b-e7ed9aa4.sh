# Release file: prod a3aecf6b → e7ed9aa4 (main on 25/11). Data and one-off commands only; the
# engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/a3aecf6b-e7ed9aa4.sh
# Range:
#   #961 the previous release file.
#   #958 bands above and below a category's list: the category drawer shows and sets them, «Trình dựng trang»
#        edits them (/admin/taxonomies/{ulid}/bands/{slot}); the lazy chunk admin-categories.js grows. No migration.
#   #960 a content section covers several categories side by side («Tin tức» = news + job offers): migration
#        2096 (UNIQUE (site_id, id) on taxonomies and content_sections, CONCURRENTLY) and the new tenant table
#        content_section_categories (RLS + FORCE), filled from content_sections.taxonomy_id by the migration
#        itself. The An Vinh content pack (sections.json) changes too: this deploy does not load it.
#   #963 a media test stores its times in UTC (test only).
#   #957 an end time for posts, pages and products («Gỡ khỏi website lúc»): migration 2095 (expires_at +
#        CHECK, then the due indexes CONCURRENTLY and the CHECK validated), two new every-minute schedules
#        entries:unpublish-due and products:unpublish-due, and a lasting history of date changes (audit).
# PR #962 (category language bits) was still open when this file was written: not in this range.
# No new env key, no permission or sanitizer change, no composer or npm change.
# shellcheck shell=bash disable=SC2034
OLD=a3aecf6b295156b0a03cf497148ebb3df3c14c81
TARGET=e7ed9aa4525037356e35aa1252f9b54c3554e0b0
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /ho-so-nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

# content-pack: database/content-packs/an-vinh/sections.json changes (#960); the engine only reports it.
DECLARED=(content-pack migration schedule)
MIGRATIONS=(
    2095_01_01_000000_add_expires_at_to_entries_and_products
    2095_01_01_000100_add_expiry_due_indexes
    2096_01_01_000000_add_site_id_id_unique_to_taxonomies_and_content_sections
    2096_01_01_000100_create_content_section_categories_table
)
declare -gA MIG_OK=(
    [2095_01_01_000000_add_expires_at_to_entries_and_products]="entries.expires_at and products.expires_at, nullable timestamptz with no default (catalog only, no table rewrite), then a CHECK added NOT VALID (no scan); each ALTER in its own transaction with lock_timeout 5s, so the ACCESS EXCLUSIVE lock lasts milliseconds or gives up after 5 s (re-runnable: IF NOT EXISTS + constraint lookup); no data written; old code never names the column"
    [2095_01_01_000100_add_expiry_due_indexes]="two partial indexes (site_id, expires_at) WHERE status = 'published' AND expires_at IS NOT NULL AND deleted_at IS NULL, built CONCURRENTLY (writes go on); the only DROP is its own INVALID leftover on a retry; then VALIDATE CONSTRAINT under SHARE UPDATE EXCLUSIVE (writes go on, lock_timeout 5s), every row NULL so it passes; An Vinh's entries/products are a few hundred rows: seconds"
    [2096_01_01_000000_add_site_id_id_unique_to_taxonomies_and_content_sections]="UNIQUE (site_id, id) on taxonomies and content_sections: index built CONCURRENTLY (writes go on; id is the primary key, so no row can violate it), then ADD CONSTRAINT … UNIQUE USING INDEX (catalog only, no scan) in its own transaction with lock_timeout 2s; the only DROP is its own INVALID leftover on a retry; no data written"
    [2096_01_01_000100_create_content_section_categories_table]="a new tenant table (RLS + FORCE, fail-closed policy, app_user grants) with same-site composite FKs: SHARE ROW EXCLUSIVE on sites, content_sections and taxonomies until the migration commits (milliseconds; lock_timeout 5s); the only write is the backfill INSERT … SELECT of each section's category into the new table, ON CONFLICT DO NOTHING (re-runnable); a handful of sections per site; old code never reads the table"
)
# No narrowing: new columns and a new table change col| lines, so every flagged file keeps the engine's
# PgBouncer restart (PGB_SKIP is for index-only files).
declare -gA PGB_SKIP=()
# #957 (routes/console.php): the two sweeps, every minute on the scheduler container.
SCHEDULE_ADDED=(entries:unpublish-due products:unpublish-due)
SCHEDULE_REMOVED=()
ENV_DEFAULT_OK=()
# Expand-only: no column, index or constraint line of the schema snapshot may go or change.
SCHEMA_REMOVED_OK=()
# #958: the categories screen stays a chunk loaded on demand from admin-lazy.js (bsCategories). No vite input
# under resources/ changes in this range, so no build marker.
BUILD_DYNAMIC=(resources/js/admin-categories.js)
BUILD_MARKERS=()

ONEOFFS_PRE=()
# No one-off: the migration fills content_section_categories itself, and no artisan command repeats that
# backfill (the engine's SQL is read-only). The checks below stop before it and verify after it.
ONEOFFS=()

# #960 checks, run with the engine's q (read-only, as the DB owner over the pg container's socket, like every
# earlier SQL check): RLS does not hold that role, so the counts see every site; app_user would see none.
# Sections whose category another section on the same site also names: the backfill gives that category to the
# first section by display order only, so the other section would be left with no row.
SHARED_SQL="SELECT count(*) FROM (SELECT site_id, taxonomy_id FROM content_sections
    WHERE taxonomy_id IS NOT NULL GROUP BY site_id, taxonomy_id HAVING count(*) > 1) AS d"
# Sections whose category is gone or belongs to another site (content_sections.taxonomy_id has no foreign key):
# the backfill joins taxonomies on the same site and skips them, and the new same-site key would refuse them.
DANGLING_SQL="SELECT count(*) FROM content_sections cs WHERE cs.taxonomy_id IS NOT NULL
    AND NOT EXISTS (SELECT 1 FROM taxonomies t WHERE t.id = cs.taxonomy_id AND t.site_id = cs.site_id)"
# After the migration: every section with a category has that category among its own rows.
MISSING_SQL="SELECT count(*) FROM content_sections cs WHERE cs.taxonomy_id IS NOT NULL
    AND NOT EXISTS (SELECT 1 FROM content_section_categories csc WHERE csc.site_id = cs.site_id
        AND csc.content_section_id = cs.id AND csc.taxonomy_id = cs.taxonomy_id)"

section_precheck() {
    expect "chuyên mục được hai mục nội dung cùng trỏ tới (#960, bản sao chép sẽ bỏ sót mục sau)" "$(q "$SHARED_SQL")" "0"
    expect "mục nội dung trỏ tới chuyên mục không còn hoặc của website khác (#960)" "$(q "$DANGLING_SQL")" "0"
}
# Step 0 (before the dump: a stop here changes nothing) and step 3 (read-only, right before migrate).
release_before() { section_precheck; }
release_check() { section_precheck; }
# Step 4, right after migrate and before the code moves: the backfill reached every section.
release_schema() {
    expect "mục nội dung có chuyên mục mà chưa có dòng trong content_section_categories (#960)" "$(q "$MISSING_SQL")" "0"
    expect "content_section_categories bật RLS + FORCE (#960)" \
        "$(q "SELECT relrowsecurity AND relforcerowsecurity FROM pg_class WHERE oid = 'content_section_categories'::regclass")" "t"
}

release_smoke() {
    local n
    n=$(docker logs --since 20m "${PREFIX}app" 2>&1 | grep -cE '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' || true)
    note "lỗi trong nhật ký ứng dụng 20 phút qua: ${n:-0}"
    [ "${n:-0}" = 0 ] || docker logs --since 20m "${PREFIX}app" 2>&1 | grep -E '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' | tail -n 5 | cut -c1-300
    # #960 again after the reload: a section the old code saved between migrate and reload kept its row too
    expect "mục nội dung có chuyên mục mà chưa có dòng trong content_section_categories (#960, sau nạp lại)" "$(q "$MISSING_SQL")" "0"
    # #958 the categories screen still answers (a signed-out visitor is sent to sign in)
    n=$(curl -s -o /dev/null -w '%{http_code}' "https://${HOST}/admin/taxonomies" || true)
    expect "màn Chuyên mục av1 chuyển sang đăng nhập" "${n}" "302"
    n=$(curl -s -o /dev/null -w '%{http_code}' "https://${HOST}/admin/login" || true)
    expect "trang đăng nhập quản trị av1 trả lời" "${n}" "200"
    # #960 section lists and #957 end times: the listing pages answer
    n=$(curl -s -o /dev/null -w '%{http_code}' "https://${HOST}/tin-tuc" || true)
    expect "trang Tin tức av1 trả lời" "${n}" "200"
    n=$(curl -s -o /dev/null -w '%{http_code}' "https://${HOST}/tuyen-dung" || true)
    expect "trang Tuyển dụng av1 trả lời" "${n}" "200"
    n=$(curl -s -D - -o /dev/null "https://${HOST}/" | grep -cE '^HTTP/[0-9.]+ 200' || true)
    expect "trang chủ av1 trả lời 200" "${n:-0}" "1"
}
