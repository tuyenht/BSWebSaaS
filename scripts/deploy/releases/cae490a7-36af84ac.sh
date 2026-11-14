# Release file: prod cae490a7 → 36af84ac (main on 14/11, after #679). Data and one-off commands only; the
# engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/cae490a7-36af84ac.sh
# Range:
#   #677 Trình đơn: the phone menu's "Tab Sản phẩm" switch (writes storefront.header drawer_products; needs
#        menus.manage and settings.manage);
#   #676 Notifications page search box (Design room): migration 2063 adds site_notifications.search_text, then
#        the one-off `notifications:search-text` fills notices written before it (runbook item 10);
#   #679 admin fix: a lazily started screen part (Trình đơn, Chuyên mục, product editor, unsaved dialog, bell,
#        notifications) no longer shows its hidden parts after a Livewire redraw — on prod the Trình đơn screen
#        showed an empty "Chọn chỗ mới" dialog that could not be closed; Trình đơn's load / self-check / search
#        no longer redraw the screen.
# No new job or schedule, no library change, no .env key.
# Run 14/11 22:18 (log plans/reports/deploy-runs/cae490a-36af84ac-run-20261114-221850.log): every step and every
# release_smoke check passed, 0 app error lines; migration 2063 took 5.29 ms, PgBouncer restarted with 0 'cached
# plan' errors after; the one-off wrote 0 rows and its re-run 0; the served admin bundle carries the redraw hook.
# shellcheck shell=bash disable=SC2034
OLD=cae490a773b049e36ad11646712da51986ea49a0
TARGET=36af84acc10f6faf87d8c5bc914732de889053e2
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /ho-so-nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

# Classes that need a human look and are in this range (the engine stops if this differs from what it finds).
DECLARED=(migration)

MIGRATIONS=(2063_01_01_000000_add_search_text_to_site_notifications)
declare -gA MIG_OK=(
    [2063_01_01_000000_add_search_text_to_site_notifications]="1 nullable text column site_notifications.search_text, no default, no rewrite; ALTER with a 5s lock timeout, IF NOT EXISTS; down() drops the column (derived data, rebuilt from data)"
)
# a new column: PgBouncer must drop its cached plans (restart after up())
declare -gA PGB_SKIP=()
SCHEDULE_ADDED=()
SCHEDULE_REMOVED=()
ENV_DEFAULT_OK=()
SCHEMA_REMOVED_OK=()
BUILD_DYNAMIC=()
# #679: the loader's redraw hook is in the built admin bundle (checked at step 6, before reload).
BUILD_MARKERS=("resources/js/app.js morph.updating")

ONEOFFS_PRE=()
ONEOFFS=(search_text)
# #676: fill the search line of every notice written before the column (site by site, safe to re-run; a site
# that fails → exit 1, the log holds no customer data). A second run writes 0.
oneoff_search_text() {
    local out
    case "$1" in
        preview) if [ -s "$STATE/search-text-filled" ]; then echo 0; else echo 1; fi ;;
        apply) out=$(art notifications:search-text 2>&1) || die "notifications:search-text thất bại: $(tail -2 <<< "$out")"
            echo "     $(tail -1 <<< "$out")"
            date '+%F %T' > "$STATE/search-text-filled" ;;
        verify) out=$(art notifications:search-text 2>&1 | tail -1)
            expect "chạy lại lệnh điền dòng tìm thông báo không còn gì để ghi (#676)" "$(grep -c 'Wrote the search text of 0 notification' <<< "$out" || true)" "1" ;;
    esac
}
release_smoke() {
    local r
    # #676: the fill command is in the running code
    r=$(art list --raw 2>&1 | grep -c '^notifications:search-text ' || true)
    expect "lệnh điền dòng tìm thông báo có trong bản chạy (#676)" "$r" "1"
    # earlier releases stay as they were
    local html code
    html=$(curl -s -m 20 "https://$HOST/?m6=$RANDOM")
    expect "cỡ chữ trong menu điện thoại vẫn là nút trượt (#673)" "$(grep -c 'class="sf-pill sf-pill--fs"' <<< "$html" || true)" "1"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/khong-co-trang-nay-$RANDOM")
    expect "địa chỉ không có trả 404 (giữ như cũ)" "$code" "404"
}
