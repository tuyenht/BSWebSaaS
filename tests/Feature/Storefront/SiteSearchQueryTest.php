<?php

use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Taxonomy\TaxonomyPaths;
use App\Modules\Storefront\Search\SearchGroup;
use App\Modules\Storefront\Search\SearchHit;
use App\Modules\Storefront\Search\SearchResult;
use App\Modules\Storefront\Search\SearchText;
use App\Modules\Storefront\Search\SiteSearch;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * The storefront search query (SiteSearch): what it finds from the words typed (folded like the word
 * tables, every word, the last as a prefix), in which group and order, only what a visitor may open,
 * only in the page's language and only on the site that asks, with every count bounded.
 */

beforeEach(function (): void {
    seedTenancy();
});

/** A published post (or page) of the current site, in Vietnamese unless told otherwise. */
function searchEntry(string $title, ?string $excerpt = null, array $categories = [], string $type = 'post', array $attributes = [], string $locale = 'vi', string $translationStatus = 'published'): Entry
{
    $entry = Entry::query()->create($attributes + ['type' => $type, 'status' => 'published', 'published_at' => now()->subMinute(), 'meta' => []]);
    $entry->translations()->create(['locale' => $locale, 'title' => $title, 'slug' => 'muc-'.$entry->id, 'status' => $translationStatus, 'excerpt' => $excerpt]);
    if ($categories !== []) {
        $entry->assignCategories($categories);
    }

    return $entry;
}

/** A published product of the current site. */
function searchProduct(string $name, ?string $sku = null, ?string $summary = null, array $categories = [], array $attributes = []): Product
{
    $product = Product::query()->create($attributes + ['status' => 'published', 'sku' => $sku, 'published_at' => now()->subMinute()]);
    $product->translations()->create(['locale' => 'vi', 'name' => $name, 'slug' => 'sp-'.$product->id, 'summary' => $summary]);
    foreach (array_values($categories) as $position => $id) {
        $product->taxonomies()->attach($id, ['site_id' => $product->site_id, 'position' => $position]);
    }

    return $product;
}

/** A category of the current site, with its ltree path written. */
function searchCategory(string $name, ?int $parent = null, string $vocabulary = 'post_cat', ?string $listTemplate = null): Taxonomy
{
    $category = Taxonomy::query()->create(['vocabulary' => $vocabulary, 'parent_id' => $parent, 'position' => 0, 'list_template' => $listTemplate]);
    $category->translations()->create(['locale' => 'vi', 'name' => $name, 'slug' => Str::slug($name).'-'.$category->id]);
    app(TaxonomyPaths::class)->refreshBranch($category->id);

    return $category->refresh();
}

/** The search of the current site: group key → the ids found, in order. */
function searchIds(string $typed, string $locale = 'vi'): array
{
    return searchGroups(app(SiteSearch::class)->overview(SearchText::from($typed), $locale));
}

/** @return array<string, list<int>> */
function searchGroups(SearchResult $result): array
{
    $out = [];
    foreach ($result->groups as $group) {
        $out[$group->key] = array_map(static fn (SearchHit $hit): int => $hit->id, $group->hits);
    }

    return $out;
}

it('finds a title whatever accents and case are typed, every word required, the last as a prefix', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $mould = searchEntry('Khuôn mẫu ép nhựa');
        $other = searchEntry('Khuôn dập nguội');

        foreach (['khuon mau', 'Khuôn Mẫu', 'KHUON MAU', 'khuôn mẫ', 'mau khuon'] as $typed) {
            expect(searchIds($typed))->toBe([SiteSearch::OTHER => [$mould->id]], $typed);
        }
        // every word must be there; only the last one may be the start of a word
        expect(searchIds('khuon'))->toBe([SiteSearch::OTHER => [$other->id, $mould->id]])
            ->and(searchIds('khuo mau'))->toBe([])
            ->and(searchIds('khuon go'))->toBe([]);
    });
});

it('finds a product by its code, ranks a title above a short description, and accents typed first', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $bySummary = searchProduct('Bàn kẹp', 'BK-1', 'Dùng cho đồ gá hàn', attributes: ['published_at' => now()->subMinute()]);
        $byTitle = searchProduct('Đồ gá hàn', 'DG-1', null, attributes: ['published_at' => now()->subDays(3)]);
        $plain = searchProduct('Do ga khoan', 'DG-2', null, attributes: ['published_at' => now()->subDays(2)]);

        expect(searchIds('AB270'))->toBe([])
            ->and(searchIds('dg-1'))->toBe([SiteSearch::PRODUCTS => [$byTitle->id]])
            ->and(searchIds('dg1'))->toBe([SiteSearch::PRODUCTS => [$byTitle->id]])
            // no accents typed: title before summary, then newest
            ->and(searchIds('do ga'))->toBe([SiteSearch::PRODUCTS => [$plain->id, $byTitle->id, $bySummary->id]])
            // accents typed: the title that holds them first
            ->and(searchIds('đồ gá'))->toBe([SiteSearch::PRODUCTS => [$byTitle->id, $plain->id, $bySummary->id]]);
    });
});

it('groups by the main category\'s level-1 post category and brings the items of a matched category and its subcategories', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $projects = searchCategory('Dự án', listTemplate: 'grid-project');
        $news = searchCategory('Tin tức');
        $robots = searchCategory('Robot hàn', $projects->id);
        $inSub = searchEntry('Dây chuyền khung xe', null, [$robots->id]);
        $inProjects = searchEntry('Đồ gá hàn robot', null, [$projects->id]);
        $inNews = searchEntry('Tin đồ gá', null, [$news->id]);
        $page = searchEntry('Giới thiệu đồ gá', null, [], 'page');
        $shelf = searchCategory('Đồ gá', vocabulary: 'product_cat');
        $filed = searchProduct('Bàn kẹp', null, null, [$shelf->id]);

        $found = searchIds('do ga');
        expect($found)->toHaveKeys([SiteSearch::PRODUCTS, 'c'.$projects->id, 'c'.$news->id, SiteSearch::OTHER])
            ->and($found[SiteSearch::PRODUCTS])->toBe([$filed->id])
            ->and($found['c'.$projects->id])->toBe([$inProjects->id])
            ->and($found['c'.$news->id])->toBe([$inNews->id])
            ->and($found[SiteSearch::OTHER])->toBe([$page->id]);

        // the category's own words bring its items, those of its subcategories too, below a title match
        $sameWord = searchEntry('Dự án mới', null, [$news->id]);
        $found = searchIds('du an');
        expect($found['c'.$projects->id])->toBe([$inProjects->id, $inSub->id])
            ->and($found['c'.$news->id])->toBe([$sameWord->id]);
    });
});

it('shows only what a visitor may open, in the language of the page', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $shown = searchEntry('Khuôn hiện');
        searchEntry('Khuôn nháp', attributes: ['status' => 'draft']);
        searchEntry('Khuôn hẹn giờ', attributes: ['status' => 'scheduled', 'published_at' => now()->addDay()]);
        searchEntry('Khuôn tương lai', attributes: ['published_at' => now()->addDay()]);
        searchEntry('Khuôn dịch nháp', translationStatus: 'draft');
        searchEntry('Khuôn english', locale: 'en');
        searchEntry('Khuôn thùng rác')->delete();
        $thanks = searchEntry('Khuôn cảm ơn', type: 'page');
        DB::table('forms')->insert(['site_id' => 1, 'ulid' => (string) Str::ulid(), 'key' => 'lien-he', 'name' => json_encode(['vi' => 'Liên hệ']), 'settings' => json_encode(['redirect_entry' => $thanks->ulid]), 'created_at' => now(), 'updated_at' => now()]);
        searchProduct('Khuôn bán', attributes: []);
        searchProduct('Khuôn nháp sp', attributes: ['status' => 'draft']);
        searchProduct('Khuôn xoá sp')->delete();

        $found = searchIds('khuon');
        expect($found[SiteSearch::OTHER])->toBe([$shown->id])
            ->and($found[SiteSearch::PRODUCTS])->toHaveCount(1)
            ->and(searchIds('khuon', 'en'))->toHaveKey(SiteSearch::OTHER)
            ->and(searchIds('khuon', 'en')[SiteSearch::OTHER])->toHaveCount(1)
            ->and(searchIds('khuon', 'en'))->not->toHaveKey(SiteSearch::PRODUCTS);
    });
});

it('sees only its own site, even with the scope off', function (): void {
    $theirs = app(Tenancy::class)->enter(2, fn (): int => searchEntry('Khuôn mẫu bên kia')->id);
    app(Tenancy::class)->enter(1, function () use ($theirs): void {
        $ours = searchEntry('Khuôn mẫu bên này');
        expect(searchIds('khuon mau'))->toBe([SiteSearch::OTHER => [$ours->id]]);

        // RLS alone: the other site's words and items are not readable either
        expect(DB::table('entry_search_words')->where('entry_id', $theirs)->count())->toBe(0)
            ->and(Entry::query()->withoutGlobalScopes()->whereKey($theirs)->count())->toBe(0);
    });
});

it('counts every group, shows three rows of each and continues one group by its cursor', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $ids = [];
        foreach (range(1, 14) as $n) {
            $ids[] = searchEntry("Bản tin số {$n}", attributes: ['published_at' => now()->subMinutes($n)])->id;
        }
        $search = app(SiteSearch::class);
        $first = $search->overview(SearchText::from('ban tin'), 'vi');
        $group = $first->groups[0];
        expect($group)->toBeInstanceOf(SearchGroup::class)
            ->and($group->count)->toBe(14)
            ->and($group->capped)->toBeFalse()
            ->and($group->more)->toBeTrue()
            ->and(array_map(fn (SearchHit $hit): int => $hit->id, $group->hits))->toBe(array_slice($ids, 0, 3));

        $next = $search->more(SearchText::from('ban tin'), 'vi', SiteSearch::OTHER, $group->hits[2]->ulid)->groups[0];
        expect(array_map(fn (SearchHit $hit): int => $hit->id, $next->hits))->toBe(array_slice($ids, 3, 10))
            ->and($next->more)->toBeTrue();
        $last = $search->more(SearchText::from('ban tin'), 'vi', SiteSearch::OTHER, $next->hits[9]->ulid)->groups[0];
        expect(array_map(fn (SearchHit $hit): int => $hit->id, $last->hits))->toBe([$ids[13]])
            ->and($last->more)->toBeFalse()
            // a group key that is not one, or a category of no row, finds nothing
            ->and($search->more(SearchText::from('ban tin'), 'vi', 'c0', null)->groups)->toBe([])
            ->and($search->more(SearchText::from('ban tin'), 'vi', 'x', null)->groups)->toBe([]);
    });
});

it('walks the newest items when even the rarest word is more common than the gather cap', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $rows = [];
        foreach (range(1, SiteSearch::GATHER + 5) as $n) {
            $rows[] = ['site_id' => 1, 'ulid' => (string) Str::ulid(), 'type' => 'post', 'status' => 'published', 'published_at' => now()->subMinutes($n), 'meta' => '{}', 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('entries')->insert($chunk);
        }
        $ids = DB::table('entries')->where('site_id', 1)->orderByDesc('published_at')->pluck('id')->all();
        DB::table('entry_translations')->insert(array_map(fn (int $id): array => ['site_id' => 1, 'entry_id' => $id, 'locale' => 'vi', 'title' => 'Tin chung', 'slug' => 'tin-'.$id, 'status' => 'published', 'body' => '{}', 'created_at' => now(), 'updated_at' => now()], $ids));
        DB::table('entry_search_words')->insert(array_merge(...array_map(fn (int $id): array => [
            ['site_id' => 1, 'locale' => 'vi', 'word' => 'tin', 'entry_id' => $id, 'weight' => 1],
            ['site_id' => 1, 'locale' => 'vi', 'word' => 'chung', 'entry_id' => $id, 'weight' => 1],
        ], $ids)));

        $group = app(SiteSearch::class)->overview(SearchText::from('tin chung'), 'vi')->groups[0];
        expect($group->capped)->toBeTrue()
            ->and($group->count)->toBe(min(SiteSearch::GATHER + 5, SiteSearch::EXAMINE))   // the walk counts what it examined: "at least"
            ->and(array_map(fn (SearchHit $hit): int => $hit->id, $group->hits))->toBe(array_slice($ids, 0, 3));
        $next = app(SiteSearch::class)->more(SearchText::from('tin chung'), 'vi', SiteSearch::OTHER, $group->hits[2]->ulid)->groups[0];
        expect(array_map(fn (SearchHit $hit): int => $hit->id, $next->hits))->toBe(array_slice($ids, 3, 10));
    });
});

it('searches at most eight words and nothing shorter than two characters', function (): void {
    $eight = SearchText::from('một hai ba bốn năm sáu bảy tám chín mười');
    expect($eight->words)->toBe(['mot', 'hai', 'ba', 'bon', 'nam', 'sau', 'bay', 'tam'])
        ->and($eight->accented)->toBe(['một', 'bốn', 'năm', 'sáu', 'bảy', 'tám'])
        ->and(SearchText::from('a')->searchable())->toBeFalse()
        ->and(SearchText::from('  ')->searchable())->toBeFalse()
        ->and(SearchText::from('%%')->searchable())->toBeFalse()
        ->and(SearchText::from(['x'])->searchable())->toBeFalse()
        ->and(SearchText::from(str_repeat('a', 150))->text)->toHaveLength(SearchText::MAX_LENGTH)
        ->and(SearchText::from('ab')->words)->toBe(['ab']);

    app(Tenancy::class)->enter(1, function (): void {
        searchEntry('A');
        expect(app(SiteSearch::class)->overview(SearchText::from('a'), 'vi')->groups)->toBe([])
            ->and(countQueries(fn () => app(SiteSearch::class)->overview(SearchText::from('a'), 'vi')))->toBe(0);
    });
});
