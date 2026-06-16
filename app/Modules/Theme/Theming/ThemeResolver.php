<?php

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
    private const DEFAULTS = [
        'primary' => '#0a66c2',
        'surface' => '#ffffff',
        'text' => '#111111',
        'font' => 'system-ui, sans-serif',
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
