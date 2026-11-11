# Release file: prod 86394872 → 077f3c0e (main on 11/11, after #525–#527). Data and one-off commands only;
# the engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/86394872-077f3c0e.sh
# Range: #525 (two kept release files), #526 (the SEO audit judges the first content image inside
# <main> only), #527 (the about photo offers sized files, card photos are asked for at the size their
# rail draws them, a phone's compact row at 10rem). Views and one PHP helper: no migration, no new job,
# no .env key, no library change, no CSS or JS change.
# shellcheck shell=bash disable=SC2034
OLD=86394872b8a9f03cbb1babd685d4022c1c51d120
TARGET=077f3c0e8a513fc5bf5aed21bf308fc8cb36613d
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
    # #527: the about photos on the about page now offer sized files
    expect "ảnh giới thiệu có tệp theo cỡ khung (#527)" "$([ $(( $(has /gioi-thieu '(min-width:768px) 64rem') + $(has /gioi-thieu '(min-width:768px) min(27rem') )) -gt 0 ] && echo 1 || echo 0)" "1"
    # #527: card photos still carry a sizes hint
    expect "trang tin tức vẫn có cỡ ảnh thẻ (#527)" "$([ "$(has /tin-tuc 'sizes="')" -gt 0 ] && echo 1 || echo 0)" "1"
}
release_rollback() { # data the old code (86394872) cannot read
    echo "     Không có: bản này không đổi bảng, không thêm khóa cài đặt và không đổi gói nội dung. Lùi là về cỡ ảnh cũ. Không mất dữ liệu."
}
