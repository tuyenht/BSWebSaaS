# Release file: prod 59ffbf6c → b0931735 (main on 22/11). Data and one-off commands only; the
# engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/59ffbf6c-b0931735.sh
# Range:
#   #876 + #880 a role or permission change reaches every Octane and queue worker at its next request or job
#        (shared version token; listeners registered in the Rbac provider, config/octane.php unchanged).
#   #878 the review inbox matches board 17 (reader, tabs, guide).
#   #879 #881 ops-log only.
# No migration. No permission change. No config change.
# shellcheck shell=bash disable=SC2034
OLD=59ffbf6c3dcdfc51ed635f62664e39661d0dca1f
TARGET=b0931735f2dfbb0396c73ae4c98fcccccb5f6624
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
    # #876: the shared permission version token (printed, not a stop: written on the first permission change)
    note "mã phiên bản quyền dùng chung: $(docker exec "${PREFIX}valkey" valkey-cli --scan --pattern '*permission*version*' 2>&1 | head -n 3 | tr '\n' ' ')"
    # #878: the review inbox still renders for a signed-out visitor as a redirect to sign-in
    n=$(curl -s -o /dev/null -w '%{http_code}' "https://${HOST}/admin/content/review" || true)
    note "Hộp duyệt khi chưa đăng nhập trả mã: ${n}"
}
