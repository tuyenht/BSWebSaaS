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

SITE_SQL="(SELECT site_id FROM domains WHERE lower(hostname) = '$HOST' LIMIT 1)"
release_before() {
    echo "Sản phẩm đang trong Thùng rác (mọi website; việc tự xóa mới chỉ xóa cái quá 30 ngày, tính từ 09/11): $(q "SELECT count(*) FROM products WHERE deleted_at IS NOT NULL")"
    expect "trang /sitemap.xml trước khi triển khai" "$(code /sitemap.xml '%{http_code}')" "200"
}
release_check() { # read-only, before step 4
    expect "ảnh logo/favicon trỏ tới ảnh không có hoặc của website khác (2051 sẽ xóa)" \
        "$(q "SELECT count(*) FROM site_profiles p WHERE (p.logo_media_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM media m WHERE m.id = p.logo_media_id AND m.site_id = p.site_id)) OR (p.favicon_media_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM media m WHERE m.id = p.favicon_media_id AND m.site_id = p.site_id))")" "0"
    [ -n "$(q "SELECT e.ulid FROM routes r JOIN entries e ON e.id = r.entry_id JOIN site_locales l ON l.site_id = r.site_id AND l.locale = r.locale AND l.is_default WHERE r.site_id = $SITE_SQL AND r.path = '/lien-he' AND e.status = 'published' LIMIT 1")" ] \
        || die "$HOST không có trang /lien-he đã đăng: nút Yêu cầu báo giá sẽ không gắn được"
}

# One-off commands, each run once after the reload: preview prints how many rows would change (last line),
# apply writes; the engine requires the second preview to print 0 and marks it done.
ONEOFFS=(link_contact fill_brand)
oneoff_link_contact() { # the request-a-quote button's contact page per site (runbook Deploy 9)
    local x
    case "$1" in
        preview) q "SELECT count(*) FROM routes r JOIN entries e ON e.id = r.entry_id
                JOIN site_locales l ON l.site_id = r.site_id AND l.locale = r.locale AND l.is_default
                WHERE r.path = '/lien-he' AND e.status = 'published' AND e.deleted_at IS NULL
                  AND NOT EXISTS (SELECT 1 FROM site_settings s JOIN entries x ON x.site_id = s.site_id
                        AND x.ulid::text = s.value->>'entry' AND x.status = 'published' AND x.deleted_at IS NULL
                      WHERE s.site_id = r.site_id AND s.group_name = 'catalog' AND s.key = 'contact_page')" ;;   # sites with no live contact page linked
        apply) x=$(art site:link-contact-page 2>&1) || { printf '%s\n' "$x"; die "site:link-contact-page lỗi"; }
            printf '%s\n' "$x"
            expect "site:link-contact-page: website lỗi" "$(printf '%s\n' "$x" | grep -cE '^site [0-9]+: failed' || true)" "0" ;;
    esac
}
oneoff_fill_brand() { # media ids into EMPTY profile image columns, matched by media key; the page does not change
    local x
    case "$1" in
        preview) x=$(art site-profiles:fill-brand-images 2>&1) || { printf '%s\n' "$x"; die "site-profiles:fill-brand-images lỗi"; }
            printf '%s\n' "$x" >&2
            printf '%s\n' "$x" | grep -cE '\|[[:space:]]*would fill[[:space:]]*\|' || true ;;
        apply) x=$(art site-profiles:fill-brand-images --apply 2>&1) || { printf '%s\n' "$x"; die "site-profiles:fill-brand-images --apply lỗi"; }
            printf '%s\n' "$x" ;;
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
}
release_rollback() { # data the old code (534b709) cannot render
    local x
    x=$(q "SELECT count(*) FROM taxonomies WHERE detail_template IS NOT NULL AND detail_template NOT IN ('category','grid-news','grid-product','grid-project','page','post','product')")
    if [ "$x" = 0 ]; then echo "     không chuyên mục nào dùng bố cục bài mà bản cũ không có."
    else echo "LƯU Ý $x chuyên mục dùng bố cục bài bản cũ không có: bài trong đó trả lỗi 500 trên bản cũ. Nhắn AI NGAY."; fi
    echo "     trang liên hệ (catalog/contact_page), mã ảnh thương hiệu, cột content_changed_at: bản cũ không dùng (vô hại)."
}
