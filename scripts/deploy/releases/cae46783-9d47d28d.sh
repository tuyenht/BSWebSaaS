# Release file: prod cae46783 → 9d47d28d (main on 12/11, after #544, #545, #546). Data and one-off commands only;
# the engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/cae46783-9d47d28d.sh
# Range: #544 (every site: lazy photos are sorted by an IntersectionObserver instead of measuring each one, so
# the page is not laid out again by script), #545 (every site: the footer factory rotator's arrows sit beside
# its dots under the card on phones, as designed), #546 (An Vinh content pack: round contact buttons email,
# Zalo, call on every width instead of the phone bar; loaded afterwards by plans/tools/anvinh-fc-load-261112.sh).
# CSS, JS, a content pack, tests. No migration, no new job, no .env key, no library change.
# shellcheck shell=bash disable=SC2034
OLD=cae46783a033f58ac2186e199cffb2e9a7ad20b6
TARGET=9d47d28d0eeccd318ff7c9705ba957e3af89effa
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

# Classes that need a human look and are in this range (the engine stops if this differs from what it finds).
DECLARED=(content-pack)   # the An Vinh pack: the engine only reports it; loaded after the deploy by its own script

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
    # #544: the served storefront script sorts lazy photos with an observer
    local js; js=$(curl -s "https://$HOST/?js=$RANDOM" | grep -o '/build/assets/storefront-[^"]*\.js' | head -1)
    expect "JS trang công khai dùng IntersectionObserver cho ảnh lazy (#544)" "$(curl -s "https://$HOST$js" | grep -c 'IntersectionObserver' || true)" "1"
    # #539, #532 and earlier releases stay as they were
    local css; css=$(curl -s "https://$HOST/?css=$RANDOM" | grep -o '/build/assets/storefront-[^"]*\.css' | head -1)
    expect "CSS trang công khai khoá lớp phủ của thẻ dưới mũi tên (#539)" "$(curl -s "https://$HOST$css" | grep -o '\.sf-card{[^}]*}' | grep -c 'isolation:isolate' || true)" "1"
    expect "trang chủ cho phép đệm ở Cloudflare (#532)" "$(curl -sI "https://$HOST/" | grep -i '^cache-control:' | grep -c 's-maxage=300' || true)" "1"
    expect "trang chủ không đặt cookie (#532)" "$(curl -sI "https://$HOST/" | grep -ci '^set-cookie:' || true)" "0"
    expect "giả tên miền qua X-Forwarded-Host không đổi được website (#533)" "$(curl -s -H 'X-Forwarded-Host: website.baoson.net' "https://$HOST/?xfh=$RANDOM" | grep -c 'website.baoson.net/login' || true)" "0"
}
release_rollback() { # data the old code (cae46783) cannot read
    echo "     Không có: bản này chỉ đổi CSS, JS và gói nội dung (chưa nạp), không đổi bảng, không đổi cài đặt."
}
