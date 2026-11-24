# Release file: prod 1afffe19 → afc74dc5 (main on 23/11). Data and one-off commands only; the
# engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/1afffe19-afc74dc5.sh
# Range:
#   #909 the previous release file.
#   #910 «Khách liên hệ»: how long a website keeps a lead (site_settings row, default 24 months) and the nightly
#        leads:purge-old (old leads + spam over 30 days). Its start guard is 24/11/2026 and a new rule waits 7 days,
#        so nothing is deleted before 01/12/2026.
# No migration. No permission change. No new .env key.
# shellcheck shell=bash disable=SC2034
OLD=1afffe19dcbf63d3e1122bdcdc5736a53001e2b7
TARGET=afc74dc5c77d594d45319f50438bf0e2edefb788
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /ho-so-nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

# Classes that need a human look and are in this range (the engine stops if this differs from what it finds).
DECLARED=(schedule)

MIGRATIONS=()
declare -gA MIG_OK=()
declare -gA PGB_SKIP=()
# #910: the nightly purge of leads past their site's retention and of old spam
SCHEDULE_ADDED=(leads:purge-old)
SCHEDULE_REMOVED=()
ENV_DEFAULT_OK=()
SCHEMA_REMOVED_OK=()
BUILD_DYNAMIC=()
BUILD_MARKERS=()

ONEOFFS_PRE=()
ONEOFFS=()

release_smoke() {
    local n
    # app errors since the reload (not a stop: printed for the report, the lines shown when any)
    n=$(docker logs --since 20m "${PREFIX}app" 2>&1 | grep -cE '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' || true)
    note "lỗi trong nhật ký ứng dụng 20 phút qua: ${n:-0}"
    [ "${n:-0}" = 0 ] || docker logs --since 20m "${PREFIX}app" 2>&1 | grep -E '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' | tail -n 5 | cut -c1-300
    # the contact page still shows its form
    n=$(curl -s "https://${HOST}/lien-he" | grep -c 'wire:submit\|<form' || true)
    expect "trang liên hệ vẫn có biểu mẫu" "$([ "${n:-0}" -gt 0 ] && echo 1 || echo 0)" "1"
    # the admin sign-in page still answers (the engine's PAGES line checks /admin/login = 200 too)
    n=$(curl -s -o /tmp/.smoke-login.$$ -w '%{http_code}' "https://${HOST}/admin/login" || true); rm -f /tmp/.smoke-login.$$
    expect "trang đăng nhập quản trị av1 trả lời" "${n}" "200"
    # #910: no site has saved a retention rule yet (printed), and the oldest lead and the spam over 30 days say what the first purge on 01/12 would take
    note "thời hạn lưu giữ đã lưu (số website): $(q "SELECT count(*) FROM site_settings WHERE key = 'lead_retention'")"
    note "khách liên hệ cũ nhất trên prod: $(q "SELECT coalesce(min(created_at)::date::text, '-') FROM form_submissions")"
    note "thư rác quá 30 ngày trên prod: $(q "SELECT count(*) FROM form_submissions WHERE status = 'spam' AND created_at < now() - interval '30 days'")"
    # the home page answers 200 (the whole header block is read: pages send 103 Early Hints first)
    n=$(curl -s -D - "https://${HOST}/" | grep -cE '^HTTP/[0-9.]+ 200' || true)
    expect "trang chủ av1 trả lời 200" "${n:-0}" "1"
}
