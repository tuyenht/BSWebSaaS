# Release file: prod 4c28da63 → 66231b7a (main on 19/11). Data and one-off commands only; the engine
# (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/4c28da63-66231b7a.sh
# Range:
#   #805 pages move in the Trang list's page tree: up/down, drag on a computer, «Chuyển đến…» to another
#        parent (at most 3 levels); moved sub-pages' old addresses redirect (301); «Hoàn tác» 10 s; tree lines
#        from 768; a flat list with «Thuộc: …» while searching or sorting; content packs take the page-tree lock.
#   #809 «Xem trước» stays first in a draft row's ⋮ while the row shows «Xem».
#   #808 one editor frame for posts, pages and products as the Admin Shell draws it (header, preview bar,
#        ⋮ with «Lưu nháp» and «Chuyển vào Thùng rác», phone child bar and bottom bar); texts in four languages.
#   #807 the previous release file.
# No migration, no library change, no new schedule, no new .env key. CSS/JS change (admin bundle).
# shellcheck shell=bash disable=SC2034
OLD=4c28da6380461614146babe3d3bb8e43636e560d
TARGET=66231b7a69a1b00be00df27c1837132a05fb4b51
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /ho-so-nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

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
    local html code ua n asset js
    ua='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
    html=$(curl -s --compressed -m 20 -A "$ua" "https://$HOST/admin/login")
    asset=$(grep -o 'build/assets/app-[A-Za-z0-9_-]*\.js' <<< "$html" | head -1)
    js=$(curl -s --compressed -m 20 -A "$ua" "https://$HOST/$asset")
    # #805: the page tree's move dialog is in the admin script
    n=$(grep -c 'entries-mv-q' <<< "$js" || true)
    expect "cây trang chuyển được trang (#805)" "$([ -n "$asset" ] && [ "${n:-0}" -ge 1 ] && echo 1 || echo 0)" "1"
    # #808: an editor that trashed its record says so on the list (toast handed to the next page)
    n=$(grep -c 'bs-toast-next' <<< "$js" || true)
    expect "khung trình sửa chung (#808)" "$([ "${n:-0}" -ge 1 ] && echo 1 || echo 0)" "1"
    # app errors since the reload (not a stop: printed for the report, the lines shown when any)
    n=$(docker logs --since 20m "${PREFIX}app" 2>&1 | grep -cE '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' || true)
    note "lỗi trong nhật ký ứng dụng 20 phút qua: ${n:-0}"
    [ "${n:-0}" = 0 ] || docker logs --since 20m "${PREFIX}app" 2>&1 | grep -E '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' | tail -n 5 | cut -c1-300
    # earlier: #806 the creator fill command is in the running code
    n=$(docker exec "${PREFIX}app" php artisan list --raw 2>/dev/null | grep -c '^site:fill-entry-authors ' || true)
    expect "có lệnh điền người tạo cho bài (#806)" "${n:-0}" "1"
    # earlier: #799 the admin preview route exists (a visitor without login is sent to sign in)
    code=$(curl -s -o /dev/null -m 20 -A "$ua" -w '%{http_code}' "https://$HOST/admin/content/01JZZZZZZZZZZZZZZZZZZZZZZZ/preview")
    expect "đường Xem trước bài viết có trong quản trị (#799)" "$code" "302"
}
