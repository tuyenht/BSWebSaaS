#!/usr/bin/env python3
"""Bring the owner's Claude Design work into the repo so every design change shows up in git diff.

Two inputs:

  python3 scripts/design_snapshot.py <project export .zip>
      The whole Claude Design project, exported as a zip. Replaces docs/design-source/ with its
      text files (screens, shared scripts, design-system tokens, the project's notes). Images and
      fonts are left out: they are not read and would bloat the repo. So are `uploads/` (whatever was
      ever attached to a chat, including files from other projects), `_audit/` (the design tool's
      own test harness and backups) and `_tmp/` (scratch pages and screenshots a round left behind):
      none of them is design. The project's CLAUDE.md is
      renamed, because Claude Code would otherwise load it as instructions for this repo.

  python3 scripts/design_snapshot.py <saved artifact .html> "docs/design-source/<Screen>.dc.html"
      One screen the owner published as a claude.ai artifact. The page is a bundle; the screen's
      .dc.html travels as the `__bundler/template` JSON string, with its assets referenced by id.
"""
import json
import re
import shutil
import sys
import zipfile
from pathlib import Path

TARGET = Path("docs/design-source")
TEXT_SUFFIXES = {".html", ".md", ".js", ".css", ".json"}
RENAMES = {"CLAUDE.md": "design-project-CLAUDE.md"}
SKIPPED_FOLDERS = {"uploads", "_audit", "_tmp"}


def from_zip(source: str) -> None:
    if TARGET.exists():
        shutil.rmtree(TARGET)
    kept = 0
    with zipfile.ZipFile(source) as archive:
        for info in archive.infolist():
            name = Path(info.filename)
            if info.is_dir() or name.suffix not in TEXT_SUFFIXES or name.name.startswith("."):
                continue
            if name.parts[0] in SKIPPED_FOLDERS:
                continue
            out = TARGET / RENAMES.get(str(name), str(name))
            out.parent.mkdir(parents=True, exist_ok=True)
            out.write_bytes(archive.read(info))
            kept += 1
    print(f"{TARGET}: {kept} files — review with: git status {TARGET} && git diff --stat {TARGET}")


def from_artifact(source: str, target: str) -> None:
    html = Path(source).read_text(encoding="utf-8")
    match = re.search(r'<script type="__bundler/template">(.*?)</script>', html, re.S)
    if match is None:
        sys.exit(f"{source}: no __bundler/template block — not a published Claude Design page")
    Path(target).write_text(json.loads(match.group(1)), encoding="utf-8")
    print(target)


def main() -> None:
    if len(sys.argv) == 2 and sys.argv[1].endswith(".zip"):
        from_zip(sys.argv[1])
    elif len(sys.argv) == 3:
        from_artifact(sys.argv[1], sys.argv[2])
    else:
        sys.exit(__doc__)


if __name__ == "__main__":
    main()
