<?php

use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Actions\Taxonomies\CreateTaxonomy;
use App\Modules\Cms\Data\TaxonomyData;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\MemberCategoryScope;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Cms\Support\EntryTags;
use App\Modules\Cms\Taxonomy\TaxonomyPaths;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Workflow\Models\Workflow;
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

/** A draft post of site 1 under $category by $author, its Vietnamese text written. */
function etgDraft(Taxonomy $category, ?User $author, string $slug = 'bai-nhap'): Entry
{
    $post = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'author_id' => $author?->id, 'meta' => []]);
    $post->translations()->create(['locale' => 'vi', 'title' => 'Bài nháp', 'slug' => $slug, 'status' => 'draft', 'body' => ['blocks' => []]]);
    $post->assignCategories([$category->id]);

    return $post->fresh() ?? $post;
}

it('files only the tags a form added or took out, so a tag another editor added meanwhile stays', function (): void {
    [$editor] = etgCast();
    $other = User::factory()->create();
    app(Tenancy::class)->enter(1, function () use ($other): void {
        grantRole($other, 'editor', ['entries.view', 'entries.create', 'entries.edit.any', 'entries.publish']);
    });
    app(TeamContext::class)->forget();
    app(Tenancy::class)->enter(1, function () use ($editor, $other): void {
        $category = etgSection();
        $post = etgDraft($category, $editor);
        app(EntryTags::class)->assign($post, 'vi', ['CNC', 'Máy phay']);

        // A has the post open with its two tags
        $a = Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid])->assertSet('tags', ['CNC', 'Máy phay']);

        // B adds a tag and saves
        Livewire::actingAs($other->fresh())->test(Editor::class, ['ulid' => $post->ulid])
            ->set('tagDraft', 'Khuôn mẫu')->call('addTag')->call('save')->assertHasNoErrors();
        expect(app(EntryTags::class)->names($post, 'vi'))->toBe(['CNC', 'Máy phay', 'Khuôn mẫu']);

        // A's draft saves itself after a title edit, no tag touched: B's tag stays, and A's card now shows it
        $a->set('title', 'Bài nháp đã sửa')->call('autosave')->assertHasNoErrors()
            ->assertSet('tags', ['CNC', 'Máy phay', 'Khuôn mẫu']);
        expect(app(EntryTags::class)->names($post, 'vi'))->toBe(['CNC', 'Máy phay', 'Khuôn mẫu']);

        // B takes «CNC» out while A takes «Máy phay» out and adds «Tiện CNC»: both changes are kept
        $b = Livewire::actingAs($other->fresh())->test(Editor::class, ['ulid' => $post->ulid]);
        $a->call('removeTag', 1)->set('tagDraft', 'Tiện CNC')->call('addTag');
        $b->call('removeTag', 0)->call('save')->assertHasNoErrors();
        $a->call('save')->assertHasNoErrors()->assertSet('tags', ['Khuôn mẫu', 'Tiện CNC']);
        expect(app(EntryTags::class)->names($post, 'vi'))->toBe(['Khuôn mẫu', 'Tiện CNC'])
            ->and($post->fresh()?->translationFor('vi')?->title)->toBe('Bài nháp đã sửa');
    });
});

it('never fails a save on a tag whose address another page holds: the name goes back to the box with a plain reason', function (): void {
    [$editor] = etgCast();
    app(Tenancy::class)->enter(1, function () use ($editor): void {
        $category = etgSection();
        $page = Entry::query()->create(['type' => 'page', 'status' => 'published', 'published_at' => now(), 'author_id' => $editor->id]);
        SiteRoute::query()->create(['locale' => 'vi', 'path' => '/the/bang-gia', 'entry_id' => $page->id]);
        $taken = __('entries.ed.tag_taken', ['name' => 'Bảng giá']);

        // «Lưu nháp» with the list set straight from the browser, past the card's own check
        $new = Livewire::actingAs($editor)->test(Editor::class, ['section' => 'tin-tuc'])
            ->set('title', 'Bài mới')->set('slug', 'bai-moi')->set('tags', ['Bảng giá', 'CNC'])
            ->call('save')->assertHasErrors(['tagDraft' => $taken])
            ->assertSet('tags', ['CNC'])->assertSet('tagDraft', 'Bảng giá');
        $saved = Entry::query()->where('ulid', $new->get('ulid'))->sole();
        expect($saved->translationFor('vi')?->title)->toBe('Bài mới')
            ->and(app(EntryTags::class)->names($saved, 'vi'))->toBe(['CNC']);

        // a draft saving itself: the same answer once, and the next save by itself has nothing left to write
        $post = etgDraft($category, $editor);
        $form = Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid])
            ->set('title', 'Bài nháp mới')->set('tags', ['Bảng giá'])
            ->call('autosave')->assertHasErrors(['tagDraft' => $taken])->assertSet('tags', [])->assertSet('tagDraft', 'Bảng giá');
        $stamp = $post->translations()->where('locale', 'vi')->value('updated_at');
        $this->travel(1)->minutes();
        $form->call('autosave');
        expect($post->translations()->where('locale', 'vi')->value('updated_at'))->toEqual($stamp)
            ->and($post->translations()->where('locale', 'vi')->value('title'))->toBe('Bài nháp mới');

        // «Đăng»: the post goes live, the refused tag stays out
        Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid])
            ->set('tags', ['Bảng giá'])->call('publish')->assertHasErrors(['tagDraft' => $taken]);
        expect($post->fresh()?->status)->toBe('published')
            ->and(app(EntryTags::class)->names($post, 'vi'))->toBe([]);

        // taken between the check and the write (a tag's own route at the address under another name): the
        // tag is not made, nothing of it is left, and the rest of the save stands
        $odd = Taxonomy::query()->create(['vocabulary' => Taxonomy::TAG, 'position' => 0]);
        $odd->translations()->create(['locale' => 'vi', 'name' => 'Cũ', 'slug' => 'cu']);
        SiteRoute::query()->create(['locale' => 'vi', 'path' => '/the/moi', 'taxonomy_id' => $odd->id]);
        $draft = etgDraft($category, $editor, 'bai-khac');
        Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $draft->ulid])
            ->set('title', 'Bài khác')->set('tags', ['Mới', 'CNC'])->call('save')
            ->assertHasErrors(['tagDraft' => __('entries.ed.tag_taken', ['name' => 'Mới'])])->assertSet('tags', ['CNC']);
        expect(Taxonomy::query()->where('vocabulary', Taxonomy::TAG)->count())->toBe(2)
            ->and($draft->translations()->where('locale', 'vi')->value('title'))->toBe('Bài khác');
    });
});

it('keeps a member limited to some categories, or to their own posts, from tagging a post they may not edit', function (): void {
    [$editor, $author] = etgCast();
    $limited = User::factory()->create();
    app(Tenancy::class)->enter(1, function () use ($limited): void {
        grantRole($limited, 'editor', ['entries.view', 'entries.create', 'entries.edit.any']);
    });
    app(TeamContext::class)->forget();
    app(Tenancy::class)->enter(1, function () use ($editor, $author, $limited): void {
        $category = etgSection();
        $inside = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'parent_id' => $category->id, 'position' => 0]);
        $inside->translations()->create(['locale' => 'vi', 'name' => 'Sự kiện', 'slug' => 'su-kien']);
        app(TaxonomyPaths::class)->refreshBranch($inside->id);
        MemberCategoryScope::query()->create(['user_id' => $limited->id, 'taxonomy_id' => $inside->id]);
        app(CategoryScopes::class)->forget();

        // outside the limits: the post does not open, and a form opened before it left them adds nothing
        $outside = etgDraft($category, $editor, 'ngoai');
        Livewire::actingAs($limited->fresh())->test(Editor::class, ['ulid' => $outside->ulid])->assertForbidden();
        $mine = etgDraft($inside, $limited, 'trong');
        $form = Livewire::actingAs($limited->fresh())->test(Editor::class, ['ulid' => $mine->ulid])->set('tagDraft', 'CNC');
        $mine->assignCategories([$category->id]);
        $form->call('addTag')->assertForbidden();
        Livewire::actingAs($limited->fresh())->test(Editor::class, ['ulid' => $mine->ulid])->assertForbidden();

        // own posts only: another member's post does not open; a post handed to someone else takes no tag
        $others = etgDraft($category, $editor, 'cua-nguoi-khac');
        Livewire::actingAs($author)->test(Editor::class, ['ulid' => $others->ulid])->assertForbidden();
        $own = etgDraft($category, $author, 'cua-minh');
        $adding = Livewire::actingAs($author)->test(Editor::class, ['ulid' => $own->ulid])->set('tagDraft', 'Máy phay');
        $saving = Livewire::actingAs($author)->test(Editor::class, ['ulid' => $own->ulid])->set('tagDraft', 'CNC')->call('addTag')
            ->assertSet('tags', ['CNC']);
        $own->forceFill(['author_id' => $editor->id])->save();
        $adding->call('addTag')->assertForbidden();
        $saving->call('save')->assertForbidden();
        Livewire::actingAs($author)->test(Editor::class, ['ulid' => $own->ulid])->assertForbidden();

        expect(Taxonomy::query()->where('vocabulary', Taxonomy::TAG)->count())->toBe(0)
            ->and(etgRows($mine->fresh()))->toBe([])
            ->and(etgRows($own->fresh()))->toBe([]);
    });
});

it('shows the card read-only to a member whose saves of a live post wait for an approval workflow', function (): void {
    [$editor] = etgCast();
    app(Tenancy::class)->enter(1, function () use ($editor): void {
        $category = etgSection();
        $workflow = Workflow::query()->create(['key' => 'duyet', 'name' => 'Duyệt', 'applies_to_type' => 'tin-tuc', 'template' => 'editorial', 'is_active' => true]);
        foreach ([['key' => 'draft', 'label' => 'Nháp', 'is_initial' => true], ['key' => 'published', 'label' => 'Đăng', 'is_published' => true]] as $state) {
            $workflow->states()->create($state);
        }
        $post = etgDraft($category, $editor);
        $post->forceFill(['status' => 'published', 'published_at' => now()->subDay()])->save();
        $post->translations()->update(['status' => 'published']);

        // the editor may publish, but the workflow holds every edit of the live post for approval
        Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid])
            ->assertSee(__('entries.ed.tag_held'))->assertSeeHtml('aria-readonly="true"')
            ->set('tags', ['Lén thêm'])->call('save')->assertHasErrors(['tagDraft' => __('entries.ed.tag_held')]);
        expect(Taxonomy::query()->where('vocabulary', Taxonomy::TAG)->count())->toBe(0)
            ->and(etgRows($post->fresh()))->toBe([]);
    });
});

it('takes out control characters and invisible marks before judging a tag name', function (string $typed, ?string $kept): void {
    expect(EntryTags::clean($typed, 'vi'))->toBe($kept);
})->with([
    'a NUL' => ["Máy\0 phay", 'Máy phay'],
    'C0 and DEL' => ["\x01CNC\x7F", 'CNC'],
    'a C1 control' => ["\u{009B}CNC", 'CNC'],
    'a direction override' => ["abc\u{202E}", 'abc'],
    'zero-width marks and a joiner' => ["C\u{200B}N\u{200D}C\u{FEFF}", 'CNC'],
    'only marks and controls' => ["\u{200E}\u{202E}\0\x07", null],
    'spaces squeezed' => ["  Máy \t  phay  ", 'Máy phay'],
    'long only before the marks are out' => [str_repeat('a', EntryTags::NAME_MAX).str_repeat("\u{200B}", 5), str_repeat('a', EntryTags::NAME_MAX)],
    'too long' => [str_repeat('a', EntryTags::NAME_MAX + 1), null],
    'not UTF-8' => ["CNC\xC3", null],
]);

it('stores a typed tag without the marks it was pasted with', function (): void {
    [$editor] = etgCast();
    app(Tenancy::class)->enter(1, function () use ($editor): void {
        etgSection();
        Livewire::actingAs($editor)->test(Editor::class, ['section' => 'tin-tuc'])
            ->set('tagDraft', "CNC\u{202E}\0")->call('addTag')->assertSet('tags', ['CNC'])
            // a list sent past the card: the stored name is the clean one
            ->set('tags', ["Máy\u{200B} phay\0"])
            ->set('title', 'Bài')->set('slug', 'bai')->call('save')->assertHasNoErrors()->assertSet('tags', ['Máy phay']);
        expect(DB::table('taxonomy_translations')->whereIn('taxonomy_id', Taxonomy::query()->where('vocabulary', Taxonomy::TAG)->select('id'))->pluck('name')->all())
            ->toBe(['Máy phay']);
    });
});
