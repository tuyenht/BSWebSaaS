<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Menu;
use App\Modules\Cms\Models\MenuAssignment;
use App\Modules\Cms\Models\MenuItem;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;

/*
 * Query Budget guardrail (CLAUDE.md §6): assert a hard ceiling of DB queries per
 * hot route so N+1 regressions fail CI. Tighten as the render path matures (cache,
 * eager-load); for now it bounds the resolve + render of one page.
 */

beforeEach(function (): void {
    seedTenancy();
    seedHomepage(1, 'An Vinh');
});

it('renders the storefront home within its query budget', function (): void {
    $budget = 11; // + nav: one assignment lookup (miss → empty menu) on top of the page resolve

    $count = countQueries(fn () => $this->get('http://a.test/')->assertOk());

    expect($count)->toBeLessThanOrEqual($budget);
});

it('renders the storefront home with the full chrome within its query budget', function (): void {
    // The home budget plus the three reads any menu costs (items, their labels, link targets).
    // Tokens, header and footer settings share the one settings read the home already makes,
    // and the footer menu rides in the same resolve as the primary one — no query of its own.
    $budget = 14;

    app(Tenancy::class)->enter(1, function (): void {
        foreach ([
            ['theme', 'tokens', ['primary' => '#f59e0b', 'ink' => '#0f172a', 'font' => 'Inter, sans-serif']],
            ['storefront', 'header', ['top_bar' => ['items' => [['kind' => 'phone', 'text' => '0901 234 567', 'value' => '0901234567']]], 'text_size' => true]],
            ['storefront', 'footer', ['about' => 'Giới thiệu.', 'factories' => [['title' => 'Nhà máy 1', 'map_url' => 'https://maps.google.com/?q=1']]]],
        ] as [$group, $key, $value]) {
            SiteSetting::query()->create(['group_name' => $group, 'key' => $key, 'value' => $value]);
        }

        $home = Entry::query()->firstOrFail();
        foreach (['primary' => 'Chính', 'footer' => 'Chân trang'] as $region => $name) {
            $menu = Menu::query()->create(['name' => $name, 'handle' => $region]);
            $parent = MenuItem::query()->create(['menu_id' => $menu->id, 'link_type' => 'url', 'url' => '#', 'position' => 0]);
            $parent->translations()->create(['locale' => 'vi', 'label' => 'Nhóm']);
            foreach (range(1, 3) as $position) {
                $item = MenuItem::query()->create(['menu_id' => $menu->id, 'parent_id' => $parent->id, 'link_type' => 'entry',
                    'routable_type' => 'entry', 'routable_id' => $home->id, 'position' => $position]);
                $item->translations()->create(['locale' => 'vi', 'label' => "Mục {$position}"]);
            }
            MenuAssignment::query()->create(['region_key' => $region, 'locale' => 'vi', 'menu_id' => $menu->id]);
        }
    });

    $html = '';
    $count = countQueries(function () use (&$html): void {
        $html = $this->get('http://a.test/')->assertOk()->getContent();
    });

    expect($html)->toContain('sf-header')->toContain('sf-footer')->toContain('Mục 3')
        ->and($count)->toBeLessThanOrEqual($budget);
});
