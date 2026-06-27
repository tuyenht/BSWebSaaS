#!/usr/bin/env python3
"""
r2_prune_backups.py — Retention pruning for db-backups/ in Cloudflare R2.

Retention policy:
  - DAILY  : keep ALL backups from the last 7 days
  - WEEKLY : keep the newest backup per ISO week for weeks 2–5
             (i.e. days 7–35 ago, 4 weekly snapshots)
  - PURGE  : delete everything else (> 35 days old or extras within a week)

Uses R2 S3-compatible API (stdlib urllib + hmac — no boto3 required).
Deletions go through `wrangler r2 object delete --remote`.

Required env vars (all set by backup-prod-db.sh):
  CLOUDFLARE_API_TOKEN, R2_BUCKET, R2_ENDPOINT, R2_ACCESS_KEY_ID,
  R2_SECRET_ACCESS_KEY, R2_REGION
"""

import os
import sys
import hmac
import hashlib
import datetime
import subprocess
import urllib.request
import urllib.parse
import xml.etree.ElementTree as ET
from collections import defaultdict

# ── Config ────────────────────────────────────────────────────────────────────
BUCKET          = os.environ["R2_BUCKET"]            # "baoson-media"
ENDPOINT        = os.environ["R2_ENDPOINT"].rstrip("/")
ACCESS_KEY      = os.environ["R2_ACCESS_KEY_ID"]
SECRET_KEY      = os.environ["R2_SECRET_ACCESS_KEY"]
REGION          = os.environ.get("R2_REGION", "auto")
CF_TOKEN        = os.environ["CLOUDFLARE_API_TOKEN"]

R2_PREFIX       = "db-backups/"
BACKUP_STEM     = "db-bswebsaas-"
DAILY_DAYS      = 7
WEEKLY_COUNT    = 4

# ── AWS Signature V4 (stdlib only) ────────────────────────────────────────────

def _sign(key: bytes, msg: str) -> bytes:
    return hmac.new(key, msg.encode("utf-8"), hashlib.sha256).digest()


def _signing_key(date_stamp: str) -> bytes:
    k = _sign(("AWS4" + SECRET_KEY).encode("utf-8"), date_stamp)
    k = _sign(k, REGION)
    k = _sign(k, "s3")
    k = _sign(k, "aws4_request")
    return k


def _s3_request(method: str, path: str, query_params: list[tuple]) -> urllib.request.Request:
    """Build a signed S3 GET/DELETE request for R2."""
    parsed = urllib.parse.urlparse(ENDPOINT)
    host   = parsed.netloc

    now        = datetime.datetime.now(datetime.timezone.utc)
    amz_date   = now.strftime("%Y%m%dT%H%M%SZ")
    date_stamp = now.strftime("%Y%m%d")

    # Canonical query string — params MUST be sorted lexicographically
    canonical_qs = "&".join(
        f"{urllib.parse.quote(k, safe='')}={urllib.parse.quote(str(v), safe='')}"
        for k, v in sorted(query_params)
    )

    payload_hash = hashlib.sha256(b"").hexdigest()

    canonical_headers = (
        f"host:{host}\n"
        f"x-amz-content-sha256:{payload_hash}\n"
        f"x-amz-date:{amz_date}\n"
    )
    signed_headers = "host;x-amz-content-sha256;x-amz-date"

    canonical_request = "\n".join([
        method,
        path,
        canonical_qs,
        canonical_headers,
        signed_headers,
        payload_hash,
    ])

    credential_scope = f"{date_stamp}/{REGION}/s3/aws4_request"
    string_to_sign = "\n".join([
        "AWS4-HMAC-SHA256",
        amz_date,
        credential_scope,
        hashlib.sha256(canonical_request.encode("utf-8")).hexdigest(),
    ])

    sig = hmac.new(
        _signing_key(date_stamp),
        string_to_sign.encode("utf-8"),
        hashlib.sha256,
    ).hexdigest()

    auth = (
        f"AWS4-HMAC-SHA256 "
        f"Credential={ACCESS_KEY}/{credential_scope}, "
        f"SignedHeaders={signed_headers}, "
        f"Signature={sig}"
    )

    url = f"{ENDPOINT}{path}"
    if canonical_qs:
        url += "?" + canonical_qs

    return urllib.request.Request(
        url,
        method=method,
        headers={
            "Authorization":           auth,
            "x-amz-date":              amz_date,
            "x-amz-content-sha256":    payload_hash,
        },
    )


# ── R2 operations ─────────────────────────────────────────────────────────────

def list_objects(prefix: str) -> list[dict]:
    """Return all objects under prefix (handles truncated responses)."""
    results = []
    continuation_token = None

    while True:
        params: list[tuple] = [
            ("list-type", "2"),
            ("max-keys",  "1000"),
            ("prefix",    prefix),
        ]
        if continuation_token:
            params.append(("continuation-token", continuation_token))

        req = _s3_request("GET", f"/{BUCKET}", params)
        with urllib.request.urlopen(req) as resp:
            body = resp.read()

        ns   = {"s3": "http://s3.amazonaws.com/doc/2006-03-01/"}
        tree = ET.fromstring(body)

        for content in tree.findall("s3:Contents", ns):
            key  = content.find("s3:Key", ns).text
            size = int(content.find("s3:Size", ns).text)
            results.append({"key": key, "size": size})

        is_truncated = tree.findtext("s3:IsTruncated", default="false", namespaces=ns)
        if is_truncated.lower() != "true":
            break
        continuation_token = tree.findtext("s3:NextContinuationToken", namespaces=ns)
        if not continuation_token:
            break

    return results


def delete_via_wrangler(key: str) -> bool:
    """Delete a single R2 object via wrangler (reuses proven upload path)."""
    result = subprocess.run(
        ["npx", "--yes", "wrangler", "r2", "object", "delete",
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
