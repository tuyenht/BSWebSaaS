# Release file: prod bd8cb440 → ad65c8d3 (main on 11/11, after #487–#491). Data and one-off commands only;
# the engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/bd8cb440-ad65c8d3.sh
# Range: #487 (the An Vinh factory page uses the company's own photos from its profile), #488 (media Worker:
# a download also drops the default four-hex content hash; deployed separately on 11/11, this deploy never
# touches it), #489 (the release file of the last deploy), #490 (content packs place a form's fields, add
# extra fields such as a select and name a thank-you page; a successful send opens that page with a 303 when
# it is a published page of the same site; the An Vinh contact form in the board's order with its "need"
# select), #491 (a years stat shows "+", the filter chips sit in one grey track, footer legal strip and small
# colour fixes). Code, one view, CSS (so a front-end build) and the pack: no migration, no new job, no .env
# key, no library change, no lang change. The pack is loaded right after this deploy (media first: the new
# factory photos).
# shellcheck shell=bash disable=SC2034
OLD=bd8cb440793a55976e71d3c45b0ccf686d75c5d4
TARGET=ad65c8d3cfd51bdfa2ef2e19f866521efdbe7712
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /san-pham /du-an /tin-tuc /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /admin/login)

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
# #491: the project page's "other projects" band and the footer link focus colour are new in storefront.css
BUILD_MARKERS=("resources/css/storefront.css post-related--project" "resources/css/storefront.css sf-footer__fact a:focus-visible")

ONEOFFS_PRE=()
ONEOFFS=()
release_smoke() {
    local x
    # #490: the container runs the new loader (form items) and the thank-you redirect
    expect "bộ nạp mới: mục biểu mẫu trong gói (#490)" \
        "$(docker exec "${PREFIX}app" grep -cF 'function fieldsAfterConsent(' app/Modules/Provisioning/ContentPack/Planner.php || true)" "1"
    expect "gửi biểu mẫu xong mở trang Cảm ơn (#490)" \
        "$(docker exec "${PREFIX}app" grep -cF 'function thankYouPath(' app/Modules/Storefront/Http/Controllers/FormSubmissionController.php || true)" "1"
    # #491: a years stat locks its unit to "+"
    expect "số năm có dấu + (#491)" \
        "$(docker exec "${PREFIX}app" grep -cF "public const string YEARS_SUFFIX = '+';" app/Modules/Theme/Components/SectionProps.php || true)" "1"
    # #487 + #490: the shipped pack carries the real factory photos and the contact form item
    expect "gói An Vinh có ảnh xưởng thật (#487)" \
        "$(docker exec "${PREFIX}app" test -f database/content-packs/an-vinh/media/plant-3-machining-hall.webp && echo 1 || echo 0)" "1"
    expect "gói An Vinh có mục biểu mẫu Liên hệ (#490)" \
        "$(docker exec "${PREFIX}app" grep -cF '"key": "form:contact"' database/content-packs/an-vinh/forms.json || true)" "1"
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
release_rollback() { # data the old code (bd8cb440) cannot read
    echo "     Không có: bản này không đổi bảng. Nếu gói đã nạp rồi mới lùi: ô \"Bạn cần tư vấn về\" là một ô chọn thường của biểu mẫu, bản cũ vẫn vẽ và lưu được (ô chọn hiện chữ gợi ý chung thay cho "— Chọn nhu cầu —"); settings.redirect_entry của biểu mẫu bản cũ bỏ qua, khách gửi xong ở lại trang với lời cảm ơn như trước; dòng sổ gói kiểu form: bản cũ chỉ báo là mục mồ côi, không xoá; ảnh xưởng mới là ảnh thường trong thư viện. Số năm hết dấu + và thanh lọc về kiểu cũ."
}
