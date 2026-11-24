# Release file: prod afc74dc5 → 792dea55 (main on 23/11). Data and one-off commands only; the
# engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/afc74dc5-792dea55.sh
# Range:
#   #911 the previous release file.
#   #912 lead attachments, backend only: new tenant table form_submission_files (RLS forced, FK SET NULL),
#        permission forms.files granted to the roles that hold forms.manage (the viewer role does not get it),
#        private disk under the media bucket's `_private/` root, download route, hourly sweeper.
#        No form can store a file yet (the form field ships later), so the table stays empty.
#   #913 nightly lead commands go on with the other sites when one fails; phones keep their leading zero in the CSV.
#   #914 each authenticator code is accepted once, across sign-in and every re-check.
#   #915 attachment hardening: stricter DOCX check, daily reconcile of objects no row names, no visitor file name in links,
#        and the forms:check-file-storage self-check run below against the real disk.
#   #916 each recovery code is taken once even when two sign-ins race.
# Permission change: the engine runs rbac:sync after the reload (add-only; the viewer template lacks forms.files).
# New .env keys, all left to their defaults (ENV_DEFAULT_OK): the private disk reuses the R2 keys media already has.
# shellcheck shell=bash disable=SC2034
OLD=afc74dc5c77d594d45319f50438bf0e2edefb788
TARGET=792dea55c0071f2362a9f64849c4679752d06284
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /ho-so-nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

# Classes that need a human look and are in this range (the engine stops if this differs from what it finds).
DECLARED=(migration permission schedule)

MIGRATIONS=(2090_01_01_000100_create_form_submission_files_table 2090_01_01_000200_grant_lead_files_permission)
declare -gA MIG_OK=(
    [2090_01_01_000100_create_form_submission_files_table]="new empty tenant table form_submission_files: RLS ENABLE + FORCE with the site policy, FK to form_submissions ON DELETE SET NULL, two partial indexes on an empty table; lock_timeout 5s for the brief FK locks; down() drops the table (no rows exist: no form can store a file yet)"
    [2090_01_01_000200_grant_lead_files_permission]="data only: inserts permission forms.files and grants it to every role that already holds forms.manage (insert or ignore, idempotent); down() deletes the permission and its grants"
)
declare -gA PGB_SKIP=()
# #912: hourly sweeper of files whose lead is gone; #915: daily reconcile of objects no row names
SCHEDULE_ADDED=(forms:sweep-files forms:reconcile-files)
SCHEDULE_REMOVED=()
# #912: the private disk's defaults are right for prod: disk `leads`, the media bucket (R2_BUCKET), root `_private`, 20 MB
ENV_DEFAULT_OK=(FORMS_FILES_BUCKET FORMS_FILES_DISK FORMS_FILES_ROOT FORMS_FILE_MAX_MB)
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
    # #912: the new table is there with RLS forced, and empty
    expect "bảng tệp đính kèm có RLS bắt buộc" "$(q "SELECT count(*) FROM pg_class WHERE relname = 'form_submission_files' AND relrowsecurity AND relforcerowsecurity")" "1"
    note "số dòng tệp đính kèm (thống kê): $(q "SELECT coalesce((SELECT n_live_tup FROM pg_stat_user_tables WHERE relname = 'form_submission_files'), 0)")"
    # #912: forms.files exists and every role holding forms.manage got it
    expect "quyền tải tệp đính kèm đã có" "$(q "SELECT count(*) FROM permissions WHERE name = 'forms.files'")" "1"
    expect "vai có quyền quản lý biểu mẫu mà thiếu quyền tải tệp" "$(q "SELECT count(*) FROM role_has_permissions m JOIN permissions pm ON pm.id = m.permission_id AND pm.name = 'forms.manage' WHERE NOT EXISTS (SELECT 1 FROM role_has_permissions f JOIN permissions pf ON pf.id = f.permission_id AND pf.name = 'forms.files' WHERE f.role_id = m.role_id)")" "0"
    expect "vai «Chỉ xem» có sẵn không có quyền tải tệp" "$(q "SELECT count(*) FROM roles r JOIN role_has_permissions rp ON rp.role_id = r.id JOIN permissions p ON p.id = rp.permission_id WHERE r.is_system AND r.name = 'viewer' AND p.name = 'forms.files'")" "0"
    note "số vai có quyền tải tệp đính kèm: $(q "SELECT count(*) FROM role_has_permissions f JOIN permissions pf ON pf.id = f.permission_id AND pf.name = 'forms.files'")"
    # #915: the private disk on the real R2 keeps the file as a download and its signed link answers as one
    n=$(art forms:check-file-storage 2>&1 | tail -n 1 | cut -c1-200 || true)
    expect "kho tệp riêng trên R2 tự kiểm" "${n}" "OK"
    # #912: the download route is behind sign-in: a stranger is sent to the sign-in page
    n=$(curl -s -o /tmp/.smoke-file.$$ -w '%{http_code}' "https://${HOST}/admin/forms/files/01JAAAAAAAAAAAAAAAAAAAAAAA" || true); rm -f /tmp/.smoke-file.$$
    expect "tải tệp đính kèm cần đăng nhập (mã trả về ${n})" "$([ "${n}" = 302 ] && echo 1 || echo 0)" "1"
    # the home page answers 200 (the whole header block is read: pages send 103 Early Hints first)
    n=$(curl -s -D - "https://${HOST}/" | grep -cE '^HTTP/[0-9.]+ 200' || true)
    expect "trang chủ av1 trả lời 200" "${n:-0}" "1"
}
