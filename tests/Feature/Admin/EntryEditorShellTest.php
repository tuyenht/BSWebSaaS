<?php

use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
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
                'aria-current="page">Thêm tin tức</span>',
            ])
            ->assertSee('Tin tức mới — chưa lưu')
            ->assertSee('Chuyên mục')
            ->assertSee('Khối nội dung')
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
            ->assertSeeHtml('data-unsaved-count="1"')
            ->call('addBlock', 'hero')
            ->assertSet('blocksChanged', 1)
            ->assertSeeHtml('data-unsaved-count="2"')
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
            ->assertDontSee('Gửi duyệt')
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
