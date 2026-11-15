# Release file: prod 315b2fc4 → e92b55ca (main on 15/11). Data and one-off commands only; the engine
# (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/315b2fc4-e92b55ca.sh
# Range:
#   #700 storefront footer: below 1024px the factory cards turn one at a time; their ‹ › arrows now float over
#        the card's left and right edges, halfway down (as every other carousel since #640), the dots sit
#        alone in a row under the card, and a turning card gets a wider side padding so its text clears the
#        arrows. The CWV measurement site seeds a second factory (SeedMeasurementSite: runs only on CI's
#        measurement site, never on prod's own sites).
#   #702 asset budget line for storefront.css (CI tooling only; nothing on prod changes).
# No migration, no library change, no .env key, no schedule change.
# shellcheck shell=bash disable=SC2034
OLD=315b2fc48f9888ddd8c549194325a487ae668984
TARGET=e92b55cafb0ce2f8b8aef9b4a4d55fc12be5cb93
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
BUILD_MARKERS=("resources/css/storefront.css .sf-rot__dots")

ONEOFFS_PRE=()
ONEOFFS=()
release_smoke() {
    local html code
    html=$(curl -s -m 20 "https://$HOST/?m11=$RANDOM")
    # #700: the factory dots are one row of their own between the two arrows (av1 has three factories)
    expect "chân trang: các chấm nhà máy nằm chung một hàng riêng (#700)" "$(grep -c 'class="sf-rot__dots"' <<< "$html" || true)" "1"
    expect "chân trang: vẫn đủ 3 chấm cho 3 nhà máy (#700)" "$(grep -o 'data-sf-rot-go' <<< "$html" | wc -l | tr -d ' ')" "3"
    # earlier releases stay as they were
    expect "trang chủ có đúng một chỗ giữ beacon (#688)" "$(grep -o 'data-sf-beacon=' <<< "$html" | wc -l | tr -d ' ')" "1"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/admin/login")
    expect "trang đăng nhập quản trị mở được" "$code" "200"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/khong-co-trang-nay-$RANDOM")
    expect "địa chỉ không có trả 404 (giữ như cũ)" "$code" "404"
}
