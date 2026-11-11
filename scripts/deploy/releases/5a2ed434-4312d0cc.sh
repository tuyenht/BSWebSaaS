# Release file: prod 5a2ed434 → 4312d0cc (main on 12/11, after #530–#533). Data and one-off commands only;
# the engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/5a2ed434-4312d0cc.sh
# Range: #531 (website phone look for every site: boxed Menu button with the word under the icon, top bar
# hidden on phones when it holds nothing useful, text size in the menu panel, ✕ always visible, rail arrows on
# the card edges, bottom bar icons in every cell, a calmer phone type scale). CSS, Blade, one icon, tests and
# one language line removed; #533 (the app trusts only the visitor IP and https flag from the edge proxy, never a
# forwarded host, port or prefix). No migration, no new job, no .env key, no library change.
# shellcheck shell=bash disable=SC2034
OLD=5a2ed434596ea58975a1b4687ed9636380afd82d
TARGET=4312d0cc180b7e6fa82fd0c4c3864c82ba6aec63
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
    # #531: the "swipe to see more" line under the rails is gone
    expect "trang chủ không còn dòng 'vuốt ngang' dưới dải thẻ (#531)" "$(has / 'sf-rail__hint')" "0"
    # #531: the header still has its menu button and the home page its rails
    # #533: a forged forwarded host no longer switches the website that answers
    expect "giả tên miền qua X-Forwarded-Host không đổi được website (#533)" "$(curl -s -H 'X-Forwarded-Host: website.baoson.net' "https://$HOST/?xfh=$RANDOM" | grep -c 'website.baoson.net/login' || true)" "0"
    expect "trang chủ còn nút Menu (#531)" "$([ "$(has / 'sf-burger')" -gt 0 ] && echo 1 || echo 0)" "1"
}
release_rollback() { # data the old code (5a2ed434) cannot read
    echo "     Không có: bản này không đổi bảng, không đổi gói nội dung, không đổi cài đặt. Lùi về bản cũ chỉ đổi lại giao diện."
}
