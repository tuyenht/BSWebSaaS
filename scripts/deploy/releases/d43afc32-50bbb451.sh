# Release file: prod d43afc32 → 50bbb451 (main on 22/11). Data and one-off commands only; the
# engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/d43afc32-50bbb451.sh
# Range:
#   #896 the previous release file.
#   #897 high contrast as each member's own preference in the admin (tokens only, no schema).
# No migration. No permission change. No new .env key. No schedule change.
# shellcheck shell=bash disable=SC2034
OLD=d43afc323ad76a9418771b02f78dcfb6773c161a
TARGET=50bbb45182800d0f9449fe9e94d5c29e7cc40b2b
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
    local n
    # app errors since the reload (not a stop: printed for the report, the lines shown when any)
    n=$(docker logs --since 20m "${PREFIX}app" 2>&1 | grep -cE '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' || true)
    note "lỗi trong nhật ký ứng dụng 20 phút qua: ${n:-0}"
    [ "${n:-0}" = 0 ] || docker logs --since 20m "${PREFIX}app" 2>&1 | grep -E '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' | tail -n 5 | cut -c1-300
    # the contact page still shows its form
    n=$(curl -s "https://${HOST}/lien-he" | grep -c 'wire:submit\|<form' || true)
    expect "trang liên hệ vẫn có biểu mẫu" "$([ "${n:-0}" -gt 0 ] && echo 1 || echo 0)" "1"
    # #876/#883: the shared permission version token (printed, not a stop: written on the first permission change)
    note "mã phiên bản quyền dùng chung: $(docker exec "${PREFIX}valkey" valkey-cli --scan --pattern '*permission*version*' 2>&1 | head -n 3 | tr '\n' ' ')"
    # #887/#888: the roles screen answers a signed-out visitor with a redirect to sign-in
    n=$(curl -s -o /dev/null -w '%{http_code}' "https://${HOST}/admin/roles" || true)
    note "«Nhóm quyền» khi chưa đăng nhập trả mã: ${n}"
    # the owner asked whether An Vinh's admin can reach every screen: read-only counts, no e-mail printed
    note "thành viên của av1 (chủ website nói hiện có 1): $(q "SELECT count(DISTINCT mhr.model_id) FROM model_has_roles mhr JOIN domains d ON d.site_id = mhr.site_id WHERE d.hostname = 'av1.baoson.net'")"
    note "nhóm của các thành viên av1: $(q "SELECT coalesce(string_agg(DISTINCT r.name, ', '), '-') FROM model_has_roles mhr JOIN roles r ON r.id = mhr.role_id JOIN domains d ON d.site_id = mhr.site_id WHERE d.hostname = 'av1.baoson.net'")"
    note "phần được bật của av1: $(q "SELECT coalesce(string_agg(sm.module_key || CASE WHEN sm.enabled THEN '' ELSE '(tắt)' END, ' ' ORDER BY sm.module_key), '-') FROM site_modules sm JOIN domains d ON d.site_id = sm.site_id WHERE d.hostname = 'av1.baoson.net'")"
    note "ngôn ngữ đang bật của av1 (thanh ngôn ngữ chỉ hiện khi hơn 1): $(q "SELECT coalesce(string_agg(DISTINCT sl.locale, ' '), '-') FROM site_locales sl JOIN domains d ON d.site_id = sl.site_id WHERE sl.enabled AND d.hostname = 'av1.baoson.net'")"
    note "loại website av1 (doanh nghiệp = mở mọi phần): $(q "SELECT coalesce(max(st.key), '-') FROM sites s JOIN site_types st ON st.id = s.site_type_id JOIN domains d ON d.site_id = s.id WHERE d.hostname = 'av1.baoson.net'")"
}
