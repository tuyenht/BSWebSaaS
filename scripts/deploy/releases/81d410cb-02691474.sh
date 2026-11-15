# Release file: prod 81d410cb → 02691474 (main on 16/11). Data and one-off commands only; the engine
# (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/81d410cb-02691474.sh
# Range:
#   #736 rich text: headings (h2–h5), list items and quotes keep their style attribute (text-align, indent)
#        through the shared HTML sanitizer; the CSS property allow-list is unchanged.
# No migration, no library change, no .env key, no schedule change, no file under resources/ (no build string).
# shellcheck shell=bash disable=SC2034
OLD=81d410cb16aa1521efe1e7965c68dc9671313df0
TARGET=0269147410982de8258cfeabcc7e7f382d9f5058
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /ho-so-nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

# Classes that need a human look and are in this range (the engine stops if this differs from what it finds).
# sanitizer: #736 lets h2–h5, li, blockquote keep style; CSS property allow-list unchanged; Opus-reviewed; smoke below proves allowed kept, disallowed dropped.
DECLARED=(sanitizer)

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
    local code r
    # #736: an aligned heading keeps its alignment after sanitizing; a disallowed property is still dropped
    r=$(art tinker --execute='$c = app(\App\Modules\Shared\Support\RichTextSanitizer::class)->cms("<h2 style=\"text-align:center;position:fixed\">a</h2>"); echo str_contains($c, "text-align:center") && ! str_contains($c, "position") ? 1 : 0;' 2>&1 | tail -1 || true)
    expect "tiêu đề căn giữa giữ căn lề, thuộc tính lạ vẫn bị bỏ (#736)" "$r" "1"
    # earlier releases stay as they were
    r=$(art tinker --execute='app()->setLocale("vi"); echo __("entries.ed.title_post") !== "entries.ed.title_post" ? 1 : 0;' 2>&1 | tail -1 || true)
    expect "khung soạn bài còn bản dịch (#730)" "$r" "1"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/admin/login")
    expect "trang đăng nhập quản trị mở được" "$code" "200"
    read -r code < <(curl -s -o /dev/null -m 20 -w '%{http_code}\n' "https://$HOST/khong-co-trang-nay-$RANDOM")
    expect "địa chỉ không có trả 404 (giữ như cũ)" "$code" "404"
}
