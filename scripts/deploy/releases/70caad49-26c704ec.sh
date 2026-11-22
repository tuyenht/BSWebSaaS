# Release file: prod 70caad49 → 26c704ec (main on 23/11). Data and one-off commands only; the
# engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/70caad49-26c704ec.sh
# Range:
#   #901 the previous release file.
#   #902 a per-site limit on which network addresses may open the admin (site_settings rows, no schema; off by default).
# No migration. No permission change. No new .env key. No schedule change.
# shellcheck shell=bash disable=SC2034
OLD=70caad496851aefc8bf96ea74edf4ec66d1a32c2
TARGET=26c704ec2f34b78888bcdf00e10a11a7c387bbc0
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
    # #902: the address limit is off on av1, so the admin sign-in page still answers (the engine's PAGES line checks /admin/login = 200 too)
    n=$(curl -s -o /tmp/.smoke-login.$$ -w '%{http_code}' "https://${HOST}/admin/login" || true); rm -f /tmp/.smoke-login.$$
    expect "trang đăng nhập quản trị av1 không bị giới hạn địa chỉ chặn" "${n}" "200"
    # #899: av1 is not in maintenance (the home page answers 200, not 503)
    # the whole header block is read (pages send 103 Early Hints first; head would close the pipe and curl exits 23)
    n=$(curl -s -D - "https://${HOST}/" | grep -cE '^HTTP/[0-9.]+ 200' || true)
    expect "trang chủ av1 không ở chế độ bảo trì" "${n:-0}" "1"
    # #900: Cloudflare never serves a stored page to a visitor carrying the session cookie
    n=$(curl -s -D - -H 'Cookie: bswebsaas-session=x' "https://${HOST}/" | grep -i '^cf-cache-status' | tr -d '\r' | awk '{print toupper($2)}' || true)
    expect "Cloudflare không trả bản lưu sẵn cho người có phiên đăng nhập (${n:-trống})" "$([ "${n}" != HIT ] && echo 1 || echo 0)" "1"
    # #900: embed code is off on av1, so the storefront script policy stays strict (printed)
    note "chính sách script trang chủ av1: $(curl -s -D - "https://${HOST}/" | grep -i '^content-security-policy' | tr ';' '\n' | grep -i 'script-src' | awk 'NR==1' | cut -c1-160)"
    note "cài đặt bảo trì / mã nhúng / giới hạn địa chỉ quản trị đã lưu ở av1: $(q "SELECT coalesce(string_agg(ss.key, ' ' ORDER BY ss.key), '-') FROM site_settings ss JOIN domains d ON d.site_id = ss.site_id WHERE d.hostname = 'av1.baoson.net' AND ss.key IN ('maintenance', 'embed_code', 'ip_limit')")"
}
