# Release file: prod 2e829a14 → 54ca4768 (main on 27/11). Data and one-off commands only; the
# engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/2e829a14-54ca4768.sh
# Range:
#   #1040 a record of each website's mail (mail_logs, by month) and a daily limit on the shared outbox
#         (mail_daily; plan limit mail.platform_daily = 30, ordinary mail held past ten times it; security
#         and contact-form mail never held). Two migrations, two scheduled commands, one optional .env key.
#   #1042 release file.
# shellcheck shell=bash disable=SC2034
OLD=2e829a1415772460780275522fac0385e9732861
TARGET=54ca47680b8bdc2c55a620e8e37ce95ae252c271
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /ho-so-nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

DECLARED=(migration schedule)
MIGRATIONS=(2100_01_01_000000_create_mail_logs_and_mail_daily 2100_01_01_000100_seed_mail_daily_limit_into_plans)
declare -gA MIG_OK=(
    [2100_01_01_000000_create_mail_logs_and_mail_daily]="two new empty tenant tables: mail_logs (partitioned by UTC month, RLS ENABLE+FORCE, app_user SELECT/INSERT only) and mail_daily (FK sites CASCADE, RLS FORCE); a NOLOGIN NOBYPASSRLS role mail_log_owner owns the SECURITY DEFINER month functions (search_path pinned); raw SQL for partitions, policies, grants and functions; runs outside a transaction so each month ATTACH has its own 2s lock_timeout, the tables step under 5s; skips when mail_logs exists; no existing table altered, no data written"
    [2100_01_01_000100_seed_mail_daily_limit_into_plans]="one plan_features row per plan for mail.platform_daily = 30, INSERT … ON CONFLICT DO NOTHING (a plan that already has the key keeps its value); plans is a handful of rows"
)
declare -gA PGB_SKIP=()
SCHEDULE_ADDED=(mail:months mail:prune)
SCHEDULE_REMOVED=()
# #1040: no platform-wide ceiling on the shared outbox unless the operator sets one (platform setting or this key).
ENV_DEFAULT_OK=(MAIL_PLATFORM_DAILY_CAP)
SCHEMA_REMOVED_OK=()
BUILD_DYNAMIC=()
BUILD_MARKERS=()

ONEOFFS_PRE=()
ONEOFFS=()

release_smoke() {
    local n
    n=$(docker logs --since 20m "${PREFIX}app" 2>&1 | grep -cE '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' || true)
    note "lỗi trong nhật ký ứng dụng 20 phút qua: ${n:-0}"
    [ "${n:-0}" = 0 ] || docker logs --since 20m "${PREFIX}app" 2>&1 | grep -E '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' | tail -n 5 | cut -c1-300
    # the post list still answers (a signed-out visitor is sent to sign in)
    n=$(curl -s -o /dev/null -w '%{http_code}' "https://${HOST}/admin/content" || true)
    expect "danh sách bài viết av1 chuyển sang đăng nhập" "${n}" "302"
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
    # the new command is registered
    read -r n < <(docker exec "${PREFIX}app" php artisan list --raw 2>/dev/null | grep -c '^media:refs-by-id ' || true)
    expect "lệnh media:refs-by-id có trong danh sách lệnh" "${n}" "1"
    # the careers page still lists job cards
    n=$(curl -s "https://${HOST}/tuyen-dung" | grep -ci 'class="[^"]*job' || true)
    [ "${n:-0}" -ge 1 ] && n=có || n=không
    expect "trang tuyển dụng còn thẻ việc làm" "${n}" "có"
}
