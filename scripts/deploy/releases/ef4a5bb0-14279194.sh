# Release file: prod ef4a5bb0 → 14279194 (main on 24/11). Data and one-off commands only; the
# engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/ef4a5bb0-14279194.sh
# Range:
#   #922 the previous release file.
#   #918 file fields on website forms: a form field may be of type `file` (CHECK widened), the website
#        form posts multipart and stores each file on the private lead disk, the lead inbox lists the
#        files with a signed download. Kinds offered: drawings (cad) and images (img); CVs stay off
#        (FORMS_FILE_KINDS default `cad,img`). A lead whose files pass post_max_size comes back to its
#        form with a plain message instead of the 413 page (ValidatePostSize replaced for that route).
# New .env key left to its default (ENV_DEFAULT_OK): FORMS_FILE_KINDS.
# shellcheck shell=bash disable=SC2034
OLD=ef4a5bb044ddbc9229fd656b47c052ac6e1e1a36
TARGET=142791948603224b2034911fc1f7b84f5fdae483
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /ho-so-nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

# Classes that need a human look and are in this range (the engine stops if this differs from what it finds).
DECLARED=(migration)

MIGRATIONS=(2090_01_01_000300_add_file_type_to_form_fields)
declare -gA MIG_OK=(
    [2090_01_01_000300_add_file_type_to_form_fields]="CHECK widen only: form_fields_type_check gets 'file' added (DROP + ADD NOT VALID + VALIDATE, lock_timeout 5s); no row changes; down() refuses while any file field exists, then restores the old list"
)
declare -gA PGB_SKIP=()
SCHEDULE_ADDED=()
SCHEDULE_REMOVED=()
# #918: cad,img is the platform default; CVs stay off until their shorter keeping period is built
ENV_DEFAULT_OK=(FORMS_FILE_KINDS)
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
    # #918: the field type list takes 'file'
    expect "loại ô «Tệp đính kèm» được phép trong CSDL" "$(q "SELECT count(*) FROM pg_constraint WHERE conname = 'form_fields_type_check' AND convalidated AND pg_get_constraintdef(oid) LIKE '%''file''%'")" "1"
    # the private disk on the real R2 still keeps a file as a download
    n=$(art forms:check-file-storage 2>&1 | tail -n 1 | cut -c1-200 || true)
    expect "kho tệp riêng trên R2 tự kiểm" "${n}" "OK"
    # the contact page still shows its form
    n=$(curl -s "https://${HOST}/lien-he" | grep -c 'wire:submit\|<form' || true)
    expect "trang liên hệ vẫn có biểu mẫu" "$([ "${n:-0}" -gt 0 ] && echo 1 || echo 0)" "1"
    n=$(curl -s -o /tmp/.smoke-login.$$ -w '%{http_code}' "https://${HOST}/admin/login" || true); rm -f /tmp/.smoke-login.$$
    expect "trang đăng nhập quản trị av1 trả lời" "${n}" "200"
    # the home page answers 200 (the whole header block is read: pages send 103 Early Hints first)
    n=$(curl -s -D - "https://${HOST}/" | grep -cE '^HTTP/[0-9.]+ 200' || true)
    expect "trang chủ av1 trả lời 200" "${n:-0}" "1"
}
