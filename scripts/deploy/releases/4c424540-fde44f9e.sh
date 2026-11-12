# Release file: prod 4c424540 → fde44f9e (main on 13/11, after #555, #574, #575, #576). Data and one-off commands only;
# the engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/4c424540-fde44f9e.sh
# Range: #555 (CI measurement site covers every block kind: tests, fixtures, SeedMeasurementSite, ci.yml — no runtime
# path), #574 (release file kept), #575 (storefront: brand-colored text on the dark footer uses the color derived for
# the ink AND the raised factory card; ThemeResolver + storefront.css; the default blue becomes #5895f7 there, a site
# whose brand already reads, such as An Vinh's amber, is unchanged), #576 (content-pack loader: products take an
# optional published_at and featured flag, same edit rules as posts; An Vinh's products.json sets them; the footer legal
# line reads "Mã số doanh nghiệp: … do … cấp ngày …" in vi/en/ja/zh). Code, lang, one CSS file and the An Vinh pack:
# no migration, no new job, no .env key, no library change. The pack is loaded after the deploy by its own script
# (plans/tools/anvinh-*-load-*.sh, preview then apply), so the product order is not checked here.
# Run 13/11 04:12 (log plans/reports/deploy-runs/4c42454-fde44f9e-run-20261113-041247.log): every step and every
# release_smoke check passed, 0 app error lines. Then the An Vinh pack was loaded (9 products written; checks all passed).
# shellcheck shell=bash disable=SC2034
OLD=4c4245409974d860dc497e6214f83df48f5c03f1
TARGET=fde44f9e136eb6683f5bd4da8bc446409ec054d0
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /ho-so-nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

# Classes that need a human look and are in this range (the engine stops if this differs from what it finds).
DECLARED=(content-pack)

MIGRATIONS=()
declare -gA MIG_OK=()
declare -gA PGB_SKIP=()
SCHEDULE_ADDED=()
SCHEDULE_REMOVED=()
ENV_DEFAULT_OK=()
SCHEMA_REMOVED_OK=()
BUILD_DYNAMIC=()
# release_smoke checks the built CSS of #575 (minified, so no source string matches the build).
BUILD_MARKERS=()

ONEOFFS_PRE=()
ONEOFFS=()
release_smoke() {
    local p html css code loc
    # #576: the legal line's new text keys are in the running code, and the footer no longer prints the old "GPĐKKD"
    expect "chữ dòng pháp lý mới có trong bản đang chạy (#576)" \
        "$(docker exec "${PREFIX}app" grep -cF '"storefront.license_no_date": "Mã số doanh nghiệp: :number do :issuer cấp"' lang/vi.json || true)" "1"
    html=$(curl -s -m 20 "https://$HOST/nha-xuong-may-moc?lg=$RANDOM")
    expect "chân trang ghi Mã số doanh nghiệp với nơi cấp và ngày cấp (#576)" \
        "$(grep -cF 'Mã số doanh nghiệp: 0108649706 do Sở Tài Chính Hà Nội cấp ngày 15/03/2019' <<< "$html" || true)" "1"
    expect "chân trang không còn chữ GPĐKKD (#576)" "$(grep -c 'GPĐKKD' <<< "$html" || true)" "0"
    # #575: the core stylesheet draws the factory card's brand text in the derived color
    css=$(grep -oE '/build/assets/storefront-[A-Za-z0-9_-]+\.css' <<< "$html" | head -1 || true)
    expect "CSS lõi tô chữ thương hiệu trên thẻ nhà máy bằng màu dẫn xuất (#575)" \
        "$([ -n "$css" ] && curl -s -m 20 "https://$HOST$css" | grep -oE '\.sf-factory__(area|chip)\{[^}]*\}' | grep -c 'color:var(--color-primary-on-dark)' || true)" "2"
    # earlier releases stay as they were
    expect "trang có biến màu thương hiệu trên nền tối" "$(grep -c -- '--color-primary-on-dark:' <<< "$html" || true)" "1"
    for p in /admin/forms; do
        read -r code loc < <(curl -s -o /dev/null -m 20 -w '%{http_code} %{redirect_url}\n' "https://$HOST$p")
        expect "$p chuyển khách chưa đăng nhập sang trang đăng nhập (#541)" "$code ${loc##*/admin/}" "302 login"
    done
    html=$(curl -s -m 20 "https://$HOST/nha-xuong-may-moc?g=$RANDOM")
    expect "thư viện ảnh nhà xưởng không có lưới 4 cột (#573)" "$(grep -c 'sf-gallery__list--4' <<< "$html" || true)" "0"
    for p in / /tin-tuc /jig-kiem-tap-lo-vf7; do
        html=$(curl -s -m 20 "https://$HOST$p?img=$RANDOM" | tr '\n' ' ')
        expect "trang $p có đúng 1 ảnh ưu tiên cao (#558)" "$({ grep -o 'fetchpriority="high"' <<< "$html" || true; } | wc -l)" "1"
    done
    html=$(curl -s -m 20 "https://$HOST/?css=$RANDOM")
    expect "trang chủ gắn đúng 1 mã đo Cloudflare (#548, #549)" "$({ grep -oF 'src="https://static.cloudflareinsights.com/beacon.min.js' <<< "$html" || true; } | wc -l)" "1"
    expect "trang chủ cho phép đệm ở Cloudflare (#532)" "$(curl -sI -m 20 "https://$HOST/" | grep -i '^cache-control:' | grep -c 's-maxage=300' || true)" "1"
    expect "trang chủ không đặt cookie (#532)" "$(curl -sI -m 20 "https://$HOST/" | grep -ci '^set-cookie:' || true)" "0"
    expect "giả tên miền qua X-Forwarded-Host không đổi được website (#533)" "$(curl -s -m 20 -H 'X-Forwarded-Host: website.baoson.net' "https://$HOST/?xfh=$RANDOM" | grep -c 'website.baoson.net/login' || true)" "0"
}
release_rollback() { # data the old code (4c424540) cannot read
    echo "     Không có bảng hay cột mới. Ngày đăng và cờ nổi bật của sản phẩm ghi vào cột có sẵn (products.published_at, products.is_featured) mà bản cũ vẫn đọc; gói chỉ nạp sau deploy bằng script riêng."
}
