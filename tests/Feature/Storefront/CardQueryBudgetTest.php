<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Tenancy\Context\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\StorefrontCards;

/*
 * Query budgets for the card looks and card list pages (CLAUDE.md §6 Query Budget). The looks cost
 * a fixed number of reads on top of the default look, never one per row or per child category; a
 * slider's photos resolve in the page's one media batch; the paged lists read no COUNT.
 */

beforeEach(function (): void {
    StorefrontCards::reset();
    StorefrontCards::pinMediaConfig();
});

/**
 * A fresh site with $rows news posts and products (each with a photo) spread over the parent and
 * $children child categories (the first row always on the parent), then the query count of one
 * GET of $path. $setup may add rows inside the tenant.
 */
function budgetCount(string $path, int $rows = 3, int $children = 2, ?string $newsTemplate = null, ?string $catalogTemplate = null, ?Closure $setup = null, bool $warm = true): int
{
    StorefrontCards::reset();
    $siteId = StorefrontCards::site();
    app(Tenancy::class)->enter($siteId, function () use ($rows, $children, $newsTemplate, $catalogTemplate, $setup): void {
        $photo = StorefrontCards::media('anh', 960, 720);
        $news = StorefrontCards::news();
        $news->update(['list_template' => $newsTemplate]);
        $catalog = StorefrontCards::category('Sản phẩm', 'san-pham', null, $catalogTemplate);
        StorefrontCards::catalog($catalog);
        $jigs = StorefrontCards::category('Đồ gá', 'do-ga', $catalog, $catalogTemplate, 1);

        $newsKids = [$news];
        $jigKids = [$jigs];
        foreach (range(1, $children) as $n) {
            $newsKids[] = StorefrontCards::category("Tin {$n}", "tin-{$n}", $news, $newsTemplate, $n);
            $jigKids[] = StorefrontCards::category("Đồ gá {$n}", "do-ga-{$n}", $jigs, $catalogTemplate, $n);
        }

        $at = CarbonImmutable::parse('2026-10-01 08:00', 'UTC');
        foreach (range(1, $rows) as $n) {
            StorefrontCards::post($newsKids[($n - 1) % ($children + 1)], "Bài {$n}", "bai-{$n}", $at->addHours($n), ['image_url' => $photo->storage_key], 'Tóm tắt.');
            StorefrontCards::product("Sản phẩm {$n}", "san-pham-{$n}", $jigKids[($n - 1) % ($children + 1)], $photo, $at->addHours($n));
        }

        if ($setup !== null) {
            $setup($photo);
        }
    });

    if ($warm) {
        // Per-site reads that outlive a request (settings, identity) are already loaded, so two
        // looks compare only what the looks themselves read; request-scoped memos start empty.
        test()->get('http://mau.platform.test'.$path)->assertOk();
        app()->forgetScopedInstances();
    }

    return countQueries(fn () => test()->get('http://mau.platform.test'.$path)->assertOk());
}

/** The count of a page at /khoi holding $blocks (rows from budgetCount, a services category). */
function blockCount(array $blocks, int $rows = 3): int
{
    return budgetCount('/khoi', $rows, setup: function ($photo) use ($blocks): void {
        $ids = [];
        foreach (['a', 'b', 'c'] as $slug) {
            $ids[$slug] = StorefrontCards::media('slide-'.$slug)->id;
        }
        $blocks = json_decode(str_replace(['"@a"', '"@b"', '"@c"'], [(string) $ids['a'], (string) $ids['b'], (string) $ids['c']], (string) json_encode($blocks)), true);
        StorefrontCards::page('/khoi', 'Các khối', $blocks);
    });
}

it('resolves a three-photo slider in the same reads as one hero photo', function (): void {
    $band = ['type' => 'page_header', 'props' => ['title' => 'Dải', 'image_url' => '@b']];
    $slider = blockCount([['type' => 'hero_slider', 'props' => ['slides' => [
        ['image_url' => '@a', 'heading' => 'Một'], ['image_url' => '@b', 'heading' => 'Hai'], ['image_url' => '@c', 'heading' => 'Ba'],
    ], 'autoplay' => 5]], $band]);
    $hero = blockCount([['type' => 'hero', 'props' => ['heading' => 'Một', 'image_url' => '@a']], $band]);

    expect($slider)->toBe($hero);
});

it('costs a fixed number of reads for each card look over its default look', function (string $type, array $props, string $variant, int $extra): void {
    $default = blockCount([['type' => $type, 'props' => $props]]);
    $cards = blockCount([['type' => $type, 'props' => $props + ['variant' => $variant]]]);

    expect($cards - $default)->toBe($extra);
})->with([
    'icon cards: none' => ['entry_grid', ['taxonomy_slug' => 'tin-tuc'], 'icon-cards', 0],
    'product photo cards: images and categories' => ['product_grid', ['heading' => 'Sản phẩm'], 'overlay', 2],
    'news cards: the site timezone' => ['entry_grid', ['taxonomy_slug' => 'tin-tuc'], 'news-cards', 1],
]);

it('keeps every card look independent of the number of rows', function (string $type, array $props): void {
    expect(blockCount([['type' => $type, 'props' => $props]], 12))->toBe(blockCount([['type' => $type, 'props' => $props]], 3));
})->with([
    'icon cards' => ['entry_grid', ['taxonomy_slug' => 'tin-tuc', 'variant' => 'icon-cards', 'limit' => 12]],
    'news cards' => ['entry_grid', ['taxonomy_slug' => 'tin-tuc', 'variant' => 'news-cards', 'limit' => 12]],
    'product photo cards' => ['product_grid', ['variant' => 'overlay', 'limit' => 12]],
]);

it('lists a category within its budget on each layout', function (string $path, ?string $news, ?string $catalog, int $budget): void {
    $count = budgetCount($path, 3, 2, $news, $catalog, warm: false);

    expect($count)->toBeLessThanOrEqual($budget);
})->with([
    // Measured on the same fixture before these layouts: the titles list 19, the product index 20.
    'titles layout: the COUNT is gone' => ['/tin-tuc', null, null, 18],
    'news cards, a parent with chips: chips and the site timezone' => ['/tin-tuc', 'grid-news', null, 22],
    'product cards, a child category: the products check, chips, photos, categories and the parent category in the trail' => ['/do-ga', null, 'grid-product', 26],
    'a product page in a child category: its category trail, the parent category, the photos and the look for products of its group (none here), and the route of the contact page for the quote button, plus the row of products of its branch that fills the group: its photos, translations and the categories of the ones from another group' => ['/san-pham-1', null, null, 26],
]);

it('reads the products of the same group in a fixed number of reads, however many there are', function (): void {
    // Three products over three categories: none shares /san-pham-1's group, so the row is filled from its branch.
    // Six over two: two do, the rest of the row is filled from the branch. Fourteen over one: the group alone fills it.
    $none = budgetCount('/san-pham-1', 3, 2);
    $two = budgetCount('/san-pham-1', 6, 1);

    // The row is one read; its rows add their translations, photos and photo files, and the categories of
    // those from another group add two more (not read when the group alone fills the row).
    expect($two)->toBe($none)
        ->and(budgetCount('/san-pham-1', 14, 1))->toBeLessThanOrEqual($two)
        ->and(budgetCount('/san-pham-1', 6, 1, warm: false))->toBeLessThanOrEqual(27);
});

it('keeps card list pages independent of the number of rows and child categories', function (string $path, ?string $news, ?string $catalog): void {
    $few = budgetCount($path, 3, 1, $news, $catalog);

    expect(budgetCount($path, 12, 1, $news, $catalog))->toBe($few)
        ->and(budgetCount($path, 3, 4, $news, $catalog))->toBe($few);
})->with([
    'news cards' => ['/tin-tuc', 'grid-news', null],
    'project cards' => ['/tin-tuc', 'grid-project', null],
    'product cards' => ['/do-ga', null, 'grid-product'],
    'titles layout' => ['/tin-tuc', null, null],
]);

it('reads one more row for a keyset page than for page 1: the anchor', function (): void {
    $first = budgetCount('/tin-tuc', 3, 1, 'grid-news');
    $anchor = (string) app(Tenancy::class)->enter(1, fn () => DB::table('entries')->where('type', 'post')->orderBy('published_at')->orderBy('id')->skip(1)->value('ulid'));

    app()->forgetScopedInstances();
    $keyset = countQueries(fn () => test()->get('http://mau.platform.test/tin-tuc?sau='.$anchor)->assertOk());

    expect($keyset)->toBe($first + 1);
});

it('lists a job category and shows a job post within a fixed number of reads', function (): void {
    $withFacts = function (): void {
        foreach (Entry::query()->get() as $post) {
            $post->meta = ($post->meta ?? []) + ['facts' => [['text' => ['vi' => 'Trung Giã'], 'tone' => 'plain'], ['text' => ['vi' => 'Lương: Thỏa thuận'], 'tone' => 'accent']], 'badge' => ['vi' => 'Tuyển gấp']];
            $post->save();
        }
        $news = Taxonomy::query()->whereNull('parent_id')->whereHas('translations', fn ($q) => $q->where('slug', 'tin-tuc'))->firstOrFail();
        $news->forceFill(['detail_template' => 'job'])->save();
    };

    $few = budgetCount('/tin-tuc', 3, 1, 'grid-job', setup: $withFacts);
    $post = budgetCount('/tin-tuc/bai-1', 3, 1, 'grid-job', setup: $withFacts);

    expect(budgetCount('/tin-tuc', 12, 1, 'grid-job', setup: $withFacts))->toBe($few)
        ->and(budgetCount('/tin-tuc', 3, 4, 'grid-job', setup: $withFacts))->toBe($few)
        ->and($few)->toBeLessThanOrEqual(22)
        ->and(budgetCount('/tin-tuc/bai-1', 12, 1, 'grid-job', setup: $withFacts))->toBe($post)
        ->and($post)->toBeLessThanOrEqual(24);
});

it('reads a job grid block in a fixed number of reads, however many posts it lists', function (): void {
    $block = [['type' => 'entry_grid', 'props' => ['taxonomy_slug' => 'tin-tuc', 'variant' => 'job', 'tabs' => 'filter', 'count_label' => '{n} vị trí', 'limit' => 12]]];

    expect(blockCount($block, 12))->toBe(blockCount($block, 3));
});
