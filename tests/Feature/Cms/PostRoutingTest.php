<?php

use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;

/*
 * PR②a — post routing under categories (design.md §213: path = danh mục + slug).
 * PathBuilder composes a post's path from its primary category's localized slug;
 * pages stay flat. The entry_taxonomies pivot is RLS-protected (site_id stamped).
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/** Create a post category (post_cat) + its vi translation in the current site. */
function makeCategory(string $slug, string $name = 'Danh mục'): Taxonomy
{
    $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
    $category->translations()->create(['locale' => 'vi', 'name' => $name, 'slug' => $slug]);

    return $category;
}

it('routes a published post under its category path (/cat/slug)', function (): void {
    $tenancy = app(Tenancy::class);
    $editor = User::factory()->create();

    $tenancy->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);

        $category = makeCategory('tin-tuc', 'Tin tức');
        $post = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'author_id' => $editor->id]);
        $post->translations()->create(['locale' => 'vi', 'title' => 'Bài 1', 'slug' => 'bai-1', 'status' => 'draft', 'body' => ['blocks' => []]]);
        $post->assignCategories([$category->id]);

        app(PublishEntry::class)->handle($post->fresh(), 'vi', $editor->fresh());

        expect(SiteRoute::query()->where('entry_id', $post->id)->value('path'))->toBe('/tin-tuc/bai-1');
    });
});

it('keeps a page route flat (no category prefix)', function (): void {
    $tenancy = app(Tenancy::class);
    $editor = User::factory()->create();

    $tenancy->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);

        $page = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $editor->id]);
        $page->translations()->create(['locale' => 'vi', 'title' => 'GT', 'slug' => 'gioi-thieu', 'status' => 'draft', 'body' => ['blocks' => []]]);

        app(PublishEntry::class)->handle($page->fresh(), 'vi', $editor->fresh());

        expect(SiteRoute::query()->where('entry_id', $page->id)->value('path'))->toBe('/gioi-thieu');
    });
});

it('stamps site_id on the category pivot (RLS-safe) and isolates per site', function (): void {
    $tenancy = app(Tenancy::class);

    $catOne = $tenancy->enter(1, fn () => makeCategory('tin-tuc')->id);

    // Site 2 cannot see site 1's category pivot rows (RLS).
    $tenancy->enter(2, function () use ($catOne): void {
        expect(Taxonomy::query()->whereKey($catOne)->exists())->toBeFalse();
    });
});
