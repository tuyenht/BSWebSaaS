#!/usr/bin/env python3
"""Extract the design source from a Claude Design page published as a claude.ai artifact.

The published page is a bundle: the screen's .dc.html travels as the `__bundler/template`
JSON string, with images, fonts and scripts in `__bundler/manifest`. Only the template is
kept — it is the part people read and diff between design versions.

Usage: python3 scripts/design_snapshot.py <saved artifact .html> "docs/design_handoff_<slug>/<Screen>.dc.html"
"""
import json
import re
import sys


def main() -> None:
    if len(sys.argv) != 3:
        sys.exit(__doc__)
    source, target = sys.argv[1], sys.argv[2]
    html = open(source, encoding="utf-8").read()
    match = re.search(r'<script type="__bundler/template">(.*?)</script>', html, re.S)
    if match is None:
        sys.exit(f"{source}: no __bundler/template block — not a published Claude Design page")
    with open(target, "w", encoding="utf-8") as out:
        out.write(json.loads(match.group(1)))
    print(target)


if __name__ == "__main__":
    main()
