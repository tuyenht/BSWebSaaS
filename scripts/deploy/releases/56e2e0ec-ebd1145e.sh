# Release file: prod 56e2e0ec → ebd1145e (main on 10/11, after #466, #459, #462, #467, #469). Data and one-off
# commands only; the engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/56e2e0ec-ebd1145e.sh
# Range: #466 (trashed media kept 30 days before the nightly purge, was 15), #459 (release files of earlier
# deploys, not run), #462 (product editor frame: save state, draft autosave, sticky button bars), #467 (the
# An Vinh content pack: Vietnamese demo content and images; shipped, NOT loaded: the owner loads it with
# plans/tools/anvinh-demo-load-1110.sh after this deploy), #469 (site:load-content -vv prints changed fields
# in full). Code, views, styles, texts, one config default and the pack: no migration, no new job, no library change.
# shellcheck shell=bash disable=SC2034
OLD=56e2e0ec3785322c5ffff512c13bc99d29f43566
TARGET=ebd1145e3a7a82face94f52a01dd232da129af4b
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /san-pham /du-an /tin-tuc /doi-tac /lien-he /chinh-sach-bao-mat /nang-luc /admin/login)

# Classes that need a human look and are in this range (the engine stops if this differs from what it finds).
DECLARED=(content-pack)

MIGRATIONS=()
declare -gA MIG_OK=()
declare -gA PGB_SKIP=()
SCHEDULE_ADDED=()
SCHEDULE_REMOVED=()
ENV_DEFAULT_OK=()
SCHEMA_REMOVED_OK=()
# The build: the product editor's save state (#462) is a lazy chunk loaded through admin-lazy.js (bsEditorSave),
# its styles land in app.css.
BUILD_DYNAMIC=(resources/js/admin-editor.js)
BUILD_MARKERS=("resources/js/app.js bsEditorSave" "resources/css/app.css bs-save-state")

ONEOFFS_PRE=()
ONEOFFS=()
release_smoke() {
    # #466: the purge keeps 30 days unless prod .env sets its own value (key names only are read)
    if sed -nE 's/^([A-Za-z_][A-Za-z0-9_]*)=.*/\1/p' "$PROD/.env" | grep -qxF MEDIA_TRASH_RETENTION_DAYS; then
        note "MEDIA_TRASH_RETENTION_DAYS có trong .env prod: số ngày giữ thùng rác theo .env, không theo mặc định 30"
    else
        expect "số ngày giữ ảnh trong thùng rác" "$(art tinker --execute 'echo config("media.trash_retention_days");' | tail -n 1)" "30"
    fi
    # #462: the product editor routes are unchanged
    for p in admin.catalog.create admin.catalog.edit; do
        expect "đường dẫn $p" "$(art route:list --name="$p" --json | jq "[.[] | select(.name == \"$p\")] | length")" "1"
    done
    # #467: the container serves the new pack (the owner's load script checks the same before it runs)
    expect "gói nội dung An Vinh mới trong container" \
        "$(docker exec "${PREFIX}app" grep -c '"key": "post:thiet-ke-lap-trinh"' database/content-packs/an-vinh/posts.json || true)" "1"
}
release_rollback() { # data the old code (56e2e0ec) cannot read
    echo "     Không có: bản này không đổi bảng; bản nháp tự lưu của trình sửa sản phẩm là sản phẩm trạng thái nháp, bản cũ đọc được."
    echo "     Lưu ý: bản cũ xoá hẳn ảnh trong thùng rác sau 15 ngày (bản mới 30): lần dọn đêm sau khi lùi sẽ xoá hẳn ảnh đã vào thùng rác 15–30 ngày trước."
}
