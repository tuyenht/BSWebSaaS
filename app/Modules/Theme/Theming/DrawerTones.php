<?php

declare(strict_types=1);

namespace App\Modules\Theme\Theming;

/**
 * The phone menu's colours (the drawer of theme::partials.header-bar), derived per site from its surface, ink
 * and brand seeds. Every role is a neutral mix of the site's own ink and surface; the brand stays on the
 * call-to-action row only. CSS can mix colours but cannot search until a contrast is met, so the roles
 * are computed here (ReadableColor's readable search) and printed on the drawer as --sf-dw-* variables.
 *
 * The panel is not fully opaque: light is the panel colour at 96% over a scrim of 34% ink, dark at 97% over
 * 44%. Every text role is therefore checked against what the panel can look like: solid, and over a white
 * or a black page pixel under the scrim (seen()).
 *
 * Roles: bg (the panel) · ink (a normal label, 7:1; 9:1 on dark) · strong (an open, trail or current label,
 * the current row's bar, the selected tab's underline and the focus ring, 7:1; 9:1 on dark) · sub and muted (secondary
 * text and icons, 4.5:1) · track and hair (the hover fill and the rules) · edge (the panel's inline-end
 * line) · pill / tap / press (the current row's fill, a pressed row, a pressed current row) · ctl (control
 * borders, 3:1) · on (the current row's label, 7:1 on its pill and 4.5:1 pressed) · thumb (a chosen
 * segment's fill).
 */
final class DrawerTones
{
    /** The panel's opacity and the scrim's ink share, per tone. */
    public const array TONES = [
        'light' => ['panel' => 0.96, 'scrim' => 0.34],
        'dark' => ['panel' => 0.97, 'scrim' => 0.44],
    ];

    /** The colour roles each tone defines, in the order they are printed. */
    public const array ROLES = ['bg', 'ink', 'strong', 'sub', 'muted', 'track', 'hair', 'edge', 'thumb', 'pill', 'tap', 'press', 'ctl', 'on'];

    /** A label on the panel (WCAG AAA for normal text). */
    public const float LABEL = 7.0;

    /** A label on the dark panel: the owner's floor there is higher. */
    public const float DARK_LABEL = 9.0;

    /** Secondary text and icons. */
    public const float SECONDARY = 4.5;

    /** How well black must read on every backdrop of the light panel, so its labels can reach 7:1. */
    private const float LIGHT_ROOM = 12.0;

    /** Control borders and other non-text marks (WCAG 2.2 non-text contrast). */
    public const float CONTROL = 3.0;

    /** The readable search's steps from a colour to black or white. */
    private const int STEPS = 40;

    /** Below this contrast against the panel the brand-filled call-to-action row gets a ring. */
    private const float CTA_FILL = 3.0;

    /**
     * Both tones and the scrim's colour; null when the surface or the ink is not a hex colour (the
     * stylesheet's own fallbacks are used then). A brand that is not hex gets no call-to-action ring.
     *
     * @return array{scrim: string, light: array<string, string>, dark: array<string, string>, light_ring: bool, dark_ring: bool}|null
     */
    public static function derive(string $surface, string $ink, string $brand): ?array
    {
        $light = self::tone($surface, $ink, $brand, false);
        $dark = self::tone($surface, $ink, $brand, true);
        if ($light === null || $dark === null) {
            return null;
        }

        return ['scrim' => $light['scrim'], 'light' => $light['roles'], 'dark' => $dark['roles'], 'light_ring' => $light['ring'], 'dark_ring' => $dark['ring']];
    }

    /**
     * The drawer's CSS variables for one tone, as a style attribute's text ("--sf-dw-bg:#…;…"); null when
     * the seeds cannot be measured. Every value is a hex colour of ours or a fixed shadow, never a seed's text.
     */
    public static function css(string $surface, string $ink, string $brand, bool $dark): ?string
    {
        $tone = self::tone($surface, $ink, $brand, $dark);
        if ($tone === null) {
            return null;
        }

        ['panel' => $panel, 'scrim' => $scrim] = self::TONES[$dark ? 'dark' : 'light'];
        $css = '';
        foreach (self::ROLES as $role) {
            $css .= '--sf-dw-'.$role.':'.$tone['roles'][$role].';';
        }
        $css .= '--sf-dw-panel:'.$tone['roles']['bg'].self::alpha($panel).';--sf-dw-scrim:'.$tone['scrim'].self::alpha($scrim).';';

        return $css.'--sf-dw-cta-ring:'.($tone['ring'] ? 'inset 0 0 0 1.5px '.$tone['roles']['ctl'] : 'none');
    }

    /**
     * One tone: its roles, the scrim's colour (the readable ink) and whether the call-to-action row needs a ring.
     *
     * @return array{roles: array<string, string>, scrim: string, ring: bool}|null
     */
    private static function tone(string $surface, string $ink, string $brand, bool $dark): ?array
    {
        $s = ReadableColor::mix($surface, $surface, 1.0);
        $k0 = ReadableColor::mix($ink, $ink, 1.0);
        if ($s === null || $k0 === null) {
            return null;
        }
        // An ink too light on the surface is first made readable on it (a misconfigured seed).
        $k = (ReadableColor::ratio($k0, $s) ?? 0.0) < self::LABEL ? self::readable($k0, [$s], self::LABEL) : $k0;
        $roles = $dark ? self::dark($s, $k, $k0) : self::light($s, $k, $k0);

        return ['roles' => $roles, 'scrim' => $k, 'ring' => (ReadableColor::ratio($brand, $roles['bg']) ?? self::CTA_FILL) < self::CTA_FILL];
    }

    /**
     * What a colour on the panel can look like: itself (a solid panel), and the panel over a white and a black
     * page pixel under the scrim, for the readable ink and the site's own ink (the scrim takes the first).
     *
     * @return list<string>
     */
    public static function seen(string $color, string $ink, string $rawInk, bool $dark): array
    {
        ['panel' => $panel, 'scrim' => $scrim] = self::TONES[$dark ? 'dark' : 'light'];
        $seen = [$color];
        foreach ([$ink, $rawInk] as $k) {
            foreach (['#ffffff', '#000000'] as $page) {
                $seen[] = self::mix($color, self::mix($k, $page, $scrim), $panel);
            }
        }

        return $seen;
    }

    /**
     * The light tone: the page's own background (97% surface), ink-based greys.
     *
     * @return array<string, string>
     */
    private static function light(string $s, string $k, string $k0): array
    {
        $bg = self::mix($s, $k, 0.97);
        // A mid-grey surface leaves too little room for 7:1 labels: lighten the panel until black reads at 12:1.
        for ($i = 0; $i < 80 && self::least('#000000', self::seen($bg, $k, $k0, false)) < self::LIGHT_ROOM; $i++) {
            $bg = self::mix($bg, '#ffffff', 0.96);
        }
        $on = self::seen($bg, $k, $k0, false);
        $track = self::mix($k, $bg, 0.09);
        $hair = self::mix($k, $bg, 0.13);
        $strong = self::readable($k, $on, self::LABEL);

        // The current row's pill: the lightest ink share (8–15%) seen against the panel and every backdrop.
        $share = 0.16;
        for ($step = 8; $step <= 15; $step++) {
            $pill = self::mix($k, $bg, $step / 100);
            if ((ReadableColor::ratio($pill, $bg) ?? 0.0) >= 1.1 && self::least($pill, $on) >= 1.06) {
                $share = $step / 100;
                break;
            }
        }
        $pill = self::mix($k, $bg, $share);
        $press = self::mix($k, $bg, min($share + 0.05, 0.25));
        $around = [...$on, $track, $hair];

        return [
            'bg' => $bg,
            'ink' => self::readable(self::mix($k, $s, 0.86), $on, self::LABEL),
            'strong' => $strong,
            'sub' => self::readable(self::mix($k, $s, 0.74), $around, self::SECONDARY),
            'muted' => self::readable(self::mix($k, $s, 0.62), $around, self::SECONDARY),
            'track' => $track,
            'hair' => $hair,
            'edge' => $hair,
            'thumb' => $s,
            'pill' => $pill,
            'tap' => self::mix($k, $bg, 0.05),
            'press' => $press,
            'ctl' => self::readable(self::mix($k, $bg, 0.5), [$track, $hair, ...$on, $s], self::CONTROL),
            'on' => self::readable(self::readable($strong, [$pill], self::LABEL), [$press], self::SECONDARY),
        ];
    }

    /**
     * The dark tone: the ink raised a little with white (its chroma capped so it never reads as mud), darkened
     * until a soft label (88% surface) reads 7:1 and white 9:1 on every backdrop; surface-based greys.
     *
     * @return array<string, string>
     */
    private static function dark(string $s, string $k, string $k0): array
    {
        $bg = self::capChroma(self::mix($k, '#ffffff', 0.92));
        // …and further while even white stays under the dark label floor (a grey ink gives a light panel).
        for ($i = 0; $i < 80 && (self::least(self::mix($s, $bg, 0.88), self::seen($bg, $k, $k0, true)) < self::LABEL
            || self::least('#ffffff', self::seen($bg, $k, $k0, true)) < self::DARK_LABEL); $i++) {
            $bg = self::mix($bg, '#000000', 0.96);
        }
        $on = self::seen($bg, $k, $k0, true);
        $track = self::mix($s, $bg, 0.07);
        $hair = self::mix($s, $bg, 0.1);
        $strong = self::readable($s, $on, self::DARK_LABEL);

        // The current row's pill: 13% surface, less until the current label reaches 7:1 on it (6% at least).
        $step = 13;
        while ($step >= 7) {
            $pill = self::mix($s, $bg, $step / 100);
            if ((ReadableColor::ratio(self::readable($strong, [$pill], self::LABEL), $pill) ?? 0.0) >= self::LABEL) {
                break;
            }
            $step--;
        }
        $pill = self::mix($s, $bg, $step / 100);
        $press = self::mix($s, $bg, $step / 100 + 0.06);
        $around = [...$on, $track, $hair];

        return [
            'bg' => $bg,
            'ink' => self::readable(self::mix($s, $bg, 0.88), $on, self::DARK_LABEL),
            'strong' => $strong,
            'sub' => self::readable(self::mix($s, $bg, 0.74), $around, self::SECONDARY),
            'muted' => self::readable(self::mix($s, $bg, 0.6), $around, self::SECONDARY),
            'track' => $track,
            'hair' => $hair,
            'edge' => self::mix($s, $bg, 0.16),
            'thumb' => $bg,
            'pill' => $pill,
            'tap' => self::mix($s, $bg, 0.08),
            'press' => $press,
            'ctl' => self::readable(self::mix($s, $bg, 0.45), [$track, $hair, ...$on], self::CONTROL),
            'on' => self::readable(self::readable($strong, [$pill], self::LABEL), [$press], self::SECONDARY),
        ];
    }

    /** A dark panel's chroma capped (OKLCH): 0.02 for yellows to olives (45–115°), else 0.08. */
    private static function capChroma(string $hex): string
    {
        $lch = ReadableColor::oklch($hex);
        if ($lch === null) {
            return $hex;
        }
        [$l, $c, $h] = $lch;
        $cap = $h >= 45 && $h <= 115 ? 0.02 : 0.08;

        return $c > $cap ? ReadableColor::fromOklch($l, $cap, $h) : $hex;
    }

    /** @param  list<string>  $backgrounds */
    private static function least(string $color, array $backgrounds): float
    {
        $least = INF;
        foreach ($backgrounds as $background) {
            $least = min($least, ReadableColor::ratio($color, $background) ?? 0.0);
        }

        return $least;
    }

    /**
     * The colour moved toward black (on a light first background) or white (on a dark one) in 40 steps until it
     * reaches the minimum on every background; the extreme when no step does. Finer than ReadableColor::onAll's
     * 20 steps, so a role stays as close to its mix as the floor allows (the approved recipe's search).
     *
     * @param  list<string>  $backgrounds
     */
    private static function readable(string $color, array $backgrounds, float $minimum): string
    {
        $first = $backgrounds[0] ?? null;
        if ($first === null) {
            return $color;
        }
        $to = (ReadableColor::ratio($first, '#000000') ?? 0.0) > 4.6 ? '#000000' : '#ffffff';
        for ($step = 0; $step <= self::STEPS; $step++) {
            $candidate = self::mix($color, $to, 1 - $step / self::STEPS);
            if (self::least($candidate, $backgrounds) >= $minimum) {
                return $candidate;
            }
        }

        return $to;
    }

    private static function mix(string $first, string $second, float $firstShare): string
    {
        return ReadableColor::mix($first, $second, $firstShare) ?? $first;
    }

    /** An opacity as the two hex digits of #rrggbbaa. */
    private static function alpha(float $opacity): string
    {
        return sprintf('%02x', (int) round($opacity * 255));
    }
}
