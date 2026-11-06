<?php

use App\Modules\Catalog\Components\ProductGridComponent;
use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Components\EntryGridComponent;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Cms\Validation\ValidPageJson;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Theme\Components\SectionProps;
use App\Modules\Theme\Rendering\BlockRenderer;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\View;
use Illuminate\Support\HtmlString;
use Tests\Fixtures\StorefrontCards;

/*
 * News tabs by sub-category (entry_grid.tabs) and the product source of the product grid
 * (product_grid.source / sort / taxonomy_key / product_keys).
 */

beforeEach(function (): void {
    seedTenancy();
});

/**
 * A "tin-tuc" category with one child per name; each child gets $posts[name] published posts and
 * $drafts[name] drafts. Returns the children's ids by name.
 *
 * @param  list<string>  $names
 * @param  array<string, int>  $posts
 * @param  array<string, int>  $drafts
 * @return array<string, int>
 */
function tabsSeed(array $names, array $posts = [], array $drafts = [], string $parentSlug = 'tin-tuc'): array
{
    $parent = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 1]);
    $parent->translations()->create(['locale' => 'vi', 'name' => 'Tin tức', 'slug' => $parentSlug]);

    $ids = [];
    foreach ($names as $position => $name) {
        $child = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'parent_id' => $parent->id, 'position' => $position]);
        $child->translations()->create(['locale' => 'vi', 'name' => "Ấ {$name} Ế", 'slug' => "{$parentSlug}-{$name}"]);
        $child->translations()->create(['locale' => 'en', 'name' => "Group {$name}", 'slug' => "{$parentSlug}-{$name}-en"]);
        $ids[$name] = $child->id;

        foreach (['published' => $posts[$name] ?? 0, 'draft' => $drafts[$name] ?? 0] as $status => $count) {
            for ($i = 1; $i <= $count; $i++) {
                $slug = "{$parentSlug}-{$name}-{$status}-{$i}";
                $entry = Entry::query()->create(['type' => 'post', 'status' => $status, 'published_at' => $status === 'published' ? now()->subMinutes(100 - $i) : null, 'meta' => []]);
                $entry->translations()->create(['locale' => 'vi', 'title' => "Ấ bài {$name} {$status} {$i} Ế", 'slug' => $slug, 'status' => $status, 'excerpt' => 'Ấ tóm tắt Ế', 'body' => ['blocks' => []]]);
                $entry->assignCategories([$child->id]);
                app(RouteSync::class)->sync($entry, 'vi', $slug);
            }
        }
    }

    return $ids;
}

/** @return array<string, mixed> */
function tabsData(array $props = []): array
{
    app()->setLocale('vi');

    return app(EntryGridComponent::class)->data($props + ['taxonomy_slug' => 'tin-tuc', 'limit' => 3, 'locale' => 'vi', 'variant' => 'news-cards', 'tabs' => 'auto']);
}

describe('tabs by sub-category', function (): void {
    it('draws no tabs below two sub-categories with posts and at most three', function (int $withPosts, int $expected): void {
        app(Tenancy::class)->enter(1, function () use ($withPosts, $expected): void {
            $names = ['a', 'b', 'c', 'd'];
            tabsSeed($names, array_fill_keys(array_slice($names, 0, $withPosts), 2));

            $data = tabsData();

            expect(isset($data['tabs']) ? count($data['tabs']) : 0)->toBe($expected);
        });
    })->with([[0, 0], [1, 0], [2, 2], [4, 3]]);

    it('gives a sub-category with only drafts no tab, and keeps the order of the categories', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            tabsSeed(['a', 'b', 'c'], ['a' => 1, 'c' => 2], ['b' => 3]);

            $tabs = tabsData()['tabs'];

            expect($tabs)->toHaveCount(2)
                ->and($tabs[0]['name'])->toBe('Ấ a Ế')->and($tabs[1]['name'])->toBe('Ấ c Ế')
                ->and($tabs[1]['items'])->toHaveCount(2)
                ->and(json_encode($tabs, JSON_UNESCAPED_UNICODE))->not->toContain('draft');
        });
    });

    it('names the tabs in the page language and orders the posts newest first', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            tabsSeed(['a', 'b'], ['a' => 2, 'b' => 1]);

            $vi = tabsData()['tabs'];
            app()->setLocale('en');
            $en = app(EntryGridComponent::class)->data(['taxonomy_slug' => 'tin-tuc', 'limit' => 3, 'locale' => 'en', 'variant' => 'news-cards', 'tabs' => 'auto']);

            // The parent has no English name: the block finds no category, so no tabs.
            expect($vi[0]['name'])->toBe('Ấ a Ế')->and($en['items'])->toBe([])->and(isset($en['tabs']))->toBeFalse()
                // published_at descending: post 2 of a is newer than post 1.
                ->and($vi[0]['items'][0]['title'])->toBe('Ấ bài a published 2 Ế');
        });
    });

    it('shows "view all" on a tab only when it has more posts than the limit', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            tabsSeed(['a', 'b'], ['a' => 5, 'b' => 2]);

            $tabs = tabsData(['layout' => 'auto'])['tabs'];

            expect($tabs[0]['items'])->toHaveCount(3)->and($tabs[0]['view_all'])->toBeTrue()
                ->and($tabs[1]['items'])->toHaveCount(2)->and($tabs[1]['view_all'])->toBeFalse();
        });
    });

    it('draws the filter choice exactly like auto', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            tabsSeed(['a', 'b'], ['a' => 2, 'b' => 2]);

            expect(tabsData(['tabs' => 'filter'])['tabs'])->toEqual(tabsData(['tabs' => 'auto'])['tabs']);
        });
    });

    it('leaves the block as it was with tabs off, missing or unknown, and on the looks that have no panel', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            tabsSeed(['a', 'b'], ['a' => 2, 'b' => 2]);

            foreach ([['tabs' => 'off'], ['tabs' => 'bogus'], ['variant' => 'list'], ['variant' => 'zigzag']] as $override) {
                expect(tabsData($override))->not->toHaveKey('tabs');
            }
        });
    });

    it('reads another site\'s posts into no tab', function (): void {
        app(Tenancy::class)->enter(2, fn () => tabsSeed(['a', 'b'], ['a' => 3, 'b' => 3]));
        app(Tenancy::class)->enter(1, function (): void {
            tabsSeed(['x', 'y'], ['x' => 1, 'y' => 1]);

            $tabs = tabsData()['tabs'];

            expect($tabs)->toHaveCount(2)->and($tabs[0]['name'])->toBe('Ấ x Ế')
                ->and(json_encode($tabs, JSON_UNESCAPED_UNICODE))->not->toContain('bài a ');
        });
        app(Tenancy::class)->enter(2, fn () => expect(tabsData()['tabs'][0]['name'])->toBe('Ấ a Ế'));
    });

    it('reads a block with tabs in a fixed number of queries, however many tabs', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            tabsSeed(['a', 'b', 'c'], ['a' => 4, 'b' => 4, 'c' => 4]);

            tabsData(); // warm the site clock the news cards read, so only the block's own reads are counted
            $three = countQueries(fn () => tabsData());
            $two = countQueries(fn () => tabsData(['tabs' => 'auto', 'limit' => 1]));

            // Category by slug, sub-categories, ranked posts, posts, then translations, routes and sectors: five for a plain list of posts, so two more.
            expect($three)->toBe(7)->and($two)->toBe(7);
        });
    });
});

describe('tabs on the page', function (): void {
    /** @return array<string, mixed> */
    function tabsRender(): string
    {
        app()->setLocale('vi');

        return app(BlockRenderer::class)->render(['blocks' => [
            ['type' => 'entry_grid', 'props' => ['heading' => 'Tin tức Ấ', 'taxonomy_slug' => 'tin-tuc', 'limit' => 3, 'variant' => 'news-cards', 'tabs' => 'auto', 'layout' => 'auto', 'view_all_label' => 'Xem tất cả', 'view_all_url' => '/tin-tuc']],
            ['type' => 'entry_grid', 'props' => ['taxonomy_slug' => 'tin-tuc', 'limit' => 3, 'variant' => 'news-cards', 'tabs' => 'auto']],
        ]]);
    }

    it('prints every panel on the server as plain content, with one view-all after them', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            tabsSeed(['a', 'b'], ['a' => 2, 'b' => 4]);

            $html = tabsRender();

            expect($html)->toContain('<div class="sf-tabs__bar" data-label="Tin tức Ấ" hidden>')
                ->toContain('<button type="button" class="sf-tabs__tab" data-sf-tab>Ấ a Ế</button>')
                ->toContain('<h3 class="sf-tabs__title">Ấ b Ế</h3>')
                ->toContain('Ấ bài b published 3 Ế')
                // no element points at hidden content before the script runs
                ->not->toContain('role="tab"')->not->toContain('role="tabpanel"')
                ->not->toContain('aria-labelledby')->not->toContain('aria-controls')
                // the plan's grid class, three across from lg, as the plain card branch does
                ->toContain('<ul class="sf-rail__list sf-rail__list--grid sf-rail__list--c3')
                // exactly one "view all", after the last panel
                ->and(substr_count($html, 'sf-view-all__link'))->toBe(1);
            expect(substr_count(substr($html, 0, (int) strpos($html, 'sf-view-all__link')), 'data-sf-tabpanel'))->toBe(2);
        });
    });

    it('keeps the heading order without a gap: the section h2, the panel h3, the card h4', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            tabsSeed(['a', 'b'], ['a' => 1, 'b' => 1]);

            preg_match_all('/<h([1-6])[\s>]/', tabsRender(), $found);
            $levels = array_map('intval', $found[1]);
            $previous = 1; // the page's h1
            foreach ($levels as $level) {
                expect($level - $previous)->toBeLessThanOrEqual(1);
                $previous = $level;
            }
            expect(array_slice($levels, 0, 3))->toBe([2, 3, 4]);
        });
    });

    it('draws the default grid three across when the block has no display group', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            tabsSeed(['a', 'b'], ['a' => 1, 'b' => 1]);
            $html = app(BlockRenderer::class)->render(['blocks' => [['type' => 'entry_grid', 'props' => ['taxonomy_slug' => 'tin-tuc', 'limit' => 3, 'variant' => 'news-cards', 'tabs' => 'auto', 'view_all_label' => 'Xem', 'view_all_url' => '/tin-tuc']]]]);

            expect($html)->toContain('<ul class="sf-grid sf-grid--3s">')->not->toContain('sf-grid--4s')
                ->and(substr_count($html, 'sf-view-all__link'))->toBe(1);
        });
    });

    it('escapes a hostile category name', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            $ids = tabsSeed(['a', 'b'], ['a' => 1, 'b' => 1]);
            DB::table('taxonomy_translations')->where('taxonomy_id', $ids['a'])->where('locale', 'vi')->update(['name' => '<script>alert(1)</script>']);

            expect(tabsRender())->not->toContain('<script>alert(1)')->toContain('&lt;script&gt;alert(1)&lt;/script&gt;');
        });
    });

    describe('with the script', function (): void {
        beforeEach(function (): void {
            StorefrontCards::reset();
            StorefrontCards::pinMediaConfig();
            $this->siteId = StorefrontCards::site();
            $this->swap(Vite::class, new class extends Vite
            {
                public function __invoke($entrypoints, $buildDirectory = null): HtmlString
                {
                    return new HtmlString(collect((array) $entrypoints)->map(fn (string $entry): string => '<script type="module" src="/'.$entry.'"></script>')->implode(''));
                }
            });
        });

        it('loads the tab script only on a page whose list asks for tabs', function (string $tabs, bool $loads): void {
            app(Tenancy::class)->enter($this->siteId, fn () => StorefrontCards::page('/tin', 'Tin', [['type' => 'entry_grid', 'props' => ['taxonomy_slug' => 'tin-tuc', 'variant' => 'news-cards', 'tabs' => $tabs]]]));
            $html = $this->get('http://mau.platform.test/tin')->assertOk()->getContent();

            expect(str_contains($html, 'resources/js/sf-tabs.js'))->toBe($loads);
        })->with([['auto', true], ['filter', true], ['off', false], ['bogus', false]]);

        it('does not load the tab script on a look that draws no tabs', function (): void {
            app(Tenancy::class)->enter($this->siteId, fn () => StorefrontCards::page('/tin', 'Tin', [['type' => 'entry_grid', 'props' => ['taxonomy_slug' => 'tin-tuc', 'variant' => 'list', 'tabs' => 'auto']]]));

            expect(str_contains($this->get('http://mau.platform.test/tin')->assertOk()->getContent(), 'resources/js/sf-tabs.js'))->toBeFalse();
        });
    });
});

describe('the tabs choice on save', function (): void {
    /** @return list<string> */
    function tabsErrors(array $props, string $type = 'entry_grid'): array
    {
        app()->setLocale('vi');

        return array_values(Validator::make(['blocks' => [['type' => $type, 'props' => $props]]], ['blocks' => [app(ValidPageJson::class)]])->errors()->get('blocks'));
    }

    it('accepts the three choices and refuses any other', function (): void {
        foreach (SectionProps::ENTRY_GRID_TABS as $option) {
            expect(tabsErrors(['tabs' => $option]))->toBe([]);
        }
        expect(tabsErrors(['tabs' => 'bogus']))->not->toBe([])
            ->and(SectionProps::ENTRY_GRID_TABS)->toBe(['off', 'auto', 'filter']);
    });
});

/** Products: a pinned catalog base plus one published product routed at /san-pham/{slug}. */
function sourceProduct(string $slug, string $status = 'published', ?string $when = null, ?int $category = null): Product
{
    SiteSetting::query()->firstOrCreate(
        ['group_name' => 'permalinks', 'key' => 'products'],
        ['value' => ['pattern' => 'base', 'base' => 'san-pham']],
    );

    $product = Product::query()->create(['status' => $status, 'published_at' => $status === 'published' ? ($when ?? now()) : null]);
    $product->translations()->create(['locale' => 'vi', 'name' => "Ấ {$slug} Ế", 'slug' => $slug]);
    app(RouteSync::class)->syncProduct($product->id, null, 'vi', $slug);
    if ($category !== null) {
        $product->assignCategories([$category]);
    }

    return $product;
}

/** @return list<string> */
function sourceTitles(array $props): array
{
    $data = app(ProductGridComponent::class)->data($props + ['limit' => 6, 'locale' => 'vi']);

    return array_column($data['items'], 'title');
}

describe('product source', function (): void {
    it('keeps the newest products as the default and when the source is unknown', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            sourceProduct('cu', when: '2026-01-01 00:00:00');
            sourceProduct('moi', when: '2026-02-01 00:00:00');

            expect(sourceTitles([]))->toBe(['Ấ moi Ế', 'Ấ cu Ế'])
                ->and(sourceTitles(['source' => 'latest']))->toBe(['Ấ moi Ế', 'Ấ cu Ế'])
                ->and(sourceTitles(['source' => 'bogus']))->toBe(['Ấ moi Ế', 'Ấ cu Ế'])
                ->and(sourceTitles(['sort' => 'oldest']))->toBe(['Ấ cu Ế', 'Ấ moi Ế'])
                ->and(sourceTitles(['sort' => 'bogus']))->toBe(['Ấ moi Ế', 'Ấ cu Ế']);
        });
    });

    it('takes the products of one category by its ulid, in the chosen order', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            $cat = Taxonomy::query()->create(['vocabulary' => 'product_cat', 'position' => 1]);
            $other = Taxonomy::query()->create(['vocabulary' => 'product_cat', 'position' => 2]);
            sourceProduct('a', when: '2026-01-01 00:00:00', category: $cat->id);
            sourceProduct('b', when: '2026-02-01 00:00:00', category: $cat->id);
            sourceProduct('c', when: '2026-03-01 00:00:00', category: $other->id);
            sourceProduct('d', 'draft', category: $cat->id);

            $props = ['source' => 'category', 'taxonomy_key' => strtolower((string) $cat->ulid)];

            expect(sourceTitles($props))->toBe(['Ấ b Ế', 'Ấ a Ế'])
                ->and(sourceTitles($props + ['sort' => 'oldest']))->toBe(['Ấ a Ế', 'Ấ b Ế'])
                // no key, a malformed key and an id all give nothing, never the newest products
                ->and(sourceTitles(['source' => 'category']))->toBe([])
                ->and(sourceTitles(['source' => 'category', 'taxonomy_key' => 'nope']))->toBe([])
                ->and(sourceTitles(['source' => 'category', 'taxonomy_key' => (string) $cat->id]))->toBe([]);
        });
    });

    it('shows a hand-picked list in its own order, skips what is gone and ignores the sort', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            $a = sourceProduct('a', when: '2026-01-01 00:00:00');
            $b = sourceProduct('b', when: '2026-02-01 00:00:00');
            $c = sourceProduct('c', when: '2026-03-01 00:00:00');
            $draft = sourceProduct('nhap', 'draft');
            $trashed = sourceProduct('xoa');
            $trashed->delete();

            $keys = [$c->ulid, $draft->ulid, $a->ulid, $trashed->ulid, '01ARZ3NDEKTSV4RRFFQ69G5FAV', $a->ulid, $b->ulid];

            expect(sourceTitles(['source' => 'picked', 'product_keys' => $keys]))->toBe(['Ấ c Ế', 'Ấ a Ế', 'Ấ b Ế'])
                ->and(sourceTitles(['source' => 'picked', 'sort' => 'oldest', 'product_keys' => $keys]))->toBe(['Ấ c Ế', 'Ấ a Ế', 'Ấ b Ế'])
                // a limit of 1 does not cut a picked list; an empty or missing list is empty
                ->and(sourceTitles(['source' => 'picked', 'limit' => 1, 'product_keys' => $keys]))->toHaveCount(3)
                ->and(sourceTitles(['source' => 'picked', 'product_keys' => []]))->toBe([])
                ->and(sourceTitles(['source' => 'picked']))->toBe([])
                ->and(sourceTitles(['source' => 'picked', 'product_keys' => [(string) $a->id, 7, null, ['x']]]))->toBe([])
                // the editor marks the same keys as gone
                ->and(ProductGridComponent::goneKeys($keys))->toBe([(string) $draft->ulid, (string) $trashed->ulid, '01ARZ3NDEKTSV4RRFFQ69G5FAV']);
        });
    });

    it('uses at most 24 picked products when a longer list is forced past the save check', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            $keys = [];
            foreach (range(1, 26) as $i) {
                $keys[] = sourceProduct("sp-{$i}")->ulid;
            }

            expect(sourceTitles(['source' => 'picked', 'product_keys' => $keys, 'limit' => 50]))->toHaveCount(24);
        });
    });

    it('renders nothing for another site\'s product or category, with two sites interleaved', function (): void {
        $b = null;
        $categoryB = null;
        app(Tenancy::class)->enter(2, function () use (&$b, &$categoryB): void {
            $categoryB = Taxonomy::query()->create(['vocabulary' => 'product_cat', 'position' => 1]);
            $b = sourceProduct('rieng-b', category: $categoryB->id);
        });
        app(Tenancy::class)->enter(1, function () use ($b, $categoryB): void {
            $a = sourceProduct('rieng-a');

            expect(sourceTitles(['source' => 'picked', 'product_keys' => [$b->ulid, $a->ulid]]))->toBe(['Ấ rieng-a Ế'])
                ->and(sourceTitles(['source' => 'picked', 'product_keys' => [$b->ulid]]))->toBe([])
                ->and(sourceTitles(['source' => 'category', 'taxonomy_key' => $categoryB->ulid]))->toBe([])
                ->and(sourceTitles(['source' => 'latest']))->toBe(['Ấ rieng-a Ế'])
                ->and(ProductGridComponent::goneKeys([$b->ulid]))->toBe([(string) $b->ulid]);
        });
        app(Tenancy::class)->enter(2, fn () => expect(sourceTitles(['source' => 'picked', 'product_keys' => [$b->ulid]]))->toBe(['Ấ rieng-b Ế']));
    });

    it('refuses more than 24 picked keys, a malformed key and an unknown choice on save', function (): void {
        $key = fn (int $i): string => '01ARZ3NDEKTSV4RRFFQ69G5F'.sprintf('%02d', $i);
        $keys = fn (int $count): array => array_map($key, range(10, 9 + $count));

        expect(tabsErrors(['source' => 'picked', 'product_keys' => $keys(24)], 'product_grid'))->toBe([])
            ->and(tabsErrors(['source' => 'picked', 'product_keys' => $keys(25)], 'product_grid'))->not->toBe([])
            ->and(tabsErrors(['source' => 'picked', 'product_keys' => ['12']], 'product_grid'))->not->toBe([])
            ->and(tabsErrors(['source' => 'picked', 'product_keys' => ['']], 'product_grid'))->toBe([])
            ->and(tabsErrors(['source' => 'category', 'taxonomy_key' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'], 'product_grid'))->toBe([])
            ->and(tabsErrors(['source' => 'category', 'taxonomy_key' => '15'], 'product_grid'))->not->toBe([])
            ->and(tabsErrors(['source' => 'newest'], 'product_grid'))->not->toBe([])
            ->and(tabsErrors(['sort' => 'random'], 'product_grid'))->not->toBe([])
            ->and(SectionProps::PRODUCT_SOURCES)->toBe(['latest', 'category', 'picked'])
            ->and(SectionProps::PRODUCT_SORTS)->toBe(['newest', 'oldest']);
    });

    it('reads every source in a fixed number of queries', function (): void {
        app(Tenancy::class)->enter(1, function (): void {
            $cat = Taxonomy::query()->create(['vocabulary' => 'product_cat', 'position' => 1]);
            $made = [];
            foreach (range(1, 4) as $i) {
                $made[] = sourceProduct("sp-{$i}", when: "2026-01-0{$i} 00:00:00", category: $cat->id);
            }
            $keys = array_map(fn (Product $product): string => $product->ulid, $made);
            $count = fn (array $props): int => countQueries(fn () => sourceTitles($props));

            // Products, translations, images, then the routes: no query per product, whatever the source.
            expect($count([]))->toBe(4)
                ->and($count(['source' => 'category', 'taxonomy_key' => $cat->ulid]))->toBe(4)
                ->and($count(['source' => 'picked', 'product_keys' => $keys]))->toBe(4)
                ->and($count(['source' => 'picked', 'product_keys' => array_slice($keys, 0, 1)]))->toBe(4);
        });
    });
});

it('has a label in every language for each new choice', function (): void {
    $options = ['tabs' => SectionProps::ENTRY_GRID_TABS, 'source' => SectionProps::PRODUCT_SOURCES, 'sort' => SectionProps::PRODUCT_SORTS];

    foreach (['vi', 'en', 'ja', 'zh'] as $locale) {
        $lang = json_decode((string) file_get_contents(lang_path($locale.'.json')), true);
        foreach ($options as $prop => $list) {
            foreach ($list as $option) {
                expect($lang)->toHaveKey("entries.opt_{$prop}_{$option}");
            }
        }
        expect($lang)->toHaveKey('entries.product_pick_gone')->toHaveKey('storefront.tabs_label');
    }
});

it('shows the same schema to the editor: the entry grid offers tabs, the product grid its source', function (): void {
    expect(app(EntryGridComponent::class)->schema()['tabs'])->toMatchArray(['type' => 'enum', 'default' => 'off'])
        ->and(app(ProductGridComponent::class)->schema())->toHaveKeys(['source', 'sort', 'taxonomy_key', 'product_keys'])
        ->and(app(ProductGridComponent::class)->schema()['source']['default'])->toBe('latest');
    // View is used by the golden tests; keep the import honest.
    expect(View::exists('theme::sections.entry_grid'))->toBeTrue();
});
