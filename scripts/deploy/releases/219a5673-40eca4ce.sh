# Release file: prod 219a5673 → 40eca4ce (main on 21/11 night). Data and one-off commands only; the
# engine (scripts/deploy/deploy.sh) does every generic step. Review this file and the printed plan:
#     bash scripts/deploy/deploy.sh plan scripts/deploy/releases/219a5673-40eca4ce.sh
# Range:
#   #861 admin UI components compiled with livewire/blaze (composer); the engine keeps compiled views
#        from crossing a reload (view:clear after octane:reload, view:cache at the end).
#   #864 cover and block images through the shared «Chọn ảnh» picker.
#   #865 Server-Timing header for signed-in admins.
#   #866 the admin sidebar keeps still on page changes (open group drawn by the server, scroll kept).
#   #867 a category slug made from the name takes a suffix; no «Giao cho» on a publishing move.
#   #868 the previous release file.
# No migration. No permission change.
# shellcheck shell=bash disable=SC2034
OLD=219a567357d1823e41544502d9b1f92a639c705d
TARGET=40eca4ced987114b7dd24f08279398beb2efb7a6
HOST=av1.baoson.net
PAGES=(/ /gioi-thieu /nha-xuong-may-moc /chung-chi-thanh-tuu /san-pham /jig-kiem-tap-lo-vf7 /du-an /tin-tuc /khanh-thanh-nha-may-so-3 /doi-tac /lien-he /cam-on /chinh-sach-bao-mat /nang-luc /ho-so-nang-luc /thiet-ke-lap-trinh /tuyen-dung /admin/login)

# Classes that need a human look and are in this range (the engine stops if this differs from what it finds).
DECLARED=(composer-lock)   # #861 adds livewire/blaze v1.0.21 only (MIT, by the Livewire team)

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
    local n
    # app errors since the reload (not a stop: printed for the report, the lines shown when any)
    n=$(docker logs --since 20m "${PREFIX}app" 2>&1 | grep -cE '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' || true)
    note "lỗi trong nhật ký ứng dụng 20 phút qua: ${n:-0}"
    [ "${n:-0}" = 0 ] || docker logs --since 20m "${PREFIX}app" 2>&1 | grep -E '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' | tail -n 5 | cut -c1-300
    # the contact page still shows its form
    n=$(curl -s "https://${HOST}/lien-he" | grep -c 'wire:submit\|<form' || true)
    expect "trang liên hệ vẫn có biểu mẫu" "$([ "${n:-0}" -gt 0 ] && echo 1 || echo 0)" "1"
    # #861: the admin sign-in page renders on Blaze-compiled components
    n=$(curl -s -o /dev/null -w '%{http_code}' "https://${HOST}/admin/login")
    expect "trang đăng nhập admin trả 200" "$n" "200"
}
