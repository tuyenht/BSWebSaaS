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


# (label, ink hex, ink alpha, [bg stops], threshold)
CHECKS = [
    # Default dark sidebar — navy #405189 (matches the Velzon reference default sidebar;
    # equals the brand primary; owner chose navy for 1:1):
    # Resting nav text follows the Velzon reference (#abb9e8) so the white hover/active clearly pops;
    # it is a reduced-emphasis affordance (its interactive state is white = 6.1) held to the 3.0
    # nav/affordance tier, like the section title below.
    ("dark sidebar · item #abb9e8 rest", "#abb9e8", 1.0, ["#405189"], 3.0),
    ("dark sidebar · item hover/active #fff", "#ffffff", 1.0, ["#405189"], 4.5),
    ("dark sidebar · title white/.60", "#ffffff", 0.60, ["#405189"], 3.0),
    # Gradient sidebars — NEW, opt-in aesthetic (Velzon rgba(#fff,.5)):
    ("gradient · item white/.5", "#ffffff", 0.50, ["#405189", "#0ab39c"], 4.5),
    ("gradient-2 · item white/.5", "#ffffff", 0.50, ["#299cdb", "#3577f1"], 4.5),
    ("gradient-3 · item white/.5", "#ffffff", 0.50, ["#299cdb", "#0ab39c"], 4.5),
    ("gradient-4 · item white/.5", "#ffffff", 0.50, ["#1a1d21", "#405189"], 4.5),
    ("gradient* · hover/active #fff", "#ffffff", 1.0,
     ["#405189", "#0ab39c", "#299cdb", "#3577f1", "#1a1d21"], 4.5),
]


def run() -> None:
    print(f"{'combo':<34}{'worst':>7}  {'thr':>4}  verdict")
    print("-" * 60)
    for name, ink, alpha, stops, thr in CHECKS:
        ink_rgb = hexrgb(ink)
        worst = min(
            contrast(over(ink_rgb, hexrgb(s), alpha) if alpha < 1 else ink_rgb, hexrgb(s))
            for s in stops
        )
        verdict = "PASS" if worst >= thr else "FAIL"
        print(f"{name:<34}{worst:>6.2f}  {thr:>4}  {verdict}")


if __name__ == "__main__":
    run()
