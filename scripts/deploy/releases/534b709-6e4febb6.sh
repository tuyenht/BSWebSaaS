# Release file: prod 534b709 → 6e4febb6 (main on 08/11). Data and one-off commands only; the engine
# (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/534b709-6e4febb6.sh
# Range: #386–#442 (website An Vinh screens and blocks, menus and categories screens, SEO/AI-bot check,
# content mark, product list). Four migrations, all expand-only; one new nightly job; the sanitizer widens
# (table cells may span) so stored bodies need no clean-up (dry run only).
# shellcheck shell=bash disable=SC2034
OLD=534b709e2130b7a1fd0a30f9082599253fba7c64
TARGET=6e4febb633a5da53117fc50032889f9ff8ddb711
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /san-pham /du-an /tin-tuc /doi-tac /lien-he /chinh-sach-bao-mat /nang-luc /admin/login)

# Classes that need a human look and are in this range (the engine stops if this differs from what it finds).
DECLARED=(migration schedule sanitizer content-pack)

MIGRATIONS=(
    2051_01_01_000000_add_brand_images_and_saved_by_to_site_settings
    2051_01_01_000100_add_brand_image_lookup_indexes
    2052_01_01_000000_add_content_changed_at_to_translations
    2053_01_01_000000_add_list_indexes_to_products
)
# Raw SQL in each (the house pattern: per-step lock_timeout 5s, re-runnable); reviewed as expand-only.
declare -gA MIG_OK=(
    [2051_01_01_000000_add_brand_images_and_saved_by_to_site_settings]="adds logo_dark/og_image/updated_by (nullable) to site_profiles and updated_by to site_settings; FKs NOT VALID then VALIDATE; clears an image id naming no media of its site (release_check: none on prod)"
    [2051_01_01_000100_add_brand_image_lookup_indexes]="4 partial indexes CONCURRENTLY on site_profiles; drops only its own INVALID leftover"
    [2052_01_01_000000_add_content_changed_at_to_translations]="content_changed_at timestamptz NULL on entry_translations and product_translations; no default, no rewrite"
    [2053_01_01_000000_add_list_indexes_to_products]="3 product list indexes CONCURRENTLY; drops only its own INVALID leftover"
)
# PgBouncer restart narrowed for index-only files (no result type changes); the two column files restart it.
declare -gA PGB_SKIP=(
    [2051_01_01_000100_add_brand_image_lookup_indexes]="indexes only"
    [2053_01_01_000000_add_list_indexes_to_products]="indexes only"
)
# routes/console.php adds the nightly purge of products in the Trash for more than 30 days (counted from
# 09/11 for products already there, so nothing is deleted before 09/12/2026).
SCHEDULE_ADDED=(products:purge-trash)
SCHEDULE_REMOVED=()
ENV_DEFAULT_OK=()
RESANITIZE=dry-run
# Expand-only: no column, index or constraint line of the schema snapshot may go or change.
SCHEMA_REMOVED_OK=()
# The build (git grep of resources/ at both commits, 08/11): the Chuyên mục screen is a lazy chunk loaded
# through admin-lazy.js; the Alpine.data names of admin-menus.js / admin-lazy.js land in app.js, the new
# classes in app.css, the breadcrumb and the photo viewer (#428) in storefront.css.
BUILD_DYNAMIC=(resources/js/admin-categories.js)
BUILD_MARKERS=("resources/js/app.js bsMenus" "resources/js/app.js bsCategories"
    "resources/css/app.css btn-act__cur" "resources/css/app.css data-bs-lazy-failed"
    "resources/css/storefront.css sf-crumbs" "resources/css/storefront.css sf-viewer")

SITE_SQL="(SELECT site_id FROM domains WHERE lower(hostname) = '$HOST' LIMIT 1)"
# The nightly products:purge-trash counts a product's 30 days from ProductTrash::SINCE at the earliest. Deployed
# on or after TRASH_DEADLINE, its first run would delete products that never showed a countdown.
TRASH_SINCE=2026-11-09
TRASH_DEADLINE=2026-12-09
trash_deadline() {
    local x; x=$(date -u +%F)
    [[ "$x" < "$TRASH_DEADLINE" ]] || die "hôm nay $x đã tới hoặc qua $TRASH_DEADLINE: lệnh xóa sản phẩm quá 30 ngày trong Thùng rác sẽ xóa ngay sản phẩm cũ. Nhắn AI: phải đổi ProductTrash::SINCE thành ngày deploy (một PR mới) rồi làm tệp khai báo mới"
    echo "OK   hôm nay $x, trước $TRASH_DEADLINE (Thùng rác sản phẩm: chưa xóa gì trước $TRASH_DEADLINE)"
}
release_guard() { trash_deadline; }   # the engine runs it in the plan and before every step, a resume included
release_git() { # plan and step 1: the grace start in the code is the date TRASH_DEADLINE was counted from
    # (fixed by TARGET, which the run record pins, so a resume cannot change it)
    local since
    since=$(git -C "$1" show "$TARGET:app/Modules/Catalog/Support/ProductTrash.php" | sed -nE "s/.*const string SINCE = '([0-9-]+)'.*/\1/p")
    expect "ngày bắt đầu ân hạn Thùng rác sản phẩm (ProductTrash::SINCE ở ${TARGET:0:8})" "$since" "$TRASH_SINCE"
}
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
release_pages() { # a post and a product page: they read the translation tables 2052 changes (step 4 runs them on the old code)
    local p
    p=$(first_open "$POSTS_SQL"); [ -n "$p" ] || die "không có bài đã đăng nào của $HOST mở được (200)"; echo "$p"
    p=$(first_open "$PRODUCTS_SQL"); [ -n "$p" ] || die "không có sản phẩm đã đăng nào của $HOST mở được (200)"; echo "$p"
}
footer_mark() { # the company line of the home page footer (public text; rich or plain footer)
    local h
    h=$(page /) || die "không gọi được trang chủ"
    printf '%s\n' "$h" | grep -oE 'class="(sf-footer__legal-name|site-footer__company)">[^<]+' | head -n 1 \
        | sed -E 's/^[^>]*>//; s/^[[:space:]]+//; s/[[:space:]]+$//' || true
}
brand_mark() { # the home page's favicon href | header logo src | robots meta: the brand image fill must not move them
    local h f l r
    h=$(page /) || die "không gọi được trang chủ"
    h=$(printf '%s' "$h" | tr -d '\r\n')
    f=$(printf '%s' "$h" | grep -oE '<link rel="icon" href="[^"]*"' | head -n 1 | sed -E 's/.*href="//; s/"$//' || true)
    l=$(printf '%s' "$h" | grep -oE '<img class="[^"]*logo[^"]*" src="[^"]*"' | head -n 1 | sed -E 's/.* src="//; s/"$//' || true)
    r=$(printf '%s' "$h" | grep -oE '<meta name="robots" content="[^"]*"' | head -n 1 | sed -E 's/.*content="//; s/"$//' || true)
    printf '%s|%s|%s\n' "$f" "$l" "$r"
}
release_before() {
    local x
    echo "Sản phẩm đang trong Thùng rác (mọi website; việc tự xóa mới chỉ xóa cái quá 30 ngày, tính từ $TRASH_SINCE): $(q "SELECT count(*) FROM products WHERE deleted_at IS NOT NULL")"
    expect "trang /sitemap.xml trước khi triển khai" "$(code /sitemap.xml '%{http_code}')" "200"
    # step 8 compares these: the footer company line, the brand marks (the fill writes ids only, K83 / #403),
    # and robots.txt (built from the search settings since #386; nobody set them, so it stays the same)
    x=$(footer_mark); [ -n "$x" ] || die "chân trang chủ không có dòng tên công ty (sf-footer__legal-name / site-footer__company): không có gì để so ở bước 8"
    printf '%s\n' "$x" > "$STATE/footer-before"; echo "OK   dòng tên công ty ở chân trang: $x"
    x=$(brand_mark); case "$x" in "|"*) die "trang chủ không có <link rel=\"icon\">: không có gì để so ở bước 8" ;; esac
    printf '%s\n' "$x" > "$STATE/brand-before"; echo "OK   favicon|logo|robots của trang chủ: $x"
    expect "trang /robots.txt trước khi triển khai" "$(code /robots.txt '%{http_code}')" "200"
    page /robots.txt > "$STATE/robots-before"
}
release_check() { # read-only, before step 4
    expect "ảnh logo/favicon trỏ tới ảnh không có hoặc của website khác (2051 sẽ xóa)" \
        "$(q "SELECT count(*) FROM site_profiles p WHERE (p.logo_media_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM media m WHERE m.id = p.logo_media_id AND m.site_id = p.site_id)) OR (p.favicon_media_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM media m WHERE m.id = p.favicon_media_id AND m.site_id = p.site_id))")" "0"
    [ -n "$(q "SELECT e.ulid FROM routes r JOIN entries e ON e.id = r.entry_id JOIN site_locales l ON l.site_id = r.site_id AND l.locale = r.locale AND l.is_default WHERE r.site_id = $SITE_SQL AND r.path = '/lien-he' AND e.status = 'published' LIMIT 1")" ] \
        || die "$HOST không có trang /lien-he đã đăng: nút Yêu cầu báo giá sẽ không gắn được"
}

# One-off commands run between optimize and octane:reload (new code, old workers still serving): none here,
# nothing this range ships needs data before its first request.
ONEOFFS_PRE=()
# One-off commands, each run once after the reload: preview prints how many rows would change (last line),
# apply <preview count> writes; the engine requires the second preview to print 0, runs verify, and marks it done.
ONEOFFS=(link_contact fill_brand)
# The ulid of $HOST's published page at /lien-he in the default language (what site:link-contact-page records)
CONTACT_SQL="SELECT e.ulid FROM routes r JOIN entries e ON e.id = r.entry_id
    JOIN site_locales l ON l.site_id = r.site_id AND l.locale = r.locale AND l.is_default
    WHERE r.site_id = $SITE_SQL AND r.path = '/lien-he' AND e.status = 'published' LIMIT 1"
CONTACT_SET_SQL="SELECT value->>'entry' FROM site_settings WHERE site_id = $SITE_SQL AND group_name = 'catalog' AND key = 'contact_page'"
oneoff_link_contact() { # the request-a-quote button's contact page per site (runbook Deploy 9)
    local x want
    case "$1" in
        preview) q "SELECT count(*) FROM routes r JOIN entries e ON e.id = r.entry_id
                JOIN site_locales l ON l.site_id = r.site_id AND l.locale = r.locale AND l.is_default
                WHERE r.path = '/lien-he' AND e.status = 'published' AND e.deleted_at IS NULL
                  AND NOT EXISTS (SELECT 1 FROM site_settings s JOIN entries x ON x.site_id = s.site_id
                        AND x.ulid::text = s.value->>'entry' AND x.status = 'published' AND x.deleted_at IS NULL
                      WHERE s.site_id = r.site_id AND s.group_name = 'catalog' AND s.key = 'contact_page')" ;;   # sites with no live contact page linked
        apply) x=$(art site:link-contact-page 2>&1) || { printf '%s\n' "$x"; die "site:link-contact-page lỗi"; }
            printf '%s\n' "$x"
            [ "$(printf '%s\n' "$x" | grep -cE '^site [0-9]+: ' || true)" -ge 1 ] || die "site:link-contact-page không in dòng nào cho website nào"
            expect "site:link-contact-page: website lỗi" "$(printf '%s\n' "$x" | grep -cE '^site [0-9]+: failed' || true)" "0" ;;
        verify) want=$(q "$CONTACT_SQL")
            [ -n "$want" ] || die "$HOST không có trang /lien-he đã đăng: nút Yêu cầu báo giá sẽ không hiện (cần chọn trang liên hệ bằng tay)"
            expect "trang liên hệ ghi cho $HOST (catalog/contact_page)" "$(q "$CONTACT_SET_SQL")" "$want" ;;
    esac
}
oneoff_fill_brand() { # media ids into EMPTY profile image columns, matched by media key; the page does not change
    local x
    case "$1" in
        preview) x=$(art site-profiles:fill-brand-images 2>&1) || { printf '%s\n' "$x"; die "site-profiles:fill-brand-images lỗi"; }
            printf '%s\n' "$x" >&2
            printf '%s\n' "$x" | grep -cE '\|[[:space:]]*would fill[[:space:]]*\|' || true ;;
        apply) x=$(art site-profiles:fill-brand-images --apply 2>&1) || { printf '%s\n' "$x"; die "site-profiles:fill-brand-images --apply lỗi"; }
            printf '%s\n' "$x"
            expect "site-profiles:fill-brand-images đã điền (bằng số xem trước)" "$(printf '%s\n' "$x" | grep -cE '\|[[:space:]]*filled[[:space:]]*\|' || true)" "$2" ;;
    esac
}
release_smoke() {
    local p x
    expect "trang /sitemap.xml" "$(code /sitemap.xml '%{http_code}')" "200"
    [ "$(has /sitemap.xml '<lastmod>')" -ge 1 ] || die "sitemap.xml không có <lastmod>"
    echo "OK   sitemap.xml có <lastmod>"
    p=/khong-co-trang-nay-$TS
    expect "trang không có $p" "$(code "$p" '%{http_code}')" "404"
    [ "$(has "$p" 'class="sf-404"')" -ge 1 ] || die "trang 404 chưa là trang 404 của website (sf-404)"
    for p in admin.settings.seo admin.settings.integrations admin.menus.index admin.taxonomies.index; do
        expect "đường dẫn quản trị $p" "$(art route:list --name="$p" --json | jq "[.[] | select(.name == \"$p\" and ((.action // \"\") | test(\"Placeholder\") | not))] | length")" "1"
    done
    for x in app horizon scheduler; do
        expect "lỗi content_changed_at trong log $PREFIX$x" "$(docker logs --since "$(cat "$STATE/t7")" "$PREFIX$x" 2>&1 | grep -c 'content_changed_at' || true)" "0"
    done
    for x in footer-before brand-before robots-before; do need_state "$x" 0; done
    expect "dòng tên công ty ở chân trang chủ (trước: \"$(cat "$STATE/footer-before")\")" "$(footer_mark)" "$(cat "$STATE/footer-before")"
    expect "favicon|logo|robots của trang chủ (trước: \"$(cat "$STATE/brand-before")\")" "$(brand_mark)" "$(cat "$STATE/brand-before")"
    expect "trang /robots.txt" "$(code /robots.txt '%{http_code}')" "200"
    page /robots.txt > "$STATE/robots-after"
    if cmp -s "$STATE/robots-before" "$STATE/robots-after"; then echo "OK   robots.txt giống hệt trước triển khai"
    else
        printf 'robots.txt trước: %s\nrobots.txt sau:   %s\n' "$(tr '\n' '¶' < "$STATE/robots-before")" "$(tr '\n' '¶' < "$STATE/robots-after")"
        [ "$(grep -cxE 'Disallow:[[:space:]]*/' "$STATE/robots-after" || true)" = 0 ] || die "robots.txt sau triển khai chặn Google cả website (Disallow: /)"
        echo "LƯU Ý robots.txt khác trước triển khai (không chặn cả website); website vẫn chạy. Nhắn AI."
    fi
}
# The layouts the OLD code (534b709) has: its TemplateResolver names the stored value as a view, so a category
# list/detail layout or a post/page override outside this list ("project", "job", "grid-job", "@parent" since
# #400/#417) answers 500 there. An empty value means "none" and is fine.
OLD_LAYOUTS="'category','grid-news','grid-product','grid-project','page','post','product'"
LAYOUT_OUT_SQL="SELECT (SELECT count(*) FROM taxonomies WHERE (list_template <> '' AND list_template NOT IN ($OLD_LAYOUTS))
        OR (detail_template <> '' AND detail_template NOT IN ($OLD_LAYOUTS)))
    ||'|'||(SELECT count(*) FROM entries WHERE deleted_at IS NULL AND template_override <> '' AND template_override NOT IN ($OLD_LAYOUTS))"
release_rollback() { # data the old code (534b709) cannot render
    local x
    x=$(q "$LAYOUT_OUT_SQL")
    if [ "$x" = "0|0" ]; then echo "     không chuyên mục hay bài/trang nào dùng bố cục mà bản cũ không có."
    else echo "LƯU Ý chuyên mục|bài/trang dùng bố cục bản cũ không có: $x. Trang của chúng trả lỗi 500 trên bản cũ. Nhắn AI NGAY."; fi
    echo "     trang liên hệ (catalog/contact_page), mã ảnh thương hiệu, cột content_changed_at: bản cũ không dùng (vô hại)."
}
