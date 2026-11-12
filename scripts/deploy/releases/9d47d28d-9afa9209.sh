# Release file: prod 9d47d28d → 9afa9209 (main on 12/11, after #543, #547 and #548). Data and one-off commands only;
# the engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/9d47d28d-9afa9209.sh
# Range: #543 (every site: storefront.css keeps only what every page needs; each block kind has its own
# stylesheet sf-<kind>.css, and the server links only those the finished page uses), #547 (release file kept),
# #548 (every site: a site with a Cloudflare Web Analytics token in its settings gets the beacon snippet before </body>;
# no site has one yet, so nothing is printed until a pack sets it).
# CSS, Blade head and footer, rendering classes, pack validator, vite inputs, tests, docs. No migration, no new job, no .env key, no library change.
OLD=9d47d28d0eeccd318ff7c9705ba957e3af89effa
TARGET=9afa92096245c3e270cb5dcc38e5a922becdf687
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

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
ONEOFFS=(purge_edge)
# The build just replaced every stylesheet name, and Cloudflare may still hold pages (s-maxage=300) that link the
# old, now deleted files: drop $HOST's cached pages once the new code serves, so no visitor gets an unstyled page.
oneoff_purge_edge() {
    local r a bad=0
    case "$1" in
        preview) if [ -s "$STATE/edge-purged" ]; then echo 0; else echo 1; fi ;;
        apply) r=$(art tinker --execute='echo app(App\Modules\Shared\Contracts\EdgeCache::class)->purgeHosts(["'"$HOST"'"]) ? "PURGED" : "REFUSED";' 2>&1 | tail -1)
            expect "xoá bản đệm Cloudflare của $HOST" "$r" "PURGED"
            date '+%F %T' > "$STATE/edge-purged" ;;
        verify) for a in $(curl -s "https://$HOST/?edge=$RANDOM" | grep -oE '/build/assets/[A-Za-z0-9_.-]+\.(css|js)' | sort -u); do
                [ "$(curl -s -o /dev/null -w '%{http_code}' "https://$HOST$a")" = 200 ] || { echo "     thiếu tệp $a"; bad=1; }
            done
            expect "mọi tệp CSS/JS trang chủ nối tới đều có (200)" "$bad" "0" ;;
    esac
}
release_smoke() {
    # #543: the home page links the core stylesheet plus some block files, never all of them
    local html; html=$(curl -s "https://$HOST/?css=$RANDOM")
    expect "trang chủ có CSS lõi (#543)" "$(grep -oE '/build/assets/storefront-[A-Za-z0-9_-]+\.css' <<< "$html" | sort -u | wc -l)" "1"
    local n; n=$(grep -oE '/build/assets/sf-[a-z0-9]+-[A-Za-z0-9_-]+\.css' <<< "$html" | sort -u | wc -l)
    expect "trang chủ chỉ nối CSS khối nó dùng: 1–16 tệp (#543)" "$([ "$n" -ge 1 ] && [ "$n" -le 16 ] && echo yes || echo "no:$n")" "yes"
    # #539 rule now lives in sf-cards: the served card stylesheet isolates each card
    local cards; cards=$(grep -oE '/build/assets/sf-cards-[A-Za-z0-9_-]+\.css' <<< "$html" | head -1)
    expect "CSS thẻ khoá lớp phủ của thẻ dưới mũi tên (#539)" "$(curl -s "https://$HOST$cards" | grep -o '\.sf-card{[^}]*}' | grep -c 'isolation:isolate' || true)" "1"
    # #548: no site has a token yet, so no page prints the beacon (CF's own injection is off too)
    expect "trang chủ chưa gắn mã đo Cloudflare (#548, chưa có mã)" "$(grep -c 'cloudflareinsights' <<< "$html" || true)" "0"
    # #544, #532 and earlier releases stay as they were
    local js; js=$(grep -o '/build/assets/storefront-[^"]*\.js' <<< "$html" | head -1)
    expect "JS trang công khai dùng IntersectionObserver cho ảnh lazy (#544)" "$(curl -s "https://$HOST$js" | grep -c 'IntersectionObserver' || true)" "1"
    expect "trang chủ cho phép đệm ở Cloudflare (#532)" "$(curl -sI "https://$HOST/" | grep -i '^cache-control:' | grep -c 's-maxage=300' || true)" "1"
    expect "trang chủ không đặt cookie (#532)" "$(curl -sI "https://$HOST/" | grep -ci '^set-cookie:' || true)" "0"
    expect "giả tên miền qua X-Forwarded-Host không đổi được website (#533)" "$(curl -s -H 'X-Forwarded-Host: website.baoson.net' "https://$HOST/?xfh=$RANDOM" | grep -c 'website.baoson.net/login' || true)" "0"
}
release_rollback() { # data the old code (9d47d28d) cannot read
    echo "     Không có: bản này đổi CSS, phần đầu/cuối trang và cách nối CSS, không đổi bảng; cài đặt web_analytics (nếu sau này có) bản cũ bỏ qua."
}
