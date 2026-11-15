# Release file: prod fe7c51c4 → f5436f58 (main on 15/11). Data and one-off commands only; the engine
# (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/fe7c51c4-f5436f58.sh
# Range:
#   #714 release file of the previous deploy;
#   #715 Admin › the no-permission page offers "Nhờ cấp quyền" (POST /admin/access-requests, throttle 20/min):
#        the member managers above the requester are told through the bell (member.access_requested), else
#        the site owner; 3 a day per member and website, one per screen, counted in Valkey. A member hears
#        when their roles change on Thành viên (member.role_changed). The bell opens /admin/users?mo=<ulid>.
#        Migration 2075 swaps the site_notifications kind CHECK for one with the two new kinds.
#   #716 puts back one comment line #715 had reworded in the shipped migration 2074 (no code change).
# No library change, no .env key, no schedule change.
# shellcheck shell=bash disable=SC2034
OLD=fe7c51c430d0f2be30402c13ffa4fc679c6af574
TARGET=f5436f58d4c2bf029063034cae0a0f3afcf0db47
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /ho-so-nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

# Classes that need a human look and are in this range (the engine stops if this differs from what it finds).
DECLARED=(migration)

MIGRATIONS=(2075_01_01_000000_add_member_access_notification_kinds)
declare -gA MIG_OK=(
    [2075_01_01_000000_add_member_access_notification_kinds]="SET LOCAL lock_timeout 5s; ALTER TABLE site_notifications DROP CONSTRAINT IF EXISTS site_notifications_kind_check, ADD it again listing the 11 current kinds + member.access_requested + member.role_changed NOT VALID, then VALIDATE (one transaction: ACCESS EXCLUSIVE on site_notifications through a scan of at most 90 days of rows; no rewrite, no row or column change); down() refuses while rows of the two new kinds exist"
)
declare -gA PGB_SKIP=()
SCHEDULE_ADDED=()
SCHEDULE_REMOVED=()
ENV_DEFAULT_OK=()
SCHEMA_REMOVED_OK=()
BUILD_DYNAMIC=()
BUILD_MARKERS=("resources/css/app.css te-ok")

ONEOFFS_PRE=()
ONEOFFS=()
release_smoke() {
    local html code r ua
    ua='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
    # #715: the kind CHECK lists the two new kinds and is validated
    expect "CHECK loại chuông có member.access_requested (#715)" \
        "$(q "SELECT count(*) FROM pg_constraint WHERE conname = 'site_notifications_kind_check' AND convalidated AND pg_get_constraintdef(oid) LIKE '%member.access_requested%' AND pg_get_constraintdef(oid) LIKE '%member.role_changed%'")" "1"
    expect "đường gửi «Nhờ cấp quyền» đã có (#715)" "$(art route:list --name=admin.access.request 2>&1 | grep -c 'access-requests' || true)" "1"
    r=$(art tinker --execute='echo __("errors.noperm.request") !== "errors.noperm.request" && __("notifications.member.access_requested") !== "notifications.member.access_requested" && __("notifications.member.role_changed") !== "notifications.member.role_changed" ? 1 : 0;' 2>&1 | tail -1 || true)
    expect "chữ «Nhờ cấp quyền» và hai loại chuông đã có trong bản dịch (#715)" "$r" "1"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/admin/users")
    expect "trang Thành viên chuyển người chưa đăng nhập đi đăng nhập (giữ như cũ)" "$code" "302"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/admin/login")
    expect "trang đăng nhập quản trị mở được" "$code" "200"
    # earlier releases stay as they were
    html=$(curl -s --compressed -m 20 -A "$ua" -H 'accept: text/html' "https://$HOST/?m15=$RANDOM")
    expect "trang chủ vẫn có 6 dải thẻ (#713)" "$(grep -o 'data-sf-rail ' <<< "$html" | wc -l | tr -d ' ')" "6"
    expect "trang chủ có đúng một chỗ giữ beacon (#688)" "$(grep -o 'data-sf-beacon=' <<< "$html" | wc -l | tr -d ' ')" "1"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/khong-co-trang-nay-$RANDOM")
    expect "địa chỉ không có trả 404 (giữ như cũ)" "$code" "404"
}
