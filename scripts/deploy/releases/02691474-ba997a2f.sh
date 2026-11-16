# Release file: prod 02691474 → ba997a2f (main on 16/11). Data and one-off commands only; the engine
# (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/02691474-ba997a2f.sh
# Range:
#   #739 phone menu automatic icons: "Năng lực" gets the award icon instead of repeating the project icon
#        of "Dự án" (Vietnamese keyword data only).
#   #737 chore only.
# No migration, no library change, no .env key, no schedule change, no front-end build change.
# shellcheck shell=bash disable=SC2034
OLD=0269147410982de8258cfeabcc7e7f382d9f5058
TARGET=ba997a2fa6b0213998f36e7def0758bd15921d3c
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
    local html code drawer ua r
    ua='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
    html=$(curl -s --compressed -m 20 -A "$ua" -H 'accept: text/html' "https://$HOST/gioi-thieu?m16d=$RANDOM")
    drawer=${html#*<div id=\"mobile-menu\"}
    drawer=${drawer%%<noscript>*}
    # #739: "Năng lực" draws the award icon (a ribbon under a circle), "Dự án" keeps the clipboard
    expect "menu điện thoại: «Năng lực» có biểu tượng huy chương (#739)" "$({ grep -o 'm15.477 12.89' <<< "$drawer" || true; } | wc -l | awk '{print ($1>0)}')" "1"
    expect "menu điện thoại: vẫn có biểu tượng tự động ở dòng cấp 1 (#734)" "$({ grep -o 'sf-nav__ic' <<< "$drawer" || true; } | wc -l | awk '{print ($1>0)}')" "1"
    expect "menu điện thoại: nhóm cha vẫn là nút bấm cả dòng (#726)" "$({ grep -o 'sf-nav__toggle' <<< "$drawer" || true; } | wc -l | awk '{print ($1>0)}')" "1"
    expect "menu điện thoại: đúng một dòng đang xem (#718)" "$({ grep -o 'aria-current="page"' <<< "$drawer" || true; } | wc -l | tr -d ' ')" "1"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/admin/login")
    expect "trang đăng nhập quản trị mở được" "$code" "200"
    html=$(curl -s --compressed -m 20 -A "$ua" -H 'accept: text/html' "https://$HOST/?m16d=$RANDOM")
    expect "trang chủ vẫn có 6 dải thẻ (#713)" "$({ grep -o 'data-sf-rail ' <<< "$html" || true; } | wc -l | tr -d ' ')" "6"
    expect "trang chủ có đúng một chỗ giữ beacon (#688)" "$({ grep -o 'data-sf-beacon=' <<< "$html" || true; } | wc -l | tr -d ' ')" "1"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/khong-co-trang-nay-$RANDOM")
    expect "địa chỉ không có trả 404 (giữ như cũ)" "$code" "404"
}
