#!/usr/bin/env python3
"""WCAG contrast check for the admin-chrome sidebar palettes.

Gate (CLAUDE.md §6): body/link text >= 4.5, icons + 11px micro-labels >= 3.0.
Handles alpha-composited ink (e.g. rgba(#fff,.5)) and gradient backgrounds
(measured at every stop; the worst stop wins — for light ink that is the
lightest stop). Pure stdlib; run: python3 scripts/admin_aa_contrast.py
"""
from __future__ import annotations


def _srgb_to_lin(c: float) -> float:
    c /= 255.0
    return c / 12.92 if c <= 0.04045 else ((c + 0.055) / 1.055) ** 2.4


def luminance(rgb: tuple[int, int, int]) -> float:
    r, g, b = (_srgb_to_lin(x) for x in rgb)
    return 0.2126 * r + 0.7152 * g + 0.0722 * b


def contrast(fg: tuple[int, int, int], bg: tuple[int, int, int]) -> float:
    l1, l2 = luminance(fg), luminance(bg)
    hi, lo = max(l1, l2), min(l1, l2)
    return (hi + 0.05) / (lo + 0.05)


def hexrgb(h: str) -> tuple[int, int, int]:
    h = h.lstrip("#")
    return (int(h[0:2], 16), int(h[2:4], 16), int(h[4:6], 16))


def over(fg: tuple[int, int, int], bg: tuple[int, int, int], alpha: float):
    """Alpha-composite opaque-ink fg at `alpha` over bg."""
    return tuple(round(alpha * f + (1 - alpha) * b) for f, b in zip(fg, bg))


# (label, ink hex, ink alpha, [bg stops], threshold) — these MUST meet the gate.
# Default dark sidebar = navy #405189 (the Velzon reference default; equals the brand primary).
# What must pass is the INTERACTIVE state and the micro-label; the muted resting colour is a
# recorded deviation below (not a relaxed threshold).
CHECKS = [
    ("dark sidebar · hover/active #fff", "#ffffff", 1.0, ["#405189"], 4.5),
    ("dark sidebar · title white/.60", "#ffffff", 0.60, ["#405189"], 3.0),
]

# Conscious, owner-approved exceptions that sit BELOW the strict gate. Recorded honestly here —
# measured against the real 4.5 line, NOT by lowering the threshold to fake a pass — each with the
# reason it is accepted. A deviation never fails the run; a CHECK failing does.
DEVIATIONS = [
    ("dark sidebar · item #abb9e8 rest", "#abb9e8", 1.0, ["#405189"], 4.5,
     "Velzon reference resting; muted so the white hover/active pops. Interactive state = white 7.6."),
    ("gradient · item white/.5", "#ffffff", 0.50, ["#405189", "#0ab39c"], 4.5,
     "opt-in aesthetic gradient sidebar, grandfathered"),
    ("gradient-2 · item white/.5", "#ffffff", 0.50, ["#299cdb", "#3577f1"], 4.5, "opt-in gradient"),
    ("gradient-3 · item white/.5", "#ffffff", 0.50, ["#299cdb", "#0ab39c"], 4.5, "opt-in gradient"),
    ("gradient-4 · item white/.5", "#ffffff", 0.50, ["#1a1d21", "#405189"], 4.5, "opt-in gradient"),
    ("gradient* · hover/active #fff", "#ffffff", 1.0,
     ["#405189", "#0ab39c", "#299cdb", "#3577f1", "#1a1d21"], 4.5, "opt-in gradient"),
]


def _worst(ink: str, alpha: float, stops: list[str]) -> float:
    ink_rgb = hexrgb(ink)
    return min(
        contrast(over(ink_rgb, hexrgb(s), alpha) if alpha < 1 else ink_rgb, hexrgb(s))
        for s in stops
    )


def run() -> None:
    print(f"{'combo':<36}{'worst':>7}  {'thr':>4}  verdict")
    print("-" * 64)
    failed = 0
    for name, ink, alpha, stops, thr in CHECKS:
        worst = _worst(ink, alpha, stops)
        ok = worst >= thr
        failed += int(not ok)
        print(f"{name:<36}{worst:>6.2f}  {thr:>4}  {'PASS' if ok else 'FAIL'}")

    print("\naccepted deviations (owner-approved, below the strict 4.5 gate):")
    print("-" * 64)
    for name, ink, alpha, stops, thr, why in DEVIATIONS:
        worst = _worst(ink, alpha, stops)
        print(f"{name:<36}{worst:>6.2f}  {thr:>4}  DEVIATION")
        print(f"    - {why}")

    if failed:
        raise SystemExit(f"\n{failed} contrast CHECK(s) FAILED — fix before shipping")


if __name__ == "__main__":
    run()
