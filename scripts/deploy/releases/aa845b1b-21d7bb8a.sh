# Release file: prod aa845b1b → 21d7bb8a (main on 24/11). Data and one-off commands only; the
# engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/aa845b1b-21d7bb8a.sh
# Range:
#   #927 the previous release file.
#   #928 An Vinh pack: accountant job offer, engineer offer completed, careers category's own side column.
#   #929 consult side box may name its own phone and email; careers box uses the hiring desk; workplaces by province.
# The pack is loaded afterwards by the owner (plans/tools/anvinh-recruit2-load-261124.sh apply).
# shellcheck shell=bash disable=SC2034
OLD=aa845b1bdd1a42ccbd267bb29526741740efebd0
TARGET=21d7bb8a486707bc555617e5ad9fba9f6d3db85a
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
    # #928/#929: the container holds the new careers content (the pack load itself is a separate, owner-run step)
    n=$(docker exec "${PREFIX}app" grep -c 'post:ke-toan-noi-bo' database/content-packs/an-vinh/posts.json || true)
    expect "gói nội dung trong container có tin kế toán" "$([ "${n:-0}" -gt 0 ] && echo 1 || echo 0)" "1"
    n=$(curl -s -o /tmp/.smoke-login.$$ -w '%{http_code}' "https://${HOST}/admin/login" || true); rm -f /tmp/.smoke-login.$$
    expect "trang đăng nhập quản trị av1 trả lời" "${n}" "200"
    n=$(curl -s -D - "https://${HOST}/" | grep -cE '^HTTP/[0-9.]+ 200' || true)
    expect "trang chủ av1 trả lời 200" "${n:-0}" "1"
}
