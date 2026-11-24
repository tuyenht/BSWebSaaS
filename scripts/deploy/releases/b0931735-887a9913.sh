# Release file: prod b0931735 → 887a9913 (main on 22/11). Data and one-off commands only; the
# engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/b0931735-887a9913.sh
# Range:
#   #882 the previous release file.
#   #883 one worker rebuilds the shared permission catalog (bounded wait); failed version-token writes are
#        retried and logged; Horizon worker max time 3600 s (config/horizon.php, no .env key).
#   #884 the review inbox carries the rest of board 17 (step dots, key facts, SEO edit, history, prompts).
#   #885 a trash for custom roles: roles.deleted_at, live-row unique name, restore, nightly
#        roles:purge-trash (30 days; a trashed role someone still holds is kept and logged).
# One migration. No permission change. No new .env key.
# Rollback of this release, code-only included: run roles:purge-trash --days=0 first (old code would see
# trashed roles as live, and a trashed + live pair of one name makes its role lookup ambiguous).
# Deploy when no long transaction is open: the PgBouncer restart comes after the whole 2088 file.
# shellcheck shell=bash disable=SC2034
OLD=b0931735f2dfbb0396c73ae4c98fcccccb5f6624
TARGET=887a9913732b6fed08ddc6ac95523629caaf103e
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /ho-so-nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

# Classes that need a human look and are in this range (the engine stops if this differs from what it finds).
DECLARED=(migration schedule)

MIGRATIONS=(2088_01_01_000000_add_trash_to_roles)
declare -gA MIG_OK=(
    [2088_01_01_000000_add_trash_to_roles]="expand only on roles: nullable deleted_at (no default, no rewrite, lock_timeout 5s), then CONCURRENTLY the live-row unique (site_id, name, guard_name) WHERE deleted_at IS NULL and the trash index (site_id, deleted_at), then the old full unique constraint dropped, so a unique on live rows always exists; drops only its own INVALID leftovers; down() checks the trash is empty under a table lock"
)
declare -gA PGB_SKIP=()
# #885: the nightly purge of custom roles trashed more than 30 days ago
SCHEDULE_ADDED=(roles:purge-trash)
SCHEDULE_REMOVED=()
ENV_DEFAULT_OK=()
# 2088 swaps the full unique for the partial live-row unique built first (roles_site_id_name_guard_name_live_unique).
SCHEMA_REMOVED_OK=("con|roles|roles_site_id_name_guard_name_unique|u|validated" "idx|roles|roles_site_id_name_guard_name_unique|valid")
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
    # #884: the review inbox still answers a signed-out visitor with a redirect to sign-in
    n=$(curl -s -o /dev/null -w '%{http_code}' "https://${HOST}/admin/content/review" || true)
    note "Hộp duyệt khi chưa đăng nhập trả mã: ${n}"
}
