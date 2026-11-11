# Release file: prod f8b89442 → 5edbd872 (main on 11/11, after #518–#519). Data and one-off commands only;
# the engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/f8b89442-5edbd872.sh
# Range: #519 (the release file of the last deploy), #518 (storefront SEO: a page title that another page
# of the site also uses gets its parent's name; category descriptions cut to 160 characters at a word;
# one schema.org @graph per page with the full Organization on the home page only and @id references
# elsewhere; product spec tables skip header rows). Code, views and lang: no migration, no new job, no
# .env key, no library change, no CSS or JS change.
# shellcheck shell=bash disable=SC2034
OLD=f8b894422613461a319ec045174c97a1efb93e2b
TARGET=5edbd872d347747fc864458ca96ba84e0797ca69
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
    # #518: one structured-data script per page, as one @graph
    expect "trang chủ có một khối dữ liệu cấu trúc @graph (#518)" "$(has / '"@graph"')" "1"
    expect "trang sản phẩm chỉ có một khối dữ liệu cấu trúc (#518)" "$(has /san-pham 'application/ld+json')" "1"
    expect "mã chống trùng tên trang có mặt (#518)" \
        "$(docker exec "${PREFIX}app" test -f app/Modules/Storefront/Support/TitleDisambiguator.php && echo 1 || echo 0)" "1"
}
release_rollback() { # data the old code (f8b89442) cannot read
    echo "     Không có: bản này không đổi bảng, không thêm khóa cài đặt và không đổi gói nội dung. Lùi là về tiêu đề và dữ liệu cấu trúc cũ. Không mất dữ liệu."
}
