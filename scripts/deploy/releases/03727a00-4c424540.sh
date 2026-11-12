# Release file: prod 03727a00 → 4c424540 (main on 13/11, after #569, #570, #568, #572, #571, #573). Data and one-off
# commands only; the engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/03727a00-4c424540.sh
# Range: #569 (Khách liên hệ: the undo says when a colleague changed the leads since; new lang keys in vi/en/ja/zh),
# #570 (An Vinh pack: list pages, capability order, footer links, policy date; shipped, NOT loaded: loaded after this
# deploy with its own load script), #568 (two lead-list indexes on form_submissions, the old (site_id, status) index
# dropped: the new status index leads with the same two columns), #572 (release file kept), #571 (CI only: the cwv job
# measures over HTTP/2 + TLS), #573 (storefront: rail arrows in the controls row on a phone and beside the list only
# from 1440px, lone last words, side share label, post cover edges, numeral process and card call to action capped,
# about text first on a phone, a gallery never draws more columns than pictures and its picture sizes follow the
# columns, icon cards line up). One migration (indexes only), the leads screen's code, views, CSS, lang and the pack:
# no new job, no .env key, no library change.
# Run 13/11 02:34 (log plans/reports/deploy-runs/03727a0-4c424540-run-20261113-023419.log): steps 0-8 all OK, 140 checks,
# 0 error lines; the #570 pack was loaded afterwards with its own load script (23 items, every page check passed).
# shellcheck shell=bash disable=SC2034
OLD=03727a00485229563ee807810424d1a644e3ff49
TARGET=4c4245409974d860dc497e6214f83df48f5c03f1
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /ho-so-nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

# Classes that need a human look and are in this range (the engine stops if this differs from what it finds).
DECLARED=(migration content-pack)

MIGRATIONS=(2055_01_01_000000_add_lead_list_indexes)
# Raw SQL (the house pattern: re-runnable, drops only its own INVALID leftover); reviewed as indexes only.
declare -gA MIG_OK=(
    [2055_01_01_000000_add_lead_list_indexes]="2 lead-list indexes CONCURRENTLY on form_submissions ((site_id, created_at DESC, ulid DESC) and (site_id, status, created_at DESC, ulid DESC)), then DROP INDEX CONCURRENTLY form_submissions_site_id_status_index, whose two columns lead the new status index (the old code's status filter uses it); drops only its own INVALID leftover"
)
declare -gA PGB_SKIP=(
    [2055_01_01_000000_add_lead_list_indexes]="indexes only"
)
SCHEDULE_ADDED=()
SCHEDULE_REMOVED=()
ENV_DEFAULT_OK=()
# The one index the migration replaces (its columns lead form_submissions_status_created_idx).
SCHEMA_REMOVED_OK=("idx|form_submissions|form_submissions_site_id_status_index|valid")
BUILD_DYNAMIC=()
BUILD_MARKERS=()

ONEOFFS_PRE=()
ONEOFFS=()
release_smoke() {
    local p html css code loc n
    # #568: the engine's step 4 already checks both new indexes are valid and only the old one went
    # #569: the new undo text is in the running code; the leads screen still asks a signed-out visitor to sign in
    expect "chữ hoàn tác mới của màn Khách liên hệ có trong bản đang chạy (#569)" \
        "$(docker exec "${PREFIX}app" grep -c '"leads.undo_changed"' lang/vi.json || true)" "1"
    read -r code loc < <(curl -s -o /dev/null -m 20 -w '%{http_code} %{redirect_url}\n' "https://$HOST/admin/forms")
    expect "/admin/forms chuyển khách chưa đăng nhập sang trang đăng nhập (#569)" "$code ${loc##*/admin/}" "302 login"
    # #570: the container serves the new pack (its load script checks the same before it runs)
    expect "gói nội dung An Vinh mới trong container (chân trang 'Về công ty', #570)" \
        "$(docker exec "${PREFIX}app" grep -c '"vi": "Về công ty"' database/content-packs/an-vinh/menus.json || true)" "1"
    # #573: the factory page's two three-picture galleries draw three columns, not four
    html=$(curl -s -m 20 "https://$HOST/nha-xuong-may-moc?g=$RANDOM")
    expect "trang Nhà xưởng: không còn thư viện ảnh 4 cột cho 3 ảnh (#573)" "$({ grep -oE 'sf-gallery__list--4' <<< "$html" || true; } | wc -l)" "0"
    expect "trang Nhà xưởng: 2 thư viện ảnh 3 cột (#573)" "$({ grep -oE 'sf-gallery__list--3' <<< "$html" || true; } | wc -l)" "2"
    # #573: the home page's call-to-action stylesheet keeps a lone last word off its own line
    html=$(curl -s -m 20 "https://$HOST/?cta=$RANDOM")
    css=$(grep -oE '/build/assets/sf-cta-[^"]+\.css' <<< "$html" | head -1 || true)
    n=$([ -n "$css" ] && curl -s -m 20 "https://$HOST$css" | grep -c 'text-wrap:pretty' || true)
    expect "CSS lời kêu gọi của trang chủ không để một chữ lẻ xuống dòng (#573)" "$([ "${n:-0}" -ge 1 ] && echo yes || echo "no: $css")" "yes"
    # earlier releases stay as they were
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
release_rollback() { # data the old code (03727a00) cannot read
    echo "     Không có dữ liệu mới: bản này thêm 2 chỉ mục và bỏ 1 chỉ mục (code cũ vẫn chạy với chỉ mục mới), đổi chữ trang quản trị, CSS và giao diện cửa hàng; gói An Vinh chưa nạp. Muốn trả lại chỉ mục cũ: migrate:rollback --step=1 bằng pgsql_admin (tạo lại form_submissions_site_id_status_index CONCURRENTLY)."
}
