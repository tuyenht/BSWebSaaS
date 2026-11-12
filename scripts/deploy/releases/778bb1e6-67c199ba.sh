# Release file: prod 778bb1e6 → 67c199ba (main on 12/11, after #551 and #552). Data and one-off commands only;
# the engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/778bb1e6-67c199ba.sh
# Range: #551 (release file kept), #552 (every site's admin "Nút liên hệ nổi": a channel switched on with no value yet
# shows as a dashed slot at its place in the preview, with a hint under its field; the preview frame grows instead of
# clipping buttons; the storefront output is unchanged).
# Admin Blade, one Livewire class, the floating-contact normalizer (new preview path only), lang, tests. No migration,
# no new job, no .env key, no library change.
# shellcheck shell=bash disable=SC2034
OLD=778bb1e624782132ae410bf8ad6205274c993a03
TARGET=67c199ba3d4b48aa222bd4bac52bce0f755d50ed
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
ONEOFFS=()
release_smoke() {
    # #548/#549: the loaded token keeps printing exactly one beacon; #543 and earlier releases stay as they were
    local html; html=$(curl -s "https://$HOST/?css=$RANDOM")
    expect "trang chủ gắn đúng 1 mã đo Cloudflare (#548, #549)" "$(grep -oF 'src="https://static.cloudflareinsights.com/beacon.min.js' <<< "$html" | wc -l)" "1"
    expect "trang chủ có CSS lõi (#543)" "$(grep -oE '/build/assets/storefront-[A-Za-z0-9_-]+\.css' <<< "$html" | sort -u | wc -l)" "1"
    local n; n=$(grep -oE '/build/assets/sf-[a-z0-9]+-[A-Za-z0-9_-]+\.css' <<< "$html" | sort -u | wc -l)
    expect "trang chủ chỉ nối CSS khối nó dùng: 1–16 tệp (#543)" "$([ "$n" -ge 1 ] && [ "$n" -le 16 ] && echo yes || echo "no:$n")" "yes"
    local cards; cards=$(grep -oE '/build/assets/sf-cards-[A-Za-z0-9_-]+\.css' <<< "$html" | head -1)
    expect "CSS thẻ khoá lớp phủ của thẻ dưới mũi tên (#539)" "$(curl -s "https://$HOST$cards" | grep -o '\.sf-card{[^}]*}' | grep -c 'isolation:isolate' || true)" "1"
    local js; js=$(grep -o '/build/assets/storefront-[^"]*\.js' <<< "$html" | head -1)
    expect "JS trang công khai dùng IntersectionObserver cho ảnh lazy (#544)" "$(curl -s "https://$HOST$js" | grep -c 'IntersectionObserver' || true)" "1"
    expect "trang chủ cho phép đệm ở Cloudflare (#532)" "$(curl -sI "https://$HOST/" | grep -i '^cache-control:' | grep -c 's-maxage=300' || true)" "1"
    expect "trang chủ không đặt cookie (#532)" "$(curl -sI "https://$HOST/" | grep -ci '^set-cookie:' || true)" "0"
    expect "giả tên miền qua X-Forwarded-Host không đổi được website (#533)" "$(curl -s -H 'X-Forwarded-Host: website.baoson.net' "https://$HOST/?xfh=$RANDOM" | grep -c 'website.baoson.net/login' || true)" "0"
}
release_rollback() { # data the old code (778bb1e6) cannot read
    echo "     Không có: bản này chỉ đổi màn quản trị (xem trước), không đổi bảng, không đổi cách lưu cài đặt."
}
