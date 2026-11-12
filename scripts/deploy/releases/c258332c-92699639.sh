# Release file: prod c258332c → 92699639 (main on 12/11, after #560, #561). Data and one-off commands only;
# the engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/c258332c-92699639.sh
# Range: #561 (release file kept), #560 (every site's storefront: blocks below the first screen and the footer are
# laid out and painted on demand, CSS content-visibility on screen only; storefront.js turns it off before a jump to
# a #target so the jump lands on the target). CSS and JS only: no migration, no new job, no .env key, no library
# change, no content pack.
# shellcheck shell=bash disable=SC2034
OLD=c258332c3b73fc7725d34aafe561a5a5943f9a37
TARGET=926996397895c536cabfefdd69d0e25e3225740a
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
BUILD_MARKERS=("resources/css/storefront.css sf-anchored" "resources/js/storefront.js sf-anchored")

ONEOFFS_PRE=()
ONEOFFS=()
release_smoke() {
    local p hdr body linked hinted html css js
    # #554 kept: a fresh (edge-missed) page hints exactly the stylesheets the HTML links. The edge copy (#557) may
    # still be a fresh-for-5-minutes HIT here, so it is checked by hand once s-maxage has run out, not in this gate.
    for p in / /tin-tuc /lien-he; do
        p="$p?eh=$RANDOM"
        hdr=$(mktemp); body=$(curl -s -m 20 -D "$hdr" "https://$HOST$p")
        linked=$(sed -n '1,/<\/head>/p' <<< "$body" | grep -oE '<link rel="stylesheet" href="[^"]+\.css"' | grep -oE '/build/assets/[^"]+\.css' | paste -sd' ' || true)
        hinted=$(awk '/^HTTP\//{b=""} {b=b $0 "\n"} END{printf "%s", b}' "$hdr" | grep -i '^link:' | grep -oE '/build/assets/[^>]+\.css>; rel="preload"; as="style"' | grep -oE '/build/assets/[^>]+\.css' | paste -sd' ' || true)
        rm -f "$hdr"
        expect "báo trước (Early Hints) đúng các CSS trang ${p%%\?*} dùng, đúng thứ tự (#554)" "$([ -n "$linked" ] && [ "$linked" = "$hinted" ] && echo yes || echo "no: html[$linked] link[$hinted]")" "yes"
    done
    # #558: one high-priority image per page, and it is eager
    for p in / /tin-tuc /jig-kiem-tap-lo-vf7; do
        html=$(curl -s -m 20 "https://$HOST$p?img=$RANDOM" | tr '\n' ' ')   # one line: an <img> tag may span several
        expect "trang $p có đúng 1 ảnh ưu tiên cao (#558)" "$({ grep -o 'fetchpriority="high"' <<< "$html" || true; } | wc -l)" "1"
        expect "ảnh ưu tiên cao của trang $p không chờ (#558)" "$({ grep -oE '<img[^>]*fetchpriority="high"[^>]*>' <<< "$html" || true; } | grep -c 'loading="lazy"' || true)" "0"
    done
    # #560: the page's stylesheet defers below-the-fold layout, and its script turns that off for a #target
    html=$(curl -s -m 20 "https://$HOST/?cv=$RANDOM")
    css=$(grep -oE '/build/assets/storefront-[^"]+\.css' <<< "$html" | head -1 || true)
    js=$(grep -oE '/build/assets/storefront-[^"]+\.js' <<< "$html" | head -1 || true)
    expect "CSS trang chủ có vẽ phần dưới màn hình khi cần (#560)" "$([ -n "$css" ] && curl -s -m 20 "https://$HOST$css" | grep -c 'content-visibility:auto' || true)" "1"
    expect "JS trang chủ tắt chế độ này trước khi nhảy tới #đích (#560)" "$([ -n "$js" ] && curl -s -m 20 "https://$HOST$js" | grep -c 'sf-anchored' || true)" "1"
    # earlier releases stay as they were
    html=$(curl -s -m 20 "https://$HOST/?css=$RANDOM")
    expect "trang chủ gắn đúng 1 mã đo Cloudflare (#548, #549)" "$(grep -oF 'src="https://static.cloudflareinsights.com/beacon.min.js' <<< "$html" | wc -l)" "1"
    expect "trang chủ cho phép đệm ở Cloudflare (#532)" "$(curl -sI -m 20 "https://$HOST/" | grep -i '^cache-control:' | grep -c 's-maxage=300' || true)" "1"
    expect "trang chủ không đặt cookie (#532)" "$(curl -sI -m 20 "https://$HOST/" | grep -ci '^set-cookie:' || true)" "0"
    expect "giả tên miền qua X-Forwarded-Host không đổi được website (#533)" "$(curl -s -m 20 -H 'X-Forwarded-Host: website.baoson.net' "https://$HOST/?xfh=$RANDOM" | grep -c 'website.baoson.net/login' || true)" "0"
}
release_rollback() { # data the old code (c258332c) cannot read
    echo "     Không có: bản này chỉ đổi CSS và JS của trang công khai; không đổi bảng, không đổi cách lưu cài đặt."
}
