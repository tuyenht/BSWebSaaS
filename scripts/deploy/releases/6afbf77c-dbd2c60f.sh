# Release file: prod 6afbf77c → dbd2c60f (main on 20/11 evening). Data and one-off commands only; the
# engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/6afbf77c-dbd2c60f.sh
# Range:
#   #853 review inbox: bulk approve, reason dialog with hand-off, loading and error states.
#   #854 the previous release file.
#   #856 «Thành viên»: «Nhóm này làm được gì?», the plain sentence, unsaved guard, «Tải lại» on stale saves.
#   #857 review inbox: approve now, publish later through the workflow engine (entries.scheduled_by,
#        notification kind workflow.scheduled).
# Two migrations. No permission change.
# shellcheck shell=bash disable=SC2034
OLD=6afbf77c71c2fefd59fb160f43db92cca4a6a72a
TARGET=dbd2c60fe54aefbfe4d7d41f6d6d213947fb7031
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /ho-so-nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

# Classes that need a human look and are in this range (the engine stops if this differs from what it finds).
DECLARED=(migration)

MIGRATIONS=(2087_01_01_000000_add_scheduled_by_to_entries 2087_01_01_000100_add_workflow_scheduled_notification_kind)
declare -gA MIG_OK=(
    [2087_01_01_000000_add_scheduled_by_to_entries]="expand only on entries: nullable scheduled_by with no default, FK to users ON DELETE SET NULL added NOT VALID then validated, partial index (scheduled_by) WHERE NOT NULL built CONCURRENTLY with INVALID rebuild, 5s lock_timeout; no rewrite; old code never reads it"
    [2087_01_01_000100_add_workflow_scheduled_notification_kind]="site_notifications kind CHECK swapped for the same list plus workflow.scheduled: dropped, re-added NOT VALID under the same name, then validated; no row rewritten; old code writes only kinds that stay allowed"
)
declare -gA PGB_SKIP=()
SCHEDULE_ADDED=()
SCHEDULE_REMOVED=()
ENV_DEFAULT_OK=()
# The kind CHECK comes back under the same name and validated, so the catalog line is unchanged.
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
    # the contact page still shows its form
    n=$(curl -s "https://${HOST}/lien-he" | grep -c 'wire:submit\|<form' || true)
    expect "trang liên hệ vẫn có biểu mẫu" "$([ "${n:-0}" -gt 0 ] && echo 1 || echo 0)" "1"
    # #857: posts approved for later (printed, not a stop: data), and the kind CHECK admits the new kind
    note "bài đã duyệt chờ giờ đăng: $(q "SELECT count(*) FROM entries WHERE scheduled_by IS NOT NULL" 2>&1 | tail -1)"
    expect "loại thông báo workflow.scheduled được phép (#857)"         "$(q "SELECT count(*) FROM pg_constraint WHERE conname = 'site_notifications_kind_check' AND convalidated AND pg_get_constraintdef(oid) LIKE '%workflow.scheduled%'")" "1"
}
