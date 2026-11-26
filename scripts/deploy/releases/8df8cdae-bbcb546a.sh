# Release file: prod 8df8cdae → bbcb546a (main on 26/11). Data and one-off commands only; the
# engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/8df8cdae-bbcb546a.sh
# Range (no migration, schedule, env or asset change):
#   #972 a website can let the admin follow each device's light or dark («Theo máy»).
#   #975 the settings menu marks Email and Tích hợp «Chưa cấu hình» until they are set up.
#   #974 shorter names («Khối dùng chung», «Việc làm»); the An Vinh pack's job category is «Tuyển dụng»
#        (pack text only: the live av1 category keeps its name until the pack is applied on its own).
#   #976 «Cập nhật lần cuối» and «Khôi phục mặc định» with «Hoàn tác» on every settings section.
#   #973 the previous release file.
# shellcheck shell=bash disable=SC2034
OLD=8df8cdaeb9bce6e0bc88a4d1bc83b7edad39088c
TARGET=bbcb546a1b90451b5ece7da10253e722fc2bd1bf
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /ho-so-nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

DECLARED=(content-pack)
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
    # #975 #976 the settings pages still answer (a signed-out visitor is sent to sign in)
    n=$(curl -s -o /dev/null -w '%{http_code}' "https://${HOST}/admin/settings/general" || true)
    expect "Thông tin chung av1 chuyển sang đăng nhập" "${n}" "302"
    n=$(curl -s -o /dev/null -w '%{http_code}' "https://${HOST}/admin/settings/email" || true)
    expect "Email av1 chuyển sang đăng nhập" "${n}" "302"
    n=$(curl -s -o /dev/null -w '%{http_code}' "https://${HOST}/admin/login" || true)
    expect "trang đăng nhập quản trị av1 trả lời" "${n}" "200"
    n=$(curl -s -D - -o /dev/null "https://${HOST}/" | grep -cE '^HTTP/[0-9.]+ 200' || true)
    expect "trang chủ av1 trả lời 200" "${n:-0}" "1"
}
