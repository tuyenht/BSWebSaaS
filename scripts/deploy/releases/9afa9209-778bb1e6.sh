# Release file: prod 9afa9209 → 778bb1e6 (main on 12/11, after #549 and #550). Data and one-off commands only;
# the engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/9afa9209-778bb1e6.sh
# Range: #549 (An Vinh content pack: storefront.web_analytics with av1's Cloudflare Web Analytics site token;
# loaded afterwards by plans/tools/anvinh-rum-load-261112.sh), #550 (release file kept).
# A content pack, one test, a release file. No migration, no new job, no .env key, no library change.
# shellcheck shell=bash disable=SC2034
OLD=9afa92096245c3e270cb5dcc38e5a922becdf687
TARGET=778bb1e624782132ae410bf8ad6205274c993a03
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
    # #543 stays as it was. #548's beacon is checked by plans/tools/anvinh-rum-load-261112.sh verify, not here:
    # step 8 may run again after the pack is loaded, so a beacon count here would fail either way.
    local html; html=$(curl -s "https://$HOST/?css=$RANDOM")
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
release_rollback() { # data the old code (9afa9209) cannot read
    echo "     Không có: bản này chỉ đổi gói nội dung (chưa nạp) và một test; cài đặt web_analytics bản cũ đọc được."
}
