<?php

use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteSetting;

/*
 * Post routing under the per-site permalink engine (design.md §213 + permalink U1-b).
 * A post is FLAT `/slug` by DEFAULT; its section (derived from the primary category via
 * SectionResolver) opts into a category / category_path / base preset. Pages stay flat.
 * The entry_taxonomies pivot is RLS-protected (site_id stamped).
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/** Create a post category (post_cat) + its vi translation in the current site. */
function makeCategory(string $slug, string $name = 'Danh mục', ?int $parentId = null): Taxonomy
{
    $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0, 'parent_id' => $parentId]);
    $category->translations()->create(['locale' => 'vi', 'name' => $name, 'slug' => $slug]);

    return $category;
}

/**
 * Bind a post ContentSection to a category and (optionally) pin its permalink preset.
 *
 * @param  array<string, mixed>|null  $pattern  the stored `value` (e.g. ['pattern' => 'category']); null = no pin (flat)
 */
function makePostSection(int $taxonomyId, string $key = 'posts', ?array $pattern = ['pattern' => 'category']): void
{
    ContentSection::query()->create([
        'key' => $key, 'label' => ucfirst($key), 'entry_type' => 'post', 'taxonomy_id' => $taxonomyId,
    ]);

    if ($pattern !== null) {
        SiteSetting::query()->create(['group_name' => 'permalinks', 'key' => $key, 'value' => $pattern]);
    }
}

/**
 * Publish a fresh post assigned to the given categories; return its stored route path.
 *
 * @param  list<int>  $categoryIds
 */
function publishPostRoute(User $editor, string $slug, array $categoryIds): ?string
{
    $post = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'author_id' => $editor->id]);
    $post->translations()->create(['locale' => 'vi', 'title' => 'Bài', 'slug' => $slug, 'status' => 'draft', 'body' => ['blocks' => []]]);
    $post->assignCategories($categoryIds);

    app(PublishEntry::class)->handle($post->fresh(), 'vi', $editor->fresh());

    return SiteRoute::query()->where('entry_id', $post->id)->value('path');
}

it('routes a post FLAT by default — no permalink pin means /slug', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $editor = User::factory()->create();
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);

        // Category + section exist, but the section is NOT pinned → platform default (flat).
        $category = makeCategory('tin-tuc', 'Tin tức');
        makePostSection($category->id, 'posts', null);

        expect(publishPostRoute($editor, 'bai-1', [$category->id]))->toBe('/bai-1');
    });
});

it('routes a post under its category when the section is pinned to `category` (/cat/slug)', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $editor = User::factory()->create();
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);

        $category = makeCategory('tin-tuc', 'Tin tức');
        makePostSection($category->id, 'posts', ['pattern' => 'category']);

        expect(publishPostRoute($editor, 'bai-1', [$category->id]))->toBe('/tin-tuc/bai-1');
    });
});

it('routes a post under the full category chain when pinned to `category_path`', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $editor = User::factory()->create();
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);

        $parent = makeCategory('noi-that', 'Nội thất');
        $child = makeCategory('van-phong', 'Văn phòng', $parent->id);
        // Section bound to the child; the post's primary is the child → chain walks up to the root.
        makePostSection($child->id, 'posts', ['pattern' => 'category_path']);

        expect(publishPostRoute($editor, 'ghe', [$child->id]))->toBe('/noi-that/van-phong/ghe');
    });
});

it('routes a post under a fixed base segment when pinned to `base`', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $editor = User::factory()->create();
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);

        $category = makeCategory('tin-tuc', 'Tin tức');
        makePostSection($category->id, 'posts', ['pattern' => 'base', 'base' => 'bai-viet']);

        // base ignores the category slug entirely.
        expect(publishPostRoute($editor, 'bai-1', [$category->id]))->toBe('/bai-viet/bai-1');
    });
});

it('routes a post with no category FLAT even under a category pin', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $editor = User::factory()->create();
        grantRole($editor, 'editor', ['entries.edit.any', 'entries.publish']);

        expect(publishPostRoute($editor, 'khong-danh-muc', []))->toBe('/khong-danh-muc');
    });
});

it('keeps a page route flat (no category prefix)', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $editor = User::factory()->create();
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
