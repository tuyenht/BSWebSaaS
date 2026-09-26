#!/usr/bin/env python3
"""
r2_prune_backups.py — Retention pruning for db-backups/ in Cloudflare R2.

Retention policy:
  - DAILY  : keep ALL backups from the last 7 days
  - WEEKLY : keep the newest backup per ISO week for weeks 2–5
             (i.e. days 7–35 ago, 4 weekly snapshots)
  - PURGE  : delete everything else (> 35 days old or extras within a week)

Listing uses the Cloudflare REST API (Bearer token, stdlib urllib — no boto3).
Deletions go through `wrangler r2 object delete --remote`. Both authenticate with
CLOUDFLARE_API_TOKEN, so the S3 access key — which lacked List/Delete on the bucket
and returned HTTP 403 (backups accumulated unbounded) — is no longer used.

Required env vars (all set by backup-prod-db.sh):
  CLOUDFLARE_API_TOKEN, R2_BUCKET, R2_ENDPOINT
  (account id is read from CLOUDFLARE_ACCOUNT_ID if set, else parsed from R2_ENDPOINT)
"""

import os
import sys
import json
import datetime
import subprocess
import urllib.request
import urllib.parse

# ── Config ────────────────────────────────────────────────────────────────────
BUCKET          = os.environ["R2_BUCKET"]            # e.g. "baoson-db-backups"
ENDPOINT        = os.environ["R2_ENDPOINT"].rstrip("/")
CF_TOKEN        = os.environ["CLOUDFLARE_API_TOKEN"]
CF_API_BASE     = "https://api.cloudflare.com/client/v4"

R2_PREFIX       = "db-backups/"
BACKUP_STEM     = "db-bswebsaas-"
DAILY_DAYS      = 7
WEEKLY_COUNT    = 4


def _account_id() -> str:
    """Cloudflare account id — from CLOUDFLARE_ACCOUNT_ID, else the R2 S3 endpoint
    host (https://<account_id>.r2.cloudflarestorage.com)."""
    explicit = os.environ.get("CLOUDFLARE_ACCOUNT_ID", "").strip()
    if explicit:
        return explicit
    host = urllib.parse.urlparse(ENDPOINT).netloc
    account = host.split(".", 1)[0]
    if not account:
        sys.exit("Cannot determine Cloudflare account id: set CLOUDFLARE_ACCOUNT_ID or R2_ENDPOINT")
    return account


ACCOUNT_ID = _account_id()


# ── R2 operations ─────────────────────────────────────────────────────────────

def list_objects(prefix: str) -> list[dict]:
    """Return all objects under prefix via the Cloudflare REST API (handles pagination).

    Uses the Bearer CLOUDFLARE_API_TOKEN — the same scope wrangler upload/delete rely on —
    instead of the S3 access key (which lacked List perms → HTTP 403).
    """
    results = []
    cursor = None
    base = f"{CF_API_BASE}/accounts/{ACCOUNT_ID}/r2/buckets/{BUCKET}/objects"

    while True:
        params: list[tuple] = [("prefix", prefix), ("per_page", "1000")]
        if cursor:
            params.append(("cursor", cursor))

        req = urllib.request.Request(
            f"{base}?{urllib.parse.urlencode(params)}",
            method="GET",
            headers={
                "Authorization": f"Bearer {CF_TOKEN}",
                "Accept":        "application/json",
            },
        )
        with urllib.request.urlopen(req) as resp:
            payload = json.loads(resp.read())

        if not payload.get("success", False):
            raise RuntimeError(f"R2 list failed: {payload.get('errors')}")

        for obj in (payload.get("result") or []):
            results.append({"key": obj["key"], "size": obj.get("size", 0)})

        info = payload.get("result_info") or {}
        if not info.get("is_truncated"):
            break
        cursor = info.get("cursor")
        if not cursor:
            break

    return results


def delete_via_wrangler(key: str) -> bool:
    """Delete a single R2 object via wrangler (reuses proven upload path)."""
    result = subprocess.run(
        ["npx", "--yes", os.environ.get("WRANGLER_PKG", "wrangler@4.101.0"), "r2", "object", "delete",
         f"{BUCKET}/{key}", "--remote"],
        capture_output=True,
        text=True,
        env={**os.environ, "CLOUDFLARE_API_TOKEN": CF_TOKEN},
    )
    if result.returncode != 0:
        print(f"  [WARN] delete failed for {key}: {result.stderr.strip()}", file=sys.stderr)
        return False
    return True


# ── Retention logic ────────────────────────────────────────────────────────────

def parse_backup_dt(key: str) -> datetime.datetime | None:
    """Extract UTC datetime from filename: db-bswebsaas-YYYYMMDD-HHMMSS.sql.gz"""
    name = os.path.basename(key)
    if not name.startswith(BACKUP_STEM) or not name.endswith(".sql.gz"):
        return None
    ts_str = name[len(BACKUP_STEM):-len(".sql.gz")]
    try:
        dt = datetime.datetime.strptime(ts_str, "%Y%m%d-%H%M%S")
        return dt.replace(tzinfo=datetime.timezone.utc)
    except ValueError:
        return None


def compute_keep_set(
    backups: list[tuple[datetime.datetime, str]],
    now: datetime.datetime,
) -> set[str]:
    """Return set of object keys that should be retained."""
    keep: set[str] = set()

    # Sort newest-first
    sorted_bk = sorted(backups, key=lambda x: x[0], reverse=True)

    cutoff_daily  = now - datetime.timedelta(days=DAILY_DAYS)
    cutoff_weekly = now - datetime.timedelta(days=DAILY_DAYS + WEEKLY_COUNT * 7)

    # Rule 1: retain all backups from the last DAILY_DAYS days
    for dt, key in sorted_bk:
        if dt >= cutoff_daily:
            keep.add(key)

    # Rule 2: retain newest-per-ISO-week for the WEEKLY window
    weekly_seen: dict[tuple, str] = {}
    for dt, key in sorted_bk:
        if cutoff_weekly <= dt < cutoff_daily:
            iso_week = dt.isocalendar()[:2]  # (iso_year, iso_week)
            if iso_week not in weekly_seen:
                weekly_seen[iso_week] = key   # first seen = newest (sorted desc)
    keep.update(weekly_seen.values())

    return keep


def prune(dry_run: bool = False) -> tuple[int, int]:
    """List, compute policy, delete. Returns (kept_count, deleted_count)."""
    print(f"Listing R2 objects under {BUCKET}/{R2_PREFIX} …")
    objects = list_objects(R2_PREFIX)
    print(f"Found {len(objects)} object(s)")

    backups: list[tuple[datetime.datetime, str]] = []
    for obj in objects:
        dt = parse_backup_dt(obj["key"])
        if dt is not None:
            backups.append((dt, obj["key"]))
        else:
            print(f"  [skip] unrecognised key: {obj['key']}")

    if not backups:
        print("No backup objects found — nothing to prune.")
        return 0, 0

    now  = datetime.datetime.now(datetime.timezone.utc)
    keep = compute_keep_set(backups, now)

    to_delete = [
        (dt, key)
        for dt, key in sorted(backups, key=lambda x: x[0])
        if key not in keep
    ]

    print(f"Keeping   {len(keep)} object(s)")
    print(f"Deleting  {len(to_delete)} object(s)")

    deleted = 0
    for dt, key in to_delete:
        age = (now - dt).days
        label = f"[age={age}d]"
        if dry_run:
            print(f"  [DRY-RUN] would delete: {key} {label}")
        else:
            print(f"  Deleting: {key} {label}")
            if delete_via_wrangler(key):
                deleted += 1

    return len(keep), deleted


# ── Entry point ───────────────────────────────────────────────────────────────

if __name__ == "__main__":
    dry_run = "--dry-run" in sys.argv
    kept, deleted = prune(dry_run=dry_run)
    print(f"Pruning complete — kept={kept}, deleted={deleted}")
