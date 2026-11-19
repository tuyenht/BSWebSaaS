# Release file: prod 14019b95 → ab7b84ec (main on 20/11). Data and one-off commands only; the engine
# (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/14019b95-ab7b84ec.sh
# Range:
#   #819 #821 #824 sign-in screen fixes (Turnstile frame, cursor placement, page language).
#   #820 product code typed as the editor board draws it (upper case, «-» for spaces, at most 64); the
#        one-off below normalises existing codes so the duplicate check sees «ab-1» and «AB-1» as one.
#   #825 post and page editor guards (block delete asks first, schedule check, slug rule, page-tree lock).
#   #822 posts and pages list menus as board 05 draws them (status order, trash guard, ⋮ title, focus).
#   #818 the previous release file.
# No migration, no schedule change, no library change, no new .env key. CSS/JS change (admin bundle).
# shellcheck shell=bash disable=SC2034
OLD=14019b95511c5bbf5fb49df7a9de4b7a722d5705
TARGET=ab7b84ecb17178715605397fb9a8c61ca851a525
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
ONEOFFS=(normalize_skus)

# #820: existing product codes → upper case, «-» for spaces. A code that would clash with another of the
# same site, or is longer than 64, is listed and left as it is (preview prints the count the dry run reports).
oneoff_normalize_skus() {
    local out n
    case "$1" in
        preview) out=$(art catalog:normalize-skus --dry-run 2>&1) || die "catalog:normalize-skus --dry-run thất bại: $(tail -2 <<< "$out")"
            grep -E '^site ' <<< "$out" | sed 's/^/     /' >&2 || true
            n=$(grep -oE 'would change [0-9]+' <<< "$(tail -1 <<< "$out")" | grep -oE '[0-9]+' || true)
            echo "${n:-0}" ;;
        apply) out=$(art catalog:normalize-skus 2>&1) || die "catalog:normalize-skus thất bại: $(tail -3 <<< "$out")"
            grep -E '^site ' <<< "$out" | sed 's/^/     /' || true
            echo "     $(tail -1 <<< "$out")" ;;
        verify) out=$(art catalog:normalize-skus --dry-run 2>&1 | tail -1)
            expect "không còn mã SKU nào cần chuẩn hoá (#820)" "$(grep -c 'would change 0 ' <<< "$out" || true)" "1" ;;
    esac
}

release_smoke() {
    local n
    # app errors since the reload (not a stop: printed for the report, the lines shown when any)
    n=$(docker logs --since 20m "${PREFIX}app" 2>&1 | grep -cE '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' || true)
    note "lỗi trong nhật ký ứng dụng 20 phút qua: ${n:-0}"
    [ "${n:-0}" = 0 ] || docker logs --since 20m "${PREFIX}app" 2>&1 | grep -E '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' | tail -n 5 | cut -c1-300
    # #820: the one-off command is in the running code
    n=$(docker exec "${PREFIX}app" php artisan list --raw 2>&1 | grep -cE '^catalog:normalize-skus ' || true)
    expect "có lệnh chuẩn hoá mã SKU (#820)" "${n:-0}" "1"
}
