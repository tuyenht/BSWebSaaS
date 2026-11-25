# Release file: prod a7348ad5 → 5110a313 (main on 25/11). Data and one-off commands only; the
# engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/a7348ad5-5110a313.sh
# Range:
#   #948 the previous release file.
#   #953 job posts tell search engines their application deadline (JobPosting reads the «Hạn nộp» fact).
#   #949 tags on posts: the editor card, chips under a post (sf-post.css .post-tags) and tag pages at
#        /the/{slug}, listed in the sitemap once a tag holds two published posts.
#   #955 other posts under a post come from its own category only (PostDetail).
#   #952 the category drawer's language row with the +N panel (lazy chunk admin-categories.js).
#   #950 page order and start-from-template in the page editor (admin only).
#   #951 media library creator and time filters, folder group, used-in chip and column: migration 2091
#        (index on media, CONCURRENTLY) and the featured picture's "in use" row (mediables, collection
#        cover), backfilled once by media:reconcile-content-usage (runbook Deploy item 11).
# No schedule change, no new env key, no permission or sanitizer change, no content pack.
# shellcheck shell=bash disable=SC2034
OLD=a7348ad5a4650c80058eeca92a024a0b0bcf9ba9
TARGET=5110a313bd2faa6996c632b3f0fea73b68fd0d79
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /ho-so-nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

DECLARED=(migration)
MIGRATIONS=(2091_01_01_000000_add_creator_index_to_media)
declare -gA MIG_OK=(
    [2091_01_01_000000_add_creator_index_to_media]="one partial index media (site_id, created_by, created_at DESC, ulid DESC) WHERE deleted_at IS NULL, built CONCURRENTLY outside a transaction; the only DROP is its own INVALID leftover on a retry; no column, no data written; old code keeps working"
)
# No narrowing: the flagged file gets the engine's PgBouncer restart (an index changes no column, but the
# restart costs two seconds and keeps the default path).
declare -gA PGB_SKIP=()
SCHEDULE_ADDED=()
SCHEDULE_REMOVED=()
ENV_DEFAULT_OK=()
# Expand-only: no column, index or constraint line of the schema snapshot may go or change.
SCHEMA_REMOVED_OK=()
# #952: the categories screen stays a chunk loaded on demand from admin-lazy.js (bsCategories).
BUILD_DYNAMIC=(resources/js/admin-categories.js)
# #949: the tags row under a post.
BUILD_MARKERS=("resources/css/sf-post.css post-tags__label")

SITE_SQL="(SELECT site_id FROM domains WHERE lower(hostname) = '$HOST' LIMIT 1)"
# Up to 10 published posts of the site in its default language, newest first
POSTS_SQL="SELECT r.path FROM routes r JOIN entries e ON e.id = r.entry_id
    JOIN site_locales l ON l.site_id = r.site_id AND l.locale = r.locale AND l.is_default
    WHERE r.site_id = $SITE_SQL
      AND e.type = 'post' AND e.status = 'published' AND e.deleted_at IS NULL
      AND (e.published_at IS NULL OR e.published_at <= now())
    ORDER BY e.published_at DESC NULLS LAST, e.id DESC LIMIT 10"

# #955 and #949 change the post page: a post found in the DB is checked in steps 0, 4 and 8 as before.
# No post that opens: nothing added (the PAGES list still runs), never a stop.
release_pages() {
    local p
    p=$(first_open "$POSTS_SQL")
    if [ -n "$p" ]; then echo "$p"; else note "không có bài đã đăng nào của $HOST mở được (200): không thêm trang bài" >&2; fi
}

ONEOFFS_PRE=()
# After the reload: old workers do not write the cover rows, the new code writes them with every save.
ONEOFFS=(media_cover_usage)

# #951 (runbook Deploy item 11): every entry's featured picture gets its "in use" row (mediables,
# collection cover), as EntryCoverUsage::reconcileSite builds it, Trash included on both sides, for
# every site the command visits (sites not soft-deleted). Preview = rows missing + rows that no
# longer match the entry's meta; the command is idempotent, so a second run leaves 0.
COVER_SQL="SELECT
    (SELECT count(*) FROM entries e
        JOIN sites s ON s.id = e.site_id AND s.deleted_at IS NULL
        JOIN media m ON m.site_id = e.site_id AND m.storage_key = e.meta->>'image_url'
        WHERE e.meta->>'image_url' <> ''
          AND NOT EXISTS (SELECT 1 FROM mediables mb WHERE mb.media_id = m.id AND mb.mediable_type = 'entry'
              AND mb.mediable_id = e.id AND mb.collection = 'cover'))
  + (SELECT count(*) FROM mediables mb
        JOIN sites s ON s.id = mb.site_id AND s.deleted_at IS NULL
        WHERE mb.mediable_type = 'entry' AND mb.collection = 'cover'
          AND NOT EXISTS (SELECT 1 FROM entries e
              JOIN media m ON m.site_id = e.site_id AND m.storage_key = e.meta->>'image_url'
              WHERE e.id = mb.mediable_id AND e.site_id = mb.site_id AND m.id = mb.media_id))"
oneoff_media_cover_usage() {
    local out
    case "$1" in
        preview) q "$COVER_SQL" ;;
        apply) out=$(art media:reconcile-content-usage 2>&1) || die "media:reconcile-content-usage thất bại: $(tail -2 <<< "$out")"
            echo "     $(tail -1 <<< "$out")" ;;
        verify) expect "mọi ảnh đại diện của bài/trang đã có dòng đang dùng (#951)" "$(q "$COVER_SQL")" "0" ;;
    esac
}

release_smoke() {
    local n
    n=$(docker logs --since 20m "${PREFIX}app" 2>&1 | grep -cE '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' || true)
    note "lỗi trong nhật ký ứng dụng 20 phút qua: ${n:-0}"
    [ "${n:-0}" = 0 ] || docker logs --since 20m "${PREFIX}app" 2>&1 | grep -E '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' | tail -n 5 | cut -c1-300
    # #951 media library filters: the library still answers (a signed-out visitor is sent to sign in)
    n=$(curl -s -o /dev/null -w '%{http_code}' "https://${HOST}/admin/media" || true)
    expect "thư viện ảnh av1 chuyển sang đăng nhập" "${n}" "302"
    n=$(curl -s -o /dev/null -w '%{http_code}' "https://${HOST}/admin/login" || true)
    expect "trang đăng nhập quản trị av1 trả lời" "${n}" "200"
    # #949 tag pages: a tag that does not exist is a plain 404, never an error page
    n=$(curl -s -o /dev/null -w '%{http_code}' "https://${HOST}/the/khong-co-the-nay-zq9x" || true)
    expect "trang thẻ không tồn tại av1 trả 404" "${n}" "404"
    # #949 the sitemap lists tag pages now: printed only (a site may have its sitemap off: 404 by design)
    n=$(curl -s -o /dev/null -w '%{http_code}' "https://${HOST}/sitemap.xml" || true)
    note "sitemap.xml av1 trả: ${n}"
    # #953 job posts and #955 post pages: the listing pages answer
    n=$(curl -s -o /dev/null -w '%{http_code}' "https://${HOST}/tuyen-dung" || true)
    expect "trang Tuyển dụng av1 trả lời" "${n}" "200"
    n=$(curl -s -o /dev/null -w '%{http_code}' "https://${HOST}/tin-tuc" || true)
    expect "trang Tin tức av1 trả lời" "${n}" "200"
    n=$(curl -s -D - -o /dev/null "https://${HOST}/" | grep -cE '^HTTP/[0-9.]+ 200' || true)
    expect "trang chủ av1 trả lời 200" "${n:-0}" "1"
}
