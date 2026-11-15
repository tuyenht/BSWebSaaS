# Release file: prod bcbf0fe7 → 5f238fb6 (main on 15/11). Data and one-off commands only; the engine
# (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/bcbf0fe7-5f238fb6.sh
# Range:
#   #706 release file of the previous deploy;
#   #707 every HTML response (storefront + admin) carries Cache-Control: no-transform (new outermost global
#        middleware KeepPagesUntransformed), so Cloudflare stops injecting its own Web Analytics script, whose
#        reports to the site's /cdn-cgi/rum answered 404 (console error on every page, PageSpeed Best
#        Practices 96). robots.txt, sitemap, JSON unchanged. Edge copies made before the deploy keep the old
#        header until they are refetched (s-maxage 300).
# No migration, no library change, no .env key, no schedule change.
# shellcheck shell=bash disable=SC2034
OLD=bcbf0fe71046354ef3ce7f14f31b6a02618ccb82
TARGET=5f238fb6676d7ccd578343a075fee8443ccc66ab
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
ONEOFFS=()
release_smoke() {
    local html code cc ua
    ua='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
    # #707: a fresh page (a query key the edge never keeps, so it comes from the new code) asks proxies
    # not to rewrite it, and Cloudflare injects no second analytics script into it
    cc=$(curl -s -o /dev/null -D - -m 20 -A "$ua" -H 'accept: text/html' "https://$HOST/?m12=$RANDOM" | tr -d '\r' | grep -i '^cache-control:' || true)
    expect "trang mới có no-transform (#707)" "$(grep -c 'no-transform' <<< "$cc" || true)" "1"
    html=$(curl -s --compressed -m 20 -A "$ua" -H 'accept: text/html' "https://$HOST/?m12=$RANDOM")
    expect "Cloudflare không chèn beacon thứ hai vào trang mới (#707)" "$(grep -c 'data-cf-beacon' <<< "$html" || true)" "0"
    expect "beacon của trang vẫn còn đúng một (#688)" "$(grep -o 'data-sf-beacon=' <<< "$html" | wc -l | tr -d ' ')" "1"
    cc=$(curl -s -o /dev/null -D - -m 20 -A "$ua" -H 'accept: text/html' "https://$HOST/admin/login" | tr -d '\r' | grep -i '^cache-control:' || true)
    expect "trang đăng nhập quản trị có no-transform (#707)" "$(grep -c 'no-transform' <<< "$cc" || true)" "1"
    cc=$(curl -s -o /dev/null -D - -m 20 "https://$HOST/robots.txt?m12=$RANDOM" | tr -d '\r' | grep -i '^cache-control:' || true)
    expect "robots.txt không đổi header (#707)" "$(grep -c 'no-transform' <<< "$cc" || true)" "0"
    # unchanged
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/admin/login")
    expect "trang đăng nhập quản trị mở được" "$code" "200"
    expect "chân trang: các chấm nhà máy nằm chung một hàng riêng (#700)" "$(grep -c 'class="sf-rot__dots"' <<< "$html" || true)" "1"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/khong-co-trang-nay-$RANDOM")
    expect "địa chỉ không có trả 404 (giữ như cũ)" "$code" "404"
}
