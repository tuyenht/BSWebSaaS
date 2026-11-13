# Release file: prod 3dee3f82 → 4bd80c14 (main on 13/11, after #637, #639, #638 and #641). Data and one-off commands
# only; the engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/3dee3f82-4bd80c14.sh
# Range: #637 (every storefront page gets a meta description: its own, else the site's, else the brand's, else a
# sentence from the page itself — first paragraph, list name and titles, product summary; CI: Lighthouse best
# practices and SEO 100 are gates), #639 (release file of the previous deploy), #638 (phone contact bar: the label
# stays 12px in wide cells — the 13px rule is gone), #641 (theme: secondary text gets its own readable color
# `--color-text-secondary`, at least 4.5:1 on every light background of the site, instead of the body color mixed
# toward white). PHP, Blade, CSS, tests and CI only: no migration, no schedule, no env.
# shellcheck shell=bash disable=SC2034
OLD=3dee3f829827edcb5883915be8300d0d79e14e76
TARGET=4bd80c144d313a4aacf44d6066fcf1aa0910e824
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
    local html about css fc p n missing=0 checked=0
    # #637: every public page of av1 tells search engines what it is about
    for p in "${PAGES[@]}"; do
        [ "$p" = /admin/login ] && continue
        n=$(curl -s -m 20 "https://$HOST$p?bp=$RANDOM" | { grep -cE '<meta name="description" content="[^"]+' || true; })
        checked=$((checked + 1))
        [ "$n" -ge 1 ] || missing=$((missing + 1))
    done
    expect "mọi trang av1 có mô tả cho máy tìm kiếm (#637; đã xem $checked trang)" "$missing" "0"
    # #641: the theme hands the page a secondary text color, and no stylesheet of the page lightens text toward white
    about=$(curl -s -m 20 "https://$HOST/gioi-thieu?bp=$RANDOM")
    expect "trang /gioi-thieu có màu chữ phụ của giao diện (#641)" \
        "$({ grep -oE -- '--color-text-secondary:#[0-9a-fA-F]{6}' <<< "$about" || true; } | wc -l | awk '{print ($1 > 0)}')" "1"
    css=$({ grep -oE '/build/assets/(storefront|sf-[a-z0-9-]+)-[A-Za-z0-9_-]+\.css' <<< "$about" || true; } | sort -u)
    expect "tìm thấy CSS của trang /gioi-thieu" "$([ -n "$css" ] && echo 1 || echo 0)" "1"
    n=0
    for p in $css; do
        n=$((n + $(curl -fsS -m 20 "https://$HOST$p" | { grep -oE 'var\(--color-text\) ?[0-9]+%, ?white\)' || true; } | wc -l)))
    done
    expect "CSS trang /gioi-thieu không còn làm nhạt chữ về màu trắng (#641)" "$n" "0"
    # #638: the contact bar label keeps 12px in a wide cell (no 13px rule left), and the bar is still on the page
    html=$(curl -s -m 20 "https://$HOST/?bp=$RANDOM")
    fc=$({ grep -oE '/build/assets/sf-floating-[A-Za-z0-9_-]+\.css' <<< "$html" || true; } | head -1)
    expect "tìm thấy CSS nút liên hệ của trang chủ av1" "$([ -n "$fc" ] && echo 1 || echo 0)" "1"
    expect "nhãn thanh liên hệ không còn cỡ 13px ở ô rộng (#638)" \
        "$(curl -fsS -m 20 "https://$HOST$fc" | { grep -c 'sf-fc-bar__label{font-size:.8125rem}' || true; })" "0"
    expect "trang chủ av1 vẫn có thanh liên hệ dưới" \
        "$({ grep -oF 'class="sf-fc-bar__dot"' <<< "$html" || true; } | wc -l | awk '{print ($1 > 0)}')" "1"
}
# Run 13/11/2026 23:20 (tmux deploy-4bd80c14-13232053, launched by the AI): XONG, prod at 4bd80c14; 104 OK, 0 LỖI/LỆCH.
# Log: plans/reports/deploy-runs/3dee3f8-4bd80c14-run-20261113-232053.log
