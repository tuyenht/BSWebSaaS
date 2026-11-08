#!/usr/bin/env bash
# Deploy launcher. The owner's tmux line reads THIS file out of a pinned commit (never from a working tree):
#   bash -c "$(git -C /opt/bswebsaas/dev show <commit>:scripts/deploy/launch.sh)" launch <commit> <engine blob> <release file> <release sha256> [step|dump|rollback]
# It copies the engine of that commit into a private folder, checks the copy's blob hash and the release
# file's sha256 against the values written in the line, then runs the copy. Any mismatch refuses to start.
set -Eeuo pipefail
die() { printf '!!!!! DỪNG: %s\n' "$*" >&2; exit 1; }
[ "$#" -ge 4 ] || die "cần: <commit> <blob bộ khung> <tệp khai báo> <sha256 tệp khai báo> [bước]"
commit=$1 blob=$2 rel=$3 relsha=$4 step=${5:-0}
dev=${DEPLOY_DEV_REPO:-/opt/bswebsaas/dev}
runs=${DEPLOY_RUNS_DIR:-/opt/bswebsaas/dev/plans/reports/deploy-runs}
[[ "$commit" =~ ^[0-9a-f]{40}$ ]] || die "commit phải đủ 40 ký tự"
[[ "$blob" =~ ^[0-9a-f]{40}$ ]] || die "blob bộ khung phải đủ 40 ký tự"
[[ "$relsha" =~ ^[0-9a-f]{64}$ ]] || die "sha256 tệp khai báo phải đủ 64 ký tự"
[ -f "$rel" ] || die "không thấy tệp khai báo $rel"
( umask 077 && mkdir -p "$runs/bin" ) && chmod 700 "$runs" "$runs/bin"
eng=$runs/bin/deploy-$blob.sh
git -C "$dev" show "$commit:scripts/deploy/deploy.sh" > "$eng.tmp" || die "không đọc được bộ khung ở commit $commit"
[ "$(git hash-object --no-filters "$eng.tmp")" = "$blob" ] || { rm -f "$eng.tmp"; die "bộ khung ở commit ${commit:0:8} khác blob đã duyệt"; }
mv -f "$eng.tmp" "$eng"
copy=$runs/bin/release-$relsha.sh
cp "$rel" "$copy.tmp"
[ "$(sha256sum "$copy.tmp" | cut -d' ' -f1)" = "$relsha" ] || { rm -f "$copy.tmp"; die "tệp khai báo khác mã kiểm đã duyệt (đã bị sửa sau khi duyệt?)"; }
mv -f "$copy.tmp" "$copy"
chmod 600 "$eng" "$copy"
exec bash "$eng" run "$copy" "$blob" "$relsha" "$commit" "$step"
