# Release file: prod 461ae173 → b6ef1f57 (main on 26/11). Data and one-off commands only; the
# engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/461ae173-b6ef1f57.sh
# Range:
#   #991 media:rekey-site moves a website's files filed before website codes to {code}/{file} and
#        rewrites every stored reference; one migration (closes the seeded folders and collections the
#        site-code migration left open); seeders file their images under the website's code.
#   #992 the previous release file.
# One-off: the only website with old-layout files on prod (checked 26/11: site 1, code zpvjzx, 78 media
# rows under an-vinh/ and co-khi-chinh-xac-an-vinh/) is moved right after the reload. Old objects stay
# on R2, so addresses already shared keep loading (docs/runbooks/prod-deploy.md, the rekey steps).
# shellcheck shell=bash disable=SC2034
OLD=461ae173f9e890dc5800c6fb5861df499ebcfe50
TARGET=b6ef1f5780926d879d9af57098985ba2b2f12b54
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /ho-so-nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

# Classes that need a human look and are in this range (the engine stops if this differs from what it finds).
DECLARED=(migration)

MIGRATIONS=(2098_01_01_000000_close_open_media_folders)
declare -gA MIG_OK=(
    [2098_01_01_000000_close_open_media_folders]="One transaction, SET LOCAL lock_timeout 5s; LOCK media SHARE ROW EXCLUSIVE (blocks media writes for the few seconds of its scans at today's size). Multi-site: the seeded folders (an-vinh, cwv) and the collections (library, cover, og, product) stop accepting keys; a website holding rows under one becomes its owner (legacy_key), so those rows stay valid; owner rows with no media row behind them are released, the folder stays frozen. Keeps 2097's fail-closed guard. Re-runnable; down() reopens collections and unowned seeded folders. Reviewed by Opus 5.5 and Fable 5.2"
)
declare -gA PGB_SKIP=()
SCHEDULE_ADDED=()
SCHEDULE_REMOVED=()
ENV_DEFAULT_OK=()
SCHEMA_REMOVED_OK=()
BUILD_DYNAMIC=()
BUILD_MARKERS=()

ONEOFFS_PRE=()
ONEOFFS=(rekey_site1)

REKEY_SITE=zpvjzx

# Files still to move + content rows still naming an old key (the dry run writes nothing).
rekey_left() {
    local out files rows
    out=$(art media:rekey-site "$REKEY_SITE" 2>&1) || die "media:rekey-site $REKEY_SITE (chạy thử) thất bại: $(tail -3 <<< "$out")"
    files=$(grep -oE '^Files to move: [0-9]+' <<< "$out" | grep -oE '[0-9]+$' || true)
    [[ "$files" =~ ^[0-9]+$ ]] || die "media:rekey-site $REKEY_SITE không in «Files to move»: $(tail -3 <<< "$out")"
    rows=$(awk -F'|' 'NF >= 7 && $3 ~ /^ *[0-9]+ *$/ { s += $3 } END { print s + 0 }' <<< "$out")
    grep -E 'skipped|unmatched' <<< "$out" | head -n 10 | sed 's/^/     /' >&2 || true
    echo $(( files + rows ))
}

# #991: move site 1's old-layout files to {code}/ and rewrite every stored reference (resumable).
oneoff_rekey_site1() {
    local out n
    case "$1" in
        preview) rekey_left ;;
        apply) out=$(art media:rekey-site "$REKEY_SITE" --apply 2>&1) || die "media:rekey-site --apply thất bại: $(tail -4 <<< "$out")"
            grep -E '^(Files to move|Objects copied|Keys moved)|missing|skipped|unmatched' <<< "$out" | head -n 15 | sed 's/^/     /' ;;
        verify)
            n=$(docker exec "${PREFIX}pg" sh -c 'psql -U "$POSTGRES_USER" -d bswebsaas -tAc "SELECT count(*) FROM media m JOIN sites s ON s.id = m.site_id WHERE split_part(m.storage_key, \$\$/\$\$, 1) <> s.code"' 2>/dev/null | tr -d '[:space:]' || true)
            expect "tệp của website nằm ngoài mã website (#991)" "$n" "0" ;;
    esac
}

release_smoke() {
    local n img
    n=$(docker logs --since 20m "${PREFIX}app" 2>&1 | grep -cE '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' || true)
    note "lỗi trong nhật ký ứng dụng 20 phút qua: ${n:-0}"
    [ "${n:-0}" = 0 ] || docker logs --since 20m "${PREFIX}app" 2>&1 | grep -E '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' | tail -n 5 | cut -c1-300
    # #991 a picture of the av1 home page loads, and the seed file (old address) still loads
    img=$(curl -s "https://${HOST}/" | grep -oE 'https://files\.baoson\.net/[^"?]+\.(webp|jpg|png)' | head -n 1 || true)
    note "ảnh đầu trang chủ av1: ${img:-không thấy}"
    n=$(curl -s -o /dev/null -w '%{http_code}' "${img:-https://files.baoson.net/none.webp}" || true)
    expect "ảnh trang chủ av1 tải được" "${n}" "200"
    n=$(curl -s -o /dev/null -w '%{http_code}' "https://files.baoson.net/an-vinh/content/about-doi-ngu-a7b78a0a6ff7.jpg" || true)
    expect "địa chỉ ảnh cũ vẫn tải (bản cũ giữ trên R2)" "${n}" "200"
    n=$(curl -s -o /dev/null -w '%{http_code}' "https://${HOST}/admin/login" || true)
    expect "trang đăng nhập quản trị av1 trả lời" "${n}" "200"
    n=$(curl -s -D - -o /dev/null "https://${HOST}/" | grep -cE '^HTTP/[0-9.]+ 200' || true)
    expect "trang chủ av1 trả lời 200" "${n:-0}" "1"
}
