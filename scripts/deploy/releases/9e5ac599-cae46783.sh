# Release file: prod 9e5ac599 → cae46783 (main on 12/11, after #539). Data and one-off commands only;
# the engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/9e5ac599-cae46783.sh
# Range: #539 (every site: a tap on a card rail's arrow on a phone turns the rail instead of opening the card under
# it; .sf-card is its own stacking layer and the arrows sit above it). CSS, the CI measurement seeder, a CI tap
# check script, tests, docs. No migration, no new job, no .env key, no library change.
# shellcheck shell=bash disable=SC2034
OLD=9e5ac5999861524e7d179203b36ef62340596cfe
TARGET=cae46783a033f58ac2186e199cffb2e9a7ad20b6
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
# The new rule exists in the source with spaces only, so no string can prove it in resources/ and in the build at
# once; the smoke below reads it from the served stylesheet instead.
BUILD_MARKERS=()

ONEOFFS_PRE=()
ONEOFFS=()
release_smoke() {
    # #539: the served storefront stylesheet isolates each card (its link overlay stays under the rail arrows)
    local css; css=$(curl -s "https://$HOST/?css=$RANDOM" | grep -o '/build/assets/storefront-[^"]*\.css' | head -1)
    expect "CSS trang công khai khoá lớp phủ của thẻ dưới mũi tên (#539)" "$(curl -s "https://$HOST$css" | grep -o '\.sf-card{[^}]*}' | grep -c 'isolation:isolate' || true)" "1"
    # #532 and earlier releases stay as they were
    expect "trang chủ cho phép đệm ở Cloudflare (#532)" "$(curl -sI "https://$HOST/" | grep -i '^cache-control:' | grep -c 's-maxage=300' || true)" "1"
    expect "trang chủ không đặt cookie (#532)" "$(curl -sI "https://$HOST/" | grep -ci '^set-cookie:' || true)" "0"
    expect "giả tên miền qua X-Forwarded-Host không đổi được website (#533)" "$(curl -s -H 'X-Forwarded-Host: website.baoson.net' "https://$HOST/?xfh=$RANDOM" | grep -c 'website.baoson.net/login' || true)" "0"
}
release_rollback() { # data the old code (9e5ac599) cannot read
    echo "     Không có: bản này chỉ đổi CSS, không đổi bảng, không đổi cài đặt."
}
