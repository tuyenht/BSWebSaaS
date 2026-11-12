# Release file: prod 54442451 → 9e5ac599 (main on 12/11, after #538 and #532). Data and one-off commands only;
# the engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/54442451-9e5ac599.sh
# Range: #538 (release file kept); #532 (every site's public pages carry no session cookie and say they may be kept
# at a shared edge for 5 minutes, browsers always ask again; any other query key, a signed-in admin, a sent form,
# a preview or an error stays private; the view count moves to a beacon so an edge copy counts too; a public write
# queues one edge purge per site, a no-op until the Cloudflare zone id and purge token are set). New job class,
# new Vite entry (sf-view.js), four optional .env keys with working defaults. No migration, no library change.
# shellcheck shell=bash disable=SC2034
OLD=544424512534074d703d41214592b10594878215
TARGET=9e5ac5999861524e7d179203b36ef62340596cfe
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

# Classes that need a human look and are in this range (the engine stops if this differs from what it finds).
DECLARED=()

MIGRATIONS=()
declare -gA MIG_OK=()
declare -gA PGB_SKIP=()
SCHEDULE_ADDED=()
SCHEDULE_REMOVED=()
# Unset on prod = edge purge off (the null driver) and the 300 s / 86400 s defaults; the owner adds the zone id
# and purge token later, together with the Cloudflare Cache Rule.
ENV_DEFAULT_OK=(CLOUDFLARE_ZONE_ID CLOUDFLARE_PURGE_TOKEN STOREFRONT_EDGE_S_MAXAGE STOREFRONT_EDGE_SWR)
SCHEMA_REMOVED_OK=()
BUILD_DYNAMIC=()
BUILD_MARKERS=("resources/js/sf-view.js sendBeacon")

ONEOFFS_PRE=()
ONEOFFS=()
release_smoke() {
    # #532: a public page may be kept at the edge and sets no cookie; an unknown query key stays private
    expect "trang chủ cho phép đệm ở Cloudflare (#532)" "$(curl -sI "https://$HOST/" | grep -i '^cache-control:' | grep -c 's-maxage=300' || true)" "1"
    expect "trang chủ không đặt cookie (#532)" "$(curl -sI "https://$HOST/" | grep -ci '^set-cookie:' || true)" "0"
    expect "trang có tham số lạ không được đệm (#532)" "$(curl -sI "https://$HOST/?x=$RANDOM" | grep -i '^cache-control:' | grep -c 'no-store' || true)" "1"
    expect "trang chủ có tệp đếm lượt xem (#532)" "$([ "$(has / 'name="sf-view"')" -gt 0 ] && echo 1 || echo 0)" "1"
    # earlier releases stay as they were
    expect "màn Thành viên chưa đăng nhập chuyển về trang đăng nhập (#534)" "$(curl -s -o /dev/null -w '%{http_code}' "https://$HOST/admin/users?x=$RANDOM")" "302"
    expect "giả tên miền qua X-Forwarded-Host không đổi được website (#533)" "$(curl -s -H 'X-Forwarded-Host: website.baoson.net' "https://$HOST/?xfh=$RANDOM" | grep -c 'website.baoson.net/login' || true)" "0"
}
release_rollback() { # data the old code (54442451) cannot read
    echo "     Không có: bản này không đổi bảng. Bản cũ lại đặt cookie trên trang công khai và đếm lượt xem ở máy chủ; nếu đã bật Cache Rule ở Cloudflare thì xoá đệm (Purge Everything) sau khi lùi."
}
