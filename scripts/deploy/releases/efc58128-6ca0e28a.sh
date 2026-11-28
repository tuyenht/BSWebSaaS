# Release file: prod efc58128 → 6ca0e28a (main on 26/11). Data and one-off commands only; the
# engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/efc58128-6ca0e28a.sh
# Range:
#   #1007 every media reference is held to a file of its own website (composite keys site_id + media id);
#         migrations 2099_01_01_000000 (expand: clear strays, add keys NOT VALID, validate) and 000100 (drop the
#         7 plain keys). Read-only counts of docs/runbooks/prod-deploy.md "Before step 2" item 8, run 26/11 15:5x
#         on prod: every "clear" 0, "STOP product_images" 0, "cover id beside meta.image_url" 0, media rows 79.
#   #1015 test only; #1018 release file.
# shellcheck shell=bash disable=SC2034
OLD=efc581286390c7f046383ae788c145e5932babff
TARGET=6ca0e28a1e6b571a115c48b5cf49d5d0fba12024
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /ho-so-nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

DECLARED=(migration)
MIGRATIONS=(2099_01_01_000000_add_same_site_media_foreign_keys 2099_01_01_000100_drop_plain_media_foreign_keys)
declare -gA MIG_OK=(
    [2099_01_01_000000_add_same_site_media_foreign_keys]="Owner-signed 26/11, reviewed before merge. Raw SQL: unique (site_id, id) on media CONCURRENTLY, composite keys NOT VALID then VALIDATE, clears only nullable stray refs (prod count 0, 26/11), stops before any change on a product_images stray (0), lock_timeout 5s, CSV of every cleared row"
    [2099_01_01_000100_drop_plain_media_foreign_keys]="Drops the 7 plain media keys the composite keys replace; down() puts them back"
)
declare -gA PGB_SKIP=()
SCHEDULE_ADDED=()
SCHEDULE_REMOVED=()
ENV_DEFAULT_OK=()
SCHEMA_REMOVED_OK=("con|mediables|mediables_media_id_foreign|f|validated" "con|product_images|product_images_media_id_foreign|f|validated" "con|site_profiles|site_profiles_favicon_media_id_foreign|f|validated" "con|site_profiles|site_profiles_logo_dark_media_id_foreign|f|validated" "con|site_profiles|site_profiles_logo_media_id_foreign|f|validated" "con|site_profiles|site_profiles_og_image_media_id_foreign|f|validated" "con|taxonomies|taxonomies_cover_media_id_foreign|f|validated")
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
}
