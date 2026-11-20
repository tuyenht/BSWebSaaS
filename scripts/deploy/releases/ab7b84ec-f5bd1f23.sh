# Release file: prod ab7b84ec → f5bd1f23 (main on 20/11). Data and one-off commands only; the engine
# (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/ab7b84ec-f5bd1f23.sh
# Range:
#   #826 sign-in: a press before the bot check answers waits for it (no false «not verified»).
#   #823 emailed sign-in code typed or pasted with spaces.
#   #828 #833 #834 post and page editor: status, scheduling, autosave, version history, undo after Trash.
#   #829 #831 #832 #835 #837 media library: rename/reorder folders, picker upload list, delete a folder
#        (queued job for large ones), two-level sub-folders, two delete rights (own/any) + who trashed a file.
#   #830 product editor as its board draws it.  #836 #838 forms screen and the leads inbox notice.
#   #827 the previous release file.
# Four migrations (media index, sub-folders, deleted_by, delete-right split). PermissionRegistry gains
# media.delete.own/.any (#837): the engine runs rbac:sync after the reload, then octane:reload again.
# No one-off, no schedule change; three new .env keys with config defaults (below). CSS/JS change (admin bundle).
# shellcheck shell=bash disable=SC2034
OLD=ab7b84ecb17178715605397fb9a8c61ca851a525
TARGET=f5bd1f23bea9e3ba034331f21080f4537f0d7028
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /ho-so-nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

# Classes that need a human look and are in this range (the engine stops if this differs from what it finds).
DECLARED=(migration permission)

MIGRATIONS=(2078_01_01_000000_add_folder_id_index_to_media 2079_01_01_000000_add_parent_id_to_media_folders 2080_01_01_000100_add_deleted_by_to_media 2080_01_01_000200_split_media_delete_permission)
declare -gA MIG_OK=(
    [2078_01_01_000000_add_folder_id_index_to_media]="1 partial index CONCURRENTLY on media (folder_id, id) WHERE folder_id IS NOT NULL (the folder-delete SET NULL lookup + the large-folder job keyset); drops only its own INVALID leftover"
    [2079_01_01_000000_add_parent_id_to_media_folders]="media_folders.parent_id (nullable, no rewrite) + unique (site_id,id) and (site_id,parent_id,position) CONCURRENTLY + composite FK (site_id,parent_id)->(site_id,id) CASCADE and CHECK not-self, NOT VALID then VALIDATE + depth trigger media_folders_two_levels (AFTER row, end of statement); each locking step lock_timeout 5s; re-runnable"
    [2080_01_01_000100_add_deleted_by_to_media]="media.deleted_by nullable bigint (no rewrite) + FK media_deleted_by_foreign -> users ON DELETE SET NULL, NOT VALID then VALIDATE; each step lock_timeout 5s; re-runnable"
    [2080_01_01_000200_split_media_delete_permission]="data only: inserts media.delete.own/.any and maps every media.delete grant (system owner/admin/editor + purgers -> any, other roles + direct grants -> own); keeps media.delete; idempotent"
)
declare -gA PGB_SKIP=(
    [2078_01_01_000000_add_folder_id_index_to_media]="index only, no column changes"
)
SCHEDULE_ADDED=()
SCHEDULE_REMOVED=()
# #832: folder undo cap 100000, inline folder delete up to 20000 files, job batch 2000 (config/media.php defaults).
ENV_DEFAULT_OK=(MEDIA_FOLDER_DELETE_BATCH_FILES MEDIA_FOLDER_DELETE_INLINE_MAX_FILES MEDIA_FOLDER_UNDO_MAX_FILES)
SCHEMA_REMOVED_OK=()
BUILD_DYNAMIC=()
BUILD_MARKERS=()

ONEOFFS_PRE=()
ONEOFFS=()

release_smoke() {
    local n
    # app errors since the reload (not a stop: printed for the report, the lines shown when any)
    n=$(docker logs --since 20m "${PREFIX}app" 2>&1 | grep -cE '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' || true)
    note "lỗi trong nhật ký ứng dụng 20 phút qua: ${n:-0}"
    [ "${n:-0}" = 0 ] || docker logs --since 20m "${PREFIX}app" 2>&1 | grep -E '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' | tail -n 5 | cut -c1-300
    # #826: the sign-in page hands the bot-check token over through its hidden field. Count the input tag
    # itself: the partial's script also names data-bs-turnstile-token (the run on 20/11 counted 2 and
    # stopped here; the rest of step 8 was checked by hand).
    n=$(curl -s "https://${HOST}/admin/login" | grep -c '<input type="hidden" wire:model="turnstileToken" data-bs-turnstile-token>' || true)
    expect "trang đăng nhập có ô ẩn chuyển mã xác minh (#826)" "${n:-0}" "1"
}
