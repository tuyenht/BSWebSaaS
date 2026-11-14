# Release file: prod 1d41d9f8 → b5c387ca (main on 15/11). Data and one-off commands only; the engine
# (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/1d41d9f8-b5c387ca.sh
# Range:
#   #685 release file of the previous deploy;
#   #687 the CWV measurement-site seeder (SeedMeasurementSite) gives its main menu a parent with two sub-links;
#        nothing it changes runs on prod's own sites;
#   #684 My account › "Phiên đăng nhập": the member's signed-in devices (an index in Valkey keyed by an HMAC of the
#        session id; no table, no migration), sign out one or every other device; an ended session is refused on
#        admin routes, /livewire/update (now behind auth.session) and the OAuth start of connect/email; a session
#        signed in 30+ days ago whose device entry is gone is signed out. Sessions alive at deploy time carry no
#        sign-in stamp yet: they get it on their next admin request, nobody is signed out by the deploy;
#   #688 storefront: rail layout reads batched; the Cloudflare analytics beacon is added by storefront.js after
#        load + idle (the page prints a hidden data-sf-beacon holder instead of the script tag);
#   #686 Notifications page search reads the feed indexes' entries only: migration 2071 rebuilds the two feed
#        indexes with INCLUDE (search_text), CONCURRENTLY; migration 2072 sets site_notifications' autovacuum
#        scale factors to 0.01 (vacuum once 1 % changed).
# No library change, no .env key, no schedule change.
# shellcheck shell=bash disable=SC2034
OLD=1d41d9f8917c093d35426a501562acebeecc67a6
TARGET=b5c387ca7ed486cb6e6501f2b49ec3ee1fd864d2
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /ho-so-nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

# Classes that need a human look and are in this range (the engine stops if this differs from what it finds).
DECLARED=(migration)

MIGRATIONS=(2071_01_01_000000_add_search_text_to_notification_feed_indexes 2072_01_01_000000_set_notification_autovacuum_thresholds)
declare -gA MIG_OK=(
    [2071_01_01_000000_add_search_text_to_notification_feed_indexes]="builds site_notifications_feed_covering (site_id, user_id, id DESC) INCLUDE (search_text) and site_notifications_kind_feed_covering (site_id, user_id, kind, id DESC) INCLUDE (search_text) CONCURRENTLY, checks each valid, then DROP INDEX CONCURRENTLY the old feed / kind_feed; an INVALID leftover is dropped and rebuilt; down() rebuilds the old two first"
    [2072_01_01_000000_set_notification_autovacuum_thresholds]="ALTER TABLE site_notifications SET (autovacuum_vacuum_scale_factor = 0.01, autovacuum_vacuum_insert_scale_factor = 0.01) under a 5s lock_timeout (SHARE UPDATE EXCLUSIVE: reads and writes go on); a timeout behind a running vacuum is retried by the engine (3 tries, 20 s apart), then stops: re-run; down() RESETs both"
)
declare -gA PGB_SKIP=(
    [2071_01_01_000000_add_search_text_to_notification_feed_indexes]="indexes only"
    [2072_01_01_000000_set_notification_autovacuum_thresholds]="storage parameters only (no column, no result type)"
)
SCHEDULE_ADDED=()
SCHEDULE_REMOVED=()
ENV_DEFAULT_OK=()
# #686: the two indexes replaced by their covering versions
SCHEMA_REMOVED_OK=("idx|site_notifications|site_notifications_feed|valid" "idx|site_notifications|site_notifications_kind_feed|valid")
BUILD_DYNAMIC=()
BUILD_MARKERS=("resources/js/storefront.js [data-sf-beacon]")

ONEOFFS_PRE=()
ONEOFFS=()
release_smoke() {
    local r html code
    # #686: the notices table carries its own vacuum thresholds
    r=$(q "SELECT array_to_string(reloptions, ',') FROM pg_class WHERE oid = 'public.site_notifications'::regclass")
    expect "bảng thông báo tự dọn khi 1 % đổi (#686)" "$(tr ',' '\n' <<< "$r" | sort | paste -sd,)" "autovacuum_vacuum_insert_scale_factor=0.01,autovacuum_vacuum_scale_factor=0.01"
    # #688: the analytics beacon is no longer a script tag in the page; storefront.js adds it after load + idle
    html=$(curl -s -m 20 "https://$HOST/?m8=$RANDOM")
    expect "trang chủ có đúng một chỗ giữ beacon (#688)" "$(grep -o 'data-sf-beacon=' <<< "$html" | wc -l | tr -d ' ')" "1"
    expect "trang chủ không còn thẻ script beacon (#688)" "$(grep -c '<script defer src="https://static.cloudflareinsights.com' <<< "$html" || true)" "0"
    # #684: the sign-in page still opens (the account screen needs a member)
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/admin/login")
    expect "trang đăng nhập quản trị mở được (#684)" "$code" "200"
    expect "/livewire/update có kiểm phiên + thiết bị đăng nhập (#684)" "$(art route:list --path=livewire/update -v 2>&1 | grep -cE 'AuthenticateSession|TrackSignedInDevice' || true)" "2"
    # earlier releases stay as they were
    expect "cỡ chữ trong menu điện thoại vẫn là nút trượt (#673)" "$(grep -c 'class="sf-pill sf-pill--fs"' <<< "$html" || true)" "1"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/khong-co-trang-nay-$RANDOM")
    expect "địa chỉ không có trả 404 (giữ như cũ)" "$code" "404"
}
