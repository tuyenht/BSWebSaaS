# Release file: prod d9fceed4 → 00e38f1c (main on 15/11). Data and one-off commands only; the engine
# (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/d9fceed4-00e38f1c.sh
# Range:
#   #731 admin floating-contact screen: a built-in channel's card title adds a muted "· hiện là “Mes”"
#        when the button label typed for the language being edited differs from the channel name.
#   #729, #732 docs/chore only.
# No migration, no library change, no .env key, no schedule change.
# shellcheck shell=bash disable=SC2034
OLD=d9fceed45adf8ebc5c6ecf5bcb245951f1a293e5
TARGET=00e38f1cb8b9fa7b248bd8af39adc41951200efe
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
    # #731: the "shown as" words exist in every language
    r=$(art tinker --execute='$ok = true; foreach (["vi", "en", "ja", "zh"] as $l) { $t = __("admin.fc.shown_as", ["text" => "Mes"], $l); $ok = $ok && $t !== "admin.fc.shown_as" && str_contains($t, "Mes"); } echo $ok ? 1 : 0;' 2>&1 | tail -1 || true)
    expect "chữ «hiện là …» có trong bốn bản dịch (#731)" "$r" "1"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/admin/settings/floating-contact")
    expect "trang «Nút liên hệ nổi» chuyển người chưa đăng nhập đi đăng nhập (#731)" "$code" "302"
    # earlier releases stay as they were
    html=$(curl -s --compressed -m 20 -A "$ua" -H 'accept: text/html' "https://$HOST/gioi-thieu?m15c=$RANDOM")
    drawer=${html#*<div id=\"mobile-menu\"}
    drawer=${drawer%%<noscript>*}
    expect "menu điện thoại: nhóm cha vẫn là nút bấm cả dòng (#726)" "$(grep -c 'sf-nav__toggle' <<< "$drawer" | awk '{print ($1>0)}')" "1"
    expect "menu điện thoại: đúng một dòng đang xem (#718)" "$(grep -o 'aria-current="page"' <<< "$drawer" | wc -l | tr -d ' ')" "1"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/admin/login")
    expect "trang đăng nhập quản trị mở được" "$code" "200"
    html=$(curl -s --compressed -m 20 -A "$ua" -H 'accept: text/html' "https://$HOST/?m15c=$RANDOM")
    expect "trang chủ vẫn có 6 dải thẻ (#713)" "$(grep -o 'data-sf-rail ' <<< "$html" | wc -l | tr -d ' ')" "6"
    expect "trang chủ có đúng một chỗ giữ beacon (#688)" "$(grep -o 'data-sf-beacon=' <<< "$html" | wc -l | tr -d ' ')" "1"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/khong-co-trang-nay-$RANDOM")
    expect "địa chỉ không có trả 404 (giữ như cũ)" "$code" "404"
}
