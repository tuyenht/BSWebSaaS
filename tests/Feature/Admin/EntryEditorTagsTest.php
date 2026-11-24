<?php

use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Actions\Taxonomies\CreateTaxonomy;
use App\Modules\Cms\Data\TaxonomyData;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\EntryTags;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * The post editor's «Thẻ» card (board 07): names typed and added with Enter or «Thêm» (several split
 * at commas, each once), taken out with «Bỏ thẻ», stored on save as tags (vocabulary `tag`) filed at
 * facet positions, so the categories stay as they are; a name no tag has in the language makes a tag
 * with its page at /the/{slug}, one that has reuses it; the right to edit the post is enough. A tag
 * whose address another page holds is refused when typed. While the member's saves of a live post
 * wait for approval the card is read-only and a save that changes the tags is refused.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy(); // sites 1 (a.test) / 2 (b.test), Vietnamese default
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
});

/** The «Tin tức» post section of site 1; returns its category. */
function etgSection(): Taxonomy
{
    $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
    $category->translations()->create(['locale' => 'vi', 'name' => 'Tin tức', 'slug' => 'tin-tuc']);
    ContentSection::query()->create([
        'key' => 'tin-tuc', 'label' => 'Tin tức', 'entry_type' => 'post', 'taxonomy_id' => $category->id,
        'permission_prefix' => 'entries', 'sort_order' => 1,
    ]);

    return $category;
}

/** An editor (edits any, publishes) and an author (edits own, may not publish) of site 1. */
function etgCast(): array
{
    $editor = User::factory()->create();
    $author = User::factory()->create();
    app(Tenancy::class)->enter(1, function () use ($author, $editor): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.create', 'entries.edit.any', 'entries.publish', 'taxonomies.manage']);
        grantRole($author, 'author', ['entries.view', 'entries.create', 'entries.edit.own', 'entries.submit']);
    });
    app(TeamContext::class)->forget();

    return [$editor->fresh(), $author->fresh()];
}

/** The post's tag rows: taxonomy id => pivot position. */
function etgRows(Entry $post): array
{
    return DB::table('entry_taxonomies')->where('entry_id', $post->id)->where('position', '>=', Entry::FACET_POSITION_BASE)
        ->orderBy('position')->pluck('position', 'taxonomy_id')->map(fn ($p): int => (int) $p)->all();
}

it('adds tags with Enter, each once, and stores them as facet terms beside the categories', function (): void {
    [$editor] = etgCast();
    app(Tenancy::class)->enter(1, function () use ($editor): void {
        $category = etgSection();

        $form = Livewire::actingAs($editor)->test(Editor::class, ['section' => 'tin-tuc'])
            ->assertSee(__('entries.ed.card_tags'))->assertSee(__('entries.ed.tag_hint'))
            ->set('tagDraft', 'Máy phay 5 trục, CNC')->call('addTag')
            ->assertSet('tags', ['Máy phay 5 trục', 'CNC'])->assertSet('tagDraft', '')
            // the same address again is the same tag
            ->set('tagDraft', 'máy  phay 5 TRỤC')->call('addTag')->assertSet('tags', ['Máy phay 5 trục', 'CNC'])
            ->assertSeeHtml('aria-label="'.__('entries.ed.tag_remove', ['name' => 'CNC']).'"')
            ->set('title', 'Khai trương xưởng')->set('slug', 'khai-truong-xuong')->call('save')->assertHasNoErrors();

        $post = Entry::query()->sole();
        $tags = Taxonomy::query()->where('vocabulary', Taxonomy::TAG)->orderBy('id')->get();
        expect($tags)->toHaveCount(2)
            ->and(etgRows($post))->toBe([$tags[0]->id => Entry::FACET_POSITION_BASE, $tags[1]->id => Entry::FACET_POSITION_BASE + 1])
            ->and($post->primaryCategory()?->id)->toBe($category->id)
            ->and(SiteRoute::query()->where('taxonomy_id', $tags[0]->id)->value('path'))->toBe('/the/may-phay-5-truc');

        // reopened: the tags in order; one taken out leaves the post (the tag itself stays)
        Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid])
            ->assertSet('tags', ['Máy phay 5 trục', 'CNC'])
            ->call('removeTag', 0)->assertSet('tags', ['CNC'])
            ->call('save')->assertHasNoErrors();
        expect(etgRows($post))->toBe([$tags[1]->id => Entry::FACET_POSITION_BASE])
            ->and(Taxonomy::query()->where('vocabulary', Taxonomy::TAG)->count())->toBe(2)
            ->and($post->primaryCategory()?->id)->toBe($category->id);

        // a second post reuses the tag of the same address
        Livewire::actingAs($editor)->test(Editor::class, ['section' => 'tin-tuc'])
            ->set('tagDraft', 'cnc')->call('addTag')
            ->set('title', 'Bài hai')->set('slug', 'bai-hai')->call('save')->assertHasNoErrors();
        expect(Taxonomy::query()->where('vocabulary', Taxonomy::TAG)->count())->toBe(2);
    });
});

it('refuses a name with nothing to make an address from, a tag past the limit, and an address another page holds', function (): void {
    [$editor] = etgCast();
    app(Tenancy::class)->enter(1, function () use ($editor): void {
        etgSection();
        $page = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $editor->id]);
        SiteRoute::query()->create(['locale' => 'vi', 'path' => '/the/bang-gia', 'entry_id' => $page->id]);

        $form = Livewire::actingAs($editor)->test(Editor::class, ['section' => 'tin-tuc'])
            ->set('tagDraft', '!!!')->call('addTag')->assertHasErrors('tagDraft')->assertSet('tags', [])
            ->set('tagDraft', str_repeat('a', EntryTags::NAME_MAX + 1))->call('addTag')->assertHasErrors('tagDraft')
            ->set('tagDraft', 'Bảng giá')->call('addTag')
            ->assertHasErrors(['tagDraft' => __('entries.ed.tag_taken', ['name' => 'Bảng giá'])])->assertSet('tags', []);

        $form->set('tagDraft', implode(',', array_map(fn (int $n): string => "thẻ {$n}", range(1, EntryTags::MAX_TAGS + 1))))->call('addTag')
            ->assertHasErrors(['tagDraft' => __('entries.ed.tag_full', ['max' => EntryTags::MAX_TAGS])]);
        // a list sent past the card is refused on save too
        $form->set('tags', array_map(fn (int $n): string => "thẻ {$n}", range(1, EntryTags::MAX_TAGS + 1)))
            ->set('title', 'Bài')->set('slug', 'bai')->call('save')->assertHasErrors('tags');
        expect(Taxonomy::query()->where('vocabulary', Taxonomy::TAG)->count())->toBe(0);
    });
});

it('keeps the card read-only while a live post’s saves wait for approval, and refuses a tag change there', function (): void {
    [$editor, $author] = etgCast();
    app(Tenancy::class)->enter(1, function () use ($editor, $author): void {
        $category = etgSection();
        $post = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'author_id' => $author->id]);
        $post->translations()->create(['locale' => 'vi', 'title' => 'Bản đang đăng', 'slug' => 'bai-1', 'status' => 'draft', 'body' => ['blocks' => []]]);
        $post->assignCategories([$category->id]);
        app(PublishEntry::class)->handle($post->fresh(), 'vi', $editor);

        Livewire::actingAs($author)->test(Editor::class, ['ulid' => $post->ulid])
            ->assertSee(__('entries.ed.tag_held'))->assertSeeHtml('aria-readonly="true"')
            ->set('tags', ['Lén thêm'])->call('save')->assertHasErrors(['tagDraft' => __('entries.ed.tag_held')]);
        expect(Taxonomy::query()->where('vocabulary', Taxonomy::TAG)->count())->toBe(0)
            ->and(etgRows($post->fresh()))->toBe([]);
    });
});

it('keeps a tag of another website out of reach and lets a category take a tag’s slug', function (): void {
    [$editor] = etgCast();
    app(Tenancy::class)->enter(2, function (): void {
        $tag = Taxonomy::query()->create(['vocabulary' => Taxonomy::TAG, 'position' => 0]);
        $tag->translations()->create(['locale' => 'vi', 'name' => 'CNC', 'slug' => 'cnc']);
    });
    app(Tenancy::class)->enter(1, function () use ($editor): void {
        etgSection();
        Livewire::actingAs($editor)->test(Editor::class, ['section' => 'tin-tuc'])
            ->set('tagDraft', 'CNC')->call('addTag')
            ->set('title', 'Bài')->set('slug', 'bai')->call('save')->assertHasNoErrors();
        // site 1 made its own tag: site 2's is never found
        expect(Taxonomy::query()->where('vocabulary', Taxonomy::TAG)->count())->toBe(1);

        // a category named like the tag keeps the plain slug (the tag lives under /the/)
        $category = app(CreateTaxonomy::class)->handle('post_cat', null, new TaxonomyData(names: ['vi' => 'CNC']));
        expect($category->translationFor('vi')?->slug)->toBe('cnc');
    });
});
