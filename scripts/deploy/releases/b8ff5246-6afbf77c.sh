# Release file: prod b8ff5246 → 6afbf77c (main on 20/11). Data and one-off commands only; the engine
# (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/b8ff5246-6afbf77c.sh
# Range:
#   #844 «Ngôn ngữ website»: turn languages on, show/hide them, change the default language.
#   #845 the previous release file.
#   #846 one run owns a form delete at a time; deleting forms' leads leave the inbox.
#   #847 erase a lead at the visitor's request; categories only look during a support session.
#   #848 «Thư viện khối»: block library list, create, rename, duplicate, delete, where a block is used.
#   #849 «Chuyển hướng»: redirects screen with a Trash (redirects.deleted_at, source, product/category
#        links), nightly redirects:purge-trash; redirects to another website behind a per-site switch,
#        off by default. One-off cms:fill-ulids after the reload (below).
#   #850 contact inbox search through a per-site word table lead_search_terms (RLS FORCE). One-off
#        forms:search-terms after the reload (below).
#   #851 support staff signed in for a member never delete a form with its leads, or files for good.
#   #852 delete at most 5,000 leads inside the request (config default), the rest through the queue.
# Three migrations. No permission change. No new .env key; one config default changed (below).
# shellcheck shell=bash disable=SC2034
OLD=b8ff5246b8f5b14bab1276dade314a82e4eccd4f
TARGET=6afbf77c71c2fefd59fb160f43db92cca4a6a72a
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /ho-so-nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

# Classes that need a human look and are in this range (the engine stops if this differs from what it finds).
DECLARED=(migration schedule)

MIGRATIONS=(2085_01_01_000000_add_trash_source_and_targets_to_redirects 2085_01_01_000100_make_redirects_unique_on_live_rows 2086_01_01_000000_create_lead_search_terms_table)
declare -gA MIG_OK=(
    [2085_01_01_000000_add_trash_source_and_targets_to_redirects]="expand only on redirects: nullable deleted_at/ulid/product_id/taxonomy_id and source with a constant default + CHECK (NOT VALID then VALIDATE), FKs ON DELETE SET NULL added NOT VALID then validated; no rewrite; rows that lead to a post/page set to source auto; old code keeps working"
    [2085_01_01_000100_make_redirects_unique_on_live_rows]="indexes built CONCURRENTLY outside a transaction (live-row unique first, then the old full unique dropped, so a unique on live rows always exists), ulid unique, partial product_id/taxonomy_id indexes; drops only its own INVALID leftovers on retry"
    [2086_01_01_000000_create_lead_search_terms_table]="new empty tenant table lead_search_terms (PK site_id, term, lead_id; lead_id index; FK to form_submissions ON DELETE CASCADE); RLS ENABLE + FORCE, site policy and app_user grant; no data written"
)
declare -gA PGB_SKIP=()
SCHEDULE_ADDED=(redirects:purge-trash)
SCHEDULE_REMOVED=()
# #852: FORMS_DELETE_INLINE_MAX_LEADS default 20000 → 5000 (not set in prod, so the default applies).
ENV_DEFAULT_OK=(FORMS_DELETE_INLINE_MAX_LEADS)
# 2085_000100 swaps the full unique for the partial live-row unique built first (redirects_site_id_locale_from_path_live_unique).
SCHEMA_REMOVED_OK=("con|redirects|redirects_site_id_locale_from_path_unique|u|validated" "idx|redirects|redirects_site_id_locale_from_path_unique|valid")
BUILD_DYNAMIC=()
BUILD_MARKERS=()

ONEOFFS_PRE=()
# Both after the reload: old workers keep writing redirects with no key and leads with no words until
# they run the new code.
ONEOFFS=(redirect_ulids lead_search_terms)

# #849: give every redirect written before (or during) the deploy its key; the screen needs it.
oneoff_redirect_ulids() {
    local out
    case "$1" in
        preview) q "SELECT count(*) FROM redirects WHERE ulid IS NULL" ;;
        apply) out=$(art cms:fill-ulids 2>&1) || die "cms:fill-ulids thất bại: $(tail -2 <<< "$out")"
            echo "     $(tail -1 <<< "$out")" ;;
        verify) expect "mọi chuyển hướng đã có mã (#849)" "$(q "SELECT count(*) FROM redirects WHERE ulid IS NULL")" "0" ;;
    esac
}

# #850: fill the word table from every existing lead once; leads saved after the reload write their own.
oneoff_lead_search_terms() {
    local out
    case "$1" in
        preview) if [ -s "$STATE/lead-search-terms-filled" ]; then echo 0; else echo 1; fi ;;
        apply) out=$(art forms:search-terms 2>&1) || die "forms:search-terms thất bại: $(tail -2 <<< "$out")"
            echo "     $(tail -1 <<< "$out")"
            date '+%F %T' > "$STATE/lead-search-terms-filled" ;;
        verify) out=$(art forms:search-terms 2>&1 | tail -1)
            expect "chạy lại lệnh ghi từ tìm kiếm liên hệ không còn gì để sửa (#850)" "$(grep -c 'corrected 0\.' <<< "$out" || true)" "1" ;;
    esac
}

release_smoke() {
    local n
    # app errors since the reload (not a stop: printed for the report, the lines shown when any)
    n=$(docker logs --since 20m "${PREFIX}app" 2>&1 | grep -cE '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' || true)
    note "lỗi trong nhật ký ứng dụng 20 phút qua: ${n:-0}"
    [ "${n:-0}" = 0 ] || docker logs --since 20m "${PREFIX}app" 2>&1 | grep -E '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' | tail -n 5 | cut -c1-300
    # the contact page still shows its form
    n=$(curl -s "https://${HOST}/lien-he" | grep -c 'wire:submit\|<form' || true)
    expect "trang liên hệ vẫn có biểu mẫu" "$([ "${n:-0}" -gt 0 ] && echo 1 || echo 0)" "1"
    # #850: the new tenant table is under RLS FORCE with its policy
    expect "lead_search_terms có RLS FORCE và chính sách (#850)" \
        "$(q "SELECT count(*) FROM pg_class c JOIN pg_policies p ON p.tablename = c.relname WHERE c.relname = 'lead_search_terms' AND c.relrowsecurity AND c.relforcerowsecurity")" "1"
    # #849: the redirect switch is off everywhere unless a super_admin turned it on
    note "website bật chuyển hướng ra ngoài: $(q "SELECT count(*) FROM site_features WHERE feature_key = 'redirects.external' AND value = 'true'::jsonb" 2>&1 | tail -1)"
}
