<?php

use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Tenancy\Context\Tenancy;

/*
 * PR②b — taxonomy (category) routing. A category gets a flat /slug route (the list page).
 * A path is owned by exactly one target, so an entry↔taxonomy clash raises a friendly
 * RouteConflictException in BOTH directions (A2) — closing the NULL-foreign-key gap the
 * entry-only guard had.
 */

beforeEach(fn () => seedTenancy());

function newCategory(string $slug, string $name = 'Tin tức'): Taxonomy
{
    $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
    $category->translations()->create(['locale' => 'vi', 'name' => $name, 'slug' => $slug]);

    return $category;
}

it('routes a category at its flat localized path', function () {
    app(Tenancy::class)->enter(1, function () {
        $category = newCategory('tin-tuc');

        $path = app(RouteSync::class)->syncTaxonomy($category, 'vi', 'tin-tuc');

        expect($path)->toBe('/tin-tuc')
            ->and(SiteRoute::query()->where('taxonomy_id', $category->id)->value('path'))->toBe('/tin-tuc');
    });
});

it('is idempotent on the category route', function () {
    app(Tenancy::class)->enter(1, function () {
        $category = newCategory('tin-tuc');
        app(RouteSync::class)->syncTaxonomy($category, 'vi', 'tin-tuc');
        app(RouteSync::class)->syncTaxonomy($category, 'vi', 'tin-tuc');

        expect(SiteRoute::query()->where('taxonomy_id', $category->id)->count())->toBe(1);
    });
});

it('rejects a category route whose path an entry already owns (A2)', function () {
    app(Tenancy::class)->enter(1, function () {
        $page = Entry::query()->create(['type' => 'page', 'status' => 'published']);
        SiteRoute::query()->create(['locale' => 'vi', 'path' => '/tin-tuc', 'entry_id' => $page->id]);

        $category = newCategory('tin-tuc');

        expect(fn () => app(RouteSync::class)->syncTaxonomy($category, 'vi', 'tin-tuc'))
            ->toThrow(RouteConflictException::class);
    });
});

it('rejects an entry route whose path a category already owns (A2 reverse)', function () {
    app(Tenancy::class)->enter(1, function () {
        $category = newCategory('tin-tuc');
        app(RouteSync::class)->syncTaxonomy($category, 'vi', 'tin-tuc');

        // Was a NULL-comparison gap: a taxonomy route leaves entry_id NULL, so the old
        // `entry_id != ?` guard never matched it. The unified guard now rejects this.
        $page = Entry::query()->create(['type' => 'page', 'status' => 'published']);

        expect(fn () => app(RouteSync::class)->sync($page, 'vi', 'tin-tuc'))
            ->toThrow(RouteConflictException::class);
    });
});
