# Release file: prod 35dc1267 → a7348ad5 (main on 25/11). Data and one-off commands only; the
# engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/35dc1267-a7348ad5.sh
# Range:
#   #945 the previous release file.
#   #944 quick sign-in screens for the platform and each website (new platform route /settings/social;
#        config/oauth.php reads two new, optional policy-page links).
#   #947 post options in the post editor: look and shown day (post layouts, sf-post.css).
#   #946 packs create content sections; An Vinh job posts get a «Tuyển dụng» section (pack file only,
#        no pack is applied here).
#   #943 media library file details in a drawer: new route /admin/media/files/{ulid}/download and a
#        lazy chunk admin-media-drawer.js.
# No migration, no schedule change, no permission or sanitizer change.
# shellcheck shell=bash disable=SC2034
OLD=35dc1267f9d68ff2e680374966eee4fdb3c3888f
TARGET=a7348ad5a4650c80058eeca92a024a0b0bcf9ba9
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /ho-so-nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

DECLARED=(content-pack)   # #946 sections.json for An Vinh: the engine only reports it; no pack is loaded here
MIGRATIONS=()
declare -gA MIG_OK=()
declare -gA PGB_SKIP=()
SCHEDULE_ADDED=()
SCHEDULE_REMOVED=()
# #944: the platform's privacy and terms links for the social sign-in settings. Unset on prod = null, and
# the settings simply do not offer them to copy (the platform has no such pages yet).
ENV_DEFAULT_OK=(OAUTH_PRIVACY_URL OAUTH_TERMS_URL)
SCHEMA_REMOVED_OK=()
# #943: the media drawer is loaded on demand from admin-lazy.js.
BUILD_DYNAMIC=(resources/js/admin-media-drawer.js)
# #947: the post's «Ảnh lớn đầu bài» look.
BUILD_MARKERS=("resources/css/sf-post.css post-cover--hero")

ONEOFFS_PRE=()
ONEOFFS=()

release_smoke() {
    local n
    n=$(docker logs --since 20m "${PREFIX}app" 2>&1 | grep -cE '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' || true)
    note "lỗi trong nhật ký ứng dụng 20 phút qua: ${n:-0}"
    [ "${n:-0}" = 0 ] || docker logs --since 20m "${PREFIX}app" 2>&1 | grep -E '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' | tail -n 5 | cut -c1-300
    # #943 media drawer: the library still answers (a signed-out visitor is sent to sign in)
    n=$(curl -s -o /dev/null -w '%{http_code}' "https://${HOST}/admin/media" || true)
    expect "thư viện ảnh av1 chuyển sang đăng nhập" "${n}" "302"
    n=$(curl -s -o /dev/null -w '%{http_code}' "https://${HOST}/admin/login" || true)
    expect "trang đăng nhập quản trị av1 trả lời" "${n}" "200"
    # #946 job posts and #947 post layouts: the listing pages answer
    n=$(curl -s -o /dev/null -w '%{http_code}' "https://${HOST}/tuyen-dung" || true)
    expect "trang Tuyển dụng av1 trả lời" "${n}" "200"
    n=$(curl -s -o /dev/null -w '%{http_code}' "https://${HOST}/tin-tuc" || true)
    expect "trang Tin tức av1 trả lời" "${n}" "200"
    n=$(curl -s -D - -o /dev/null "https://${HOST}/" | grep -cE '^HTTP/[0-9.]+ 200' || true)
    expect "trang chủ av1 trả lời 200" "${n:-0}" "1"
}
