<?php

use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Admin\Livewire\Entries\Index;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use Livewire\Livewire;

/*
 * PR②a — the content editor serving `post` entries: type is taken from the content_section
 * (?section=posts), posts carry a primary category (Entry::assignCategories) so they route
 * under /tin-tuc/{slug}, and the same Index lists by the section's entry_type. Posts reuse
 * the block-list Page-JSON body (D1). All site-scoped by BelongsToSite + RLS.
 */

beforeEach(function () {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/** Seed a post_cat category + a 'posts' content_section bound to it; return the category id. */
function seedPostCollection(string $slug = 'tin-tuc', string $name = 'Tin tức'): int
{
    $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
    $category->translations()->create(['locale' => 'vi', 'name' => $name, 'slug' => $slug]);

    ContentSection::query()->create([
        'key' => 'posts',
        'label' => $name,
        'entry_type' => 'post',
        'taxonomy_id' => $category->id,
        'permission_prefix' => 'entries',
        'sort_order' => 1,
    ]);

    return $category->id;
}

it('creates a post (type from section) with its primary category', function () {
    $tenancy = app(Tenancy::class);
    $editor = User::factory()->create();

    $tenancy->enter(1, function () use ($editor) {
        grantRole($editor, 'editor', ['entries.create', 'entries.edit.any']);
        $categoryId = seedPostCollection();

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class, ['section' => 'posts'])
            ->assertSet('type', 'post')
            ->assertSet('categoryId', $categoryId)
            ->set('title', 'Bài 1')
            ->set('slug', 'bai-1')
            ->call('addBlock', 'richtext')
            ->set('blocks.0.props.body', 'Nội dung bài viết')
            ->call('save')
            ->assertHasNoErrors();

        $entry = Entry::query()->where('type', 'post')->first();
        expect($entry)->not->toBeNull()
            ->and($entry->status)->toBe('draft')
            ->and($entry->taxonomies()->first()?->id)->toBe($categoryId)
            ->and($entry->translationFor('vi')->body['blocks'][0]['type'])->toBe('richtext');
    });
});

it('publishes a post under its category path /tin-tuc/{slug}', function () {
    $tenancy = app(Tenancy::class);
    $editor = User::factory()->create();

    $tenancy->enter(1, function () use ($editor) {
        grantRole($editor, 'editor', ['entries.create', 'entries.edit.any', 'entries.publish']);
        seedPostCollection();

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class, ['section' => 'posts'])
            ->set('title', 'Bài 1')
            ->set('slug', 'bai-1')
            ->call('addBlock', 'richtext')
            ->call('publish')
            ->assertHasNoErrors()
            ->assertSet('status', 'published');

        $entry = Entry::query()->where('type', 'post')->first();
        expect(SiteRoute::query()->where('entry_id', $entry->id)->value('path'))->toBe('/tin-tuc/bai-1');
    });
});

it('requires a category when creating a post', function () {
    $tenancy = app(Tenancy::class);
    $editor = User::factory()->create();

    $tenancy->enter(1, function () use ($editor) {
        grantRole($editor, 'editor', ['entries.create', 'entries.edit.any']);
        seedPostCollection();

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class, ['section' => 'posts'])
            ->set('title', 'Bài 1')
            ->set('slug', 'bai-1')
            ->set('categoryId', null)
            ->call('addBlock', 'richtext')
            ->call('save')
            ->assertHasErrors('categoryId');

        expect(Entry::query()->where('type', 'post')->count())->toBe(0);
    });
});

it('cannot flip a post category to another site (RLS-scoped exists)', function () {
    $tenancy = app(Tenancy::class);
    $editor = User::factory()->create();

    // A post_cat that lives in site 2.
    $foreignCategory = $tenancy->enter(2, fn () => seedPostCollection('cat-b', 'Cat B'));

    $tenancy->enter(1, function () use ($editor, $foreignCategory) {
        grantRole($editor, 'editor', ['entries.create', 'entries.edit.any']);
        seedPostCollection();

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class, ['section' => 'posts'])
            ->set('title', 'Bài 1')
            ->set('slug', 'bai-1')
            ->set('categoryId', $foreignCategory) // belongs to site 2 — invisible under RLS
            ->call('save')
            ->assertHasErrors('categoryId');

        expect(Entry::query()->where('type', 'post')->count())->toBe(0);
    });
});

it('lists posts for the posts section and pages by default', function () {
    $tenancy = app(Tenancy::class);
    $viewer = User::factory()->create();

    $tenancy->enter(1, function () use ($viewer) {
        grantRole($viewer, 'editor', ['entries.view']);
        seedPostCollection();

        $page = Entry::query()->create(['type' => 'page', 'status' => 'draft']);
        $page->translations()->create(['locale' => 'vi', 'title' => 'Trang A', 'slug' => 'trang-a', 'status' => 'draft', 'body' => ['blocks' => []]]);

        $post = Entry::query()->create(['type' => 'post', 'status' => 'draft']);
        $post->translations()->create(['locale' => 'vi', 'title' => 'Bài B', 'slug' => 'bai-b', 'status' => 'draft', 'body' => ['blocks' => []]]);

        // The posts section lists posts only.
        Livewire::actingAs($viewer->fresh())
            ->test(Index::class, ['section' => 'posts'])
            ->assertSee('Bài B')
            ->assertDontSee('Trang A');

        // No section → the pages fallback.
        Livewire::actingAs($viewer->fresh())
            ->test(Index::class)
            ->assertSee('Trang A')
            ->assertDontSee('Bài B');
    });
});
