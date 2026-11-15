# Release file: prod f4fbd59d → 5d09372c (main on 15/11). Data and one-off commands only; the engine
# (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/f4fbd59d-5d09372c.sh
# Range:
#   #710 release file of the previous deploy;
#   #711 reverts #707: pages no longer say Cache-Control no-transform. With it Cloudflare served every page
#        uncompressed (home 135 KB instead of 19 KB brotli; live Lighthouse mobile LCP 2099 → 2616 ms).
#        Cloudflare's own injected analytics beacon comes back until it is switched off in the dashboard.
# No migration, no library change, no .env key, no schedule change.
# shellcheck shell=bash disable=SC2034
OLD=f4fbd59d70ac5bbdf105ae145d5a17c4abe77722
TARGET=5d09372c701e9c1dfe66b691fb9ed50b89638cd9
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
    local html code cc ua size
    ua='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
    # #711: a fresh page (a query key the edge never keeps) no longer says no-transform and comes compressed
    cc=$(curl -s -o /dev/null -D - -m 20 -A "$ua" -H 'accept: text/html' "https://$HOST/?m13=$RANDOM" | tr -d '\r' | grep -i '^cache-control:' || true)
    expect "trang mới không còn no-transform (#711)" "$(grep -c 'no-transform' <<< "$cc" || true)" "0"
    read -r size < <(curl -s -o /dev/null -m 20 -A "$ua" -H 'accept: text/html' -H 'accept-encoding: br, gzip' -w '%{size_download}\n' "https://$HOST/?m13=$RANDOM")
    expect "trang chủ được nén lại (dưới 40 KB khi tải) (#711)" "$([ "$size" -lt 40000 ] && echo 1 || echo "0 ($size)")" "1"
    html=$(curl -s --compressed -m 20 -A "$ua" -H 'accept: text/html' "https://$HOST/?m13=$RANDOM")
    expect "beacon của trang vẫn còn đúng một (#688)" "$(grep -o 'data-sf-beacon=' <<< "$html" | wc -l | tr -d ' ')" "1"
    # unchanged
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/admin/login")
    expect "trang đăng nhập quản trị mở được" "$code" "200"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/khong-co-trang-nay-$RANDOM")
    expect "địa chỉ không có trả 404 (giữ như cũ)" "$code" "404"
}
