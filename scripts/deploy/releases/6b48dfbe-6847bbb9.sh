# Release file: prod 6b48dfbe → 6847bbb9 (main on 26/11). Data and one-off commands only; the
# engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/6b48dfbe-6847bbb9.sh
# Range:
#   #1005 contact cards: value line sits under its title (tap height from padding); the footer's
#         registered name wraps evenly (1rem on phones); a Zalo card without a label says "Nhắn tin qua Zalo".
#   #1004 page weight ceilings for the posts list and the post editor (tests only).
#   #1003 release file.
# The An Vinh Zalo label text lives in the page data: plans/tools/anvinh-contact-zalo-label-load-261126.sh after this deploy.
# shellcheck shell=bash disable=SC2034
OLD=6b48dfbef7caf6458cad4fcd1a3befabf72bfab6
TARGET=6847bbb95d63711f62acd5160b6c879c1e3abf2e
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
    # #1005: the contact cards still draw and the new card rule is in the served stylesheet
    n=$(curl -s "https://${HOST}/lien-he" | grep -c 'class="sf-contact-card"' || true)
    expect "trang /lien-he có 4 thẻ liên hệ" "${n}" "4"
    local css
    css=$(curl -s "https://${HOST}/lien-he" | grep -oE '/build/assets/sf-form-[^"]+\.css' | head -n 1 || true)
    n=$(curl -s "https://${HOST}${css:-/none}" | grep -c 'margin-block:-.375rem -.625rem' || true)
    expect "bảng kiểu sf-form mới đã phục vụ (#1005)" "${n}" "1"
}
