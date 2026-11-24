# Release file: prod a7188403 → aa845b1b (main on 24/11). Data and one-off commands only; the
# engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/a7188403-aa845b1b.sh
# Range:
#   #907 composer minor/patch group (laravel/framework 13.40.0 → 13.43.0, tinker 3.0.2 → 3.2.0; no major).
#   #908 npm minor/patch group (vite 8.3.6 → 8.3.8, rolldown, axe-core; no major).
#   #925 menus screen: read-only during a support session; description limit 200/90 by depth with a counter.
#   #926 the previous release file.
# shellcheck shell=bash disable=SC2034
OLD=a718840375b8e0a6cdc4a34eb7c74ff78203a38d
TARGET=aa845b1bdd1a42ccbd267bb29526741740efebd0
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /ho-so-nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

DECLARED=(composer-lock npm)
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
    # #925: the menus screen still opens for a signed-out visitor as a sign-in redirect
    n=$(curl -s -o /tmp/.smoke-menus.$$ -w '%{http_code}' "https://${HOST}/admin/menus" || true); rm -f /tmp/.smoke-menus.$$
    expect "màn Trình đơn đòi đăng nhập (mã ${n})" "$([ "${n}" = 302 ] && echo 1 || echo 0)" "1"
    # #907: the framework the container runs
    note "phiên bản Laravel trong container: $(art --version 2>&1 | tail -n 1 | cut -c1-60)"
    n=$(curl -s -o /tmp/.smoke-login.$$ -w '%{http_code}' "https://${HOST}/admin/login" || true); rm -f /tmp/.smoke-login.$$
    expect "trang đăng nhập quản trị av1 trả lời" "${n}" "200"
    n=$(curl -s -D - "https://${HOST}/" | grep -cE '^HTTP/[0-9.]+ 200' || true)
    expect "trang chủ av1 trả lời 200" "${n:-0}" "1"
}
