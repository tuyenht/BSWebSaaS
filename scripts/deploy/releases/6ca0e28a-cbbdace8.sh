# Release file: prod 6ca0e28a → cbbdace8 (main on 26/11). Data and one-off commands only; the
# engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/6ca0e28a-cbbdace8.sh
# Range:
#   #1019 a Google/Facebook sign-in asks for the second factor like a password does.
#   #1021 media library tiles start below the tools line, as on the board.
#   #1022 release file.
# shellcheck shell=bash disable=SC2034
OLD=6ca0e28a1e6b571a115c48b5cf49d5d0fba12024
TARGET=cbbdace8fb271f6567543faadca03e1b2f481639
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
    # #1019: sign-in still answers; the second-factor pages and the social finish send a guest to the sign-in page
    expect "/admin/login" "$(curl -s -o /dev/null -w '%{http_code}' "https://${HOST}/admin/login")" "200"
    local p
    for p in /admin/otp/challenge /admin/two-factor/challenge '/admin/oauth/finish?token=x'; do
        expect "${p} → đăng nhập (#1019)" "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "https://${HOST}${p}")" "302 https://${HOST}/admin/login"
    done
}
