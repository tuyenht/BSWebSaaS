# Release file: prod 26c704ec → 1afffe19 (main on 23/11). Data and one-off commands only; the
# engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/26c704ec-1afffe19.sh
# Range:
#   #903 the previous release file.
#   #904 «Khách liên hệ»: download the leads list as a CSV after re-typing the password or code (new admin route, no schema).
#   #905 «Khách liên hệ»: a Trash for leads (deleted_at column + three partial indexes built CONCURRENTLY; nightly purge,
#        which does nothing before 23/12/2026 by its own start guard). The two older list indexes stay (dropped in a later release).
# No permission change. No new .env key.
# shellcheck shell=bash disable=SC2034
OLD=26c704ec2f34b78888bcdf00e10a11a7c387bbc0
TARGET=1afffe19dcbf63d3e1122bdcdc5736a53001e2b7
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /ho-so-nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

# Classes that need a human look and are in this range (the engine stops if this differs from what it finds).
DECLARED=(migration schedule)

MIGRATIONS=(2089_01_01_000000_add_trash_to_form_submissions 2089_01_01_000100_add_trash_aware_lead_indexes)
declare -gA MIG_OK=(
    [2089_01_01_000000_add_trash_to_form_submissions]="expand only on form_submissions: nullable deleted_at timestamptz (no default, no rewrite, lock_timeout 5s); down() drops the column, which puts trashed leads back in their groups (no personal data lost)"
    [2089_01_01_000100_add_trash_aware_lead_indexes]="CONCURRENTLY, outside a transaction: three partial indexes (kept rows by created_at, kept rows by status, trash by deleted_at); drops only its own INVALID leftovers; nothing dropped"
)
declare -gA PGB_SKIP=()
# #905: the nightly purge of leads in the Trash for more than 30 days
SCHEDULE_ADDED=(leads:purge-trash)
SCHEDULE_REMOVED=()
ENV_DEFAULT_OK=()
SCHEMA_REMOVED_OK=()
BUILD_DYNAMIC=()
BUILD_MARKERS=()

ONEOFFS_PRE=()
ONEOFFS=()

release_smoke() {
    local n
    # app errors since the reload (not a stop: printed for the report, the lines shown when any)
    n=$(docker logs --since 20m "${PREFIX}app" 2>&1 | grep -cE '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' || true)
    note "lỗi trong nhật ký ứng dụng 20 phút qua: ${n:-0}"
    [ "${n:-0}" = 0 ] || docker logs --since 20m "${PREFIX}app" 2>&1 | grep -E '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' | tail -n 5 | cut -c1-300
    # the contact page still shows its form
    n=$(curl -s "https://${HOST}/lien-he" | grep -c 'wire:submit\|<form' || true)
    expect "trang liên hệ vẫn có biểu mẫu" "$([ "${n:-0}" -gt 0 ] && echo 1 || echo 0)" "1"
    # the admin sign-in page still answers (the engine's PAGES line checks /admin/login = 200 too)
    n=$(curl -s -o /tmp/.smoke-login.$$ -w '%{http_code}' "https://${HOST}/admin/login" || true); rm -f /tmp/.smoke-login.$$
    expect "trang đăng nhập quản trị av1 trả lời" "${n}" "200"
    # #904: the CSV download is behind sign-in: a stranger with a well-formed token is sent to the sign-in page, never a file
    n=$(curl -s -o /tmp/.smoke-export.$$ -w '%{http_code}' "https://${HOST}/admin/forms/export/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA" || true); rm -f /tmp/.smoke-export.$$
    expect "tải danh sách khách liên hệ cần đăng nhập (mã trả về ${n})" "$([ "${n}" = 302 ] && echo 1 || echo 0)" "1"
    # #905: the Trash column and its three indexes are there and valid
    expect "cột thùng rác của khách liên hệ đã có" "$(q "SELECT count(*) FROM information_schema.columns WHERE table_name = 'form_submissions' AND column_name = 'deleted_at'")" "1"
    expect "ba chỉ mục mới của khách liên hệ hợp lệ" "$(q "SELECT count(*) FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname IN ('form_submissions_kept_created_idx', 'form_submissions_kept_status_created_idx', 'form_submissions_trash_idx') AND i.indisvalid")" "3"
    # the home page answers 200 (the whole header block is read: pages send 103 Early Hints first)
    n=$(curl -s -D - "https://${HOST}/" | grep -cE '^HTTP/[0-9.]+ 200' || true)
    expect "trang chủ av1 trả lời 200" "${n:-0}" "1"
}
