# Release file: prod 353dc9f9 → 16ad0ff8 (main on 14/11, after #653). Data and one-off commands only;
# the engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/353dc9f9-16ad0ff8.sh
# Range: #652 (release file and result of the previous deploy), #654 (admin "Nút liên hệ nổi": the phone preview
# draws a phone as wide as the admin's own screen, 320–390px, and every colour swatch shares one named hit area).
# #653 (the header logo, full and simple header, gets fetchpriority="low", not lazy, so the main photo loads first).
# Blade views and tests: no migration, no schedule, no env.
# Correction to the previous release file's last line: the cause of the one-off axe "meta-viewport" failure on the
# first page of CI run 43481956168 was not verified; the step passed on its rerun.
# shellcheck shell=bash disable=SC2034
OLD=353dc9f9ebea462ea952676627736536894ff295
TARGET=16ad0ff838b91042f62db8a1cd1849404e6674c2
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /ho-so-nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

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
    local login app
    login=$(curl -sS -m 20 "https://$HOST/admin/login?bp=$RANDOM")
    app=$({ grep -oE '/build/assets/app-[A-Za-z0-9_-]+\.css' <<< "$login" || true; } | head -1)
    expect "tìm thấy CSS quản trị trên trang đăng nhập av1" "$([ -n "$app" ] && echo 1 || echo 0)" "1"
    # #654: the preview card's padding grows from a 26rem settings container (class built only for this screen)
    expect "CSS quản trị av1 có khoảng đệm xem trước theo bề rộng (#654)" \
        "$(curl -fsS -m 20 "https://$HOST$app" | { grep -cF '@container fc (width>=26rem)' || true; } | awk '{print ($1 > 0)}')" "1"
    local html
    html=$(curl -sS -m 20 "https://$HOST/?bp=$RANDOM")
    # #653: the header logo yields load priority
    expect "trang chủ av1: logo đầu trang nhường ưu tiên tải (#653)" \
        "$({ grep -oE 'class="sf-bar__logo"[^>]*fetchpriority="low"' <<< "$html" || true; } | wc -l | awk '{print ($1 > 0)}')" "1"
    expect "trang chủ av1 vẫn có nút Gọi nổi giữa thanh điện thoại" \
        "$({ grep -oF 'sf-fc-bar__item--up' <<< "$html" || true; } | wc -l | awk '{print ($1 > 0)}')" "1"
}
# Log: plans/reports/deploy-runs/353dc9f-16ad0ff8-run-20261114-091756.log (XONG, 101 OK, release smoke 4/4, 0 app log errors; full CI on main 43543893679 green)
