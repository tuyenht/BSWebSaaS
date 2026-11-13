# Release file: prod 3d003d9e → 306bf89a (main on 13/11, after #627–#631). Data and one-off commands only;
# the engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/3d003d9e-306bf89a.sh
# Range: #627/#628 (release file and result of the previous deploy), #629 (block library data core: new tenant
# tables content_blocks, content_block_translations, block_usages (migration 2058, RLS + FORCE), right blocks.manage
# for owner/admin/editor, module "blocks", config cms.blocks.max_per_site (default 200); no screen, no route, no
# storefront change yet), #630 (a member limited to some categories cannot change blocks; list cursor checked),
# #631 (role grant check reads permissions from the cached catalog; refuses a permission newer than the catalog).
# No schedule, no library, no new build entry. The env key CMS_BLOCKS_MAX_PER_SITE is optional (default 200).
# shellcheck shell=bash disable=SC2034
OLD=3d003d9e3abcd0bd55223a1c83741d81b790f0a8
TARGET=306bf89a15d370b572ef77d8efc12e9a9f7a0f2e
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /ho-so-nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

# Classes that need a human look and are in this range (the engine stops if this differs from what it finds).
DECLARED=(migration permission)

MIGRATIONS=(
    2058_01_01_000000_create_content_block_tables
)
# Raw SQL (lock_timeout 5s, CREATE TABLE of three NEW tables, CHECKs, UNIQUE NULLS NOT DISTINCT, indexes, RLS + FORCE,
# policies, grants to app_user).
declare -gA MIG_OK=(
    [2058_01_01_000000_create_content_block_tables]="creates three empty tables content_blocks, content_block_translations, block_usages (site_id FK CASCADE to sites; composite (site_id, content_block_id) FKs CASCADE; owner FKs CASCADE to entries, taxonomies, site_settings, menu_items; CHECKs on key/region/status/slot/locale/one owner; UNIQUE NULLS NOT DISTINCT; indexes led by site_id or the owner column; RLS + FORCE fail closed; grants to app_user). The FKs take SHARE ROW EXCLUSIVE on sites/entries/taxonomies/site_settings/menu_items for milliseconds, held to the migration's commit (under a second on empty tables) and bounded by lock_timeout 5s; no existing column, index or row changes; the old code never reads the new tables"
)
declare -gA PGB_SKIP=()
SCHEDULE_ADDED=()
SCHEDULE_REMOVED=()
ENV_DEFAULT_OK=(CMS_BLOCKS_MAX_PER_SITE)
SCHEMA_REMOVED_OK=()
BUILD_DYNAMIC=()
BUILD_MARKERS=()

ONEOFFS_PRE=()
ONEOFFS=()
release_smoke() {
    local t
    # #629: the three tables exist with row security forced, a policy each, and the grant to app_user
    expect "ba bảng thư viện khối có RLS + FORCE (#629)" \
        "$(q "SELECT count(*) FROM pg_class WHERE relname IN ('content_blocks','content_block_translations','block_usages') AND relrowsecurity AND relforcerowsecurity")" "3"
    expect "mỗi bảng thư viện khối có một chính sách cách ly (#629)" \
        "$(q "SELECT count(DISTINCT tablename) FROM pg_policies WHERE tablename IN ('content_blocks','content_block_translations','block_usages')")" "3"
    for t in content_blocks content_block_translations block_usages; do
        expect "app_user đọc ghi được $t (#629)" \
            "$(q "SELECT has_table_privilege('app_user', '$t', 'SELECT') AND has_table_privilege('app_user', '$t', 'INSERT') AND has_table_privilege('app_user', '$t', 'UPDATE') AND has_table_privilege('app_user', '$t', 'DELETE')")" "t"
    done
    # #629: the new right exists; every corporate website's owner, admin and editor role holds it; author,
    # contributor and viewer do not
    expect "quyền blocks.manage có trong cơ sở dữ liệu (#629)" \
        "$(q "SELECT count(*) FROM permissions WHERE name = 'blocks.manage'")" "1"
    expect "có vai Chủ website / Admin website / Biên tập viên của website doanh nghiệp để kiểm (#629)" \
        "$(q "SELECT count(*) > 0 FROM roles r WHERE r.is_system AND r.name IN ('owner','admin','editor') AND EXISTS (SELECT 1 FROM sites s JOIN site_types t ON t.id = s.site_type_id WHERE s.id = r.site_id AND t.key = 'corporate')")" "t"
    expect "mọi vai Chủ website, Admin website, Biên tập viên có quyền blocks.manage (#629)" \
        "$(q "SELECT count(*) FROM roles r WHERE r.is_system AND r.name IN ('owner','admin','editor') AND EXISTS (SELECT 1 FROM sites s JOIN site_types t ON t.id = s.site_type_id WHERE s.id = r.site_id AND t.key = 'corporate') AND NOT EXISTS (SELECT 1 FROM role_has_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = r.id AND p.name = 'blocks.manage')")" "0"
    expect "không vai Người viết, Cộng tác viên hay Người xem nào có quyền blocks.manage (#629)" \
        "$(q "SELECT count(*) FROM roles r JOIN role_has_permissions rp ON rp.role_id = r.id JOIN permissions p ON p.id = rp.permission_id WHERE r.is_system AND r.name IN ('author','contributor','viewer') AND p.name = 'blocks.manage'")" "0"
    expect "tên quyền Quản lý khối có trong bản đang chạy (#629)" \
        "$(docker exec "${PREFIX}app" grep -c '"admin.members.perm.blocks.manage"' lang/vi.json || true)" "1"
    # #630: the block policy refuses a member limited to some categories
    expect "chính sách khối từ chối người bị giới hạn chuyên mục (#630)" \
        "$(docker exec "${PREFIX}app" grep -c 'scopes->isLimited' app/Modules/Cms/Policies/ContentBlockPolicy.php || true)" "1"
    # #631: the grant check asks by name and refuses a permission newer than the catalog
    expect "kiểm cấp vai trò tra quyền theo tên (#631)" \
        "$(docker exec "${PREFIX}app" grep -c 'hasPermissionTo($permission->name)' app/Modules/Rbac/Support/RoleHierarchyGuard.php || true)" "1"
    expect "kiểm cấp vai trò từ chối quyền mới hơn bộ nhớ đệm (#631)" \
        "$(docker exec "${PREFIX}app" grep -c 'catch (PermissionDoesNotExist)' app/Modules/Rbac/Support/RoleHierarchyGuard.php || true)" "1"
}
# Run 13/11/2026 20:51 (tmux deploy-306bf89a-13205149, launched by the AI): XONG, prod at 306bf89a; 143 OK, 0 LỖI/LỆCH;
# 0 error lines in the app log after the reload. Log: plans/reports/deploy-runs/3d003d9-306bf89a-run-20261113-205149.log
