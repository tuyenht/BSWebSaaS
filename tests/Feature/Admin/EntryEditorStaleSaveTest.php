<?php

use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Admin\Livewire\Entries\Index;
use App\Modules\Cms\Actions\ApplyEntryWorkflowTransition;
use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Actions\PublishScheduledEntry;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\EntryViewCounter;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteLocale;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Workflow\Actions\InstantiateWorkflow;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * Two members editing the same entry: a save made over a version someone else saved after this
 * form was loaded stops and asks («… vừa được … lưu lúc …»: Xem khác biệt · Lấy bản mới · Giữ bản
 * của tôi) instead of silently replacing it. The stored values decide — this language's title,
 * slug, body, excerpt and Google fields, the shared cover, a post's main category — never a
 * timestamp: a status change, a category moved or renamed, or a view count is no reason to ask.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/** Two members who may edit and publish any entry of site 1. Call outside any site context. */
function staleSaveMembers(): array
{
    $a = User::factory()->create(['name' => 'Minh Trí']);
    $b = User::factory()->create(['name' => 'Thu Hà']);
    app(Tenancy::class)->enter(1, function () use ($a, $b): void {
        foreach ([$a, $b] as $member) {
            grantRole($member, 'editor', ['entries.view', 'entries.create', 'entries.edit.any', 'entries.publish']);
        }
    });

    return [$a->fresh(), $b->fresh()];
}

/** A page with a block body (the builder's layout), in the given status. */
function staleSavePage(string $status = 'draft'): Entry
{
    $entry = Entry::query()->create([
        'type' => 'page', 'status' => $status, 'meta' => [],
        'published_at' => $status === 'scheduled' ? now()->subMinute() : null,
    ]);
    $entry->translations()->create([
        'locale' => 'vi', 'title' => 'Giới thiệu', 'slug' => 'gioi-thieu', 'status' => 'draft', 'excerpt' => 'Về chúng tôi',
        'body' => ['blocks' => [['type' => 'hero', 'props' => ['heading' => 'Xin chào', 'subheading' => 'Công ty An Vinh']]]],
    ]);

    return $entry->fresh();
}

/** A `posts` section over a post_cat category and a draft post filed under it (a written body). */
function staleSavePost(): array
{
    $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
    $category->translations()->create(['locale' => 'vi', 'name' => 'Tin tức', 'slug' => 'tin-tuc']);
    ContentSection::query()->create([
        'key' => 'posts', 'label' => 'Tin tức', 'entry_type' => 'post', 'taxonomy_id' => $category->id,
        'permission_prefix' => 'entries', 'sort_order' => 1,
    ]);
    SiteSetting::query()->create(['group_name' => 'permalinks', 'key' => 'posts', 'value' => ['pattern' => 'category']]);

    $entry = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'meta' => []]);
    $entry->translations()->create([
        'locale' => 'vi', 'title' => 'Máy tiện mới', 'slug' => 'may-tien-moi', 'status' => 'draft',
        'body' => ['blocks' => [['type' => 'richtext_html', 'props' => ['html' => '<p>Bản đầu tiên.</p>']]]],
    ]);
    $entry->assignCategories([$category->id]);

    return [$entry->fresh(), $category];
}

it('stops a save over a version another member saved, and offers the three choices', function (): void {
    [$a, $b] = staleSaveMembers();

    app(Tenancy::class)->enter(1, function () use ($a, $b): void {
        $page = staleSavePage();
        $mine = Livewire::actingAs($b)->test(Editor::class, ['ulid' => $page->ulid]);
        Livewire::actingAs($a)->test(Editor::class, ['ulid' => $page->ulid])
            ->set('title', 'Giới thiệu công ty')->call('save')->assertHasNoErrors()->assertSet('stale', null);

        $this->actingAs($b);
        $mine->set('title', 'Về An Vinh')->set('excerpt', 'Chúng tôi là ai')->call('save')
            ->assertHasNoErrors()
            ->assertSee(__('entries.ed.stale_diff_show'))
            ->assertSee(__('entries.ed.stale_take'))
            ->assertSee(__('entries.ed.stale_keep'))
            ->assertSeeHtml('data-entry-stale')
            ->assertNotDispatched('bs-toast');

        // Nothing was written over the other member's save.
        $stored = $page->fresh()->translationFor('vi');
        expect($stored->title)->toBe('Giới thiệu công ty')
            ->and($stored->excerpt)->toBe('Về chúng tôi');

        // «Xem khác biệt»: the fields that differ, this form's value beside the one just saved. The
        // excerpt was changed only here, so it is not one of them.
        $stale = $mine->get('stale');
        expect($stale['action'])->toBe('save')
            ->and($stale['name'])->toBeNull()
            ->and($stale['title'])->toMatch('/\d{2}:\d{2}$/')
            ->and($stale['line'])->toBe(__('entries.ed.stale_line_anon'))
            ->and($stale['rows'])->toBe([[
                'label' => __('entries.ed.title_page'), 'mine' => 'Về An Vinh', 'theirs' => 'Giới thiệu công ty',
            ]]);
        $mine->assertSee(__('entries.ed.stale_mine', ['value' => 'Về An Vinh']))
            ->assertSee(__('entries.ed.stale_theirs', ['value' => 'Giới thiệu công ty']));
    });
});

it('saves over that version on «Giữ bản của tôi»', function (): void {
    [$a, $b] = staleSaveMembers();

    app(Tenancy::class)->enter(1, function () use ($a, $b): void {
        $page = staleSavePage();
        $mine = Livewire::actingAs($b)->test(Editor::class, ['ulid' => $page->ulid]);
        Livewire::actingAs($a)->test(Editor::class, ['ulid' => $page->ulid])->set('title', 'Giới thiệu công ty')->call('save');

        $this->actingAs($b);
        $mine->set('title', 'Về An Vinh')->call('save');
        expect($mine->get('stale'))->not->toBeNull();

        $mine->call('keepMine')->assertHasNoErrors()->assertSet('stale', null)->assertSet('overwrite', null)
            ->assertDispatched('bs-toast')
            ->assertDontSeeHtml('data-entry-stale');
        expect($page->fresh()->translationFor('vi')->title)->toBe('Về An Vinh');

        // Saved: the next save of this form asks nothing.
        $mine->set('title', 'Về An Vinh hôm nay')->call('save')->assertSet('stale', null);
        expect($page->fresh()->translationFor('vi')->title)->toBe('Về An Vinh hôm nay');
    });
});

it('asks again when a third save lands after the question was shown', function (): void {
    [$a, $b] = staleSaveMembers();

    app(Tenancy::class)->enter(1, function () use ($a, $b): void {
        $page = staleSavePage();
        $mine = Livewire::actingAs($b)->test(Editor::class, ['ulid' => $page->ulid]);
        $theirs = Livewire::actingAs($a)->test(Editor::class, ['ulid' => $page->ulid]);
        $theirs->set('title', 'Lần một')->call('save');

        $this->actingAs($b);
        $mine->set('title', 'Của tôi')->call('save');
        $shown = $mine->get('stale')['print'];

        // The other member saves once more while the question is open.
        $this->actingAs($a);
        $theirs->set('title', 'Lần hai')->call('save')->assertSet('stale', null);

        // «Giữ bản của tôi» covers only the version the question showed.
        $this->actingAs($b);
        $mine->call('keepMine');
        expect($page->fresh()->translationFor('vi')->title)->toBe('Lần hai')
            ->and($mine->get('stale'))->not->toBeNull()
            ->and($mine->get('stale')['print'])->not->toBe($shown)
            ->and($mine->get('stale')['rows'][0]['theirs'])->toBe('Lần hai');
    });
});

it('takes the newer version on «Lấy bản mới», and the next save asks nothing', function (): void {
    [$a, $b] = staleSaveMembers();

    app(Tenancy::class)->enter(1, function () use ($a, $b): void {
        [$post] = staleSavePost();
        $mine = Livewire::actingAs($b)->test(Editor::class, ['ulid' => $post->ulid]);
        Livewire::actingAs($a)->test(Editor::class, ['ulid' => $post->ulid])
            ->set('bodyHtml', '<p>Bản của Minh Trí.</p>')->call('save')->assertHasNoErrors();

        $this->actingAs($b);
        $mine->set('bodyHtml', '<p>Bản của Thu Hà.</p>')->call('save')
            ->assertSeeHtml('data-entry-stale');
        expect($mine->get('stale')['rows'])->toBe([[
            'label' => __('entries.ed.body_label'), 'mine' => 'Bản của Thu Hà.', 'theirs' => 'Bản của Minh Trí.',
        ]]);

        $mine->call('takeNewer')
            ->assertSet('stale', null)
            ->assertSet('bodyHtml', '<p>Bản của Minh Trí.</p>')
            ->assertDispatched('admin-editor-set-html', target: 'bodyHtml', html: '<p>Bản của Minh Trí.</p>')
            ->assertDispatched('bs-toast', text: __('entries.ed.stale_taken_anon'))
            ->assertDontSeeHtml('data-entry-stale');

        $mine->set('title', 'Máy tiện mới 2026')->call('save')->assertSet('stale', null);
        $stored = $post->fresh()->translationFor('vi');
        expect($stored->title)->toBe('Máy tiện mới 2026')
            ->and($stored->body['blocks'][0]['props']['html'])->toBe('<p>Bản của Minh Trí.</p>');
    });
});

it('puts the text kept on this device back with «Khôi phục», unsaved', function (): void {
    [, $b] = staleSaveMembers();

    app(Tenancy::class)->enter(1, function () use ($b): void {
        [$post] = staleSavePost();

        Livewire::actingAs($b)->test(Editor::class, ['ulid' => $post->ulid])
            ->assertSee(__('entries.ed.kept_restore'))
            ->call('restoreKept', [
                'title' => 'Bản giữ tạm', 'excerpt' => 'Mô tả giữ tạm', 'bodyHtml' => '<p>Chữ giữ tạm.</p>',
                'slug' => ['not', 'a', 'string'], 'categoryId' => 'x', 'status' => 'published',
            ])
            ->assertSet('title', 'Bản giữ tạm')
            ->assertSet('excerpt', 'Mô tả giữ tạm')
            ->assertSet('bodyHtml', '<p>Chữ giữ tạm.</p>')
            ->assertSet('slug', 'may-tien-moi')
            ->assertSet('categoryId', null)
            ->assertSet('status', 'draft')
            ->assertDispatched('admin-editor-set-html', target: 'bodyHtml', html: '<p>Chữ giữ tạm.</p>');

        expect($post->fresh()->translationFor('vi')->title)->toBe('Máy tiện mới');
    });
});

it('lets only a member who may edit the entry restore kept text or answer the question', function (): void {
    [$a] = staleSaveMembers();

    app(Tenancy::class)->enter(1, function () use ($a): void {
        $page = staleSavePage();
        $open = fn () => Livewire::actingAs($a)->test(Editor::class, ['ulid' => $page->ulid]);
        [$one, $two, $three] = [$open(), $open(), $open()];

        // The right to edit is taken away while the editor is open.
        Role::findByName('editor')->syncPermissions(['entries.view']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $one->call('restoreKept', ['title' => 'Không được'])->assertForbidden();
        $two->call('keepMine')->assertForbidden();
        $three->call('closeStale')->assertForbidden();
        expect($page->fresh()->translationFor('vi')->title)->toBe('Giới thiệu');
    });
});

it('never takes the question or the overwrite choice from the browser', function (): void {
    [, $b] = staleSaveMembers();

    app(Tenancy::class)->enter(1, function () use ($b): void {
        $page = staleSavePage();
        $editor = Livewire::actingAs($b)->test(Editor::class, ['ulid' => $page->ulid]);

        expect(fn () => $editor->set('overwrite', ['title' => md5('x')]))->toThrow(CannotUpdateLockedPropertyException::class)
            ->and(fn () => $editor->set('storedPrint', []))->toThrow(CannotUpdateLockedPropertyException::class)
            ->and(fn () => $editor->set('stale', ['action' => 'publish']))->toThrow(CannotUpdateLockedPropertyException::class);
    });
});

it('stops «Đăng» too, and «Giữ bản của tôi» then publishes', function (): void {
    [$a, $b] = staleSaveMembers();

    app(Tenancy::class)->enter(1, function () use ($a, $b): void {
        $page = staleSavePage();
        $mine = Livewire::actingAs($b)->test(Editor::class, ['ulid' => $page->ulid]);
        Livewire::actingAs($a)->test(Editor::class, ['ulid' => $page->ulid])->set('slug', 'gioi-thieu-cong-ty')->call('save');

        $this->actingAs($b);
        $mine->set('title', 'Về An Vinh')->call('publish');
        expect($page->fresh()->status)->toBe('draft')
            ->and($mine->get('stale')['action'])->toBe('publish')
            ->and(array_column($mine->get('stale')['rows'], 'label'))->toBe([__('entries.ed.slug')]);

        $mine->call('keepMine')->assertHasNoErrors();
        $fresh = $page->fresh();
        expect($fresh->status)->toBe('published')
            ->and($fresh->translationFor('vi')->title)->toBe('Về An Vinh')
            ->and($fresh->translationFor('vi')->slug)->toBe('gioi-thieu');
    });
});

it('stops a workflow move too, and «Giữ bản của tôi» then makes the move', function (): void {
    [$a, $b] = staleSaveMembers();

    app(Tenancy::class)->enter(1, function () use ($a, $b): void {
        grantRole($b, 'editor', ['entries.view', 'entries.create', 'entries.edit.any', 'entries.publish', 'entries.submit']);
        [$post] = staleSavePost();
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);

        $mine = Livewire::actingAs($b->fresh())->test(Editor::class, ['ulid' => $post->ulid]);
        Livewire::actingAs($a)->test(Editor::class, ['ulid' => $post->ulid])->set('title', 'Máy tiện CNC')->call('save');

        $this->actingAs($b->fresh());
        $mine->set('excerpt', 'Ra mắt tháng 11')->call('applyTransition', 'submit');
        expect($post->fresh()->status)->toBe('draft')
            ->and($mine->get('stale')['action'])->toBe('transition')
            ->and($mine->get('stale')['transition'])->toBe('submit');

        $mine->call('keepMine')->assertHasNoErrors();
        expect($post->fresh()->status)->toBe('pending')
            ->and($post->fresh()->translationFor('vi')->excerpt)->toBe('Ra mắt tháng 11');
    });
});

it('names the member who saved live content, and when', function (): void {
    [$a, $b] = staleSaveMembers();

    app(Tenancy::class)->enter(1, function () use ($a, $b): void {
        $page = staleSavePage();
        app(PublishEntry::class)->handle($page, 'vi', $a);
        $mine = Livewire::actingAs($b)->test(Editor::class, ['ulid' => $page->ulid]);
        $this->travel(2)->seconds();
        Livewire::actingAs($a)->test(Editor::class, ['ulid' => $page->ulid])->set('title', 'Giới thiệu công ty')->call('publish');

        $this->actingAs($b);
        $mine->set('title', 'Về An Vinh')->call('publish');
        $stale = $mine->get('stale');
        expect($stale['name'])->toBe('Minh Trí')
            ->and($stale['title'])->toMatch('/\d{2}:\d{2}$/')
            ->and($stale['title'])->toBe(__('entries.ed.stale_title', ['what' => __('entries.ed.stale_what_page'), 'name' => 'Minh Trí', 'time' => substr($stale['title'], -5)]))
            ->and($stale['line'])->toBe(__('entries.ed.stale_line', ['name' => 'Minh Trí']));
    });
});

it('asks when someone else changed the cover or re-filed the post: both are fields this editor writes', function (): void {
    [, $b] = staleSaveMembers();

    app(Tenancy::class)->enter(1, function () use ($b): void {
        [$post, $category] = staleSavePost();
        $other = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 1]);
        $other->translations()->create(['locale' => 'vi', 'name' => 'Sự kiện', 'slug' => 'su-kien']);

        $mine = Livewire::actingAs($b)->test(Editor::class, ['ulid' => $post->ulid]);
        $post->update(['meta' => ['image_url' => 'may-tien-0123456789ab']]);
        $post->assignCategories([$other->id]);

        $mine->set('title', 'Máy tiện mới 2026')->call('save');
        $rows = collect($mine->get('stale')['rows'])->keyBy('label');
        expect($rows->keys()->all())->toBe([__('entries.ed.card_cover'), __('entries.ed.card_category')])
            ->and($rows[__('entries.ed.card_cover')])->toMatchArray(['mine' => __('entries.ed.stale_empty'), 'theirs' => 'may-tien-0123456789ab'])
            ->and($rows[__('entries.ed.card_category')])->toMatchArray(['mine' => 'Tin tức', 'theirs' => 'Sự kiện']);
        expect($post->fresh()->taxonomies()->wherePivot('position', 0)->value('taxonomies.id'))->toBe($other->id);
    });
});

// Each runs outside any site context, as its real caller does (a queued job or the scheduler).
dataset('writes that change nothing the editor writes', [
    'publish by another member' => [function (string $ulid, User $other): void {
        app(Tenancy::class)->enter(1, function () use ($ulid, $other): void {
            app(PublishEntry::class)->handle(Entry::query()->where('ulid', $ulid)->sole(), 'vi', $other);
        });
    }],
    'scheduled publish' => [function (string $ulid): void {
        app(Tenancy::class)->enter(1, function () use ($ulid): void {
            $entry = Entry::query()->where('ulid', $ulid)->sole();
            $entry->update(['status' => 'scheduled', 'published_at' => now()->subMinute()]);
            expect(app(PublishScheduledEntry::class)->handle($entry))->toBeTrue();
        });
    }],
    'content mark from a category move or rename, or a cover elsewhere' => [function (string $ulid): void {
        app(Tenancy::class)->enter(1, function () use ($ulid): void {
            Entry::markContentChanged([Entry::query()->where('ulid', $ulid)->value('id')]);
        });
    }],
    'view count flush' => [function (string $ulid): void {
        $id = app(Tenancy::class)->enter(1, fn (): int => (int) Entry::query()->where('ulid', $ulid)->value('id'));
        app(EntryViewCounter::class)->record(1, $id);
        test()->artisan('entries:flush-views')->assertSuccessful();
        app(Tenancy::class)->enter(1, function () use ($ulid): void {
            expect(Entry::query()->where('ulid', $ulid)->value('view_count'))->toBe(1);
        });
    }],
]);

it('raises no alarm after a write that changes nothing the editor writes', function (Closure $write): void {
    [$a, $b] = staleSaveMembers();

    [$ulid, $mine] = app(Tenancy::class)->enter(1, function () use ($b): array {
        $page = staleSavePage();

        return [$page->ulid, Livewire::actingAs($b)->test(Editor::class, ['ulid' => $page->ulid])];
    });
    $this->travel(2)->seconds();

    $write($ulid, $a);

    app(Tenancy::class)->enter(1, function () use ($b, $mine, $ulid): void {
        $this->actingAs($b);
        $mine->set('title', 'Về An Vinh')->call('save')->assertHasNoErrors()->assertSet('stale', null);
        expect(Entry::query()->where('ulid', $ulid)->sole()->translationFor('vi')->title)->toBe('Về An Vinh');
    });
})->with('writes that change nothing the editor writes');

it('raises no alarm after a workflow move by another member', function (): void {
    [$a, $b] = staleSaveMembers();

    app(Tenancy::class)->enter(1, function () use ($a, $b): void {
        grantRole($a, 'editor', ['entries.view', 'entries.create', 'entries.edit.any', 'entries.publish', 'entries.submit']);
        [$post] = staleSavePost();
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);

        $mine = Livewire::actingAs($b)->test(Editor::class, ['ulid' => $post->ulid]);
        $before = $post->fresh()->translationFor('vi')->updated_at;
        $this->travel(2)->seconds();
        app(ApplyEntryWorkflowTransition::class)->handle($post->fresh(), 'vi', $a->fresh(), 'submit');
        $after = $post->fresh()->translationFor('vi');
        // The move did bump the translation's updated_at: a timestamp would have asked.
        expect($after->status)->toBe('pending')
            ->and($after->updated_at->gt($before))->toBeTrue();

        $this->actingAs($b);
        $mine->set('excerpt', 'Ra mắt tháng 11')->call('save')->assertHasNoErrors()->assertSet('stale', null);
        expect($post->fresh()->translationFor('vi')->excerpt)->toBe('Ra mắt tháng 11');
    });
});

it('never asks a member about their own saves, a block body and a written one alike', function (): void {
    [, $b] = staleSaveMembers();

    app(Tenancy::class)->enter(1, function () use ($b): void {
        $page = staleSavePage();
        [$post] = staleSavePost();

        $layout = Livewire::actingAs($b)->test(Editor::class, ['ulid' => $page->ulid]);
        $layout->set('blocks.0.props.heading', 'Xin chào quý khách')->call('save')->assertSet('stale', null);
        $layout->set('title', 'Giới thiệu chung')->call('save')->assertSet('stale', null);
        $layout->call('addBlock', 'hero')->call('save')->assertSet('stale', null);

        $written = Livewire::actingAs($b)->test(Editor::class, ['ulid' => $post->ulid]);
        $written->set('bodyHtml', '<p>Một <strong>đoạn</strong> mới.</p>')->call('save')->assertSet('stale', null);
        $written->set('title', 'Máy tiện 2026')->call('publish')->assertSet('stale', null);
        $written->set('excerpt', 'Mô tả')->call('save')->assertSet('stale', null);

        expect($page->fresh()->translationFor('vi')->title)->toBe('Giới thiệu chung')
            ->and($post->fresh()->translationFor('vi')->excerpt)->toBe('Mô tả');
    });
});

it('asks when another member added this language after the form was opened empty', function (): void {
    [$a, $b] = staleSaveMembers();

    app(Tenancy::class)->enter(1, function () use ($a, $b): void {
        $page = staleSavePage();
        $page->translations()->where('locale', 'vi')->delete();
        $mine = Livewire::actingAs($b)->test(Editor::class, ['ulid' => $page->ulid]);
        Livewire::actingAs($a)->test(Editor::class, ['ulid' => $page->ulid])
            ->set('title', 'Bản của Minh Trí')->set('slug', 'ban-minh-tri')->call('save');

        $this->actingAs($b);
        $mine->set('title', 'Bản của Thu Hà')->set('slug', 'ban-thu-ha')->call('save');
        expect($mine->get('stale'))->not->toBeNull()
            ->and($page->fresh()->translationFor('vi')->title)->toBe('Bản của Minh Trí');
    });
});

it('closes the question without saving, and the next save asks again', function (): void {
    [$a, $b] = staleSaveMembers();

    app(Tenancy::class)->enter(1, function () use ($a, $b): void {
        $page = staleSavePage();
        $mine = Livewire::actingAs($b)->test(Editor::class, ['ulid' => $page->ulid]);
        Livewire::actingAs($a)->test(Editor::class, ['ulid' => $page->ulid])->set('title', 'Giới thiệu công ty')->call('save');

        $this->actingAs($b);
        $mine->set('title', 'Về An Vinh')->call('save')->call('closeStale')->assertSet('stale', null);
        $mine->call('save');
        expect($mine->get('stale'))->not->toBeNull()
            ->and($page->fresh()->translationFor('vi')->title)->toBe('Giới thiệu công ty');
    });
});

it('raises no alarm when another member saves a different language only', function (): void {
    [$a, $b] = staleSaveMembers();

    app(Tenancy::class)->enter(1, function () use ($a, $b): void {
        SiteLocale::query()->firstOrCreate(['locale' => 'en'], ['is_default' => false, 'enabled' => true]);
        $page = staleSavePage();
        $page->translations()->create(['locale' => 'en', 'title' => 'About', 'slug' => 'about', 'status' => 'draft', 'body' => ['blocks' => []]]);

        $mine = Livewire::actingAs($b)->test(Editor::class, ['ulid' => $page->ulid]);
        Livewire::actingAs($a)->test(Editor::class, ['ulid' => $page->ulid, 'locale' => 'en'])
            ->set('title', 'About us')->call('save')->assertHasNoErrors();

        $this->actingAs($b);
        $mine->set('title', 'Về An Vinh')->call('save')->assertHasNoErrors()->assertSet('stale', null);
        expect($page->fresh()->translationFor('vi')->title)->toBe('Về An Vinh');
    });
});

it('asks when another member changed only the address or only the Google fields', function (string $field, string $value): void {
    [, $b] = staleSaveMembers();

    app(Tenancy::class)->enter(1, function () use ($b, $field, $value): void {
        $page = staleSavePage();
        $mine = Livewire::actingAs($b)->test(Editor::class, ['ulid' => $page->ulid]);
        $page->translations()->where('locale', 'vi')->update([$field => $value]);

        $mine->set('excerpt', 'Mô tả mới')->call('save');
        expect($mine->get('stale'))->not->toBeNull()
            ->and($page->fresh()->translationFor('vi')->excerpt)->toBe('Về chúng tôi');
    });
})->with([
    'address only' => ['slug', 'gioi-thieu-moi'],
    'Google title only' => ['seo_title', 'Giới thiệu | An Vinh'],
    'Google description only' => ['seo_description', 'Công ty cơ khí chính xác'],
]);

it('asks when the list\'s inline title or address edit by another member came first', function (string $action, string $value, string $column): void {
    [$a, $b] = staleSaveMembers();

    app(Tenancy::class)->enter(1, function () use ($a, $b, $action, $value, $column): void {
        $page = staleSavePage();
        $mine = Livewire::actingAs($b)->test(Editor::class, ['ulid' => $page->ulid]);
        Livewire::actingAs($a)->test(Index::class)->call($action, $page->ulid, $value);
        expect($page->fresh()->translationFor('vi')->{$column})->not->toBe($column === 'title' ? 'Giới thiệu' : 'gioi-thieu');

        $this->actingAs($b);
        $mine->set('excerpt', 'Mô tả mới')->call('save');
        expect($mine->get('stale'))->not->toBeNull()
            ->and($page->fresh()->translationFor('vi')->excerpt)->toBe('Về chúng tôi');
    });
})->with([
    'title' => ['saveTitle', 'Giới thiệu công ty', 'title'],
    'address' => ['saveSlug', 'gioi-thieu-cong-ty', 'slug'],
]);

it('takes the entry row lock before the list\'s inline address edit, and refuses a trashed row', function (): void {
    [$a] = staleSaveMembers();

    app(Tenancy::class)->enter(1, function () use ($a): void {
        $page = staleSavePage();
        $sql = [];
        DB::listen(function ($query) use (&$sql): void {
            $sql[] = strtolower($query->sql);
        });

        Livewire::actingAs($a)->test(Index::class)->call('saveSlug', $page->ulid, 'gioi-thieu-moi');

        $locks = array_values(array_filter($sql, fn (string $q): bool => str_contains($q, 'from "entries"') && str_contains($q, 'for update')));
        expect($locks)->not->toBeEmpty()
            ->and($page->fresh()->translationFor('vi')->slug)->toBe('gioi-thieu-moi');

        // A row trashed meanwhile (the lock sees it) is refused, as saveTitle does.
        $page->delete();
        Livewire::actingAs($a)->test(Index::class)->call('saveSlug', $page->ulid, 'khac-nua');
        expect($page->translations()->where('locale', 'vi')->value('slug'))->toBe('gioi-thieu-moi');
    });
});
