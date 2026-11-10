# Release file: prod 77992f34 → bd8cb440 (main on 11/11, after #479–#486). Data and one-off commands only;
# the engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/77992f34-bd8cb440.sh
# Range: #479 (the engine treats the media Worker folder as a declared class), #480 (compact phone bar
# with up to five floating-contact buttons; settings fold the admin menu), #481 (the release file of the
# last deploy), #482 (shared centred section heads, features grid, dark band, split call to action),
# #483 (pack pages can name a parent page, a pack media slot can hold a PDF, a page button can download
# a pack document; the An Vinh about pages and the company profile PDF), #484 (media Worker: a downloaded
# PDF keeps its readable name; deployed separately on 11/11, this deploy never touches it), #485 (contact
# page cards with Zalo and hours, form heading, field order and placeholders for new forms, Turnstile box
# only when a visitor must click; new lang keys), #486 (the An Vinh pack fills those contact props).
# Code, views, CSS (so a front-end build), lang and the pack: no migration, no new job, no .env key, no
# library change. The pack is shipped, then loaded right after this deploy (media first: the profile PDF).
# shellcheck shell=bash disable=SC2034
OLD=77992f34f95ff83b50d95abe94b10cd71f21812f
TARGET=bd8cb440793a55976e71d3c45b0ccf686d75c5d4
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /san-pham /du-an /tin-tuc /doi-tac /lien-he /chinh-sach-bao-mat /nang-luc /admin/login)

# Classes that need a human look and are in this range (the engine stops if this differs from what it finds).
DECLARED=(content-pack worker)

MIGRATIONS=()
declare -gA MIG_OK=()
declare -gA PGB_SKIP=()
SCHEDULE_ADDED=()
SCHEDULE_REMOVED=()
ENV_DEFAULT_OK=()
SCHEMA_REMOVED_OK=()
BUILD_DYNAMIC=()
# #485: the contact card note line and #480: the fifth floating-contact button are new in storefront.css
BUILD_MARKERS=("resources/css/storefront.css sf-contact-card__note" "resources/css/storefront.css sf-fc-bar__item--r5")

ONEOFFS_PRE=()
ONEOFFS=()
release_smoke() {
    local x
    # #483: the container runs the new loader (parent pages, PDF slots, document buttons)
    expect "bộ nạp mới: trang con có trang mẹ (#483)" \
        "$(docker exec "${PREFIX}app" grep -cF 'function parentLinks(' app/Modules/Provisioning/ContentPack/Planner.php || true)" "1"
    # #485: the Turnstile box only appears when a visitor must click
    expect "biểu mẫu mới: ô chống robot chỉ hiện khi cần (#485)" \
        "$(docker exec "${PREFIX}app" grep -cF 'data-sitekey="{{ $turnstileSiteKey }}" data-appearance="interaction-only"' themes/default/sections/form.blade.php || true)" "1"
    # #486: the shipped pack carries the contact hours
    expect "gói An Vinh có giờ làm việc ở trang Liên hệ" \
        "$(docker exec "${PREFIX}app" grep -cF '"hours": "Thứ 2 – Thứ 7, giờ hành chính"' database/content-packs/an-vinh/pages.json || true)" "1"
    # what the load would do now (a dry run: writes nothing); every skip/conflict line, so the AI can choose
    # the FORCE keys before the load
    x=$({ art site:load-content an-vinh --host="$HOST" -v 2>&1 || true; })
    note "kế hoạch nạp gói An Vinh (xem trước, không ghi): $(printf '%s\n' "$x" | grep -E '^Totals:' || echo 'không đọc được dòng Totals')"
    note "mục gói sẽ bỏ qua hoặc xung đột (nhân viên đã sửa; AI chọn khóa FORCE từ đây):"
    printf '%s\n' "$x" | awk '
        /^.{11}(skip:|conflict)/ { on = 1; n++; print "       " $0; next }
        on && /^ {31}/ { print "       " $0; next }
        { on = 0 }
        END { if (!n) print "       (không có)" }'
}
release_rollback() { # data the old code (77992f34) cannot read
    echo "     Không có: bản này không đổi bảng. Nếu gói đã nạp rồi mới lùi: trang con có trang mẹ dùng cột parent_id sẵn có; nút tải tài liệu lưu đúng dạng nút tệp mà trang quản trị bản cũ vẫn lưu; dòng sổ gói có thêm trường parent, bản cũ bỏ qua trường lạ; tệp PDF là một ảnh/tệp thường trong thư viện; các thuộc tính mới của khối liên hệ (hours, heading) bản cũ không in ra; khối kêu gọi kiểu split hiện như dải mặc định."
}
