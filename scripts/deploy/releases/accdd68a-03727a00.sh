# Release file: prod accdd68a → 03727a00 (main on 13/11, after #566, #541, #567). Data and one-off commands only;
# the engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/accdd68a-03727a00.sh
# Range: #566 (release file kept), #541 (admin screen Khách liên hệ: one screen with the list and the lead being
# read, keyset cursor on (created_at, ulid), status changes with undo; the old Detail component and its view are
# removed and /admin/forms/{ulid} opens the same screen; the route no longer carries `can:forms.view`, the component
# asks forms.view / forms.manage on every request and shows its own no-permission and plan cards; new lang keys in
# vi/en/ja/zh), #567 (storefront: icon-head cards two a row on a phone put the icon above the title). Code, views,
# lang, routes and one CSS file: no migration, no new job, no .env key, no library change, no content pack.
# Run 13/11 01:31 (log plans/reports/deploy-runs/accdd68-03727a00-run-20261113-013119.log): steps 0-7 and every engine
# check of step 8 passed; release_smoke stopped at the #541 line because `read` returned 1 on curl output with no
# newline (set -e). The line now ends the -w format with \n (this file). The engine refuses a changed file on resume, as
# designed, so the rest of release_smoke was run by hand over public HTTPS (same expects, all as wanted) and prod's
# HEAD read as 03727a00; the two container checks of #541 are covered by that HEAD and steps 5-7.
# shellcheck shell=bash disable=SC2034
OLD=accdd68a6b0b1ae486b8ca66ebd3db691f75c018
TARGET=03727a00485229563ee807810424d1a644e3ff49
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
# #567 adds no string that is the same in the source and the minified build; release_smoke checks the built CSS.
BUILD_MARKERS=()

ONEOFFS_PRE=()
ONEOFFS=()
release_smoke() {
    local p html css code loc
    # #541: the leads screen and a lead's address still ask a signed-out visitor to sign in (no 500, no page)
    for p in /admin/forms /admin/forms/01ARZ3NDEKTSV4RRFFQ69G5FAV; do
        read -r code loc < <(curl -s -o /dev/null -m 20 -w '%{http_code} %{redirect_url}\n' "https://$HOST$p")
        expect "$p chuyển khách chưa đăng nhập sang trang đăng nhập (#541)" "$code ${loc##*/admin/}" "302 login"
    done
    # #541 is in the running code: the screen's new text key, and the old detail view is gone
    expect "chữ mới của màn Khách liên hệ có trong bản đang chạy (#541)" \
        "$(docker exec "${PREFIX}app" grep -c '"leads.toast_spam"' lang/vi.json || true)" "1"
    expect "màn chi tiết cũ đã bỏ (#541)" \
        "$(docker exec "${PREFIX}app" sh -c 'ls app/Modules/Admin/Livewire/Forms/Detail.php 2>/dev/null | wc -l' || true)" "0"
    # #567: the careers page links the list stylesheet, and its built file stacks the icon-head card head on a phone
    html=$(curl -s -m 20 "https://$HOST/tuyen-dung?hc=$RANDOM")
    css=$(grep -oE '/build/assets/sf-lists-[^"]+\.css' <<< "$html" | head -1 || true)
    expect "CSS danh sách xếp biểu tượng lên trên tiêu đề thẻ trên điện thoại (#567)" \
        "$([ -n "$css" ] && curl -s -m 20 "https://$HOST$css" | grep -c 'sf-grid--4p>.sf-hcard>.sf-hcard__head' || true)" "1"
    # earlier releases stay as they were
    for p in / /tin-tuc /jig-kiem-tap-lo-vf7; do
        html=$(curl -s -m 20 "https://$HOST$p?img=$RANDOM" | tr '\n' ' ')
        expect "trang $p có đúng 1 ảnh ưu tiên cao (#558)" "$({ grep -o 'fetchpriority="high"' <<< "$html" || true; } | wc -l)" "1"
    done
    html=$(curl -s -m 20 "https://$HOST/?fc=$RANDOM" | tr '\n' ' ')
    expect "trang chủ có cụm nút tròn cho màn từ 768px (#563)" "$({ grep -oE '<nav class="sf-fc sf-fc--[a-z]+ sf-fc--wide"' <<< "$html" || true; } | wc -l)" "1"
    html=$(curl -s -m 20 "https://$HOST/?css=$RANDOM")
    expect "trang chủ gắn đúng 1 mã đo Cloudflare (#548, #549)" "$(grep -oF 'src="https://static.cloudflareinsights.com/beacon.min.js' <<< "$html" | wc -l)" "1"
    expect "trang chủ cho phép đệm ở Cloudflare (#532)" "$(curl -sI -m 20 "https://$HOST/" | grep -i '^cache-control:' | grep -c 's-maxage=300' || true)" "1"
    expect "trang chủ không đặt cookie (#532)" "$(curl -sI -m 20 "https://$HOST/" | grep -ci '^set-cookie:' || true)" "0"
    expect "giả tên miền qua X-Forwarded-Host không đổi được website (#533)" "$(curl -s -m 20 -H 'X-Forwarded-Host: website.baoson.net' "https://$HOST/?xfh=$RANDOM" | grep -c 'website.baoson.net/login' || true)" "0"
}
release_rollback() { # data the old code (accdd68a) cannot read
    echo "     Không có: bản này đổi màn quản trị Khách liên hệ (chỉ đọc và đổi trạng thái liên hệ, giá trị trạng thái như cũ), chữ trong trang quản trị và một tệp CSS; không đổi bảng, không đổi cách lưu dữ liệu."
}
