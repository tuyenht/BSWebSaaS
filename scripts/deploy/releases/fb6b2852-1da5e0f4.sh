# Release file: prod fb6b2852 → 1da5e0f4 (main on 26/11). Data and one-off commands only; the
# engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/fb6b2852-1da5e0f4.sh
# Range:
#   #983 every new upload is filed under the website's permanent 6-character code ({code}/{file});
#        one migration (sites.code, reserved_site_codes, media key guard).
#   #988 a Livewire rule note; #986 the previous release file.
# Window (docs/runbooks/prod-deploy.md, item 7): between the migration and the reload, do not create or
# rename a website; old-code uploads are accepted under each site's frozen name folder.
# shellcheck shell=bash disable=SC2034
OLD=fb6b28522d94c84446190a5b5c4d8bf84293e7c6
TARGET=1da5e0f4cc8473ae6d329d07d4d64a8c8e9107ca
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /ho-so-nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

# Classes that need a human look and are in this range (the engine stops if this differs from what it finds).
DECLARED=(migration)

MIGRATIONS=(2097_01_01_000000_add_code_to_sites)
declare -gA MIG_OK=(
    [2097_01_01_000000_add_code_to_sites]="One transaction, SET LOCAL lock_timeout 5s; LOCK media SHARE ROW EXCLUSIVE then sites ACCESS EXCLUSIVE (upload order); aborts unless the role sees every row and every key is clean and unshared. Creates global reserved_site_codes (bigint id, code, site_id owner, SELECT only for app_user) and freezes every existing first key folder plus slug(name) of every site with its owner; ADD COLUMN sites.code (nullable varchar(6) + CHECK), plain unique index, one set-based UPDATE backfill via issue_site_code(); triggers: code immutable, retired codes kept, media storage_key guard (SECURITY DEFINER, search_path pg_catalog,pg_temp). Reviewed by Opus 5.5 twice and Fable 5.2; down() refuses once a key is filed under a code"
)
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
    # #983 every website has its permanent code
    n=$(docker exec bsweb_prod_pg sh -c 'psql -U "$POSTGRES_USER" -d bswebsaas -tAc "SELECT count(*) FROM sites WHERE code IS NULL OR code !~ '"'"'^[0-9a-hjkmnp-tv-z]{6}$'"'"'"' 2>/dev/null | tr -d '[:space:]' || true)
    expect "mọi website có mã 6 ký tự" "${n}" "0"
    # old pictures still load: the av1 home page's first files.baoson.net image answers 200
    local img
    img=$(curl -s "https://${HOST}/" | grep -oE 'https://files\.baoson\.net/[^"?]+\.(webp|jpg|png)' | head -n 1 || true)
    n=$(curl -s -o /dev/null -w '%{http_code}' "${img:-https://files.baoson.net/none.webp}" || true)
    expect "ảnh cũ av1 vẫn tải (${img##*/})" "${n}" "200"
    n=$(curl -s -o /dev/null -w '%{http_code}' "https://${HOST}/admin/login" || true)
    expect "trang đăng nhập quản trị av1 trả lời" "${n}" "200"
    n=$(curl -s -D - -o /dev/null "https://${HOST}/" | grep -cE '^HTTP/[0-9.]+ 200' || true)
    expect "trang chủ av1 trả lời 200" "${n:-0}" "1"
}
