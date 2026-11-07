<?php

use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Taxonomy\CategoryTemplates;
use App\Modules\Cms\Taxonomy\TaxonomyRules;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Theme\Rendering\TemplateCatalog;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\StorefrontCards;

/*
 * Which layout a category really uses: its own, the one of the category above when it follows its
 * parent (read by parent_id with a depth cap), or the theme's default (null) for a value of the
 * wrong role or kind or one the theme lacks.
 */

beforeEach(function (): void {
    StorefrontCards::reset();
    $this->siteId = StorefrontCards::site();
});

/** Run inside the test site. */
function ctSite(Closure $callback): mixed
{
    return app(Tenancy::class)->enter(test()->siteId, $callback);
}

/** A category row with the given stored layouts (written as stored, not through the write path). */
function ctCategory(string $slug, ?Taxonomy $parent = null, ?string $list = null, ?string $detail = null, string $vocabulary = 'post_cat'): Taxonomy
{
    $category = StorefrontCards::category('Ưu tiên ốc', $slug, $parent, $list);
    $category->forceFill(['detail_template' => $detail, 'vocabulary' => $vocabulary])->save();

    return $category->fresh() ?? $category;
}

it('follows the parent through one, two and three levels', function (): void {
    ctSite(function (): void {
        $root = ctCategory('tuyen-dung', null, 'grid-job', 'job');
        $one = ctCategory('ha-noi', $root, '@parent', '@parent');
        $two = ctCategory('cau-giay', $one, '@parent', '@parent');
        $templates = app(CategoryTemplates::class);

        expect($templates->list($one))->toBe('grid-job')
            ->and($templates->detail($one))->toBe('job')
            ->and($templates->list($two))->toBe('grid-job')
            ->and($templates->detail($two))->toBe('job');
    });
});

it('reaches the root from the third level of the tree', function (): void {
    ctSite(function (): void {
        $root = ctCategory('goc', null, 'grid-project', 'project');
        $b = ctCategory('con', $root, '@parent', '@parent');
        $c = ctCategory('chau', $b, '@parent', '@parent');

        expect(app(CategoryTemplates::class)->list($c))->toBe('grid-project')
            ->and(app(CategoryTemplates::class)->detail($c))->toBe('project');
    });
});

it('skips a parent whose layout has the wrong role and goes on up', function (): void {
    ctSite(function (): void {
        $root = ctCategory('goc', null, 'grid-job', 'job');
        $middle = ctCategory('giua', $root, 'post', 'grid-news');   // a post layout as a list, a list layout as a post
        $leaf = ctCategory('la', $middle, '@parent', '@parent');

        expect(app(CategoryTemplates::class)->list($leaf))->toBe('grid-job')
            ->and(app(CategoryTemplates::class)->detail($leaf))->toBe('job');
    });
});

it('falls back to the theme default when no category above has a usable layout', function (): void {
    ctSite(function (): void {
        $root = ctCategory('goc', null, null, null);
        $middle = ctCategory('giua', $root, 'post', 'grid-news');
        $leaf = ctCategory('la', $middle, '@parent', '@parent');
        $alone = ctCategory('mot-minh', null, '@parent', '@parent');

        expect(app(CategoryTemplates::class)->list($leaf))->toBeNull()
            ->and(app(CategoryTemplates::class)->detail($leaf))->toBeNull()
            ->and(app(CategoryTemplates::class)->list($alone))->toBeNull();
    });
});

it('gives a product category no post layout from the post category above it', function (): void {
    ctSite(function (): void {
        $posts = ctCategory('tin', null, 'grid-news', 'job');
        $products = ctCategory('hang', $posts, '@parent', '@parent', 'product_cat');
        $shelf = ctCategory('ke', null, 'grid-product', null, 'product_cat');
        $jigs = ctCategory('do-ga', $shelf, '@parent', null, 'product_cat');
        $templates = app(CategoryTemplates::class);

        expect($templates->list($products))->toBeNull()
            ->and($templates->detail($products))->toBeNull()
            ->and($templates->list($jigs))->toBe('grid-product');
    });
});

it('treats a stored layout the theme no longer lists, or of the wrong kind, as the default', function (string $stored): void {
    ctSite(function () use ($stored): void {
        $category = ctCategory('nhom', null, $stored, null);
        $child = ctCategory('con', $category, '@parent');

        expect(app(CategoryTemplates::class)->list($category))->toBeNull()
            ->and(app(CategoryTemplates::class)->list($child))->toBeNull();
    });
})->with(['gone from the theme' => 'khong-co', 'a post layout' => 'job', 'the reserved value for a missing ancestor' => '@parent', 'not a layout name' => '../post']);

it('never accepts the reserved value as a layout file name', function (): void {
    expect(preg_match(TaxonomyRules::TEMPLATE_PATTERN, TemplateCatalog::PARENT))->toBe(0)
        ->and(TaxonomyRules::template(TemplateCatalog::PARENT))->toBeFalse()
        ->and(TemplateCatalog::fits(TemplateCatalog::PARENT, TemplateCatalog::ROLE_LIST))->toBeFalse();
});

it('reads the chain from this site only, even when a parent id points into another site', function (): void {
    $other = StorefrontCards::site('Ánh sáng ấy', 'khac.platform.test');
    $foreign = app(Tenancy::class)->enter($other, fn (): Taxonomy => ctCategory('goc-khac', null, 'grid-job', 'job'));
    $mine = ctSite(fn (): Taxonomy => ctCategory('nhom-cua-toi', null, '@parent', '@parent'));
    // Corrupt on purpose: the parent id names the other site's category (the privileged connection skips RLS).
    DB::connection('pgsql_admin')->table('taxonomies')->where('id', $mine->id)->update(['parent_id' => $foreign->id]);

    $log = [];
    $resolved = ctSite(function () use ($mine, &$log): array {
        $fresh = Taxonomy::query()->findOrFail($mine->id);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $result = [app(CategoryTemplates::class)->list($fresh), app(CategoryTemplates::class)->detail($fresh)];
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        return $result;
    });
    $theirs = app(Tenancy::class)->enter($other, fn (): ?string => app(CategoryTemplates::class)->list(Taxonomy::query()->findOrFail($foreign->id)));

    // The row security alone would hide the other site's rows; the query itself must also name the site
    // on the anchor and on the recursive step, so a connection that skips it still reads only this site.
    $chains = array_values(array_filter($log, fn (array $entry): bool => str_contains((string) $entry['query'], 'WITH RECURSIVE')));
    expect($chains)->not->toBeEmpty();
    foreach ($chains as $entry) {
        expect(preg_match_all('/\bt\.site_id = \?/', (string) $entry['query']))->toBe(1)
            ->and(preg_match_all('/\bup\.site_id = \?/', (string) $entry['query']))->toBe(1)
            ->and(array_values(array_filter($entry['bindings'], fn (mixed $binding): bool => $binding === test()->siteId)))->toHaveCount(2);
    }

    expect($resolved)->toBe([null, null])
        ->and($theirs)->toBe('grid-job');
});

it('ends a parent loop at the depth cap and falls back to the default', function (): void {
    [$a, $b] = ctSite(fn (): array => [ctCategory('vong-a', null, '@parent', '@parent'), ctCategory('vong-b', null, '@parent', '@parent')]);
    // Corrupt on purpose: two categories that are each other's parent (the privileged connection skips the write rules).
    DB::connection('pgsql_admin')->table('taxonomies')->where('id', $a->id)->update(['parent_id' => $b->id]);
    DB::connection('pgsql_admin')->table('taxonomies')->where('id', $b->id)->update(['parent_id' => $a->id]);

    $log = [];
    $resolved = ctSite(function () use ($a, &$log): array {
        $fresh = Taxonomy::query()->findOrFail($a->id);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $result = [app(CategoryTemplates::class)->list($fresh), app(CategoryTemplates::class)->detail($fresh), app(CategoryTemplates::class)->jobListTarget($fresh)->id];
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        return $result;
    });

    expect($resolved)->toBe([null, null, $a->id])
        ->and(count($log))->toBe(3);
});

it('points the open-positions link at the nearest category above that lists jobs, else at the post category', function (): void {
    ctSite(function (): void {
        $jobs = ctCategory('tuyen-dung', null, 'grid-job', 'job');
        $place = ctCategory('ha-noi', $jobs, '@parent', '@parent');
        $deep = ctCategory('cau-giay', $place, '@parent', '@parent');
        $news = ctCategory('tin', null, 'grid-news', 'post');
        $templates = app(CategoryTemplates::class);

        expect($templates->jobListTarget($deep)->id)->toBe($jobs->id)
            ->and($templates->jobListTarget($jobs)->id)->toBe($jobs->id)
            ->and($templates->jobListTarget($news)->id)->toBe($news->id);
    });
});

it('lists only the layouts that fit a role and a kind of category', function (): void {
    expect(TemplateCatalog::names(TemplateCatalog::ROLE_LIST, TemplateCatalog::KIND_POST))->toBe(['category', 'grid-job', 'grid-news', 'grid-product', 'grid-project'])
        ->and(TemplateCatalog::names(TemplateCatalog::ROLE_LIST, TemplateCatalog::KIND_PRODUCT))->toBe(['category', 'grid-product'])
        ->and(TemplateCatalog::names(TemplateCatalog::ROLE_POST, TemplateCatalog::KIND_POST))->toBe(['job', 'post', 'project'])
        ->and(TemplateCatalog::names(TemplateCatalog::ROLE_PAGE))->toBe(['page'])
        ->and(TemplateCatalog::isJob('grid-job'))->toBeTrue()
        ->and(TemplateCatalog::isJob('job'))->toBeTrue()
        ->and(TemplateCatalog::isJob('post'))->toBeFalse()
        ->and(TemplateCatalog::cards('grid-job'))->toBe('news')
        ->and(TemplateCatalog::cards('category'))->toBeNull();
});

it('counts the stored layouts of the wrong role without changing anything', function (): void {
    ctSite(function (): void {
        ctCategory('sai-mot', null, 'post', null);
        ctCategory('sai-hai', null, null, 'grid-news');
        ctCategory('dung', null, 'grid-job', 'job');
        ctCategory('theo-cha', null, '@parent', '@parent');
        $post = StorefrontCards::post(StorefrontCards::news(), 'Tin sai', 'tin-sai');
        $post->forceFill(['template_override' => 'grid-news'])->save();
        StorefrontCards::page('/gioi-thieu', 'Giới thiệu', [])->forceFill(['template_override' => 'job'])->save();
        StorefrontCards::page('/lien-he-hai', 'Liên hệ hai', [])->forceFill(['template_override' => 'page'])->save();
    });

    $this->artisan('cms:count-wrong-templates')
        ->expectsOutputToContain('Wrong layout in total: 2 categor(ies), 1 post(s), 1 page(s). Nothing was changed.')
        ->assertSuccessful();

    ctSite(function (): void {
        expect(Taxonomy::query()->where('list_template', 'post')->count())->toBe(1)
            ->and(Taxonomy::query()->where('detail_template', 'grid-news')->count())->toBe(1);
    });
});
