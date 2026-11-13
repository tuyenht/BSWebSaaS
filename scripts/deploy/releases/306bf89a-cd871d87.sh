# Release file: prod 306bf89a → cd871d87 (main on 13/11, after #632 and #634). Data and one-off commands only;
# the engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/306bf89a-cd871d87.sh
# Range: #632 (release file and result of the previous deploy), #634 (phone contact bar: disc 22 → 28px, 32px with a
# 13px label once a bar cell is at least 100px wide through a container query on the cell; bar 56px minimum; the
# "Thêm" sheet sits 4.375rem up; asset budget line for sf-floating.css). CSS only: no migration, no schedule, no env.
# shellcheck shell=bash disable=SC2034
OLD=306bf89a15d370b572ef77d8efc12e9a9f7a0f2e
TARGET=cd871d876e9c80101ef7b4e9184e94b08583263f
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
    local html
    # #634: the bar cell is a size container and a wide cell gets the 32px disc
    expect "CSS website có ô thanh dưới theo độ rộng, chấm 32px khi ô rộng (#634)" \
        "$(docker exec "${PREFIX}app" sh -c 'grep -l "@container sf-fc-cell (width>=100px){.sf-fc-bar__dot{width:32px;height:32px}" public/build/assets/sf-floating-*.css | wc -l' || true)" "1"
    expect "CSS website có chấm thanh dưới 28px mặc định (#634)" \
        "$(docker exec "${PREFIX}app" sh -c 'grep -l "container:sf-fc-cell/inline-size" public/build/assets/sf-floating-*.css | wc -l' || true)" "1"
    html=$(curl -s -m 20 "https://$HOST/?bp=$RANDOM")
    expect "trang chủ av1 vẫn có thanh liên hệ dưới (#634)" "$({ grep -oF 'class="sf-fc-bar__dot"' <<< "$html" || true; } | wc -l | awk '{print ($1 > 0)}')" "1"
}
