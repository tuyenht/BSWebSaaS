# Release file: prod e92b55ca → bcbf0fe7 (main on 15/11). Data and one-off commands only; the engine
# (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/e92b55ca-bcbf0fe7.sh
# Range:
#   #701, #703 release files of the two previous deploys;
#   #705 Members › a member (or an invitation) may be limited to some PRODUCT categories, like post
#        categories: a limit row's axis comes from its category's vocabulary; the admin catalog applies the
#        member's product limits; a lead limited on an axis never leaves a member or a custom role working in
#        every category of that axis (refused, nothing written); an invitation whose inviter is gone or was
#        limited after sending is refused. Migration 2074 adds a function + a BEFORE UPDATE OF vocabulary
#        trigger on taxonomies (no column, no row change; it first refuses if a limit row names a non-post
#        category, which the old code could never write). New Cms listener on the custom-role event.
# No library change, no .env key, no schedule change.
# shellcheck shell=bash disable=SC2034
OLD=e92b55cafb0ce2f8b8aef9b4a4d55fc12be5cb93
TARGET=bcbf0fe71046354ef3ce7f14f31b6a02618ccb82
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /ho-so-nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

# Classes that need a human look and are in this range (the engine stops if this differs from what it finds).
DECLARED=(migration)

MIGRATIONS=(2074_01_01_000000_keep_taxonomy_vocabulary_fixed)
declare -gA MIG_OK=(
    [2074_01_01_000000_keep_taxonomy_vocabulary_fixed]="CREATE OR REPLACE FUNCTION public.taxonomies_keep_vocabulary() + CREATE TRIGGER taxonomies_keep_vocabulary BEFORE UPDATE OF vocabulary ON taxonomies (brief SHARE ROW EXCLUSIVE lock on taxonomies, no rewrite, no row or column change); first counts member/invitation limit rows naming a non-post category and refuses before any DDL if there are any (none can exist: the old writers accepted post categories only); down() drops the trigger and the function"
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
    local html code r
    # #705: the vocabulary trigger and its function are in place
    expect "trigger giữ loại chuyên mục cố định đã có (#705)" \
        "$(q "SELECT count(*) FROM pg_trigger WHERE tgname = 'taxonomies_keep_vocabulary' AND tgrelid = 'public.taxonomies'::regclass AND NOT tgisinternal")" "1"
    expect "hàm của trigger giữ loại chuyên mục đã có (#705)" \
        "$(q "SELECT count(*) FROM pg_proc WHERE proname = 'taxonomies_keep_vocabulary' AND pronamespace = 'public'::regnamespace")" "1"
    expect "sửa nhóm quyền tuỳ chỉnh được kiểm giới hạn chuyên mục (#705)" "$(art event:list 2>&1 | grep -c 'KeepCustomRolesWithinCategoryScope' || true)" "1"
    expect "nhận lời mời vẫn chép giới hạn chuyên mục (giữ như cũ)" "$(art event:list 2>&1 | grep -c 'CopyInvitationCategoryScopes' || true)" "1"
    r=$(art tinker --execute='echo __("admin.members.pn_sc_err_needs_limits") !== "admin.members.pn_sc_err_needs_limits" && __("rbac.role_change.outside_category_scope") !== "rbac.role_change.outside_category_scope" ? 1 : 0;' 2>&1 | tail -1 || true)
    expect "chữ báo lỗi giới hạn chuyên mục đã có trong bản dịch (#705)" "$r" "1"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/admin/users")
    expect "trang Thành viên chuyển người chưa đăng nhập đi đăng nhập (giữ như cũ)" "$code" "302"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/admin/login")
    expect "trang đăng nhập quản trị mở được" "$code" "200"
    # earlier releases stay as they were
    html=$(curl -s -m 20 "https://$HOST/?m11=$RANDOM")
    expect "chân trang: các chấm nhà máy nằm chung một hàng riêng (#700)" "$(grep -c 'class="sf-rot__dots"' <<< "$html" || true)" "1"
    expect "trang chủ có đúng một chỗ giữ beacon (#688)" "$(grep -o 'data-sf-beacon=' <<< "$html" | wc -l | tr -d ' ')" "1"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/khong-co-trang-nay-$RANDOM")
    expect "địa chỉ không có trả 404 (giữ như cũ)" "$code" "404"
}
