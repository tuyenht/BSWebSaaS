# Release file: prod 655398f9 → c258332c (main on 12/11, after #556, #557, #558, #559). Data and one-off commands only;
# the engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/655398f9-c258332c.sh
# Range: #556 (release file kept), #557 (every storefront page's ETag covers its Link header too, so the edge, which
# revalidates with If-None-Match and keeps its stored headers on a 304, refetches a page whose early hints changed),
# #558 (every site's storefront pages: exactly one first-screen image is eager + fetchpriority=high, every other image
# lazy + fetchpriority=low; plain post lists and product grids get a capped srcset), #559 (the An Vinh content pack:
# the new 27-page capability profile and the plant facts it changes; shipped, NOT loaded: loaded after this deploy
# with its own load script). Code, views and the pack: no migration, no new job, no .env key, no library change,
# no front-end build change.
# shellcheck shell=bash disable=SC2034
OLD=655398f93a75e0acea09d1e2bb76f9824f8d5b8d
TARGET=c258332c3b73fc7725d34aafe561a5a5943f9a37
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /ho-so-nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

# Classes that need a human look and are in this range (the engine stops if this differs from what it finds).
DECLARED=(content-pack)

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
    local p hdr body linked hinted html
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
    # #559: the container serves the new pack (its load script checks the same before it runs)
    expect "gói nội dung An Vinh mới trong container (hồ sơ 27 trang, #559)" \
        "$(docker exec "${PREFIX}app" grep -c 'Hồ sơ năng lực Công ty An Vinh (PDF, 27 trang)' database/content-packs/an-vinh/media.json || true)" "1"
    # earlier releases stay as they were
    html=$(curl -s -m 20 "https://$HOST/?css=$RANDOM")
    expect "trang chủ gắn đúng 1 mã đo Cloudflare (#548, #549)" "$(grep -oF 'src="https://static.cloudflareinsights.com/beacon.min.js' <<< "$html" | wc -l)" "1"
    expect "trang chủ cho phép đệm ở Cloudflare (#532)" "$(curl -sI -m 20 "https://$HOST/" | grep -i '^cache-control:' | grep -c 's-maxage=300' || true)" "1"
    expect "trang chủ không đặt cookie (#532)" "$(curl -sI -m 20 "https://$HOST/" | grep -ci '^set-cookie:' || true)" "0"
    expect "giả tên miền qua X-Forwarded-Host không đổi được website (#533)" "$(curl -s -m 20 -H 'X-Forwarded-Host: website.baoson.net' "https://$HOST/?xfh=$RANDOM" | grep -c 'website.baoson.net/login' || true)" "0"
}
release_rollback() { # data the old code (655398f9) cannot read
    echo "     Không có: bản này đổi thuộc tính ảnh, thẻ ETag của trang công khai và gói nội dung An Vinh (chưa nạp); không đổi bảng, không đổi cách lưu cài đặt."
}
