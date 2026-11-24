# Release file: prod 792dea55 → b5664490 (main on 23/11). Data and one-off commands only; the
# engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/792dea55-b5664490.sh
# Range:
#   #917 the previous release file.
#   #919 a recovery code typed in capitals or with spaces around it is taken at sign-in (the service lowers it).
#   #920 a wrong or just-used app code at sign-in shows the wording «Tài khoản của tôi» already uses.
# No migration, no permission, no schedule, no new .env key, no front-end change.
# shellcheck shell=bash disable=SC2034
OLD=792dea55c0071f2362a9f64849c4679752d06284
TARGET=b56644905f19d09d10b357be2815f2dc8f45e1fe
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
    # app errors since the reload (not a stop: printed for the report, the lines shown when any)
    n=$(docker logs --since 20m "${PREFIX}app" 2>&1 | grep -cE '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' || true)
    note "lỗi trong nhật ký ứng dụng 20 phút qua: ${n:-0}"
    [ "${n:-0}" = 0 ] || docker logs --since 20m "${PREFIX}app" 2>&1 | grep -E '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' | tail -n 5 | cut -c1-300
    # the admin sign-in page still answers (the engine's PAGES line checks /admin/login = 200 too)
    n=$(curl -s -o /tmp/.smoke-login.$$ -w '%{http_code}' "https://${HOST}/admin/login" || true); rm -f /tmp/.smoke-login.$$
    expect "trang đăng nhập quản trị av1 trả lời" "${n}" "200"
    # #919/#920: the sign-in code step without a pending sign-in goes back to the sign-in page
    n=$(curl -s -o /tmp/.smoke-2fa.$$ -w '%{http_code}' "https://${HOST}/admin/two-factor" || true); rm -f /tmp/.smoke-2fa.$$
    note "bước mã 2 lớp khi chưa đăng nhập trả mã ${n}"
    # the contact page still shows its form
    n=$(curl -s "https://${HOST}/lien-he" | grep -c 'wire:submit\|<form' || true)
    expect "trang liên hệ vẫn có biểu mẫu" "$([ "${n:-0}" -gt 0 ] && echo 1 || echo 0)" "1"
    # the home page answers 200 (the whole header block is read: pages send 103 Early Hints first)
    n=$(curl -s -D - "https://${HOST}/" | grep -cE '^HTTP/[0-9.]+ 200' || true)
    expect "trang chủ av1 trả lời 200" "${n:-0}" "1"
}
