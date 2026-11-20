<?php

use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Admin\Livewire\Entries\Index;
use App\Modules\Cms\Actions\ApplyEntryDraftTransition;
use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Actions\RestoreEntryRevision;
use App\Modules\Cms\Actions\SaveEntryDraft;
use App\Modules\Cms\Actions\TransitionEntryStatus;
use App\Modules\Cms\Enums\EntryStatus;
use App\Modules\Cms\Exceptions\EntryDraftRejectedException;
use App\Modules\Cms\Exceptions\RevisionRestoreRejectedException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryDraft;
use App\Modules\Cms\Models\EntryRevision;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Workflow\Actions\InstantiateWorkflow;
use App\Modules\Workflow\Models\WorkflowLog;
use App\Modules\Workflow\Services\WorkflowEngine;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
 * The editor of a live post whose edits are held back (design.md §8, board 07): while an approval
 * workflow governs it, or the member may not publish it, a save goes into the post's one pending
 * change and visitors keep the live version until the change is approved — or, with no workflow,
 * put on the website by someone who may publish. The list's quick edits and a restored version
 * take the same road; a post that leaves the website takes its change in; a post with a change is
 * not scheduled. A member who may publish a post no workflow governs writes live, as before.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/** The `posts` collection (category «Tin tức», category permalinks); returns the category. */
function pcCollection(): Taxonomy
{
    $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
    $category->translations()->create(['locale' => 'vi', 'name' => 'Tin tức', 'slug' => 'tin-tuc']);
    ContentSection::query()->create([
        'key' => 'posts', 'label' => 'Tin tức', 'entry_type' => 'post', 'taxonomy_id' => $category->id,
        'permission_prefix' => 'entries', 'sort_order' => 1,
    ]);
    SiteSetting::query()->create(['group_name' => 'permalinks', 'key' => 'posts', 'value' => ['pattern' => 'category']]);

    return $category;
}

/** A post by $author, put live by $publisher (tier-1: no workflow yet). */
function pcLivePost(Taxonomy $category, User $author, User $publisher, string $slug = 'bai-1'): Entry
{
    $entry = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'author_id' => $author->id]);
    $entry->translations()->create([
        'locale' => 'vi', 'title' => 'Bản đang đăng', 'slug' => $slug, 'status' => 'draft',
        'body' => ['blocks' => [['type' => 'hero', 'props' => ['heading' => 'Mở đầu cũ']]]],
    ]);
    $entry->assignCategories([$category->id]);
    app(PublishEntry::class)->handle($entry->fresh(), 'vi', $publisher->fresh());

    return $entry->fresh();
}

/** An author (edits own, sends) and an editor (edits any, approves, returns, publishes). */
function pcCast(): array
{
    $author = User::factory()->create();
    $editor = User::factory()->create();
    app(Tenancy::class)->enter(1, function () use ($author, $editor): void {
        grantRole($author, 'author', ['entries.view', 'entries.create', 'entries.edit.own', 'entries.submit']);
        grantRole($editor, 'editor', ['entries.view', 'entries.create', 'entries.edit.any', 'entries.submit', 'entries.review', 'entries.approve', 'entries.publish']);
    });
    app(TeamContext::class)->forget();

    return [$author->fresh(), $editor->fresh()];
}

function pcChange(Entry $entry): ?EntryDraft
{
    return EntryDraft::query()->where('entry_id', $entry->id)->first();
}

function pcRevisions(Entry $entry): int
{
    return EntryRevision::query()->where('entry_id', $entry->id)->count();
}

it('holds an author’s save of a governed live post in its pending change, then sends, approves and shows it', function (): void {
    [$author, $editor] = pcCast();
    $ids = app(Tenancy::class)->enter(1, function () use ($author, $editor): array {
        $post = pcLivePost(pcCollection(), $author, $editor);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);
        $revisions = pcRevisions($post);

        $page = Livewire::actingAs($author)->test(Editor::class, ['ulid' => $post->ulid])
            ->assertSee(__('entries.chg.head_editing', ['noun' => __('entries.chg.noun_post')]))
            ->assertSeeHtml('wire:click="submitChange"')
            ->assertDontSeeHtml('wire:click="publish"')
            ->set('title', 'Bản sửa chờ duyệt')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('bs-toast', text: __('entries.chg.t_saved_wf'))
            ->assertSee(__('entries.chg.sub_draft'));

        $fresh = $post->fresh();
        expect(pcChange($post))->not->toBeNull()
            ->and(pcChange($post)->content['locales']['vi']['title'])->toBe('Bản sửa chờ duyệt')
            ->and($fresh->status)->toBe('published')
            ->and($fresh->workflow_state)->toBe($post->workflow_state)
            ->and($fresh->translationFor('vi')->title)->toBe('Bản đang đăng')
            ->and(pcRevisions($post))->toBe($revisions);

        // Sending: the change moves to review through the engine; the entry stays where it stands.
        $page->call('submitChange')->assertHasNoErrors()->assertDispatched('bs-toast', text: __('entries.chg.t_sent'));
        expect(pcChange($post)->workflow_state)->toBe('pending')
            ->and(WorkflowLog::query()->where('entry_id', $post->id)->where('target', WorkflowEngine::TARGET_CHANGE)->count())->toBe(1)
            ->and($post->fresh()->status)->toBe('published');

        // Locked once sent: a further save and a discard are refused, nothing written.
        $page->set('title', 'Sửa thêm sau khi gửi')->call('save')
            ->assertHasErrors('workflow');
        expect(pcChange($post)->content['locales']['vi']['title'])->toBe('Bản sửa chờ duyệt');

        // Approving a sent change leaves out what the reviewer typed since, and the question says so.
        Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid])
            ->call('askApplyChange')
            ->assertHasNoErrors()
            ->assertSet('changeApplyAsk', true)
            ->assertDontSee(__('entries.chg.ask_unsaved'))
            ->set('changeApplyAsk', false)
            ->set('title', 'Gõ thêm chưa lưu')
            ->call('askApplyChange')
            ->assertHasNoErrors()
            ->assertSet('changeApplyAsk', true)
            ->assertSee(__('entries.chg.ask_unsaved'));
        expect(pcChange($post)->content['locales']['vi']['title'])->toBe('Bản sửa chờ duyệt');

        // The reviewer opens it: the band offers approve and return, discard locked with its reason.
        Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid])
            ->assertSee(__('entries.chg.sub_locked'))
            ->assertSee(__('entries.chg.btn_approve'))
            ->assertSee(__('entries.chg.btn_return'))
            ->assertSee(__('entries.change_err_change_pending'))
            ->assertSet('title', 'Bản sửa chờ duyệt')
            ->call('openChangeDiff')
            ->assertSee(__('entries.chg.diff_seen', ['text' => 'Bản đang đăng']))
            ->call('askApplyChange')
            ->assertSet('changeApplyAsk', true)
            ->call('applyChange')
            ->assertHasNoErrors()
            ->assertDispatched('bs-toast', text: __('entries.chg.t_approved', ['langs' => 'Tiếng Việt']));

        return ['post' => $post->id, 'revisions' => $revisions];
    });

    $this->get('http://a.test/tin-tuc/bai-1')->assertOk()->assertSee('Bản sửa chờ duyệt');
    app(Tenancy::class)->enter(1, function () use ($ids): void {
        expect(EntryDraft::query()->where('entry_id', $ids['post'])->exists())->toBeFalse()
            ->and(EntryRevision::query()->where('entry_id', $ids['post'])->count())->toBe($ids['revisions'] + 1);
    });
});

it('keeps visitors on the live version while the change waits', function (): void {
    [$author, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($author, $editor): void {
        $post = pcLivePost(pcCollection(), $author, $editor);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);
        Livewire::actingAs($author)->test(Editor::class, ['ulid' => $post->ulid])
            ->set('title', 'Chưa được duyệt')
            ->call('submitChange')
            ->assertHasNoErrors();
    });

    $this->get('http://a.test/tin-tuc/bai-1')->assertOk()->assertSee('Bản đang đăng')->assertDontSee('Chưa được duyệt');
});

it('returns a change only with a note, and shows the note to its writer', function (): void {
    [$author, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($author, $editor): void {
        $post = pcLivePost(pcCollection(), $author, $editor);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);
        Livewire::actingAs($author)->test(Editor::class, ['ulid' => $post->ulid])
            ->set('title', 'Bản sửa')->call('submitChange');

        $review = Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid])
            ->call('openChangeReturn')
            ->call('returnChange')
            ->assertHasErrors(['changeNote']);
        expect(pcChange($post)->workflow_state)->toBe('pending');

        $review->set('changeNote', 'Sửa lại đoạn giá năm 2026')->call('returnChange')
            ->assertHasNoErrors()
            ->assertSet('changeReturnOpen', false)
            ->assertDispatched('bs-toast', text: __('entries.chg.t_returned', ['by' => $author->name]));
        expect(pcChange($post)->workflow_state)->toBe('draft');

        Livewire::actingAs($author)->test(Editor::class, ['ulid' => $post->ulid])
            ->assertSee(__('entries.chg.sub_returned', ['note' => 'Sửa lại đoạn giá năm 2026']), false)
            ->assertSeeHtml('wire:click="submitChange"');
    });
});

it('lets a member who may publish a post no workflow governs write it live, as before', function (): void {
    [$author, $editor] = pcCast();
    $id = app(Tenancy::class)->enter(1, function () use ($author, $editor): int {
        $post = pcLivePost(pcCollection(), $author, $editor);
        $revisions = pcRevisions($post);
        Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid])
            ->assertDontSee(__('entries.chg.head_editing', ['noun' => __('entries.chg.noun_post')]))
            ->set('title', 'Cập nhật thẳng')
            ->call('publish')
            ->assertHasNoErrors();
        expect(pcChange($post))->toBeNull()
            ->and(pcRevisions($post))->toBe($revisions + 1);

        return $post->id;
    });

    $this->get('http://a.test/tin-tuc/bai-1')->assertOk()->assertSee('Cập nhật thẳng');
    expect($id)->toBeInt();
});

it('holds the save of a member who may not publish, until a publisher updates the website', function (): void {
    [, $editor] = pcCast();
    $writer = User::factory()->create();
    app(Tenancy::class)->enter(1, function () use ($writer, $editor): void {
        grantRole($writer, 'writer', ['entries.view', 'entries.edit.any']);
        $post = pcLivePost(pcCollection(), $editor, $editor);

        Livewire::actingAs($writer->fresh())->test(Editor::class, ['ulid' => $post->ulid])
            ->assertSee(__('entries.chg.btn_save'))
            ->set('title', 'Bản của người viết')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('bs-toast', text: __('entries.chg.t_saved'))
            ->assertSee(__('entries.chg.head_wait'));
        expect($post->fresh()->translationFor('vi')->title)->toBe('Bản đang đăng');

        Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid])
            ->assertSeeHtml('wire:click="askApplyChange"')
            ->call('askApplyChange')
            ->call('applyChange')
            ->assertHasNoErrors()
            ->assertSet('title', 'Bản của người viết');
        expect(pcChange($post))->toBeNull();
    });

    $this->get('http://a.test/tin-tuc/bai-1')->assertOk()->assertSee('Bản của người viết');
});

it('writes nothing when the save differs in nothing from the website', function (): void {
    [$author, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($author, $editor): void {
        $post = pcLivePost(pcCollection(), $author, $editor);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);
        Livewire::actingAs($author)->test(Editor::class, ['ulid' => $post->ulid])
            ->call('save')
            ->assertDispatched('bs-toast', text: __('entries.chg.t_same'));
        expect(pcChange($post))->toBeNull();
    });
});

it('throws a change away on «Bỏ thay đổi», the website untouched', function (): void {
    [$author, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($author, $editor): void {
        $post = pcLivePost(pcCollection(), $author, $editor);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);
        Livewire::actingAs($author)->test(Editor::class, ['ulid' => $post->ulid])
            ->set('title', 'Sẽ bỏ')->call('save')
            ->call('askDiscardChange')
            ->assertSet('changeDiscardAsk', true)
            ->call('discardChange')
            ->assertHasNoErrors()
            ->assertSet('title', 'Bản đang đăng')
            ->assertSet('changeSeen', null);
        expect(pcChange($post))->toBeNull();
    });
});

it('refuses a stale tab: the change moved on since the form read it', function (): void {
    [$author, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($author, $editor): void {
        $post = pcLivePost(pcCollection(), $author, $editor);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);
        $old = Livewire::actingAs($author)->test(Editor::class, ['ulid' => $post->ulid]);
        app(SaveEntryDraft::class)->handle($post->fresh(), $editor, 'vi', ['title' => 'Bản của tab khác']);

        $old->set('title', 'Bản của tab cũ')->call('save')
            ->assertHasErrors('workflow');
        expect(pcChange($post)->content['locales']['vi']['title'])->toBe('Bản của tab khác');
    });
});

it('never lets the browser set the change version or the entry', function (): void {
    [$author, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($author, $editor): void {
        $post = pcLivePost(pcCollection(), $author, $editor);
        $other = pcLivePost(Taxonomy::query()->firstOrFail(), $editor, $editor, 'bai-2');
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);
        $page = Livewire::actingAs($author)->test(Editor::class, ['ulid' => $post->ulid]);

        expect(fn () => $page->set('changeSeen', 'forged'))->toThrow(CannotUpdateLockedPropertyException::class)
            ->and(fn () => $page->set('ulid', $other->ulid))->toThrow(CannotUpdateLockedPropertyException::class);
    });
});

it('refuses the change moves to a member who may not edit the post', function (): void {
    [$author, $editor] = pcCast();
    $stranger = User::factory()->create();
    app(Tenancy::class)->enter(1, function () use ($author, $editor, $stranger): void {
        grantRole($stranger, 'stranger', ['entries.view', 'entries.edit.own', 'entries.submit']);
        $post = pcLivePost(pcCollection(), $author, $editor);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);
        app(SaveEntryDraft::class)->handle($post->fresh(), $author, 'vi', ['title' => 'Bản sửa']);

        Livewire::actingAs($stranger->fresh())->test(Editor::class, ['ulid' => $post->ulid])->assertForbidden();

        // The reviewer's rights are taken away while the page is open: every move is refused.
        $page = Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid]);
        $editor->syncRoles([]);
        app(TeamContext::class)->forget();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $page->call('discardChange')->assertForbidden();
        expect(pcChange($post))->not->toBeNull();
    });
});

it('keeps another website’s pending changes out of reach', function (): void {
    [$author, $editor] = pcCast();
    $ulid = app(Tenancy::class)->enter(1, function () use ($author, $editor): string {
        $post = pcLivePost(pcCollection(), $author, $editor);
        app(SaveEntryDraft::class)->handle($post->fresh(), $editor, 'vi', ['title' => 'Bản sửa của A']);

        return $post->ulid;
    });

    app(Tenancy::class)->enter(2, function () use ($editor, $ulid): void {
        expect(EntryDraft::query()->count())->toBe(0);
        Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $ulid])->assertNotFound();
    });
    expect(DB::table('entry_drafts')->count())->toBe(0);   // no site context: the RLS policy shows no row
});

it('refuses the list’s quick title and link edits on a post whose edits are held back', function (): void {
    [$author, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($author, $editor): void {
        $post = pcLivePost(pcCollection(), $author, $editor);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);
        $revisions = pcRevisions($post);

        Livewire::actingAs($editor)->test(Index::class, ['section' => 'posts'])
            ->call('saveTitle', $post->ulid, 'Tên mới ở danh sách')
            ->call('saveSlug', $post->ulid, 'duong-dan-moi');

        $translation = $post->fresh()->translationFor('vi');
        expect($translation->title)->toBe('Bản đang đăng')
            ->and($translation->slug)->toBe('bai-1')
            ->and(SiteRoute::query()->where('entry_id', $post->id)->value('path'))->toBe('/tin-tuc/bai-1')
            ->and(pcChange($post))->toBeNull()
            ->and(pcRevisions($post))->toBe($revisions);
    });
});

it('still renames a post from the list for a member who may publish it, no workflow', function (): void {
    [$author, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($author, $editor): void {
        $post = pcLivePost(pcCollection(), $author, $editor);
        Livewire::actingAs($editor)->test(Index::class, ['section' => 'posts'])
            ->call('saveTitle', $post->ulid, 'Tên mới ở danh sách');
        expect($post->fresh()->translationFor('vi')->title)->toBe('Tên mới ở danh sách');
    });
});

it('refuses a live page’s quick rename to a member who may not publish it, no workflow', function (): void {
    $writer = User::factory()->create();
    app(Tenancy::class)->enter(1, function () use ($writer): void {
        grantRole($writer, 'writer', ['entries.view', 'entries.edit.any']);
        $page = Entry::query()->create(['type' => 'page', 'status' => 'published', 'author_id' => $writer->id]);
        $page->translations()->create(['locale' => 'vi', 'title' => 'Giới thiệu', 'slug' => 'gioi-thieu', 'status' => 'published', 'body' => ['blocks' => []]]);

        Livewire::actingAs($writer->fresh())->test(Index::class)
            ->call('saveTitle', $page->ulid, 'Tên mới')
            ->assertDispatched('bs-toast', text: __('entries.err_edit_in_change', ['noun' => __('entries.noun_page')]));
        expect($page->fresh()->translationFor('vi')->title)->toBe('Giới thiệu')
            ->and(pcChange($page))->toBeNull();
    });
});

it('restores a version of a governed live post into its pending change', function (): void {
    [$author, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($author, $editor): void {
        $post = pcLivePost(pcCollection(), $author, $editor);
        $first = EntryRevision::query()->where('entry_id', $post->id)->orderBy('id')->firstOrFail();
        $post->translationFor('vi')->update(['title' => 'Bản thứ hai']);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);
        $revisions = pcRevisions($post);

        app(RestoreEntryRevision::class)->handle($first, $author);

        expect($post->fresh()->translationFor('vi')->title)->toBe('Bản thứ hai')
            ->and(pcChange($post)->content['locales']['vi']['title'])->toBe('Bản đang đăng')
            ->and(pcRevisions($post))->toBe($revisions);

        // sent for review: no restore into it until it is returned
        app(ApplyEntryDraftTransition::class)->handle($post->fresh(), 'vi', $author, 'submit', pcChange($post)->version());
        expect(fn () => app(RestoreEntryRevision::class)->handle($first, $author))
            ->toThrow(RevisionRestoreRejectedException::class);
    });
});

it('restores a version live for a member who may publish a post no workflow governs', function (): void {
    [$author, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($author, $editor): void {
        $post = pcLivePost(pcCollection(), $author, $editor);
        $first = EntryRevision::query()->where('entry_id', $post->id)->orderBy('id')->firstOrFail();
        $post->translationFor('vi')->update(['title' => 'Bản thứ hai']);

        app(RestoreEntryRevision::class)->handle($first, $editor);

        expect($post->fresh()->translationFor('vi')->title)->toBe('Bản đang đăng')
            ->and(pcChange($post))->toBeNull();
    });
});

it('moves the address only when a change with a new link is approved', function (): void {
    [$author, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($author, $editor): void {
        $post = pcLivePost(pcCollection(), $author, $editor);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);
        Livewire::actingAs($author)->test(Editor::class, ['ulid' => $post->ulid])
            ->set('slug', 'bai-moi')->call('submitChange')->assertHasNoErrors();
        expect(SiteRoute::query()->where('entry_id', $post->id)->pluck('path')->all())->toBe(['/tin-tuc/bai-1']);
    });
    $this->get('http://a.test/tin-tuc/bai-1')->assertOk();
    $this->get('http://a.test/tin-tuc/bai-moi')->assertNotFound();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        $post = Entry::query()->firstOrFail();
        Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid])
            ->call('askApplyChange')->call('applyChange')->assertHasNoErrors();
    });
    $this->get('http://a.test/tin-tuc/bai-moi')->assertOk();
    $this->get('http://a.test/tin-tuc/bai-1')->assertRedirect();
});

it('puts the title’s link into the change when the link field is left empty, the address untouched', function (): void {
    [$author, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($author, $editor): void {
        $post = pcLivePost(pcCollection(), $author, $editor);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);
        Livewire::actingAs($author)->test(Editor::class, ['ulid' => $post->ulid])
            ->set('title', 'Bài mới tinh')->set('slug', '')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('slug', 'bai-moi-tinh');
        expect(pcChange($post)->content['locales']['vi']['slug'])->toBe('bai-moi-tinh')
            ->and($post->fresh()->translationFor('vi')->slug)->toBe('bai-1')
            ->and(SiteRoute::query()->where('entry_id', $post->id)->pluck('path')->all())->toBe(['/tin-tuc/bai-1']);
    });
});

it('folds the change into a post that leaves the website, and refuses to schedule one that has a change', function (): void {
    [$author, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($author, $editor): void {
        $post = pcLivePost(pcCollection(), $author, $editor);
        app(SaveEntryDraft::class)->handle($post->fresh(), $author, 'vi', ['title' => 'Chữ chưa đăng']);

        $refused = null;
        try {
            app(TransitionEntryStatus::class)->handle($post->fresh(), EntryStatus::Scheduled, $editor, now()->addDay()->toImmutable());
        } catch (EntryDraftRejectedException $e) {
            $refused = $e->reason;
        }
        expect($refused)->toBe('change_open')->and($post->fresh()->status)->toBe('published');

        app(TransitionEntryStatus::class)->handle($post->fresh(), EntryStatus::Draft, $editor);
        $fresh = $post->fresh();
        expect($fresh->status)->toBe('draft')
            ->and($fresh->translationFor('vi')->title)->toBe('Chữ chưa đăng')
            ->and(pcChange($post))->toBeNull();
    });
    $this->get('http://a.test/tin-tuc/bai-1')->assertNotFound();
});

it('moves every language’s address when a folded change files the post elsewhere', function (): void {
    [$author, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($author, $editor): void {
        DB::connection('pgsql_admin')->table('site_locales')->insert([
            'site_id' => 1, 'locale' => 'en', 'is_default' => false, 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $news = pcCollection();
        $news->translations()->create(['locale' => 'en', 'name' => 'News', 'slug' => 'news']);
        $sub = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 1, 'parent_id' => $news->id]);
        $sub->translations()->create(['locale' => 'vi', 'name' => 'Sự kiện', 'slug' => 'su-kien']);
        $sub->translations()->create(['locale' => 'en', 'name' => 'Events', 'slug' => 'events']);
        $post = pcLivePost($news, $author, $editor);
        $post->translations()->create(['locale' => 'en', 'title' => 'Live', 'slug' => 'live-en', 'status' => 'draft', 'body' => ['blocks' => []]]);
        app(PublishEntry::class)->handle($post->fresh(), 'en', $editor);
        $before = SiteRoute::query()->where('entry_id', $post->id)->orderBy('locale')->pluck('path', 'locale')->all();

        app(SaveEntryDraft::class)->handle($post->fresh(), $editor, 'vi', [], ['primary_category_id' => $sub->id]);
        app(TransitionEntryStatus::class)->handle($post->fresh(), EntryStatus::Draft, $editor);

        $after = SiteRoute::query()->where('entry_id', $post->id)->orderBy('locale')->pluck('path', 'locale')->all();
        expect(array_keys($after))->toBe(array_keys($before))
            ->and($after['vi'])->not->toBe($before['vi'])
            ->and($after['en'])->not->toBe($before['en'])
            ->and($after['vi'])->toContain('su-kien')
            ->and($after['en'])->toContain('events');
    });
});

it('refuses the list’s schedule of a live post with a change in plain words', function (): void {
    [$author, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($author, $editor): void {
        $post = pcLivePost(pcCollection(), $author, $editor);
        app(SaveEntryDraft::class)->handle($post->fresh(), $author, 'vi', ['title' => 'Chữ chưa đăng']);

        Livewire::actingAs($editor)->test(Index::class, ['section' => 'posts'])
            ->call('scheduleEntry', $post->ulid, now()->addDay()->format('Y-m-d\\TH:i'))
            ->assertDispatched('bs-toast', text: __('entries.change_err_change_open'));
        expect($post->fresh()->status)->toBe('published')
            ->and(pcChange($post))->not->toBeNull();
    });
});

it('lets a held save make one of the post’s other categories the main one, keeping the old main beside it', function (): void {
    [$author, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($author, $editor): void {
        $root = pcCollection();
        [$news, $events] = array_map(function (array $c) use ($root): Taxonomy {
            $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => $c[0], 'parent_id' => $root->id]);
            $category->translations()->create(['locale' => 'vi', 'name' => $c[1], 'slug' => $c[2]]);

            return $category;
        }, [[0, 'Bản tin', 'ban-tin'], [1, 'Sự kiện', 'su-kien']]);
        $post = pcLivePost($root, $author, $editor);
        $post->assignCategories([$news->id, $events->id]);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);

        Livewire::actingAs($author)->test(Editor::class, ['ulid' => $post->ulid])
            ->call('setMainCategory', $events->ulid)
            ->call('submitChange')
            ->assertHasNoErrors();
        expect(pcChange($post)->content['shared']['primary_category_id'])->toBe($events->id)
            ->and($post->fresh()->primaryCategory()?->id)->toBe($news->id);

        // opened again, the form shows the change's filing and saves it unchanged
        Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid])
            ->assertSet('categoryIds', [$events->id, $news->id])
            ->call('askApplyChange')->call('applyChange')->assertHasNoErrors();
        $fresh = $post->fresh();
        expect($fresh->primaryCategory()?->id)->toBe($events->id)
            ->and($fresh->taxonomies()->wherePivot('position', '<', Entry::FACET_POSITION_BASE)->orderByPivot('position')->pluck('taxonomies.id')->all())
            ->toBe([$events->id, $news->id]);
    });
});

it('keeps a live page under its parent when the member’s edits are held back', function (): void {
    $writer = User::factory()->create();
    app(Tenancy::class)->enter(1, function () use ($writer): void {
        grantRole($writer, 'writer', ['entries.view', 'entries.edit.any']);
        $live = function (string $slug): Entry {
            $page = Entry::query()->create(['type' => 'page', 'status' => 'published', 'author_id' => null]);
            $page->translations()->create(['locale' => 'vi', 'title' => 'Trang '.$slug, 'slug' => $slug, 'status' => 'published', 'body' => ['blocks' => []]]);

            return $page;
        };
        $about = $live('gioi-thieu');
        $team = $live('doi-ngu');

        Livewire::actingAs($writer->fresh())->test(Index::class)
            ->call('movePageTo', $team->ulid, $about->ulid)
            ->assertDispatched('bs-toast', text: __('entries.move_refused_change_held'));
        expect($team->fresh()->parent_id)->toBeNull();
    });
});

it('still reorders a held member’s live page among its siblings, and lets a publisher give it a parent', function (): void {
    $writer = User::factory()->create();
    app(Tenancy::class)->enter(1, function () use ($writer): void {
        grantRole($writer, 'writer', ['entries.view', 'entries.edit.any']);
        $live = function (string $slug, int $position): Entry {
            $page = Entry::query()->create(['type' => 'page', 'status' => 'published', 'author_id' => null, 'position' => $position]);
            $page->translations()->create(['locale' => 'vi', 'title' => 'Trang '.$slug, 'slug' => $slug, 'status' => 'published', 'body' => ['blocks' => []]]);

            return $page;
        };
        $about = $live('gioi-thieu', 0);
        $team = $live('doi-ngu', 1);

        Livewire::actingAs($writer->fresh())->test(Index::class)
            ->call('movePageStep', $about->ulid, 'down')
            ->assertNotDispatched('bs-toast', text: __('entries.move_refused_change_held'));
        expect($about->fresh()->position)->toBeGreaterThan($team->fresh()->position)
            ->and($about->fresh()->parent_id)->toBeNull();

        $writer->givePermissionTo('entries.publish');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Livewire::actingAs($writer->fresh())->test(Index::class)
            ->call('movePageTo', $team->ulid, $about->ulid);
        expect($team->fresh()->parent_id)->toBe($about->id);
    });
});

it('moves a folded page’s sub-pages to the address its change gave it', function (): void {
    [, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($editor): void {
        SiteSetting::query()->updateOrCreate(['group_name' => 'permalinks', 'key' => 'pages'], ['value' => ['pattern' => 'nested']]);
        $page = function (string $slug, ?Entry $parent) use ($editor): Entry {
            $entry = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $editor->id, 'parent_id' => $parent?->id]);
            $entry->translations()->create(['locale' => 'vi', 'title' => 'Trang '.$slug, 'slug' => $slug, 'status' => 'draft', 'body' => ['blocks' => []]]);
            app(PublishEntry::class)->handle($entry->fresh(), 'vi', $editor);

            return $entry->fresh();
        };
        $about = $page('gioi-thieu', null);
        $team = $page('doi-ngu', $about);
        expect(SiteRoute::query()->where('entry_id', $team->id)->value('path'))->toBe('/gioi-thieu/doi-ngu');

        app(SaveEntryDraft::class)->handle($about, $editor, 'vi', ['slug' => 've-chung-toi']);
        app(TransitionEntryStatus::class)->handle($about->fresh(), EntryStatus::Draft, $editor);

        expect(SiteRoute::query()->where('entry_id', $about->id)->value('path'))->toBe('/ve-chung-toi')
            ->and(SiteRoute::query()->where('entry_id', $team->id)->value('path'))->toBe('/ve-chung-toi/doi-ngu');
    });
});

it('authorizes every change method on its own', function (string $method): void {
    [$author, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($author, $editor, $method): void {
        $post = pcLivePost(pcCollection(), $author, $editor);
        app(SaveEntryDraft::class)->handle($post->fresh(), $author, 'vi', ['title' => 'Bản sửa']);
        $page = Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid]);
        $editor->syncRoles([]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $page->call($method)->assertForbidden();
        expect(pcChange($post))->not->toBeNull();
    });
})->with(['applyChange', 'discardChange', 'askApplyChange', 'askDiscardChange', 'openChangeDiff', 'openChangeReturn', 'returnChange']);
