<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Theme\Rendering\LayoutSlots;
use App\Modules\Theme\Theming\StorefrontSettings;

/**
 * The blocks a page shows in one of the slots around posts, lists and products (LayoutSlots), for
 * one language. The page's main category speaks first (its `meta.layout_blocks.<slot>`): "none"
 * hides the slot, a block list per language replaces the site's; with no choice of its own the
 * site's default applies (the `storefront.layout_blocks` setting). Within either, a language with
 * no blocks of its own takes the site's default language. An unknown slot, or nothing set, is [].
 *
 * Reads only what the page already holds: the category the caller loaded (never queried here) and
 * the settings the storefront reads in one memoized query per request (StorefrontSettings), so it
 * adds no query to a page that has read any storefront setting. Registered scoped and holding no
 * state of its own, so it never carries one request's answer into the next under Octane.
 */
final readonly class LayoutSlotBlocks
{
    public function __construct(
        private StorefrontSettings $settings,
        private SiteLocale $locales,
    ) {}

    /**
     * @return list<mixed> the slot's blocks ([{type, props}, …])
     */
    public function blocks(string $slot, string $locale, ?Taxonomy $primaryCategory = null): array
    {
        if (! LayoutSlots::has($slot)) {
            return [];
        }

        if ($primaryCategory !== null) {
            $own = Cast::arr(Cast::arr($primaryCategory->meta)[LayoutSlots::META_KEY] ?? null)[$slot] ?? null;
            if ($own === LayoutSlots::NONE) {
                return [];
            }
            $found = is_array($own) ? $this->inLocale($own, $locale) : null;
            if ($found !== null) {
                return $found;
            }
        }

        $site = Cast::arr($this->settings->get(LayoutSlots::SETTING_GROUP, LayoutSlots::SETTING_KEY))[$slot] ?? null;

        return (is_array($site) ? $this->inLocale($site, $locale) : null) ?? [];
    }

    /**
     * The blocks of $locale, else of the site's default language, else null (nothing in either).
     *
     * @param  array<array-key, mixed>  $value  locale → {"blocks": […]}
     * @return list<mixed>|null
     */
    private function inLocale(array $value, string $locale): ?array
    {
        foreach ([$locale, $this->locales->default()] as $candidate) {
            $blocks = is_array($value[$candidate] ?? null) ? ($value[$candidate]['blocks'] ?? null) : null;
            if (is_array($blocks)) {
                return array_values($blocks);
            }
        }

        return null;
    }
}
