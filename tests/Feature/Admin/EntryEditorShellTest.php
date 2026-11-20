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
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteLocale;
use Livewire\Livewire;

/*
 * The editor's frame (design 07): one screen for posts and pages. The crumb names the list the
 * entry belongs to, the bar carries the save state and the buttons the member may use, the leave
 * dialog counts what is unsaved (the block list too), and after the first save of a new entry the
 * address becomes its edit address, so a reload never starts a second one.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
});

function seedShellPostSection(string $name = 'Tin tức', string $key = 'posts'): int
{
    $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
    $category->translations()->create(['locale' => 'vi', 'name' => $name, 'slug' => 'tin-tuc']);
    ContentSection::query()->create([
        'key' => $key, 'label' => $name, 'entry_type' => 'post', 'taxonomy_id' => $category->id,
        'permission_prefix' => 'entries', 'sort_order' => 1,
    ]);

    return $category->id;
}

/** A page of the current site published at /$slug through the publish action (so it has its route). */
function shellPublishedPage(User $by, string $title, string $slug): Entry
{
    $entry = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $by->id, 'meta' => []]);
    $entry->translations()->create(['locale' => 'vi', 'title' => $title, 'slug' => $slug, 'status' => 'draft', 'body' => ['blocks' => []]]);
    app(PublishEntry::class)->handle($entry->fresh() ?? $entry, 'vi', $by->fresh() ?? $by);

    return $entry->fresh() ?? $entry;
}

/** A post category of the current site written through the category action (so it has its tree path). */
function shellCategory(string $name, ?Taxonomy $parent = null): Taxonomy
{
    return app(CreateTaxonomy::class)->handle('post_cat', $parent, new TaxonomyData(names: ['vi' => $name]));
}

it('turns the address into the edit address after the first save, so reopening edits the same post', function (): void {
    $tenancy = app(Tenancy::class);
    $editor = User::factory()->create();

    $tenancy->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.create', 'entries.edit.any']);
        seedShellPostSection();

        $c = Livewire::actingAs($editor->fresh())
            ->test(Editor::class, ['section' => 'posts'])
            ->set('title', 'Ưu đãi tháng mười một')
            ->call('save')
            ->assertHasNoErrors();

        $entry = Entry::query()->where('type', 'post')->sole();
        $c->assertSet('ulid', $entry->ulid)
            ->assertJs('history.replaceState(history.state, "", '.json_encode(route('admin.entries.edit', ['ulid' => $entry->ulid])).')');

        // A second save is an edit of the same post: no new row, the address left alone.
        $c->set('title', 'Ưu đãi tháng mười hai')->call('save')->assertHasNoErrors()->assertNoJs();
        expect(Entry::query()->where('type', 'post')->count())->toBe(1);
    });
});

it('keeps a non-default language in the edit address it moves to', function (): void {
    $tenancy = app(Tenancy::class);
    $editor = User::factory()->create();

    $tenancy->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.create', 'entries.edit.any']);
        SiteLocale::query()->firstOrCreate(['locale' => 'vi'], ['is_default' => true, 'enabled' => true]);
        SiteLocale::query()->firstOrCreate(['locale' => 'en'], ['is_default' => false, 'enabled' => true]);

        $c = Livewire::actingAs($editor->fresh())
            ->test(Editor::class, ['locale' => 'en'])
            ->set('title', 'About us')
            ->call('save')
            ->assertHasNoErrors();

        $entry = Entry::query()->where('type', 'page')->sole();
        $c->assertJs('history.replaceState(history.state, "", '.json_encode(route('admin.entries.edit', ['ulid' => $entry->ulid, 'locale' => 'en'])).')');
    });
});

it('draws a post with its section crumb and the Chuyên mục card, a page with its blocks under Trình dựng', function (): void {
    $tenancy = app(Tenancy::class);
    $editor = User::factory()->create();

    $tenancy->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.create', 'entries.edit.any', 'entries.publish']);
        seedShellPostSection();

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class, ['section' => 'posts'])
            ->assertSee('Thêm tin tức')
            ->assertSeeHtmlInOrder([
                'href="'.route('admin.entries.index', ['section' => 'posts']).'"', '>Tin tức</a>',
                'aria-current="page">Thêm mới</span>', 'id="editor-title" tabindex="-1"', 'Thêm tin tức</h1>',
            ])
            ->assertSee('Tin tức mới — chưa lưu')
            ->assertSee('Chuyên mục')
            // a post's body is written in «Soạn thảo»; it has no builder tab
            ->assertSee('Soạn thảo')
            ->assertDontSee('Trình dựng')
            ->assertSee('Lưu nháp')
            ->assertSee('Đăng');

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class)
            ->assertSee('Thêm trang')
            ->assertSee('Tiêu đề trang')
            ->assertSee('Trình dựng')
            ->assertSee('Bố cục trang · 0 khối')
            ->assertSee('Bản này chưa có nội dung.')
            ->assertDontSee('Chuyên mục')
            ->call('addBlock', 'hero')
            ->assertSee('Bố cục trang · 1 khối')
            ->assertDontSee('Bản này chưa có nội dung.');
    });
});

it('opens an existing post under the section its category belongs to', function (): void {
    $tenancy = app(Tenancy::class);
    $editor = User::factory()->create();

    $tenancy->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.create', 'entries.edit.any']);
        // A first post section the category does not belong to: only the walk up the category
        // tree can land on "Dự án".
        $other = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
        $other->translations()->create(['locale' => 'vi', 'name' => 'Tin tức', 'slug' => 'tin-tuc-khac']);
        ContentSection::query()->create([
            'key' => 'posts', 'label' => 'Tin tức', 'entry_type' => 'post', 'taxonomy_id' => $other->id,
            'permission_prefix' => 'entries', 'sort_order' => 0,
        ]);
        $root = seedShellPostSection('Dự án', 'du-an');
        $child = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0, 'parent_id' => $root]);
        $child->translations()->create(['locale' => 'vi', 'name' => 'Nhà xưởng', 'slug' => 'nha-xuong']);

        $c = Livewire::actingAs($editor->fresh())
            ->test(Editor::class, ['section' => 'du-an'])
            ->set('title', 'Xưởng Bình Dương')
            ->set('categoryId', $child->id)
            ->call('save')
            ->assertHasNoErrors();

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class, ['ulid' => $c->get('ulid')])
            ->assertSet('sectionKey', 'du-an')
            ->assertSet('listLabel', 'Dự án')
            ->assertSee('Sửa dự án');
    });
});

it('counts unsaved changes for the leave dialog, the block list included, and forgets them on save or discard', function (): void {
    $tenancy = app(Tenancy::class);
    $editor = User::factory()->create();

    $tenancy->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.create', 'entries.edit.any']);

        $c = Livewire::actingAs($editor->fresh())
            ->test(Editor::class)
            ->assertSeeHtml('data-unsaved-count="0"')
            ->set('title', 'Giới thiệu')
            // the link follows the title of a new page: two fields changed
            ->assertSet('slug', 'gioi-thieu')
            ->assertSeeHtml('data-unsaved-count="2"')
            ->call('addBlock', 'hero')
            ->assertSet('blocksChanged', 1)
            ->assertSeeHtml('data-unsaved-count="3"')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('blocksChanged', 0)
            ->assertSeeHtml('data-unsaved-count="0"');

        $c->set('title', 'Giới thiệu công ty')
            ->call('moveDown', 0)
            ->call('addBlock', 'cta')
            ->assertSeeHtml('data-unsaved-count="2"')
            ->call('resetChanges')
            ->assertSet('title', 'Giới thiệu')
            ->assertCount('blocks', 1)
            ->assertSeeHtml('data-unsaved-count="0"');
    });
});

it('says a field to fix in the save state when a save is refused', function (): void {
    $tenancy = app(Tenancy::class);
    $editor = User::factory()->create();

    $tenancy->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.create', 'entries.edit.any']);

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class)
            ->set('title', '')
            ->call('save')
            ->assertHasErrors('title')
            ->assertSet('saveFailed', true)
            ->assertSee('Chưa lưu — còn ô cần sửa')
            ->set('title', 'Liên hệ')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('saveFailed', false)
            ->assertDontSee('Chưa lưu — còn ô cần sửa');
    });
});

it('offers Gửi duyệt to a member who may not publish, Cập nhật and the live band on published content', function (): void {
    $tenancy = app(Tenancy::class);
    $author = User::factory()->create();
    $editor = User::factory()->create();

    $tenancy->enter(1, function () use ($author, $editor): void {
        grantRole($author, 'author', ['entries.view', 'entries.create', 'entries.edit.own', 'entries.submit']);
        grantRole($editor, 'editor', ['entries.view', 'entries.create', 'entries.edit.any', 'entries.publish']);

        // Once it waits for review, sending it again would move nothing: only the save stays.
        Livewire::actingAs($author->fresh())
            ->test(Editor::class)
            ->assertSee('Gửi duyệt')
            ->assertDontSee('Cập nhật')
            ->set('title', 'Tuyển dụng')
            ->set('slug', 'tuyen-dung')
            ->call('addBlock', 'hero')
            ->call('publish')
            ->assertHasNoErrors()
            ->assertSet('status', 'pending')
            // «Gửi duyệt» stays in the status list (the board's «Khi lưu, trang sẽ là»), never as the main button
            ->assertViewHas('actions', fn (array $a): bool => ($a['primary'] ?? '') !== 'Gửi duyệt')
            ->assertSee('Chờ duyệt');

        $c = Livewire::actingAs($editor->fresh())
            ->test(Editor::class)
            ->set('title', 'Về chúng tôi')
            ->set('slug', 've-chung-toi')
            ->call('addBlock', 'hero')
            ->call('publish')
            ->assertHasNoErrors()
            ->assertSet('status', 'published')
            ->assertDispatched('bs-toast');

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class, ['ulid' => $c->get('ulid')])
            ->assertSee('Công khai')
            ->assertSee('Cập nhật')
            ->assertDontSee('Lưu nháp')
            ->assertSee('Xem trên website')
            ->assertSee('/ve-chung-toi');
    });
});

it('keeps the live band on the address visitors reach when a save changes the slug without publishing it', function (): void {
    $tenancy = app(Tenancy::class);
    $publisher = User::factory()->create();
    $writer = User::factory()->create();

    $tenancy->enter(1, function () use ($publisher, $writer): void {
        grantRole($publisher, 'editor', ['entries.view', 'entries.create', 'entries.edit.any', 'entries.publish']);
        grantRole($writer, 'writer', ['entries.view', 'entries.edit.any']);
        $page = shellPublishedPage($publisher, 'Về chúng tôi', 've-chung-toi');

        $c = Livewire::actingAs($writer->fresh())
            ->test(Editor::class, ['ulid' => $page->ulid])
            ->assertSee('Xem trên website');
        $live = $c->get('liveAddress');
        expect($live)->toEndWith('/ve-chung-toi');

        // «Lưu» writes the new slug, but only a publish moves the route: the band keeps the live one.
        $c->set('slug', 'gioi-thieu-moi')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('liveAddress', $live)
            ->assertSeeHtml('href="//'.$live.'"');
        expect(SiteRoute::query()->where('entry_id', $page->id)->value('path'))->toBe('/ve-chung-toi');
    });
});

it('keeps the live band on the old address when publishing a new slug runs into another page', function (): void {
    $tenancy = app(Tenancy::class);
    $editor = User::factory()->create();

    $tenancy->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.create', 'entries.edit.any', 'entries.publish']);
        shellPublishedPage($editor, 'Liên hệ', 'lien-he');
        $page = shellPublishedPage($editor, 'Về chúng tôi', 've-chung-toi');

        $c = Livewire::actingAs($editor->fresh())->test(Editor::class, ['ulid' => $page->ulid]);
        $live = $c->get('liveAddress');
        expect($live)->toEndWith('/ve-chung-toi');

        $c->set('slug', 'lien-he')
            ->call('publish')
            ->assertHasErrors('slug')
            ->assertSet('liveAddress', $live)
            ->assertSeeHtml('href="//'.$live.'"');
    });
});

it('shows no live band for a language of a live page that was never published, until it is', function (): void {
    $tenancy = app(Tenancy::class);
    $editor = User::factory()->create();

    $tenancy->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.create', 'entries.edit.any', 'entries.publish']);
        SiteLocale::query()->firstOrCreate(['locale' => 'vi'], ['is_default' => true, 'enabled' => true]);
        SiteLocale::query()->firstOrCreate(['locale' => 'en'], ['is_default' => false, 'enabled' => true]);
        $page = shellPublishedPage($editor, 'Về chúng tôi', 've-chung-toi');

        $c = Livewire::actingAs($editor->fresh())
            ->test(Editor::class, ['ulid' => $page->ulid, 'locale' => 'en'])
            ->assertSet('liveAddress', null)
            ->assertDontSee('Xem trên website');

        // Saved on a live page, the English version is stored as published but has no route yet.
        $c->set('title', 'About us')
            ->set('slug', 'about-us')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('liveAddress', null)
            ->assertDontSee('Xem trên website');

        $c->call('publish')
            ->assertHasNoErrors()
            ->assertSee('Xem trên website');
        expect($c->get('liveAddress'))->toEndWith('/en/about-us');
    });
});

it('offers Đăng on a new post only when its pre-filled category lies inside the member\'s limits', function (): void {
    $tenancy = app(Tenancy::class);
    $editor = User::factory()->create();

    $tenancy->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.create', 'entries.edit.any', 'entries.publish']);
        $inside = shellCategory('Dự án');
        $outside = shellCategory('Tin tức');
        foreach (['du-an' => $inside, 'posts' => $outside] as $key => $category) {
            ContentSection::query()->create([
                'key' => $key, 'label' => $category->translationFor('vi')?->name, 'entry_type' => 'post', 'taxonomy_id' => $category->id,
                'permission_prefix' => 'entries', 'sort_order' => 1,
            ]);
        }
        MemberCategoryScope::query()->create(['user_id' => $editor->id, 'taxonomy_id' => $inside->id]);
        app(CategoryScopes::class)->forget();

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class, ['section' => 'posts'])
            ->assertSet('categoryId', $outside->id)
            ->assertSet('mayPublish', false)
            ->assertDontSeeHtml('wire:click="publish"');

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class, ['section' => 'du-an'])
            ->assertSet('categoryId', $inside->id)
            ->assertSet('mayPublish', true)
            ->assertSeeHtml('wire:click="publish"');
    });
});

it('forgets an open picker, a typed note and a picked member on discard', function (): void {
    $tenancy = app(Tenancy::class);
    $editor = User::factory()->create();

    $tenancy->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.create', 'entries.edit.any', 'media.view']);

        Livewire::actingAs($editor->fresh())
            ->test(Editor::class)
            ->set('title', 'Giới thiệu')
            ->call('addBlock', 'hero')
            ->call('save')
            ->assertHasNoErrors()
            ->call('openCoverPicker')
            ->call('openBlockImagePicker', '0.props.image_url')
            ->set('transitionNote', 'Cần sửa ảnh')
            ->set('assignTo', $editor->id)
            ->call('resetChanges')
            ->assertSet('showImagePicker', false)
            ->assertSet('blockImagePickerPath', '')
            ->assertSet('transitionNote', '')
            ->assertSet('assignTo', null);
    });
});

describe('query budget', function (): void {
    it('opens a post on a flat query budget, however deep its category sits', function (): void {
        $editor = User::factory()->create();
        $ulids = [];

        app(Tenancy::class)->enter(1, function () use ($editor, &$ulids): void {
            grantRole($editor, 'editor', ['entries.view', 'entries.create', 'entries.edit.any', 'entries.publish']);
            $root = shellCategory('Dự án');
            ContentSection::query()->create([
                'key' => 'du-an', 'label' => 'Dự án', 'entry_type' => 'post', 'taxonomy_id' => $root->id,
                'permission_prefix' => 'entries', 'sort_order' => 1,
            ]);
            $middle = shellCategory('Nhà xưởng', $root);
            $deepest = shellCategory('Bình Dương', $middle);

            foreach (['middle' => $middle, 'deep' => $deepest] as $at => $category) {
                $post = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'author_id' => $editor->id, 'meta' => []]);
                $post->translations()->create(['locale' => 'vi', 'title' => 'Xưởng '.$at, 'slug' => 'xuong-'.$at, 'status' => 'draft', 'body' => ['blocks' => []]]);
                $post->assignCategories([$category->id]);
                $ulids[$at] = $post->ulid;
            }
        });

        $user = $editor->fresh();
        $open = function (string $ulid) use ($user): int {
            app()->forgetScopedInstances();

            return countQueries(fn () => $this->actingAs($user)->get("http://a.test/admin/content/{$ulid}/edit")->assertOk()->assertSee('Sửa dự án'));
        };
        $open($ulids['middle']);   // warm the member's caches (permissions, preferences) first
        $middle = $open($ulids['middle']);
        $deep = $open($ulids['deep']);

        // The section is found from the category's tree path: no query per level above it. 24 when
        // written: the member, the site and its settings, the post with its category, the live route.
        expect($deep)->toBe($middle)
            ->and($middle)->toBeLessThanOrEqual(24);
    });
});
