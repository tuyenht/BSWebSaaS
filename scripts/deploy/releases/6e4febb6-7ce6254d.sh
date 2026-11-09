# Not deployed: stopped at step 3 on 09/11 (the engine's schema snapshot failed on real PostgreSQL, fixed in #458);
# prod went 6e4febb6 → 4f093714 with 6e4febb6-4f093714.sh instead. Kept as the record of that run.
# Release file: prod 6e4febb6 → 7ce6254d (main on 09/11). Data and one-off commands only; the engine
# (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/6e4febb6-7ce6254d.sh
# Range: #444–#457 (product preview from the list, "Google & mạng xã hội" settings and its share image,
# ask before leaving a settings page, the per-website product search words, library patches, the deploy
# engine itself). One migration (a new, empty table); one new nightly job; the word table is filled after
# optimize and before octane:reload (docs/runbooks/prod-deploy.md, "The deploy that carries migration 2054").
# shellcheck shell=bash disable=SC2034
OLD=6e4febb633a5da53117fc50032889f9ff8ddb711
TARGET=7ce6254d0d0776cd1ff080703674df47d40531e9
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /san-pham /du-an /tin-tuc /doi-tac /lien-he /chinh-sach-bao-mat /nang-luc /admin/login)

# Classes that need a human look and are in this range (the engine stops if this differs from what it finds).
DECLARED=(migration schedule composer-lock npm)

MIGRATIONS=(
    2054_01_01_000000_create_product_search_words_table
)
# Raw SQL (lock_timeout 5s, CREATE TABLE … NOT NULL columns of a NEW table, RLS + FORCE, grant to app_user).
declare -gA MIG_OK=(
    [2054_01_01_000000_create_product_search_words_table]="creates the empty table product_search_words (site_id, product_id, word; PK (site_id, word, product_id), index (product_id), FKs CASCADE to sites/products, RLS + FORCE fail closed); NOT NULL is on the new table's own columns; no existing column, index or row changes; the old code never reads it"
)
# PgBouncer restarts after 2054 as the engine does for every table file (the runbook says it is not needed:
# no existing column changes; the restart costs about 3 seconds and is kept as the safe default).
declare -gA PGB_SKIP=()
# routes/console.php adds the nightly check of the search words (20:45 UTC):
# catalog:search-words --recent=500 --rolling=10000.
SCHEDULE_ADDED=(catalog:search-words)
SCHEDULE_REMOVED=()
ENV_DEFAULT_OK=()
# Expand-only: no column, index or constraint line of the schema snapshot may go or change.
SCHEMA_REMOVED_OK=()
# The build: the unsaved-changes guard (#451) is a lazy chunk loaded through admin-lazy.js (bsUnsaved), its
# styles land in app.css.
BUILD_DYNAMIC=(resources/js/admin-unsaved.js)
BUILD_MARKERS=("resources/js/app.js bsUnsaved")

SITE_SQL="(SELECT site_id FROM domains WHERE lower(hostname) = '$HOST' LIMIT 1)"
# Up to 10 published posts / products of the site in its default language, newest first
POSTS_SQL="SELECT r.path FROM routes r JOIN entries e ON e.id = r.entry_id
    JOIN site_locales l ON l.site_id = r.site_id AND l.locale = r.locale AND l.is_default
    WHERE r.site_id = $SITE_SQL
      AND e.type = 'post' AND e.status = 'published' AND e.deleted_at IS NULL
      AND (e.published_at IS NULL OR e.published_at <= now())
    ORDER BY e.published_at DESC NULLS LAST, e.id DESC LIMIT 10"
PRODUCTS_SQL="SELECT r.path FROM routes r JOIN products p ON p.id = r.product_id
    JOIN site_locales l ON l.site_id = r.site_id AND l.locale = r.locale AND l.is_default
    WHERE r.site_id = $SITE_SQL AND p.status = 'published' AND p.deleted_at IS NULL
    ORDER BY p.id DESC LIMIT 10"
release_pages() { # a post and a product page (#444 changed the product layout and the page head)
    local p
    p=$(first_open "$POSTS_SQL"); [ -n "$p" ] || die "không có bài đã đăng nào của $HOST mở được (200)"; echo "$p"
    p=$(first_open "$PRODUCTS_SQL"); [ -n "$p" ] || die "không có sản phẩm đã đăng nào của $HOST mở được (200)"; echo "$p"
}
csp_of() { # the Content-Security-Policy header of a storefront page (#444 moved it into StorefrontCsp, same text)
    docker exec "${PREFIX}app" curl -s -m 10 -o /dev/null -D - -H "Host: $HOST" "http://localhost:80$1" \
        | tr -d '\r' | sed -nE 's/^[Cc]ontent-[Ss]ecurity-[Pp]olicy:[[:space:]]*//p' | head -n 1
}
release_before() {
    local x
    x=$(csp_of /); [ -n "$x" ] || die "trang chủ không gửi Content-Security-Policy: không có gì để so ở bước 8"
    printf '%s\n' "$x" > "$STATE/csp-before"; echo "OK   trang chủ có Content-Security-Policy (${#x} ký tự)"
    echo "Sản phẩm trên prod (mọi website, cả Thùng rác): $(q "SELECT count(*) FROM products")"
}

# Products (every site, the Trash too) with a letter or digit in their SKU or a name and no search words yet.
# Postgres [[:alnum:]] is no wider than PHP's \p{L}\p{M}\p{N}, so a product counted here always gets words.
WORDLESS_SQL="SELECT count(*) FROM products p
    WHERE NOT EXISTS (SELECT 1 FROM product_search_words w WHERE w.product_id = p.id)
      AND (coalesce(p.sku, '') ~ '[[:alnum:]]'
           OR EXISTS (SELECT 1 FROM product_translations t WHERE t.product_id = p.id AND t.name ~ '[[:alnum:]]'))"
# Run before octane:reload: from the reload on the admin products search reads only the word table, and a
# product with no words is not found. The old workers ignore the table, so the writes are harmless to them
# and a rollback can keep them (runbook "The deploy that carries migration 2054", step 3).
ONEOFFS_PRE=(fill_words)
oneoff_fill_words() {
    local x n k all i
    case "$1" in
        preview) q "$WORDLESS_SQL" ;;
        apply) all=$(q "SELECT count(*) FROM products")
            x=$(art catalog:search-words 2>&1) || { printf '%s\n' "$x"; die "catalog:search-words lỗi (website hỏng được ghi trong log): chưa nạp lại, nhắn AI"; }
            printf '%s\n' "$x"
            n=$(printf '%s\n' "$x" | sed -nE 's/^Checked ([0-9]+) product\(s\) on [0-9]+ site\(s\); corrected [0-9]+\.$/\1/p')
            k=$(printf '%s\n' "$x" | sed -nE 's/^Checked [0-9]+ product\(s\) on [0-9]+ site\(s\); corrected ([0-9]+)\.$/\1/p')
            [[ "$n" =~ ^[0-9]+$ && "$k" =~ ^[0-9]+$ ]] || die "catalog:search-words không in dòng \"Checked … corrected …\""
            [ "$n" -ge "$all" ] || die "catalog:search-words chỉ kiểm $n sản phẩm, prod có $all"
            # K (written) equals the preview count unless a worker saved a product meanwhile (runbook step 3)
            echo "OK   catalog:search-words đã kiểm $n sản phẩm (prod có $all lúc bắt đầu), ghi từ cho $k (xem trước: $2)"
            # a product an old-code worker created during the fill has no words yet: fill again (at most twice)
            for i in 1 2; do
                [ "$(q "$WORDLESS_SQL")" != 0 ] || break
                art catalog:search-words >/dev/null 2>&1 || die "catalog:search-words (lần $((i + 1))) lỗi"
            done ;;
        verify) # a save by an old-code worker during the run can leave one product to correct: up to 3 runs
            for i in 1 2 3; do
                x=$(art catalog:search-words 2>&1) || { printf '%s\n' "$x"; die "catalog:search-words (chạy lại) lỗi"; }
                printf '%s\n' "$x"
                [ "$(printf '%s\n' "$x" | grep -cE '; corrected 0\.$' || true)" != 1 ] || return 0
            done
            die "catalog:search-words chạy 3 lần vẫn sửa sản phẩm (phải là corrected 0.). Chưa nạp lại; chạy lại từ bước 7 là an toàn, rồi nhắn AI" ;;
    esac
}
ONEOFFS=()
release_smoke() {
    local p x
    # products saved by a worker still on the old code between the merge and the reload got no words or
    # stale ones: they are among the 500 edited last (runbook step 4). Safe to re-run on a resume.
    # Up to 4 runs until one corrects 0 (an admin save during a run can show as one product to correct).
    for p in 1 2 3 4; do
        x=$(art catalog:search-words --recent=500 2>&1) || { printf '%s\n' "$x"; die "catalog:search-words --recent=500 lỗi"; }
        printf '%s\n' "$x"
        [ "$(printf '%s\n' "$x" | grep -cE '; corrected 0\.$' || true)" != 1 ] || break
        [ "$p" != 4 ] || die "từ tìm kiếm của sản phẩm sửa gần đây vẫn lệch sau 4 lần sửa (website vẫn chạy; nhắn AI)"
    done
    echo "OK   từ tìm kiếm của 500 sản phẩm sửa gần nhất mỗi website đều đúng"
    expect "sản phẩm chưa có từ tìm kiếm" "$(q "$WORDLESS_SQL")" "0"
    for p in admin.catalog.preview admin.settings.seo; do
        expect "đường dẫn quản trị $p" "$(art route:list --name="$p" --json | jq "[.[] | select(.name == \"$p\" and ((.action // \"\") | test(\"Placeholder\") | not))] | length")" "1"
    done
    expect "xem trước sản phẩm khi chưa đăng nhập (chuyển tới trang đăng nhập)" "$(code "/admin/catalog/01ARZ3NDEKTSV4RRFFQ69G5FAV/preview" '%{http_code}')" "302"
    expect "trang /sitemap.xml" "$(code /sitemap.xml '%{http_code}')" "200"
    need_state csp-before 0
    expect "Content-Security-Policy của trang chủ (giống trước triển khai)" "$(csp_of /)" "$(cat "$STATE/csp-before")"
    for x in app horizon scheduler; do
        expect "lỗi product_search_words trong log $PREFIX$x" "$(docker logs --since "$(cat "$STATE/t7")" "$PREFIX$x" 2>&1 | grep -c 'product_search_words' || true)" "0"
    done
}
release_rollback() { # data the old code (6e4febb6) cannot read
    echo "     bảng product_search_words, ảnh chia sẻ đã chọn ở \"Google & mạng xã hội\": bản cũ không dùng (vô hại, giữ lại)."
}
