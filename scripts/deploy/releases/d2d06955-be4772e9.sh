# Release file: prod d2d06955 → be4772e9 (main on 26/11). Data and one-off commands only; the
# engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/d2d06955-be4772e9.sh
# Range (no migration, schedule or env change):
#   #995 a page body or block slot can place a shared block by its code; the storefront draws it, the
#        places are recorded in the save's transaction, blocks:reconcile-usage rebuilds them. No admin
#        picker yet, and no website places a block yet, so nothing changes on a page today.
# shellcheck shell=bash disable=SC2034
OLD=d2d069550f31a9052f0a848f530f4f588ded3db0
TARGET=be4772e93376644cb20f0211f74212240753c7ff
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
    # #995: the rebuild command is in the running code; the block library still answers
    n=$(docker exec "${PREFIX}app" php artisan list --raw 2>&1 | grep -cE '^blocks:reconcile-usage ' || true)
    expect "có lệnh tính lại nơi đặt khối dùng chung (#995)" "${n:-0}" "1"
    n=$(curl -s -o /dev/null -w '%{http_code}' "https://${HOST}/admin/blocks" || true)
    expect "thư viện khối dùng chung av1 chuyển sang đăng nhập" "${n}" "302"
}
