<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\ResolvedCategory;
use App\Modules\Cms\Routing\RouteResolver;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Database\QueryException;

/*
 * P1.1 — the route resolver maps (locale, path) → publishable content within the
 * current site only. Visibility = entry published AND translation published.
 */

beforeEach(fn () => seedTenancy());

function makePage(int $site, string $path, string $status = 'published', string $tStatus = 'published'): void
{
    app(Tenancy::class)->enter($site, function () use ($path, $status, $tStatus) {
        $entry = Entry::create(['type' => 'page', 'status' => $status]);
        $entry->translations()->create([
            'locale' => 'vi',
            'title' => 'Trang chủ',
            'slug' => 'home',
            'body' => ['blocks' => []],
            'status' => $tStatus,
        ]);
        SiteRoute::create(['locale' => 'vi', 'path' => $path, 'entry_id' => $entry->id]);
    });
}

function resolveIn(int $site, string $path): mixed
{
    return app(Tenancy::class)->enter($site, fn () => app(RouteResolver::class)->resolve('vi', $path));
}

it('resolves a published page by path within the current site', function () {
    makePage(1, '/');

    $resolved = resolveIn(1, '/');

    expect($resolved)->not->toBeNull()
        ->and($resolved->entry->type)->toBe('page')
        ->and($resolved->translation->title)->toBe('Trang chủ');
});

it('does not resolve another site route (isolation)', function () {
    makePage(1, '/');

    expect(resolveIn(2, '/'))->toBeNull();
});

it('does not resolve an unpublished entry', function () {
    makePage(1, '/', status: 'draft');

    expect(resolveIn(1, '/'))->toBeNull();
});

it('does not resolve an unpublished translation', function () {
    makePage(1, '/', tStatus: 'draft');

    expect(resolveIn(1, '/'))->toBeNull();
});

it('enforces the single-target route constraint (D3)', function () {
    app(Tenancy::class)->enter(1, function () {
        expect(fn () => SiteRoute::create(['locale' => 'vi', 'path' => '/orphan']))
            ->toThrow(QueryException::class);
    });
});

it('resolves a category list route to a ResolvedCategory', function () {
    app(Tenancy::class)->enter(1, function () {
        $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
        $category->translations()->create(['locale' => 'vi', 'name' => 'Tin tức', 'slug' => 'tin-tuc']);
        app(RouteSync::class)->syncTaxonomy($category, 'vi', 'tin-tuc');

        $resolved = app(RouteResolver::class)->resolve('vi', '/tin-tuc');

        expect($resolved)->toBeInstanceOf(ResolvedCategory::class)
            ->and($resolved->taxonomy->id)->toBe($category->id)
            ->and($resolved->translation->name)->toBe('Tin tức');
    });
});
