# Release file: prod be4772e9 → 6b48dfbe (main on 26/11). Data and one-off commands only; the
# engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/be4772e9-6b48dfbe.sh
# Range:
#   #999 «Thông tin tuyển dụng» card in the post editor (facts, badge, deadline; shape set in the
#        default language only).
#   #998 entry covers are read by id first, then the old key.
#   #1001 mail goes through the shared outbox when a website's own one fails.
#   #1000 #1002 release files.
# shellcheck shell=bash disable=SC2034
OLD=be4772e93376644cb20f0211f74212240753c7ff
TARGET=6b48dfbef7caf6458cad4fcd1a3befabf72bfab6
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
    # #999: the post list and editor still answer (a signed-out visitor is sent to sign in)
    n=$(curl -s -o /dev/null -w '%{http_code}' "https://${HOST}/admin/content" || true)
    expect "danh sách bài viết av1 chuyển sang đăng nhập" "${n}" "302"
    n=$(curl -s -o /dev/null -w '%{http_code}' "https://${HOST}/admin/login" || true)
    expect "trang đăng nhập quản trị av1 trả lời" "${n}" "200"
    # #998: the first sized picture on /tin-tuc (hero or a post cover) still loads; checked 26/11: 200
    local cover
    cover=$(curl -s "https://${HOST}/tin-tuc" | grep -oE 'https://files\.baoson\.net/[^" ,]+\?w=[0-9]+' | grep -v '/logo-' | head -n 1 || true)
    n=$(curl -s -o /dev/null -w '%{http_code}' "${cover:-https://files.baoson.net/none}" || true)
    expect "ảnh đầu tiên ở /tin-tuc tải được (#998)" "${n}" "200"
}
