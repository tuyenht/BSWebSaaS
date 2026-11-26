# Release file: prod 8ef7d9ff → 766c373b (main on 26/11). Data and one-off commands only; the
# engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/8ef7d9ff-766c373b.sh
# Range (no migration, schedule or env change):
#   #1009 a large panel of a main-menu item can show a shared block (Trình đơn screen + storefront
#         menu). No menu uses it yet, so every page's menu draws as before.
#   #1010 a row tick on the content list answers in the browser.
#   #1013 release file.
# shellcheck shell=bash disable=SC2034
OLD=8ef7d9ff661597c75925df3584ea2956a9b27c19
TARGET=766c373b3d8977739294b261b508786035d7ce92
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
    # #1009: the home page still draws its main menu (the nav landmark is there)
    n=$(curl -s "https://${HOST}/" | grep -c '<nav' || true)
    expect "trang chủ av1 còn trình đơn chính" "$([ "${n:-0}" -ge 1 ] && echo 1 || echo 0)" "1"
    n=$(curl -s -o /dev/null -w '%{http_code}' "https://${HOST}/admin/login" || true)
    expect "trang đăng nhập quản trị av1 trả lời" "${n}" "200"
}
