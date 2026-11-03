"""Build public/fonts/inter-latin-vi.woff2, the one Inter file a Vietnamese storefront page needs.

From public/fonts/Inter-roman.woff2 (Inter 4, OFL; no Reserved Font Name, so a subset keeps the
name): the Latin and Vietnamese code points of the two Google Fonts subsets it replaces, the
default text features (fractions dropped) plus tabular figures, the optical size pinned to 14 and
the weight axis kept from 400 to 900. Outlines match the Fontsource files it replaces at every
weight checked. One request instead of two, 39 KB instead of 59 KB.

Needs fontTools 4.55 and brotli, e.g. `python3 -m pip install --target /tmp/ft fonttools==4.55.3
brotli==1.1.0`, then: python3 scripts/build-inter-latin-vi.py /tmp/ft
"""

import io
import sys
from pathlib import Path

if len(sys.argv) > 1:
    sys.path.insert(0, sys.argv[1])

from fontTools import subset  # noqa: E402
from fontTools.ttLib import TTFont  # noqa: E402
from fontTools.varLib import instancer  # noqa: E402

# Keep in step with the unicode-range of the "inter-latin-vi" face in resources/css/storefront.css.
RANGES = (
    "U+0000-00FF, U+0102-0103, U+0110-0111, U+0128-0129, U+0131, U+0152-0153, U+0168-0169, "
    "U+01A0-01A1, U+01AF-01B0, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0300-0301, U+0303-0304, "
    "U+0308-0309, U+0323, U+0329, U+1EA0-1EF9, U+2000-206F, U+2074, U+20AB-20AC, U+2122, U+2191, "
    "U+2193, U+2212, U+2215, U+FEFF, U+FFFD"
)


def code_points(spec: str) -> list[int]:
    points = []
    for part in spec.split(","):
        first, _, last = part.strip()[2:].partition("-")
        points += range(int(first, 16), int(last or first, 16) + 1)
    return points


fonts = Path(__file__).resolve().parent.parent / "public" / "fonts"
font = TTFont(fonts / "Inter-roman.woff2")
options = subset.Options()
options.layout_features = [f for f in options.layout_features if f not in ("frac", "numr", "dnom")] + ["tnum"]
options.hinting = False
options.desubroutinize = True
options.name_IDs = ["*"]
options.name_languages = ["*"]
options.notdef_outline = True
subsetter = subset.Subsetter(options)
subsetter.populate(unicodes=code_points(RANGES))
subsetter.subset(font)

# Subset first, then pin the axes (the instancer trips over glyphs the subsetter has not settled).
buffer = io.BytesIO()
font.save(buffer)
buffer.seek(0)
font = instancer.instantiateVariableFont(TTFont(buffer), {"opsz": 14, "wght": (400, 900)})
font.flavor = "woff2"
font.save(fonts / "inter-latin-vi.woff2")
print((fonts / "inter-latin-vi.woff2").stat().st_size, "bytes")
