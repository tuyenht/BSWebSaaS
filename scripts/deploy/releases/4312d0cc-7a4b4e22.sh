# Release file: prod 4312d0cc → 7a4b4e22 (main on 12/11, after #535–#536). Data and one-off commands only;
# the engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/4312d0cc-7a4b4e22.sh
# Range: #535 (website motion for every site: blocks come in once they are ~12% into the screen, list items one
# after another 60 ms apart, lazy photos fade in over 200 ms, the hero slider crossfades in 250 ms, nothing moves
# with reduced motion); #536 (release file kept). CSS, JS, one style-class rule, tests. No migration, no new job,
# no .env key, no library change.
# shellcheck shell=bash disable=SC2034
OLD=4312d0cc180b7e6fa82fd0c4c3864c82ba6aec63
TARGET=7a4b4e2250b1313d4d902260e3985d63f1f52bb6
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
BUILD_MARKERS=("resources/css/storefront.css sf-img--wait" "resources/js/sections.js --sf-step")

ONEOFFS_PRE=()
ONEOFFS=()
release_smoke() {
    # #535: the home page still has blocks that move in, and the new motion build is the one served
    expect "trang chủ còn khối có hiệu ứng (#535)" "$([ "$(has / 'sf-s--m-')" -gt 0 ] && echo 1 || echo 0)" "1"
    # #533 stays closed
    expect "giả tên miền qua X-Forwarded-Host không đổi được website (#533)" "$(curl -s -H 'X-Forwarded-Host: website.baoson.net' "https://$HOST/?xfh=$RANDOM" | grep -c 'website.baoson.net/login' || true)" "0"
}
release_rollback() { # data the old code (4312d0cc) cannot read
    echo "     Không có: bản này không đổi bảng, không đổi gói nội dung, không đổi cài đặt. Lùi về bản cũ chỉ đổi lại hiệu ứng."
}
