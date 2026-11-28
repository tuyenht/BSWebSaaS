# Release file: prod c90fb8e8 → d718a46d (main on 28/11). Data and one-off commands only; the
# engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/c90fb8e8-d718a46d.sh
# Range:
#   fix(email): keep service names whole in the «Nhà cung cấp khác» picker on narrow screens (#1108)
#   fix(locales): refuse a public language whose code a permalink section uses as its prefix (#1105)
#   chore(deploy): release file 8e538342 → c90fb8e8 (#1107)
#   Code, views and lang only. No migration, schedule, permission or .env change.
# shellcheck shell=bash disable=SC2034
OLD=c90fb8e83ee4bc79e0e7fbbd57110f07abbd313e
TARGET=d718a46dc6348050e22eac0161286ab2b2f5375d
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
    local n
    # the website-context core changed: how many requests PostgreSQL aborted since the reload (printed for
    # the report, not a stop: one can be a real failed statement that was rolled back as designed)
    note "cảnh báo «aborted the site transaction» 20 phút qua: $(docker logs --since 20m "${PREFIX}app" 2>&1 | grep -c 'aborted the site transaction' || true)"
    n=$(docker logs --since 20m "${PREFIX}app" 2>&1 | grep -cE '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' || true)
    note "lỗi trong nhật ký ứng dụng 20 phút qua: ${n:-0}"
    [ "${n:-0}" = 0 ] || docker logs --since 20m "${PREFIX}app" 2>&1 | grep -E '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' | tail -n 5 | cut -c1-300
    # the post list still answers (a signed-out visitor is sent to sign in)
    n=$(curl -s -o /dev/null -w '%{http_code}' "https://${HOST}/admin/content" || true)
    expect "danh sách bài viết av1 chuyển sang đăng nhập" "${n}" "302"
    # the email settings screen still answers (a signed-out visitor is sent to sign in)
    n=$(curl -s -o /dev/null -w '%{http_code}' "https://${HOST}/admin/settings/email" || true)
    expect "màn cài đặt email av1 chuyển sang đăng nhập" "${n}" "302"
    # the new address-format screen answers (a signed-out visitor is sent to sign in)
    n=$(curl -s -o /dev/null -w '%{http_code}' "https://${HOST}/admin/settings/permalinks" || true)
    expect "màn Đường dẫn av1 chuyển sang đăng nhập" "${n}" "302"
    n=$(curl -s -o /dev/null -w '%{http_code}' "https://${HOST}/admin/login" || true)
    expect "trang đăng nhập quản trị av1 trả lời" "${n}" "200"
    # a sign-in return with an unknown state goes to that website's own sign-in page;
    n=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' 'https://website.baoson.net/oauth/callback?state=x' || true)
    expect "đăng nhập mạng xã hội hết hạn về trang đăng nhập của website " "${n}" "302 https://website.baoson.net/login?oauth_error=state_mismatch"
    n=$(curl -s -o /dev/null -w '%{http_code}' 'https://website.baoson.net/login' || true)
    expect "trang đăng nhập website.baoson.net trả lời" "${n}" "200"
    # the capability profile's download button still links its PDF (#1027)
    n=$(curl -s "https://${HOST}/ho-so-nang-luc" | grep -c 'href="https://files.baoson.net/[^"]*\.pdf"' || true)
    [ "${n:-0}" -ge 1 ] && n=có || n=không
    expect "nút tải hồ sơ năng lực còn link PDF" "${n}" "có"
    # the home page still names a share image
    n=$(curl -s "https://${HOST}/" | grep -c 'property="og:image"' || true)
    [ "${n:-0}" -ge 1 ] && n=có || n=không
    expect "trang chủ còn thẻ og:image" "${n}" "có"
    # the careers page still lists job cards
    n=$(curl -s "https://${HOST}/tuyen-dung" | grep -ci 'class="[^"]*job' || true)
    [ "${n:-0}" -ge 1 ] && n=có || n=không
    expect "trang tuyển dụng còn thẻ việc làm" "${n}" "có"
}
