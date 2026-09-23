<?php

use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Catalog\Models\Product;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\SectionResolver;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use Livewire\Livewire;

/*
 * One pivot (entry_taxonomies / product_taxonomies) holds an item's categories AND its facet terms
 * (sector, tag…). Pivot position 0 is THE primary category — routing, the section a post belongs
 * to (hence which workflow governs it), the category listings and the admin filter all read it —
 * so categories occupy 0..n-1 and facets live from FACET_POSITION_BASE up. Two ways that broke:
 * re-filing a post wiped its facet terms (sync() diffs against every pivot row), and the "primary
 * category" readers took the first row of ANY vocabulary, so a post carrying only a sector had that
 * sector loaded as its category — and saving the untouched post then failed validation.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

function pcTerm(string $vocabulary, string $name, string $slug): Taxonomy
{
    $term = Taxonomy::query()->create(['vocabulary' => $vocabulary, 'position' => 0]);
    $term->translations()->create(['locale' => 'vi', 'name' => $name, 'slug' => $slug]);

    return $term;
}

function pcSection(string $key, Taxonomy $category): void
{
    ContentSection::query()->create([
        'key' => $key, 'label' => $key, 'entry_type' => 'post', 'taxonomy_id' => $category->id,
        'permission_prefix' => 'entries', 'sort_order' => 1,
    ]);
}

function pcPost(): Entry
{
    $entry = Entry::query()->create(['type' => 'post', 'status' => 'draft']);
    $entry->translations()->create(['locale' => 'vi', 'title' => 'Dây chuyền', 'slug' => 'day-chuyen', 'status' => 'draft', 'body' => ['blocks' => []]]);

    return $entry;
}

/** File $term on $item as a facet — where a facet writer puts it (never at position 0). */
function pcFacet(Entry|Product $item, Taxonomy $term, int $offset = 0): void
{
    $item->taxonomies()->attach($term->id, ['position' => $item::FACET_POSITION_BASE + $offset, 'site_id' => 1]);
}

it('keeps facet terms when a post is re-filed under another category', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $news = pcTerm('post_cat', 'Tin tức', 'tin-tuc');
        $projects = pcTerm('post_cat', 'Dự án', 'du-an');
        $sector = pcTerm('sector', 'Ô tô', 'o-to');
        $post = pcPost();
        $post->assignCategories([$news->id]);
        pcFacet($post, $sector);

        $post->assignCategories([$projects->id]);

        expect($post->sectors()->pluck('taxonomies.id')->all())->toBe([$sector->id])
            ->and($post->primaryCategory()?->id)->toBe($projects->id);
    });
});

it('never reads a facet term as the primary category', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $news = pcTerm('post_cat', 'Tin tức', 'tin-tuc');
        pcSection('posts', $news);
        $sector = pcTerm('sector', 'Ô tô', 'o-to');
        $post = pcPost();
        pcFacet($post, $sector);

        $loaded = Entry::query()->with('taxonomies')->findOrFail($post->id);

        expect($post->primaryCategory())->toBeNull()
            ->and($loaded->primaryCategory())->toBeNull()
            ->and(app(SectionResolver::class)->forEntry($loaded))->toBeNull();

        // Filed under a category, the category wins over the facet, loaded or not.
        $post->assignCategories([$news->id]);
        $reloaded = Entry::query()->with('taxonomies')->findOrFail($post->id);

        expect($post->primaryCategory()?->id)->toBe($news->id)
            ->and($reloaded->primaryCategory()?->id)->toBe($news->id)
            ->and(app(SectionResolver::class)->forEntry($reloaded)['key'] ?? null)->toBe('posts');
    });
});

it('opens a post that carries only a facet term with no category selected', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.create', 'entries.edit.any', 'entries.publish']);
        pcSection('posts', pcTerm('post_cat', 'Tin tức', 'tin-tuc'));
        $post = pcPost();
        pcFacet($post, pcTerm('sector', 'Ô tô', 'o-to'));

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class, ['ulid' => $post->ulid])
            ->assertSet('categoryId', null);
    });
});

it('keeps a product facet term when the product is re-filed', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $jigs = pcTerm('product_cat', 'Đồ gá', 'do-ga');
        $molds = pcTerm('product_cat', 'Khuôn', 'khuon');
        $sector = pcTerm('sector', 'Ô tô', 'o-to');
        $product = Product::query()->create(['status' => 'draft']);
        $product->assignCategories([$jigs->id]);
        pcFacet($product, $sector);

        $product->assignCategories([$molds->id]);

        expect($product->taxonomies()->pluck('taxonomies.id')->all())->toBe([$molds->id, $sector->id])
            ->and($product->primaryCategory()?->id)->toBe($molds->id);
    });
});

it('refuses more categories than fit below the facet range', function (): void {
    app(Tenancy::class)->enter(1, fn () => pcPost()->assignCategories(range(1, Entry::FACET_POSITION_BASE)));
})->throws(InvalidArgumentException::class);
