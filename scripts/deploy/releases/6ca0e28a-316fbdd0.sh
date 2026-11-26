# Release file: prod 6ca0e28a → 316fbdd0 (main on 26/11). Data and one-off commands only; the
# engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/6ca0e28a-316fbdd0.sh
# Range:
#   #1019 a Google/Facebook sign-in asks for the second factor like a password does (authenticator app if
#         on; otherwise a direct sign-in only when Google vouches for the account's own email, else an
#         emailed code); the emailed-code step never stands in for the authenticator app. No migration.
# shellcheck shell=bash disable=SC2034
OLD=6ca0e28a1e6b571a115c48b5cf49d5d0fba12024
TARGET=316fbdd0ad3898f777365a5716b6d15a2573c04a
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
    # #1019: the sign-in page and both second-factor steps answer (a visitor with no pending step is sent back)
    n=$(curl -s -o /dev/null -w '%{http_code}' "https://${HOST}/admin/login" || true)
    expect "trang đăng nhập quản trị av1 trả lời" "${n}" "200"
    n=$(curl -s -o /dev/null -w '%{http_code}' "https://${HOST}/admin/otp/challenge" || true)
    expect "màn mã email không có bước chờ → chuyển về đăng nhập" "${n}" "302"
    n=$(curl -s -o /dev/null -w '%{http_code}' "https://${HOST}/admin/oauth/finish?token=x" || true)
    expect "oauth/finish với mã sai → chuyển về đăng nhập" "${n}" "302"
}
