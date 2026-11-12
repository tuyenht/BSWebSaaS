# Release file: prod 7a4b4e22 → 54442451 (main on 12/11, after #537 and #534). Data and one-off commands only;
# the engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/7a4b4e22-54442451.sh
# Range: #537 (release file kept); #534 (admin members screen for every site: list with search and role filter,
# roles panel with undo, invite by email with a per-send cap and the site's daily mail quota; role changes go
# through the existing role-hierarchy guard). Livewire screen, CSS, routes, lang, tests. No migration, no new job,
# no .env key, no library change.
# shellcheck shell=bash disable=SC2034
OLD=7a4b4e2250b1313d4d902260e3985d63f1f52bb6
TARGET=544424512534074d703d41214592b10594878215
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
BUILD_MARKERS=("resources/css/app.css people")

ONEOFFS_PRE=()
ONEOFFS=()
release_smoke() {
    # #534: the members screen still sends a signed-out visitor to the sign-in page (not an error)
    expect "màn Thành viên chưa đăng nhập chuyển về trang đăng nhập (#534)" "$(curl -s -o /dev/null -w '%{http_code}' "https://$HOST/admin/users?x=$RANDOM")" "302"
    # #535: the home page still has blocks that move in
    expect "trang chủ còn khối có hiệu ứng (#535)" "$([ "$(has / 'sf-s--m-')" -gt 0 ] && echo 1 || echo 0)" "1"
    # #533 stays closed
    expect "giả tên miền qua X-Forwarded-Host không đổi được website (#533)" "$(curl -s -H 'X-Forwarded-Host: website.baoson.net' "https://$HOST/?xfh=$RANDOM" | grep -c 'website.baoson.net/login' || true)" "0"
}
release_rollback() { # data the old code (7a4b4e22) cannot read
    echo "     Không có: bản này không đổi bảng, không đổi cài đặt. Lời mời gửi trong lúc chạy bản mới vẫn nằm ở bảng lời mời có sẵn; bản cũ đọc được."
}
