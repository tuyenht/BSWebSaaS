<?php

declare(strict_types=1);

namespace App\Modules\Theme\Theming;

use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Models\SiteSetting;
use Illuminate\Database\Eloquent\Builder;

/**
 * The site settings a storefront page reads — the `theme` group (design tokens), the
 * `storefront` group (header, footer), the `seo` group (SiteSeo), the `catalog` group (the
 * catalog mode and the contact page the product page's quote button follows) and the site's time zone
 * (`general`/`timezone`, for how long the edge may keep the page) — loaded in ONE query and
 * memoized per site for the request, so the tokens, the header, the footer and the page's search
 * settings share a single read. Registered scoped
 * (ThemeServiceProvider), so the memo is flushed between Octane requests; it is keyed by site
 * as well, and dropped whenever a setting is saved, so a write in the same request is seen.
 * No site context → no query, no values.
 */
final class StorefrontSettings
{
    /** @var list<string> */
    private const array GROUPS = ['theme', 'storefront', 'seo', 'catalog'];

    /** @var array<int, array<string, array<string, mixed>>> site id → group → key → value */
    private array $memo = [];

    public function __construct(private readonly CurrentSite $current) {}

    /** One setting's stored value, or null when the site has none (or there is no site). */
    public function get(string $group, string $key): mixed
    {
        if (! $this->current->hasId()) {
            return null;
        }

        $siteId = $this->current->id();
        $this->memo[$siteId] ??= $this->load();

        return $this->memo[$siteId][$group][$key] ?? null;
    }

    /** Drop every memo — for a writer that changed a setting within this request. */
    public function forget(): void
    {
        $this->memo = [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function load(): array
    {
        $values = [];
        $rows = SiteSetting::query()
            ->where(static fn (Builder $q): Builder => $q->whereIn('group_name', self::GROUPS)
                ->orWhere(static fn (Builder $q): Builder => $q->where('group_name', 'general')->where('key', 'timezone')))
            ->get(['group_name', 'key', 'value']);
        foreach ($rows as $setting) {
            $values[(string) $setting->group_name][(string) $setting->key] = $setting->value;
        }

        return $values;
    }
}
