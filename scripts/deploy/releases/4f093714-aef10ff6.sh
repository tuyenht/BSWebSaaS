# Release file: prod 4f093714 → aef10ff6 (main on 09/11, after #461). Data and one-off commands only; the engine
# (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/4f093714-aef10ff6.sh
# Range: #460 (settings pages: the (i) note with a per-account "Đã hiểu", a compact phone layout) and #461
# (storefront: one gap between the contact cards and the form, a page layout with a numbered table of
# contents). Code, views, styles, texts and two routes only: no migration, no new job, no library change.
# shellcheck shell=bash disable=SC2034
OLD=4f093714cb7fe00c413d134bac94f46057200bd5
TARGET=aef10ff60069cbea74b708bd978a695dd5f977ee
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /san-pham /du-an /tin-tuc /doi-tac /lien-he /chinh-sach-bao-mat /nang-luc /admin/login)

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

SITE_SQL="(SELECT site_id FROM domains WHERE lower(hostname) = '$HOST' LIMIT 1)"
# Up to 10 published posts of the site in its default language, newest first
POSTS_SQL="SELECT r.path FROM routes r JOIN entries e ON e.id = r.entry_id
    JOIN site_locales l ON l.site_id = r.site_id AND l.locale = r.locale AND l.is_default
    WHERE r.site_id = $SITE_SQL
      AND e.type = 'post' AND e.status = 'published' AND e.deleted_at IS NULL
      AND (e.published_at IS NULL OR e.published_at <= now())
    ORDER BY e.published_at DESC NULLS LAST, e.id DESC LIMIT 10"
release_pages() { # a post page (#461 split the post band's days out of PostDetail::band)
    local p
    p=$(first_open "$POSTS_SQL"); [ -n "$p" ] || die "không có bài đã đăng nào của $HOST mở được (200)"; echo "$p"
}
csp_of() { # the Content-Security-Policy header of a storefront page
    docker exec "${PREFIX}app" curl -s -m 10 -o /dev/null -D - -H "Host: $HOST" "http://localhost:80$1" \
        | tr -d '\r' | sed -nE 's/^[Cc]ontent-[Ss]ecurity-[Pp]olicy:[[:space:]]*//p' | head -n 1
}
release_before() {
    local x
    x=$(csp_of /); [ -n "$x" ] || die "trang chủ không gửi Content-Security-Policy: không có gì để so ở bước 8"
    printf '%s\n' "$x" > "$STATE/csp-before"; echo "OK   trang chủ có Content-Security-Policy (${#x} ký tự)"
}
ONEOFFS_PRE=()
ONEOFFS=()
release_smoke() {
    local p
    # #460: the per-account "Đã hiểu" of the settings note posts here (website admin and platform)
    for p in admin.hint.dismiss platform.hint.dismiss; do
        expect "đường dẫn $p" "$(art route:list --name="$p" --json | jq "[.[] | select(.name == \"$p\")] | length")" "1"
    done
    need_state csp-before 0
    expect "Content-Security-Policy của trang chủ (giống trước triển khai)" "$(csp_of /)" "$(cat "$STATE/csp-before")"
}
release_rollback() { # data the old code (4f093714) cannot read
    echo "     \"Đã hiểu\" của ghi chú cài đặt lưu theo tài khoản: bản cũ không đọc (vô hại, giữ lại)."
}
