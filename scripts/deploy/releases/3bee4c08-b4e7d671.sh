# Release file: prod 3bee4c08 → b4e7d671 (main on 22/11). Data and one-off commands only; the
# engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/3bee4c08-b4e7d671.sh
# Range:
#   #889 the previous release file.
#   #890 deleting a role that someone restored at the same moment says it changed elsewhere.
#   #891 a scheduled post whose address is taken leaves the schedule (a draft again, or back to
#        review) and its author and scheduler are told once, instead of failing every minute.
#   #892 Tab on an open list menu moves on from its button instead of losing the focus.
# No migration. No permission change. No new .env key. No schedule change.
# shellcheck shell=bash disable=SC2034
OLD=3bee4c082f5fad16f8bf7f4a53da720fe4f45efd
TARGET=b4e7d6713b77b4f5e292a77d7a5c77ba54a35d04
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /ho-so-nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

# Classes that need a human look and are in this range (the engine stops if this differs from what it finds).
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
    # the contact page still shows its form
    n=$(curl -s "https://${HOST}/lien-he" | grep -c 'wire:submit\|<form' || true)
    expect "trang liên hệ vẫn có biểu mẫu" "$([ "${n:-0}" -gt 0 ] && echo 1 || echo 0)" "1"
    # #876/#883: the shared permission version token (printed, not a stop: written on the first permission change)
    note "mã phiên bản quyền dùng chung: $(docker exec "${PREFIX}valkey" valkey-cli --scan --pattern '*permission*version*' 2>&1 | head -n 3 | tr '\n' ' ')"
    # #887/#888: the roles screen answers a signed-out visitor with a redirect to sign-in
    n=$(curl -s -o /dev/null -w '%{http_code}' "https://${HOST}/admin/roles" || true)
    note "«Nhóm quyền» khi chưa đăng nhập trả mã: ${n}"
}
