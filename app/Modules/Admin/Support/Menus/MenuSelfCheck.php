<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Menus;

use App\Modules\Cms\Actions\Menus\ApplyMenuDraft;
use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Cms\Navigation\MenuLimits;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Theme\Theming\MenuAreas;
use Illuminate\Support\Str;

/**
 * "Hệ thống tự kiểm": what the website is quietly doing that the editor should know about.
 *
 * For the menu being edited — items whose page, post or product is not published, in the trash
 * or gone, and items whose category has no version in a language the menu is written for (the
 * website hides each of them by itself); items deeper than the strictest area showing the menu
 * can draw; the menu not shown anywhere. For the site — an area with no menu, and languages an
 * area has no menu for (the website shows the main language's menu there).
 *
 * Each issue: code, level (problem = the website hides or lacks something; tip = worth a look),
 * the plain-language message, and what the screen needs to act on it — the item's ulid, the
 * source's kind, public slug and ulid for "Mở trang / sản phẩm / chuyên mục", the area, the
 * languages. Never an id. A fixed number of queries whatever the menu's size: the assignments,
 * the items and their labels, two per kind of source.
 */
final readonly class MenuSelfCheck
{
    public function __construct(
        private SiteLocale $siteLocales,
        private ApplyMenuDraft $drafts,
        private MenuSources $sources,
        private MenuScreen $screen,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function check(?Menu $menu): array
    {
        $main = $this->siteLocales->default();
        $enabled = $this->siteLocales->enabled();
        $assignments = $this->screen->assignments();

        $issues = $menu === null ? [] : $this->menuIssues($menu, $assignments, $enabled, $main);

        return [...$issues, ...$this->areaIssues($assignments, $enabled, $main)];
    }

    /**
     * @param  list<array{region: string, locale: string, menu: int|null, follows: string|null}>  $assignments
     * @param  list<string>  $enabled
     * @return list<array<string, mixed>>
     */
    private function menuIssues(Menu $menu, array $assignments, array $enabled, string $main): array
    {
        [$items, $labels] = $this->drafts->read($menu->id);
        $locales = $this->screen->menuLocales($menu, $enabled);
        $sources = $this->sources->of($items, $locales, $main);
        $mainLabel = [];
        foreach ($labels as $label) {
            if ($label->locale === $main && $label->label !== '') {
                $mainLabel[$label->menu_item_id] = $label->label;
            }
        }

        $issues = [];
        foreach ($items->sortBy([['position', 'asc'], ['id', 'asc']]) as $item) {
            $source = $sources[$item->id] ?? null;
            if ($source === null || ($item->meta['hidden'] ?? false) === true) {
                continue;   // no page behind it, or hidden by hand: nothing the website does by itself
            }
            $name = $mainLabel[$item->id] ?? $source['title'] ?? __('menus.kind_'.$source['kind']);
            $kind = __('menus.kind_'.$source['kind']);
            $about = [
                'item' => $item->ulid,
                'label' => $name,
                'source' => ['kind' => $source['kind'], 'slug' => $source['slug'], 'ref' => $source['ref']],
            ];
            if ($source['state'] !== 'live') {
                $issues[] = [
                    'code' => 'source_'.$source['state'],
                    'level' => 'problem',
                    ...$about,
                    'message' => __('menus.check_source_'.$source['state'], ['label' => $name, 'kind' => $kind]),
                ];
            } elseif ($source['untranslated'] !== []) {
                $issues[] = [
                    'code' => 'category_untranslated',
                    'level' => 'problem',
                    ...$about,
                    'locales' => $source['untranslated'],
                    'message' => __('menus.check_category_untranslated', ['label' => $name, 'langs' => $this->names($source['untranslated'])]),
                ];
            }
        }

        $limits = MenuLimits::fromAssignments($menu->id, $assignments);
        $tooDeep = $this->deeperThan($items->all(), $limits['depth']);
        if ($tooDeep > 0) {
            $area = $limits['area'] === null ? null : MenuAreas::all()[$limits['area']]['label'];
            $issues[] = [
                'code' => 'too_deep',
                'level' => 'problem',
                'area' => $limits['area'],
                'count' => $tooDeep,
                'depth' => $limits['depth'],
                'message' => __('menus.check_too_deep', ['count' => $tooDeep, 'area' => $area === null ? __('menus.check_no_area') : __($area), 'depth' => $limits['depth']]),
            ];
        }

        if (MenuLimits::areasShowing($menu->id, $assignments) === []) {
            $issues[] = [
                'code' => 'menu_unplaced',
                'level' => 'problem',
                'menu' => $menu->ulid,
                'message' => __('menus.check_menu_unplaced', ['name' => $menu->name]),
            ];
        }

        return $issues;
    }

    /**
     * @param  list<array{region: string, locale: string, menu: int|null, follows: string|null}>  $assignments
     * @param  list<string>  $enabled
     * @return list<array<string, mixed>>
     */
    private function areaIssues(array $assignments, array $enabled, string $main): array
    {
        $rows = [];
        foreach ($assignments as $row) {
            $rows[$row['region']][$row['locale']] = true;
        }

        $issues = [];
        foreach (MenuAreas::all() as $key => $area) {
            if (! isset($rows[$key][$main])) {
                // The phone menu with nothing of its own shows the main menu: not empty.
                if ($area['canSame'] !== null && isset($rows[$area['canSame']][$main])) {
                    continue;
                }
                $issues[] = [
                    'code' => 'area_empty',
                    'level' => 'problem',
                    'area' => $key,
                    'message' => __('menus.check_area_empty', ['area' => __($area['label'])]),
                ];

                continue;
            }
            $missing = array_values(array_filter($enabled, fn (string $locale): bool => $locale !== $main && ! isset($rows[$key][$locale])));
            if ($missing !== []) {
                $issues[] = [
                    'code' => 'locale_unassigned',
                    'level' => 'tip',
                    'area' => $key,
                    'locales' => $missing,
                    'message' => __('menus.check_locale_unassigned', [
                        'count' => count($missing), 'langs' => $this->names($missing),
                        'area' => Str::lower(__($area['label'])), 'main' => $this->names([$main]),
                    ]),
                ];
            }
        }

        return $issues;
    }

    /**
     * How many items sit deeper than $depth levels.
     *
     * @param  array<int, MenuItem>  $items
     */
    private function deeperThan(array $items, int $depth): int
    {
        $parent = [];
        foreach ($items as $item) {
            $parent[$item->id] = $item->parent_id;
        }
        $deep = 0;
        foreach (array_keys($parent) as $id) {
            $level = 1;
            for ($at = $parent[$id]; $at !== null && array_key_exists($at, $parent) && $level <= count($parent); $at = $parent[$at]) {
                $level++;
            }
            if ($level > $depth) {
                $deep++;
            }
        }

        return $deep;
    }

    /** @param list<string> $locales */
    private function names(array $locales): string
    {
        return implode(', ', array_map(fn (string $code): string => is_string(config("locales.labels.{$code}")) ? config("locales.labels.{$code}") : Str::upper($code), $locales));
    }
}
