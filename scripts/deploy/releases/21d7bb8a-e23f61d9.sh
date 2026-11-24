# Release file: prod 21d7bb8a → e23f61d9 (main on 24/11). Data and one-off commands only; the
# engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/21d7bb8a-e23f61d9.sh
# Range:
#   #744 dev-only lint/measure tool bump (lockfile; nothing runs on prod).
#   #930 the previous release file.
#   #931 An Vinh pack: the privacy page states the retention periods (loaded afterwards by the owner).
#   #932 settings pages use the full content width.
#   #933 the website menu button keeps its full touch size when the top bar is crowded.
# shellcheck shell=bash disable=SC2034
OLD=21d7bb8a486707bc555617e5ad9fba9f6d3db85a
TARGET=e23f61d92c1487ad0e4388b655a10d4c40ea6265
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /ho-so-nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

DECLARED=(content-pack npm)
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
    # #931: the container holds the privacy page's retention text (the pack load is a separate, owner-run step)
    n=$(docker exec "${PREFIX}app" grep -c 'lưu tối đa 24 tháng' database/content-packs/an-vinh/pages.json || true)
    expect "gói nội dung trong container có thời hạn lưu trên trang bảo mật" "$([ "${n:-0}" -gt 0 ] && echo 1 || echo 0)" "1"
    # #932/#933: the admin sign-in page still answers after the view changes
    n=$(curl -s -o /tmp/.smoke-login.$$ -w '%{http_code}' "https://${HOST}/admin/login" || true); rm -f /tmp/.smoke-login.$$
    expect "trang đăng nhập quản trị av1 trả lời" "${n}" "200"
    n=$(curl -s -D - "https://${HOST}/" | grep -cE '^HTTP/[0-9.]+ 200' || true)
    expect "trang chủ av1 trả lời 200" "${n:-0}" "1"
}
