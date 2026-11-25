# Release file: prod 1da5e0f4 → 3c9313cc (main on 26/11). Data and one-off commands only; the
# engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/1da5e0f4-3c9313cc.sh
# Range (no migration, schedule, env or asset change):
#   #982 pictures inside a shared block's body count as used; the usage rebuild now covers shared blocks.
#   #985 the product row menu's divider prints a whole x-show (no console syntax error on «Sản phẩm»).
#   #989 the previous release file.
# One-off: rebuild image usage once so pictures already in shared blocks count (idempotent command).
# shellcheck shell=bash disable=SC2034
OLD=1da5e0f4cc8473ae6d329d07d4d64a8c8e9107ca
TARGET=3c9313ccc740ca98f4c8cea36ee0016592c773d8
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
ONEOFFS=(reconcile_usage)

# #982: rows for pictures already inside shared blocks are written once; the command rebuilds every
# site's usage and is safe to run again (preview has no dry run: it reports the one command to run).
oneoff_reconcile_usage() {
    local out
    case "$1" in
        preview) echo 1 ;;
        apply) out=$(art media:reconcile-content-usage 2>&1) || die "media:reconcile-content-usage thất bại: $(tail -3 <<< "$out")"
            echo "     $(tail -1 <<< "$out")" ;;
        verify) out=$(art media:reconcile-content-usage 2>&1 | tail -1)
            expect "tính lại ảnh đang dùng có cả khối dùng chung (#982)" "$(grep -c 'shared blocks' <<< "$out" || true)" "1" ;;
    esac
}

release_smoke() {
    local n
    n=$(docker logs --since 20m "${PREFIX}app" 2>&1 | grep -cE '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' || true)
    note "lỗi trong nhật ký ứng dụng 20 phút qua: ${n:-0}"
    [ "${n:-0}" = 0 ] || docker logs --since 20m "${PREFIX}app" 2>&1 | grep -E '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' | tail -n 5 | cut -c1-300
    # #982 #985: the library and the product list still answer (a signed-out visitor is sent to sign in)
    n=$(curl -s -o /dev/null -w '%{http_code}' "https://${HOST}/admin/media" || true)
    expect "thư viện ảnh av1 chuyển sang đăng nhập" "${n}" "302"
    n=$(curl -s -o /dev/null -w '%{http_code}' "https://${HOST}/admin/catalog" || true)
    expect "danh sách sản phẩm av1 chuyển sang đăng nhập" "${n}" "302"
    n=$(curl -s -o /dev/null -w '%{http_code}' "https://${HOST}/admin/login" || true)
    expect "trang đăng nhập quản trị av1 trả lời" "${n}" "200"
}
