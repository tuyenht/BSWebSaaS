# Release file: prod 7db7aa8e → bc5d00f0 (main on 15/11). Data and one-off commands only; the engine
# (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/7db7aa8e-bc5d00f0.sh
# Range:
#   #692, #693 release files of the two previous deploys;
#   #689 My account › change the sign-in email: password, then the sign-in second step (authenticator or backup
#        code if on, else a code to the current address), then a 6-digit code to the new address; the old
#        address always gets a notice; refused while support is signed in as the member. Codes and caps live in
#        the cache (Valkey); the code mails are queued (Horizon is restarted by the engine); no table, no
#        migration, no .env key;
#   #695 CI runs the Arch suite as a job of its own (CI files and docs only; nothing on prod changes);
#   #694 Members › invite with the post categories the new member is limited to: migration 2073 creates
#        invitation_category_scopes (new empty table, RLS ENABLE + FORCE, one site-isolation policy); the rows
#        are copied to member_category_scopes when the invitation is accepted. Deleting a category moves or
#        refuses invitation limits as it does for members.
# No library change, no .env key, no schedule change.
# shellcheck shell=bash disable=SC2034
OLD=7db7aa8e159d321d95bc791fe7887dec151ba9a3
TARGET=bc5d00f0420f06ad5a1f800fd8a3f5714f6e1f09
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /ho-so-nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

# Classes that need a human look and are in this range (the engine stops if this differs from what it finds).
DECLARED=(migration)

MIGRATIONS=(2073_01_01_000000_create_invitation_category_scopes_table)
declare -gA MIG_OK=(
    [2073_01_01_000000_create_invitation_category_scopes_table]="CREATE TABLE invitation_category_scopes (new, empty; FKs to sites CASCADE, (site_id, invitation_id) → invitations CASCADE, taxonomy_id RESTRICT; unique + taxonomy_id index built with the table; brief SHARE ROW EXCLUSIVE locks on sites, invitations, taxonomies for the FKs) + ENABLE/FORCE RLS + one policy; touches no existing row; down() drops the table"
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
    # #694: the new tenant table is fail-closed like every other one
    expect "bảng giới hạn chuyên mục của lời mời bật RLS + FORCE (#694)" \
        "$(q "SELECT count(*) FROM pg_class WHERE relname = 'invitation_category_scopes' AND relrowsecurity AND relforcerowsecurity")" "1"
    expect "bảng giới hạn chuyên mục của lời mời có đúng một chính sách tách website (#694)" \
        "$(q "SELECT count(*) FROM pg_policies WHERE tablename = 'invitation_category_scopes' AND policyname = 'invitation_category_scopes_site_isolation'")" "1"
    expect "app_user đọc ghi được bảng giới hạn chuyên mục của lời mời (#694)" \
        "$(q "SELECT has_table_privilege('app_user', 'invitation_category_scopes', 'SELECT') AND has_table_privilege('app_user', 'invitation_category_scopes', 'INSERT') AND has_table_privilege('app_user', 'invitation_category_scopes', 'DELETE')")" "t"
    expect "nhận lời mời chép giới hạn chuyên mục cho thành viên (#694)" "$(art event:list 2>&1 | grep -c 'CopyInvitationCategoryScopes' || true)" "1"
    # #689: the email-change texts are in the deployed language files (the screen itself needs a member)
    r=$(art tinker --execute='echo __("admin.account.em_new_same") !== "admin.account.em_new_same" ? 1 : 0;' 2>&1 | tail -1 || true)
    expect "chữ của màn đổi email đã có trong bản dịch (#689)" "$r" "1"
    # unchanged: the account page still sends a signed-out visitor to sign in
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/admin/account")
    expect "trang Tài khoản của tôi chuyển người chưa đăng nhập đi đăng nhập (giữ như cũ)" "$code" "302"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/admin/login")
    expect "trang đăng nhập quản trị mở được" "$code" "200"
    # earlier releases stay as they were
    html=$(curl -s -m 20 "https://$HOST/?m10=$RANDOM")
    expect "menu điện thoại nền tối theo thanh đầu trang (#690)" "$(grep -c 'class="sf-drawer sf-drawer--dark"' <<< "$html" || true)" "1"
    expect "trang chủ có đúng một chỗ giữ beacon (#688)" "$(grep -o 'data-sf-beacon=' <<< "$html" | wc -l | tr -d ' ')" "1"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/khong-co-trang-nay-$RANDOM")
    expect "địa chỉ không có trả 404 (giữ như cũ)" "$code" "404"
}
