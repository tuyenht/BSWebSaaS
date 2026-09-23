<?php

declare(strict_types=1);

namespace App\Modules\Theme\Theming;

use App\Modules\Tenancy\Models\SiteSetting;

/**
 * Per-site customization over the SHARED master template (design.md §19): every site
 * uses the same theme Blade, but overrides design tokens (colors/fonts/…) to fit its
 * brand. No customer code runs (ADR-04) — overrides are CONFIG, applied as CSS
 * variables. Tokens are sanitized (CSS context) to prevent style breakout.
 *
 * Octane-safe: reads the current site's settings per call (RLS-scoped); no site
 * context → safe defaults only.
 */
final class ThemeResolver
{
    /** @var array<string, string> */
    private const array DEFAULTS = [
        // #3b82f6, not the more obvious #0a66c2: storefront.css puts `ink` as TEXT on
        // top of `primary` (buttons, CTAs) as well as using it for headings — a site
        // that never configures a theme gets this pairing for free, and #0a66c2 only
        // clears ~3.3:1 against #111111 (WCAG2AA needs 4.5:1 for normal text; axe
        // caught it live in the `cwv` CI job, 23/09). #3b82f6 clears ~5.1:1.
        'primary' => '#3b82f6',
        'surface' => '#ffffff',
        'text' => '#111111',
        'font' => 'system-ui, sans-serif',
        // Storefront-specific roles (design.md §19 token-hardening): `ink` is the
        // near-black used for headings, dark-surface fills (header/footer), and text
        // on top of `primary` — one brand decision, reused across those three
        // contexts. `body` is long-form copy, deliberately softer than `ink` for
        // readability over a full article.
        'ink' => '#111111',
        'body' => '#333333',
    ];

    /**
     * @return array<string, string>
     */
    public function tokens(): array
    {
        $setting = SiteSetting::query()
            ->where('group_name', 'theme')
            ->where('key', 'tokens')
            ->first();

        $custom = is_array($setting?->value) ? $setting->value : [];
        $tokens = self::DEFAULTS;

        foreach ($custom as $key => $value) {
            if (is_string($value) && $this->safeKey((string) $key) && $this->safeValue($value)) {
                $tokens[(string) $key] = $value;
            }
        }

        return $tokens;
    }

    private function safeKey(string $key): bool
    {
        return preg_match('/^[a-z0-9-]{1,40}$/', $key) === 1;
    }

    private function safeValue(string $value): bool
    {
        // CSS-value-safe characters only: no ; { } : < > " — prevents style breakout.
        return preg_match('/^[a-zA-Z0-9#,.%()\s_-]{1,80}$/', $value) === 1;
    }
}
