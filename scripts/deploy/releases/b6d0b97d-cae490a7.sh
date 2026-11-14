# Release file: prod b6d0b97d → cae490a7 (main on 14/11, after #673). Data and one-off commands only; the
# engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/b6d0b97d-cae490a7.sh
# Range: #673 (storefront phone menu v5: "Menu | Sản phẩm" tabs when the header setting drawer_products is on
# (default) and the site has product categories, the Menu tab no longer repeats the product groups, text size
# and language as one segmented pill, call / Zalo buttons in the menu only for channels the floating contact
# does not carry). Code, lang, CSS/JS only: no migration, no new job or schedule, no library change, no .env key.
# Run 14/11 20:52 (log plans/reports/deploy-runs/b6d0b97-cae490a7-run-20261114-205233.log): every step and every
# release_smoke check passed, 0 app error lines; av1 shows the Menu | Sản phẩm tabs. Live phone shots at 320/360/390
# (and the two larger text sizes): no overflow, every visible control ≥ 44×44.
# shellcheck shell=bash disable=SC2034
OLD=b6d0b97d462da1df825c451e655c527e4c1e58d9
TARGET=cae490a773b049e36ad11646712da51986ea49a0
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
# #673: the new pill styles are in the built storefront CSS (checked at step 6, before reload).
BUILD_MARKERS=("resources/css/storefront.css sf-pill__th")

ONEOFFS_PRE=()
ONEOFFS=()
release_smoke() {
    local html code n
    html=$(curl -s -m 20 "https://$HOST/?m5=$RANDOM")
    # #673: text size is the segmented pill, labelled for screen readers
    expect "cỡ chữ trong menu điện thoại là nút trượt mới (#673)" "$(grep -c 'class="sf-pill sf-pill--fs"' <<< "$html" || true)" "1"
    expect "nhóm cỡ chữ có nhãn Cỡ chữ (#673)" "$(grep -c 'id="msm-fs-label"' <<< "$html" || true)" "1"
    # #673: the products tab shows only when the site has categories under its products section (data, not code)
    n=$(grep -c 'role="tablist"' <<< "$html" || true)
    note "menu điện thoại av1 có thanh tab Menu | Sản phẩm: $n (1 = website có nhóm sản phẩm; 0 = không có, đúng thiết kế)"
    # earlier releases stay as they were
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/khong-co-trang-nay-$RANDOM")
    expect "địa chỉ không có trả 404 (giữ như cũ)" "$code" "404"
    html=$(curl -s -m 20 "https://$HOST/nha-xuong-may-moc?lg=$RANDOM")
    expect "chân trang ghi Mã số doanh nghiệp (giữ như cũ)" "$(grep -c 'Mã số doanh nghiệp: 0108649706' <<< "$html" || true)" "1"
}
