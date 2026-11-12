# Release file: prod 92699639 → accdd68a (main on 12/11, after #562, #563, #564, #565). Data and one-off commands only;
# the engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/92699639-accdd68a.sh
# Range: #562 (release file kept), #563 (every site's floating contact in "horizontal" mode: the phone bar lists every
# channel that is on, e-mail and Messenger included, as many as fit at 4.5rem a cell, the rest dropped from the end;
# from 768px the same channels are round buttons; admin texts updated), #564 (An Vinh pack: Nhà máy 3 gets an
# approximate map pin and a sample photo; shipped, NOT loaded: loaded after this deploy with its own load script),
# #565 (CI only: the axe step scans each page at its #noi-dung target). Views, CSS, lang and the pack: no migration,
# no new job, no .env key, no library change.
# shellcheck shell=bash disable=SC2034
OLD=926996397895c536cabfefdd69d0e25e3225740a
TARGET=accdd68a6b0b1ae486b8ca66ebd3db691f75c018
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
BUILD_MARKERS=("resources/css/sf-floating.css sf-fc--wide")

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
    # #563: av1 is in horizontal mode with e-mail, Zalo and call on: the phone bar now holds the e-mail link too, and
    # the same channels are printed as round buttons for 768px and up
    html=$(curl -s -m 20 "https://$HOST/?fc=$RANDOM" | tr '\n' ' ')
    expect "thanh dưới trang chủ có cả nút email (#563)" "$({ grep -oE '<a class="sf-fc-bar__link" href="mailto:' <<< "$html" || true; } | wc -l)" "1"
    expect "trang chủ có cụm nút tròn cho màn từ 768px (#563)" "$({ grep -oE '<nav class="sf-fc sf-fc--[a-z]+ sf-fc--wide"' <<< "$html" || true; } | wc -l)" "1"
    # #564: the container serves the new pack (its load script checks the same before it runs)
    expect "gói nội dung An Vinh mới trong container (ghim Nhà máy 3, #564)" \
        "$(docker exec "${PREFIX}app" grep -c '"lat": 21.3655865' database/content-packs/an-vinh/settings.json || true)" "1"
    # #560 kept: the page's stylesheet defers below-the-fold layout, and its script turns that off for a #target
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
release_rollback() { # data the old code (92699639) cannot read
    echo "     Không có: bản này đổi giao diện nút liên hệ nổi, chữ trong trang quản trị và gói nội dung An Vinh (chưa nạp); không đổi bảng, không đổi cách lưu cài đặt."
}
