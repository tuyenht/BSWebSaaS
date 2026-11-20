#!/usr/bin/env python3
"""Rebuild tests/.pest/shards.json from the Pest shard logs of green CI runs.

Pest's `--shard` reads that file and splits the test classes by their measured time instead of
by count. Its own `--update-shards` needs a full green local run, which the shared dev database
cannot give, so the times come from CI instead: the per-test durations the shard jobs print,
summed per class and averaged over the runs given (runner speed varies from run to run).

Usage: python3 scripts/ci-shard-timings.py <run-id> [<run-id> ...]
Take full runs on main (nightly or `gh workflow run ci.yml --ref main`) that passed.
Needs `gh` signed in. Classes added after the file was written are dealt out in turn by Pest,
which prints a WARN that the file is out of date: rerun this script then.
"""

import hashlib
import json
import re
import statistics
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path

REPO = 'tuyenht/BSWebSaaS'
OUT = Path(__file__).resolve().parent.parent / 'tests' / '.pest' / 'shards.json'
ANSI = re.compile(r'\x1b\[[0-9;]*m')
# Collision heads each class with one of these labels (State::getTestCaseTitle).
CLASS = re.compile(r'^\s+(?:PASS|FAIL|WARN|NOTI|DEPR|TODO)\s+(Tests\\\S+)')
OTHER_HEADER = re.compile(r'^\s+[A-Z]{4}\s+\S')
TEST = re.compile(r' [✓⨯!-] .* ([0-9]+\.[0-9]+)s\s*$')
STAMP = re.compile(r'^\d{4}-\d{2}-\d{2}T\S+Z ')


def gh(*args: str) -> str:
    return subprocess.run(['gh', *args], check=True, capture_output=True, text=True).stdout


def run_timings(run_id: str) -> dict[str, float]:
    jobs = json.loads(gh('run', 'view', run_id, '--json', 'jobs,conclusion'))
    if jobs['conclusion'] != 'success':
        sys.exit(f'run {run_id} is {jobs["conclusion"]}: use a green run')
    shards = [j for j in jobs['jobs'] if re.match(r'Pest shard \d+/\d+$', j['name'])]
    if not shards:
        sys.exit(f'run {run_id} has no Pest shard jobs')
    timings: dict[str, float] = {}
    for job in shards:
        current = None
        for line in gh('api', f'repos/{REPO}/actions/jobs/{job["databaseId"]}/logs').splitlines():
            line = STAMP.sub('', ANSI.sub('', line))
            if m := CLASS.match(line):
                current = m.group(1)
                timings.setdefault(current, 0.0)
            elif OTHER_HEADER.match(line):
                current = None  # never add a class's times to the class before it
            elif current and (m := TEST.search(line)):
                timings[current] += float(m.group(1))
    return timings


def main() -> None:
    if len(sys.argv) < 2:
        sys.exit(__doc__)
    runs = [run_timings(r) for r in sys.argv[1:]]
    classes = sorted(set().union(*runs))
    timings = {c: round(statistics.mean(r[c] for r in runs if c in r), 2) for c in classes}
    # A log with no durations (a format change, COLLISION_IGNORE_DURATION) would give every class 0 s:
    # Pest would then put every class in shard 1 and run the whole suite in each of the others.
    tests = OUT.parent.parent  # the suites CI shards: Arch runs as its own job, Tools outside CI
    test_files = sum(len(list((tests / d).glob('**/*Test.php'))) for d in ('Unit', 'Feature', 'Concurrency'))
    total = sum(timings.values())
    if len(classes) < 0.9 * test_files or total < 60:
        sys.exit(f'refusing to write: {len(classes)} classes for {test_files} test files, {total:.0f} s in all')
    OUT.parent.mkdir(parents=True, exist_ok=True)
    OUT.write_text(json.dumps({
        'timings': timings,
        'checksum': hashlib.md5('\n'.join(classes).encode()).hexdigest(),
        'updated_at': datetime.now(timezone.utc).isoformat(timespec='seconds'),
    }, indent=4, ensure_ascii=False) + '\n')
    print(f'{OUT}: {len(timings)} classes, {sum(timings.values()) / 60:.1f} min in all')


if __name__ == '__main__':
    main()
