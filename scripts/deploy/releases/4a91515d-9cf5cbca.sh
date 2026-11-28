# Release file: prod 4a91515d → 9cf5cbca (main on 28/11). Data and one-off commands only; the
# engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/4a91515d-9cf5cbca.sh
# Range:
#   feat(media): count each website's stored bytes and show the storage meter (#1092)
#   chore(deploy): release file ac31eb1f → 4a91515d (#1095)
#   One migration: the new website table site_counters (RLS, forced). One new nightly job
#   (counters:reconcile) and one new .env key with a safe default (the upload limit stays off).
#   The totals of the websites that already hold files are filled once after the reload.
# shellcheck shell=bash disable=SC2034
OLD=4a91515dc3ee4426d6b818a6b226db6782017d04
TARGET=9cf5cbca2f2a18da7896566b07787ed335263844
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /ho-so-nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

DECLARED=(migration schedule)
MIGRATIONS=(2103_01_01_000000_create_site_counters)
declare -gA MIG_OK=(
    [2103_01_01_000000_create_site_counters]="a new, empty table (CREATE TABLE IF NOT EXISTS site_counters with its primary key, UNIQUE (site_id, metric, subject_id) and a CHECK on metric), row security enabled and forced with one fail-closed policy on site_id, and the four table rights for app_user. No existing table is rewritten; the foreign key to sites takes a brief lock under lock_timeout 5s. The code that reads and writes it ships in the same release, after the migration."
)
declare -gA PGB_SKIP=()
SCHEDULE_ADDED=(counters:reconcile)
SCHEDULE_REMOVED=()
ENV_DEFAULT_OK=(MEDIA_STORAGE_ENFORCE)   # default false: every upload is counted, none is refused
SCHEMA_REMOVED_OK=()
BUILD_DYNAMIC=()
BUILD_MARKERS=()

ONEOFFS_PRE=()
ONEOFFS=(site_counters)

# Fill the stored-bytes totals of the websites that already hold files, once; the nightly run keeps them
# after that. Safe to re-run: only totals that differ are written, so a second run corrects 0.
oneoff_site_counters() {
    local out
    case "$1" in
        preview) if [ -s "$STATE/site-counters-filled" ]; then echo 0; else echo 1; fi ;;
        apply) out=$(art counters:reconcile 2>&1) || die "counters:reconcile thất bại: $(tail -2 <<< "$out")"
            echo "     $(tail -1 <<< "$out")"
            date '+%F %T' > "$STATE/site-counters-filled" ;;
        verify) out=$(art counters:reconcile 2>&1 | tail -1)
            expect "chạy lại lệnh đếm dung lượng không còn gì để sửa" "$(grep -c 'corrected 0\.' <<< "$out" || true)" "1"
            note "số dòng tổng dung lượng đã có trong site_counters: $(q "SELECT count(*) FROM site_counters WHERE metric = 'media.bytes'")" ;;
    esac
}

release_smoke() {
    local n
    n=$(docker logs --since 20m "${PREFIX}app" 2>&1 | grep -cE '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' || true)
    note "lỗi trong nhật ký ứng dụng 20 phút qua: ${n:-0}"
    [ "${n:-0}" = 0 ] || docker logs --since 20m "${PREFIX}app" 2>&1 | grep -E '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' | tail -n 5 | cut -c1-300
    # the post list still answers (a signed-out visitor is sent to sign in)
    n=$(curl -s -o /dev/null -w '%{http_code}' "https://${HOST}/admin/content" || true)
    expect "danh sách bài viết av1 chuyển sang đăng nhập" "${n}" "302"
    # the email settings screen still answers (a signed-out visitor is sent to sign in)
    n=$(curl -s -o /dev/null -w '%{http_code}' "https://${HOST}/admin/settings/email" || true)
    expect "màn cài đặt email av1 chuyển sang đăng nhập" "${n}" "302"
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
