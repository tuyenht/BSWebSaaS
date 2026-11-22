# Release file: prod 50bbb451 → 70caad49 (main on 23/11). Data and one-off commands only; the
# engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/50bbb451-70caad49.sh
# Range:
#   #898 the previous release file.
#   #899 website maintenance mode under Settings › Security & Advanced (site_settings rows, no schema).
#   #900 extra embed code under the same screen (site_settings rows, no schema; off by default).
# No migration. No permission change. No new .env key. No schedule change.
# shellcheck shell=bash disable=SC2034
OLD=50bbb45182800d0f9449fe9e94d5c29e7cc40b2b
TARGET=70caad496851aefc8bf96ea74edf4ec66d1a32c2
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
    # #899: av1 is not in maintenance (the home page answers 200, not 503)
    n=$(curl -s -D - "https://${HOST}/" | head -n 1 | tr -d '\r')
    expect "trang chủ av1 không ở chế độ bảo trì" "$(echo "$n" | grep -c ' 200')" "1"
    # #900: Cloudflare never serves a stored page to a visitor carrying the session cookie
    n=$(curl -s -D - -H 'Cookie: bswebsaas-session=x' "https://${HOST}/" | grep -i '^cf-cache-status' | tr -d '\r' | awk '{print toupper($2)}')
    expect "Cloudflare không trả bản lưu sẵn cho người có phiên đăng nhập (${n:-trống})" "$([ "${n}" != HIT ] && echo 1 || echo 0)" "1"
    # #900: embed code is off on av1, so the storefront script policy stays strict (printed)
    note "chính sách script trang chủ av1: $(curl -s -D - "https://${HOST}/" | grep -i '^content-security-policy' | tr ';' '\n' | grep -i 'script-src' | head -n 1 | cut -c1-160)"
    note "cài đặt bảo trì / mã nhúng đã lưu ở av1: $(q "SELECT coalesce(string_agg(ss.key, ' ' ORDER BY ss.key), '-') FROM site_settings ss JOIN domains d ON d.site_id = ss.site_id WHERE d.hostname = 'av1.baoson.net' AND ss.key IN ('maintenance', 'embed_code')")"
}
