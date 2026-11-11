# Release file: prod 077f3c0e → 801fee26 (main on 11/11, after #528–#529). Data and one-off commands only;
# the engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/077f3c0e-801fee26.sh
# Range: #528 (one kept release file), #529 (website blocks move into view by default, chosen by their
# kind; the first block never moves; nothing is hidden before the visitor scrolls; one new choice
# "Tự động" in the block look panel, four new language lines). PHP, views, one small JS file and a CSS
# comment: no migration, no new job, no .env key, no library change.
# shellcheck shell=bash disable=SC2034
OLD=077f3c0e8a513fc5bf5aed21bf308fc8cb36613d
TARGET=801fee26b601a8ca320410d9a660ee78bdfb160a
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
    # #529: blocks below the first one on the home page now move into view by default
    expect "trang chủ có khối trượt lên theo mặc định (#529)" "$([ "$(has / 'sf-s--m-rise')" -gt 0 ] && echo 1 || echo 0)" "1"
    # #529: the page that has a moving block loads the small motion script
    expect "trang chủ tải tệp hiệu ứng khối (#529)" "$([ "$(has / '/build/assets/sections-')" -gt 0 ] && echo 1 || echo 0)" "1"
}
release_rollback() { # data the old code (077f3c0e) cannot read
    echo "     Không có: bản này không đổi bảng và không đổi gói nội dung. Một khối đã lưu lựa chọn \"Tự động\" sẽ hiện như \"Không\" ở bản cũ. Không mất dữ liệu."
}
