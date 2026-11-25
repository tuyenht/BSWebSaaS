<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Categories;

use App\Modules\Admin\Support\BlockKinds;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Theme\Rendering\LayoutSlots;
use App\Modules\Theme\Theming\StorefrontSettings;

/**
 * The two bands of a category page that the category drawer edits (design 10, «Khối trên danh sách
 * / Khối dưới danh sách»): the block slots before and after its list (LayoutSlots). Each band is in
 * one of three modes, read from `taxonomies.meta.layout_blocks.<slot>`: `default` (no value: the
 * site's default applies), `own` (a block list per language) or `none` (hidden in every language).
 *
 * What the drawer shows is a summary only — per language, each block's name and kind (BlockKinds)
 * — never the blocks themselves; the blocks are edited in «Trình dựng trang» (BandEditor). A
 * language with no blocks of its own reads as the site's default language does, as the storefront
 * resolves it (LayoutSlotBlocks). Holds no state.
 */
final readonly class CategoryBands
{
    /** The slots the drawer shows, top band first. */
    public const array SLOTS = ['before_list', 'after_list'];

    public const string DEFAULT = 'default';

    public const string OWN = 'own';

    public const string NONE = 'none';

    /** The modes the drawer may send. */
    public const array MODES = [self::DEFAULT, self::OWN, self::NONE];

    public function __construct(
        private StorefrontSettings $settings,
        private SiteLocale $locales,
    ) {}

    /**
     * The bands of the categories among $ids that have any value of their own, in ONE query; a
     * category left out of the answer follows the site's default in both bands (see blank()).
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, array{mode: string, own: array<string, list<array{name: string, kind: string, items: int}>>}>>
     */
    public function forCategories(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $out = [];
        $rows = Taxonomy::query()->whereIn('id', $ids)->whereNotNull('meta->'.LayoutSlots::META_KEY)->get(['id', 'meta']);
        foreach ($rows as $row) {
            $out[$row->id] = $this->of($row);
        }

        return $out;
    }

    /**
     * One category's two bands.
     *
     * @return array<string, array{mode: string, own: array<string, list<array{name: string, kind: string, items: int}>>}>
     */
    public function of(Taxonomy $category): array
    {
        $stored = Cast::arr(Cast::arr($category->meta)[LayoutSlots::META_KEY] ?? null);
        $bands = [];
        foreach (self::SLOTS as $slot) {
            $value = $stored[$slot] ?? null;
            $bands[$slot] = match (true) {
                $value === LayoutSlots::NONE => ['mode' => self::NONE, 'own' => []],
                is_array($value) => ['mode' => self::OWN, 'own' => $this->perLocale($value, false)],
                default => ['mode' => self::DEFAULT, 'own' => []],
            };
        }

        return $bands;
    }

    /**
     * Both bands of a category with no value of its own.
     *
     * @return array<string, array{mode: string, own: array<string, list<array{name: string, kind: string, items: int}>>}>
     */
    public static function blank(): array
    {
        return array_fill_keys(self::SLOTS, ['mode' => self::DEFAULT, 'own' => []]);
    }

    /** The mode a category's band is in now. */
    public static function modeOf(Taxonomy $category, string $slot): string
    {
        $value = Cast::arr(Cast::arr($category->meta)[LayoutSlots::META_KEY] ?? null)[$slot] ?? null;

        return $value === LayoutSlots::NONE ? self::NONE : (is_array($value) ? self::OWN : self::DEFAULT);
    }

    /**
     * The site's default blocks of each band, per language of the site (a language with none of its
     * own showing the default language's, as the storefront does).
     *
     * @return array<string, array<string, list<array{name: string, kind: string, items: int}>>>
     */
    public function site(): array
    {
        $stored = Cast::arr($this->settings->get(LayoutSlots::SETTING_GROUP, LayoutSlots::SETTING_KEY));
        $out = [];
        foreach (self::SLOTS as $slot) {
            $value = $stored[$slot] ?? null;
            $out[$slot] = is_array($value) ? $this->perLocale($value, true) : [];
        }

        return $out;
    }

    /**
     * The value SetCategoryLayoutBlocks takes for a band the drawer moved to $mode: null for the
     * site's default, "none" to hide it, and for its own blocks an empty list in the default
     * language (the band shows nothing until blocks are added in «Trình dựng trang»).
     *
     * @return array<string, array{blocks: list<mixed>}>|string|null
     */
    public function valueFor(string $mode): array|string|null
    {
        return match ($mode) {
            self::NONE => LayoutSlots::NONE,
            self::OWN => [$this->locales->default() => ['blocks' => []]],
            default => null,
        };
    }

    /**
     * Each block of a list as the drawer names it: its heading or text when it has one, then its kind
     * and how many items it holds.
     *
     * @param  array<array-key, mixed>  $blocks
     * @return list<array{name: string, kind: string, items: int}>
     */
    public static function names(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $block) {
            if (! is_array($block) || ! is_string($block['type'] ?? null)) {
                continue;
            }
            $kind = BlockKinds::of($block['type'])['label'];
            $props = Cast::arr($block['props'] ?? null);
            $name = BlockKinds::preview($props);
            // how many items the block holds (a feature grid's cards, a gallery's pictures): 0 for a block without a list
            $items = is_array($props['items'] ?? null) ? count($props['items']) : 0;
            $out[] = ['name' => $name !== '' ? $name : $kind, 'kind' => $kind, 'items' => $items];
        }

        return $out;
    }

    /**
     * A stored `{locale: {"blocks": […]}}` as names per language. With $fill, every enabled language
     * gets an answer (its own, else the default language's); without, only the languages stored.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<string, list<array{name: string, kind: string, items: int}>>
     */
    private function perLocale(array $value, bool $fill): array
    {
        $lists = [];
        foreach ($value as $locale => $text) {
            if (is_string($locale) && is_array($text) && is_array($text['blocks'] ?? null)) {
                $lists[$locale] = self::names($text['blocks']);
            }
        }
        if (! $fill) {
            return $lists;
        }

        $default = $this->locales->default();
        $out = [];
        foreach ($this->locales->enabled() as $locale) {
            $out[$locale] = $lists[$locale] ?? $lists[$default] ?? [];
        }

        return $out;
    }
}
