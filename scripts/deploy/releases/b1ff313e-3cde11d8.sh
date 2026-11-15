# Release file: prod b1ff313e → 3cde11d8 (main on 15/11). Data and one-off commands only; the engine
# (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/b1ff313e-3cde11d8.sh
# Range:
#   #723/#724 release files of the two previous deploys (no code);
#   #722 Admin › the post and page editor gets the signed board-07 frame: crumb, sticky save bar with the
#        save state (a bottom bar on narrow screens), the «Đã đăng — Xem trên website» band read from the
#        language's real route, two columns of cards, a leave dialog that counts unsaved changes on the
#        server (blocks included) and «Bỏ thay đổi»; after the first «Lưu nháp» the address becomes the
#        edit address. Three unused lang keys removed (entries.draft_saved / edit_title / create_title).
# No migration, no library change, no .env key, no schedule change. CSS/JS: admin-editor.js (no new
# string literal; a lazy chunk, not a Vite input) and Tailwind classes written in app/Modules views, so no
# build string is possible; step 6's build and its manifest check of every input are the evidence.
# Also carries the two #721 translation checks its run could not reach (its step 8 stopped early). Its
# head-count check is left out: a website may hold more people than its limit by design (plan lowered,
# owner restored), so that line could stop a sound run.
# shellcheck shell=bash disable=SC2034
OLD=b1ff313e94610a6121fbd6d184f0cc7ee1f0024a
TARGET=3cde11d8405d56d3f650eb451e41fa14e9527996
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
    local html code r ua
    ua='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
    # #722: the editor's new words are translated and the edit address still exists
    r=$(art tinker --execute='echo __("entries.ed.title_post") !== "entries.ed.title_post" && __("entries.ed.title_page") !== "entries.ed.title_page" ? 1 : 0;' 2>&1 | tail -1 || true)
    expect "chữ mới của trình soạn bài đã có trong bản dịch (#722)" "$r" "1"
    expect "đường sửa bài vẫn có (#722)" "$(art route:list --name=admin.entries.edit 2>&1 | grep -c 'content/{ulid}/edit' || true)" "1"
    # #721, carried from its run
    r=$(art tinker --execute='echo __("rbac.invitation.error_user_limit") !== "rbac.invitation.error_user_limit" && __("rbac.invitation.error_user_limit_join") !== "rbac.invitation.error_user_limit_join" ? 1 : 0;' 2>&1 | tail -1 || true)
    expect "hai câu báo đủ số người đã có trong bản dịch (#721)" "$r" "1"
    # earlier releases stay as they were
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/admin/users")
    expect "trang Thành viên chuyển người chưa đăng nhập đi đăng nhập (giữ như cũ)" "$code" "302"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/admin/login")
    expect "trang đăng nhập quản trị mở được" "$code" "200"
    html=$(curl -s --compressed -m 20 -A "$ua" -H 'accept: text/html' "https://$HOST/?m15=$RANDOM")
    expect "trang chủ có đúng một chỗ giữ beacon (#688)" "$(grep -o 'data-sf-beacon=' <<< "$html" | wc -l | tr -d ' ')" "1"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/khong-co-trang-nay-$RANDOM")
    expect "địa chỉ không có trả 404 (giữ như cũ)" "$code" "404"
}
