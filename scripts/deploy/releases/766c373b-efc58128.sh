# Release file: prod 766c373b → efc58128 (main on 26/11). Data and one-off commands only; the
# engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/766c373b-efc58128.sh
# Range:
#   #1006 entry covers are written as their library row (cover_media_id); readers still fall back to the old key.
#   #1011 quick sign-in says how to link an unlinked account; links and quick sign-ins are logged.
#   #1014 a list row's menus are built only while open.
#   #1017 contact card title level with its icon; An Vinh letter signed by the board, no card hours
#         (the page text reaches av1 through plans/tools/anvinh-letter-sign-hours-load-261126.sh after this deploy).
#   #1016 release file.
# shellcheck shell=bash disable=SC2034
OLD=766c373b3d8977739294b261b508786035d7ce92
TARGET=efc581286390c7f046383ae788c145e5932babff
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
    # #1006 #1014: the post list and editor still answer (a signed-out visitor is sent to sign in)
    n=$(curl -s -o /dev/null -w '%{http_code}' "https://${HOST}/admin/content" || true)
    expect "danh sách bài viết av1 chuyển sang đăng nhập" "${n}" "302"
    n=$(curl -s -o /dev/null -w '%{http_code}' "https://${HOST}/admin/login" || true)
    expect "trang đăng nhập quản trị av1 trả lời" "${n}" "200"
    # #1006: the first sized picture on /tin-tuc (hero or a post cover) still loads; checked 26/11: 200
    local cover
    cover=$(curl -s "https://${HOST}/tin-tuc" | grep -oE 'https://files\.baoson\.net/[^" ,]+\?w=[0-9]+' | grep -v '/logo-' | head -n 1 || true)
    n=$(curl -s -o /dev/null -w '%{http_code}' "${cover:-https://files.baoson.net/none}" || true)
    expect "ảnh đầu tiên ở /tin-tuc tải được (#1006)" "${n}" "200"
    # #1017: the new card title rule is in the served stylesheet
    local css
    css=$(curl -s "https://${HOST}/lien-he" | grep -oE '/build/assets/sf-form-[^"]+\.css' | head -n 1 || true)
    n=$(curl -s "https://${HOST}${css:-/none}" | grep -c 'margin-top:-.25rem' || true)
    expect "bảng kiểu sf-form có tiêu đề thẻ mới (#1017)" "${n}" "1"
}
