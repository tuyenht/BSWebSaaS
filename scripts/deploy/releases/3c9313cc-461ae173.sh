# Release file: prod 3c9313cc → 461ae173 (main on 26/11). Data and one-off commands only; the
# engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/3c9313cc-461ae173.sh
# Range (no migration, schedule or env change):
#   #987 «Xuất bản» / «Đưa về nháp» in a shared block's «⋯» (a used block asks first), «Lưu & xuất bản»
#        in the editor of a draft block; the shared question dialog gains a warning tone.
#   #989 #990 the previous release files (the 1da5e0f4 → 3c9313cc run stopped after its one-off; this
#        run repeats every after-deploy check).
# shellcheck shell=bash disable=SC2034
OLD=3c9313ccc740ca98f4c8cea36ee0016592c773d8
TARGET=461ae173f9e890dc5800c6fb5861df499ebcfe50
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /ho-so-nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

DECLARED=()
MIGRATIONS=()
declare -gA MIG_OK=()
declare -gA PGB_SKIP=()
SCHEDULE_ADDED=()
SCHEDULE_REMOVED=()
ENV_DEFAULT_OK=()
SCHEMA_REMOVED_OK=()
BUILD_DYNAMIC=()
BUILD_MARKERS=()

ONEOFFS_PRE=()
ONEOFFS=()

release_smoke() {
    local n
    n=$(docker logs --since 20m "${PREFIX}app" 2>&1 | grep -cE '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' || true)
    note "lỗi trong nhật ký ứng dụng 20 phút qua: ${n:-0}"
    [ "${n:-0}" = 0 ] || docker logs --since 20m "${PREFIX}app" 2>&1 | grep -E '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' | tail -n 5 | cut -c1-300
    # #987: the shared-block library still answers (a signed-out visitor is sent to sign in)
    n=$(curl -s -o /dev/null -w '%{http_code}' "https://${HOST}/admin/blocks" || true)
    expect "thư viện khối dùng chung av1 chuyển sang đăng nhập" "${n}" "302"
    n=$(curl -s -o /dev/null -w '%{http_code}' "https://${HOST}/admin/login" || true)
    expect "trang đăng nhập quản trị av1 trả lời" "${n}" "200"
}
