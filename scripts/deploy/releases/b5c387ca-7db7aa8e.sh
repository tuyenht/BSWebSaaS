# Release file: prod b5c387ca → 7db7aa8e (main on 15/11). Data and one-off commands only; the engine
# (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/b5c387ca-7db7aa8e.sh
# Range:
#   #691 CI runs Pest in five shards (CI files and docs only; nothing on prod changes);
#   #690 storefront phone menu: the drawer follows the header tone (the header bar is always the site's dark
#        colour, so every site gets the dark drawer), one selection style for tabs and the text-size control,
#        the current page drawn as a pill with a check mark, the current page's parent opens in the Menu tab,
#        no row dividers, a "Kết nối với chúng tôi" caption over the contact block, a blurred scrim.
#        A new header option `drawer_tone` (auto | light | dark) lives in the existing storefront.header JSON;
#        no admin control yet, nothing stored by this deploy.
# No migration, no library change, no .env key, no schedule change.
# shellcheck shell=bash disable=SC2034
OLD=b5c387ca7ed486cb6e6501f2b49ec3ee1fd864d2
TARGET=7db7aa8e159d321d95bc791fe7887dec151ba9a3
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
BUILD_MARKERS=("resources/css/storefront.css .sf-drawer--dark")

ONEOFFS_PRE=()
ONEOFFS=()
release_smoke() {
    local html code
    html=$(curl -s -m 20 "https://$HOST/?m9=$RANDOM")
    # #690: the phone menu follows the dark header bar and names its contact block
    expect "menu điện thoại nền tối theo thanh đầu trang (#690)" "$(grep -c 'class="sf-drawer sf-drawer--dark"' <<< "$html" || true)" "1"
    expect "menu điện thoại có dòng 'Kết nối với chúng tôi' (#690)" "$(grep -c 'class="sf-drawer__cap"' <<< "$html" || true)" "1"
    # earlier releases stay as they were
    expect "trang chủ có đúng một chỗ giữ beacon (#688)" "$(grep -o 'data-sf-beacon=' <<< "$html" | wc -l | tr -d ' ')" "1"
    expect "cỡ chữ trong menu điện thoại vẫn là nút trượt (#673)" "$(grep -c 'class="sf-pill sf-pill--fs"' <<< "$html" || true)" "1"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/admin/login")
    expect "trang đăng nhập quản trị mở được" "$code" "200"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/khong-co-trang-nay-$RANDOM")
    expect "địa chỉ không có trả 404 (giữ như cũ)" "$code" "404"
}
