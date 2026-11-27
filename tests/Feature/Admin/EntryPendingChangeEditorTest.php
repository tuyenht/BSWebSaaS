<?php

use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Admin\Livewire\Entries\Index;
use App\Modules\Admin\Support\Entries\PageMoveWords;
use App\Modules\Cms\Actions\ApplyEntryDraft;
use App\Modules\Cms\Actions\ApplyEntryDraftTransition;
use App\Modules\Cms\Actions\DiscardEntryDraft;
use App\Modules\Cms\Actions\MovePage;
use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Actions\PublishScheduledEntry;
use App\Modules\Cms\Actions\RestoreEntryRevision;
use App\Modules\Cms\Actions\SaveEntryDraft;
use App\Modules\Cms\Actions\TransitionEntryStatus;
use App\Modules\Cms\Enums\EntryStatus;
use App\Modules\Cms\Exceptions\EntryDraftRejectedException;
use App\Modules\Cms\Exceptions\PageMoveRejectedException;
use App\Modules\Cms\Exceptions\RevisionRestoreRejectedException;
use App\Modules\Cms\Exceptions\RouteConflictException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryDraft;
use App\Modules\Cms\Models\EntryRevision;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Routing\RouteSync;
use App\Modules\Cms\Support\BlankEntrySlug;
use App\Modules\Cms\Support\EntryWorkflowResolver;
use App\Modules\Identity\Models\User;
use App\Modules\Media\Models\Media;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\SiteLocale;
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
function pcLivePost(Taxonomy $category, User $author, User $publisher, string $slug = 'bai-1', ?array $body = null): Entry
{
    $entry = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'author_id' => $author->id]);
    $entry->translations()->create([
        'locale' => 'vi', 'title' => 'Bản đang đăng', 'slug' => $slug, 'status' => 'draft',
        'body' => $body ?? ['blocks' => [['type' => 'hero', 'props' => ['heading' => 'Mở đầu cũ']]]],
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
            ->assertHasNoErrors('workflow')
            ->assertSet('refusal', ['title' => __('entries.refuse.t_locked'), 'text' => __('entries.change_err_change_locked'), 'reload' => false]);
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

it('holds a governed live post’s focus keyword in its pending change, shows it in «So sánh» and puts it live on approval', function (): void {
    [$author, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($author, $editor): void {
        $post = pcLivePost(pcCollection(), $author, $editor);
        $post->translationFor('vi')->update(['focus_keyword' => 'tin cũ']);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);

        Livewire::actingAs($author)->test(Editor::class, ['ulid' => $post->ulid])
            ->assertSet('focusKeyword', 'tin cũ')
            ->assertSee(__('entries.ed.keyword_note', ['doc' => __('entries.ed.doc_post')]))
            ->set('focusKeyword', 'tin gia công CNC')
            ->call('save')
            ->assertHasNoErrors();

        expect(pcChange($post)->content['locales']['vi']['focus_keyword'])->toBe('tin gia công CNC')
            ->and($post->fresh()->translationFor('vi')->focus_keyword)->toBe('tin cũ');

        // the change is what the form opens with again
        Livewire::actingAs($author)->test(Editor::class, ['ulid' => $post->ulid])
            ->assertSet('focusKeyword', 'tin gia công CNC')
            ->call('submitChange')
            ->assertHasNoErrors();

        Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid])
            ->call('openChangeDiff')
            ->assertSee(__('entries.chg.f_focus_keyword'))
            ->assertSee(__('entries.chg.diff_seen', ['text' => 'tin cũ']))
            ->call('askApplyChange')
            ->call('applyChange')
            ->assertHasNoErrors();

        expect($post->fresh()->translationFor('vi')->focus_keyword)->toBe('tin gia công CNC');
    });
});

it('writes no change when the form holds the live focus keyword unchanged', function (): void {
    [$author, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($author, $editor): void {
        $post = pcLivePost(pcCollection(), $author, $editor);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);

        // a form with the live words, saved unchanged: nothing differs from the website
        $post->translationFor('vi')->update(['focus_keyword' => 'tin cũ']);
        Livewire::actingAs($author)->test(Editor::class, ['ulid' => $post->ulid])
            ->call('save')
            ->assertHasNoErrors();

        expect(pcChange($post))->toBeNull();
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
            ->assertHasNoErrors('workflow')
            ->assertSet('refusal', ['title' => __('entries.refuse.t_save'), 'text' => __('entries.change_err_change_stale'), 'reload' => true]);
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

it('never puts an emptied link live on a governed post: the list refuses, the editor holds the title’s link in the change', function (): void {
    [$author, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($author, $editor): void {
        $post = pcLivePost(pcCollection(), $author, $editor);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);

        Livewire::actingAs($editor)->test(Index::class, ['section' => 'posts'])
            ->call('saveSlug', $post->ulid, '')
            ->assertDispatched('bs-toast', text: __('entries.err_edit_in_change', ['noun' => __('entries.noun_post')]));
        expect($post->fresh()->translationFor('vi')->slug)->toBe('bai-1')
            ->and(pcChange($post))->toBeNull();

        $titleLink = app(BlankEntrySlug::class)->make($post->fresh(), 'vi', 'Bản đang đăng');
        Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid])
            ->set('slug', '')
            ->assertSet('slug', $titleLink)
            ->call('save')
            ->assertHasNoErrors();
        expect($titleLink)->toBe('ban-dang-dang')
            ->and(pcChange($post)->content['locales']['vi']['slug'])->toBe($titleLink)
            ->and($post->fresh()->translationFor('vi')->slug)->toBe('bai-1')
            ->and(SiteRoute::query()->where('entry_id', $post->id)->pluck('path')->all())->toBe(['/tin-tuc/bai-1']);
    });
    $this->get('http://a.test/tin-tuc/bai-1')->assertOk();
    $this->get('http://a.test/tin-tuc/ban-dang-dang')->assertNotFound();
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

/** Nested page addresses, and a page by $editor put live (tier-1) under $parent. */
function pcLivePage(User $editor, string $slug, ?Entry $parent): Entry
{
    SiteSetting::query()->updateOrCreate(['group_name' => 'permalinks', 'key' => 'pages'], ['value' => ['pattern' => 'nested']]);
    $entry = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $editor->id, 'parent_id' => $parent?->id]);
    $entry->translations()->create(['locale' => 'vi', 'title' => 'Trang '.$slug, 'slug' => $slug, 'status' => 'draft', 'body' => ['blocks' => []]]);
    app(PublishEntry::class)->handle($entry->fresh(), 'vi', $editor);

    return $entry->fresh();
}

it('keeps a folded page’s live sub-pages at their address until the page is live again, then moves them with a 301', function (string $road): void {
    [, $editor] = pcCast();
    $ids = app(Tenancy::class)->enter(1, function () use ($editor): array {
        $about = pcLivePage($editor, 'gioi-thieu', null);
        $team = pcLivePage($editor, 'doi-ngu', $about);

        app(SaveEntryDraft::class)->handle($about, $editor, 'vi', ['slug' => 've-chung-toi']);
        app(TransitionEntryStatus::class)->handle($about->fresh(), EntryStatus::Draft, $editor);

        // the page is offline: its own address may follow the folded link; the live sub-page's may not
        expect(SiteRoute::query()->where('entry_id', $about->id)->value('path'))->toBe('/ve-chung-toi')
            ->and(SiteRoute::query()->where('entry_id', $team->id)->pluck('path')->all())->toBe(['/gioi-thieu/doi-ngu'])
            ->and(Redirect::query()->where('from_path', '/gioi-thieu/doi-ngu')->exists())->toBeFalse();

        return [$about->id, $team->id];
    });
    $this->get('http://a.test/gioi-thieu/doi-ngu')->assertOk();

    app(Tenancy::class)->enter(1, function () use ($editor, $road, $ids): void {
        [$aboutId, $teamId] = $ids;
        $about = Entry::query()->findOrFail($aboutId);
        if ($road === 'publish') {
            app(PublishEntry::class)->handle($about, 'vi', $editor);
        } else {
            $about->update(['status' => 'scheduled', 'published_at' => now()->subMinute()]);
            expect(app(PublishScheduledEntry::class)->handle($about->fresh()))->toBeTrue();
        }

        expect(SiteRoute::query()->where('entry_id', $teamId)->pluck('path')->all())->toBe(['/ve-chung-toi/doi-ngu'])
            ->and(Redirect::query()->where('from_path', '/gioi-thieu/doi-ngu')->where('to_path', '/ve-chung-toi/doi-ngu')->exists())->toBeTrue();
    });
    $this->get('http://a.test/gioi-thieu/doi-ngu')->assertRedirect('http://a.test/ve-chung-toi/doi-ngu');
    $this->get('http://a.test/ve-chung-toi/doi-ngu')->assertOk();
})->with(['publish', 'scheduled']);

it('refuses to put a folded page live again while its sub-page’s new address is taken, nothing moved', function (): void {
    [, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($editor): void {
        $about = pcLivePage($editor, 'gioi-thieu', null);
        $team = pcLivePage($editor, 'doi-ngu', $about);
        app(SaveEntryDraft::class)->handle($about, $editor, 'vi', ['slug' => 've-chung-toi']);
        app(TransitionEntryStatus::class)->handle($about->fresh(), EntryStatus::Draft, $editor);
        $other = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $editor->id]);
        SiteRoute::query()->create(['locale' => 'vi', 'path' => '/ve-chung-toi/doi-ngu', 'entry_id' => $other->id]);

        expect(fn () => app(PublishEntry::class)->handle($about->fresh(), 'vi', $editor))->toThrow(RouteConflictException::class);
        expect($about->fresh()->status)->toBe('draft')
            ->and(SiteRoute::query()->where('entry_id', $team->id)->pluck('path')->all())->toBe(['/gioi-thieu/doi-ngu']);
    });
});

it('moves a republished page’s sub-pages only in the languages that are live again', function (): void {
    [, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($editor): void {
        DB::connection('pgsql_admin')->table('site_locales')->insert([
            'site_id' => 1, 'locale' => 'en', 'is_default' => false, 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $about = pcLivePage($editor, 'gioi-thieu', null);
        $team = pcLivePage($editor, 'doi-ngu', $about);
        foreach ([[$about, 'about'], [$team, 'team']] as [$page, $slug]) {
            $page->translations()->create(['locale' => 'en', 'title' => ucfirst($slug), 'slug' => $slug, 'status' => 'draft', 'body' => ['blocks' => []]]);
            app(PublishEntry::class)->handle($page->fresh(), 'en', $editor);
        }
        $teamEn = SiteRoute::query()->where('entry_id', $team->id)->where('locale', 'en')->value('path');
        app(SaveEntryDraft::class)->handle($about->fresh(), $editor, 'vi', ['slug' => 've-chung-toi']);
        app(SaveEntryDraft::class)->handle($about->fresh(), $editor, 'en', ['slug' => 'about-us']);
        app(TransitionEntryStatus::class)->handle($about->fresh(), EntryStatus::Draft, $editor);
        // English is no longer live on the page: its folded link was never put on the website
        $about->translationFor('en')->update(['status' => 'draft']);

        app(PublishEntry::class)->handle($about->fresh(), 'vi', $editor);

        expect(SiteRoute::query()->where('entry_id', $team->id)->where('locale', 'vi')->value('path'))->toBe('/ve-chung-toi/doi-ngu')
            ->and(SiteRoute::query()->where('entry_id', $team->id)->where('locale', 'en')->value('path'))->toBe($teamEn);
    });
});

it('keeps an offline page under its parent when a live sub-page’s edits are held back for the member', function (string $case): void {
    [, $editor] = pcCast();
    $writer = User::factory()->create();
    app(Tenancy::class)->enter(1, function () use ($writer, $editor, $case): void {
        grantRole($writer, 'writer', ['entries.view', 'entries.edit.any']);
        if ($case === 'a workflow on posts, which governs no page') {
            pcCollection();
            app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);
        }
        $about = pcLivePage($editor, 'gioi-thieu', null);
        $team = pcLivePage($editor, 'doi-ngu', $about);
        // a page files under no category, so no approval flow can govern one: the right to publish decides
        expect(app(EntryWorkflowResolver::class)->forEntry($team))->toBeNull();
        $services = pcLivePage($editor, 'dich-vu', null);
        app(TransitionEntryStatus::class)->handle($about->fresh(), EntryStatus::Draft, $editor);
        $mover = $writer;
        if ($case === 'a pending change on the sub-page') {
            // even someone who may publish: approving that change is what moves the sub-page for visitors
            app(SaveEntryDraft::class)->handle($team->fresh(), $writer->fresh(), 'vi', ['title' => 'Chữ chờ duyệt']);
            $mover = $editor;
        }
        $routes = SiteRoute::query()->orderBy('id')->get(['entry_id', 'locale', 'path'])->toArray();
        $redirects = Redirect::query()->count();

        Livewire::actingAs($mover->fresh())->test(Index::class)
            ->call('movePageTo', $about->ulid, $services->ulid)
            ->assertDispatched('bs-toast', text: __('entries.move_refused_subpage_held'));
        expect($about->fresh()->parent_id)->toBeNull()
            ->and(SiteRoute::query()->orderBy('id')->get(['entry_id', 'locale', 'path'])->toArray())->toBe($routes)
            ->and(Redirect::query()->count())->toBe($redirects);

        if ($case === 'no workflow') {
            // the right to publish settles it: the page and its live sub-page move, with their 301s
            $writer->givePermissionTo('entries.publish');
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            Livewire::actingAs($writer->fresh())->test(Index::class)->call('movePageTo', $about->ulid, $services->ulid);
            expect($about->fresh()->parent_id)->toBe($services->id)
                ->and(SiteRoute::query()->where('entry_id', $team->id)->value('path'))->toBe('/dich-vu/gioi-thieu/doi-ngu');
        }
    });
    $this->get('http://a.test/gioi-thieu/doi-ngu')->assertStatus($case === 'no workflow' ? 301 : 200);
})->with(['no workflow', 'a workflow on posts, which governs no page', 'a pending change on the sub-page']);

it('holds an editor save in a second language in the same change, the live languages untouched', function (): void {
    [$author, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($author, $editor): void {
        DB::connection('pgsql_admin')->table('site_locales')->insert([
            'site_id' => 1, 'locale' => 'en', 'is_default' => false, 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $news = pcCollection();
        $news->translations()->create(['locale' => 'en', 'name' => 'News', 'slug' => 'news']);
        $post = pcLivePost($news, $author, $editor);
        $post->translations()->create(['locale' => 'en', 'title' => 'Live in English', 'slug' => 'live-en', 'status' => 'draft', 'body' => ['blocks' => []]]);
        app(PublishEntry::class)->handle($post->fresh(), 'en', $editor);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);

        Livewire::actingAs($author)->test(Editor::class, ['ulid' => $post->ulid, 'locale' => 'en'])
            ->set('title', 'Edited in English')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('bs-toast', text: __('entries.chg.t_saved_wf'));

        $fresh = $post->fresh();
        expect(pcChange($post)?->content['locales']['en']['title'])->toBe('Edited in English')
            ->and($fresh->status)->toBe('published')
            ->and($fresh->translationFor('en')->title)->toBe('Live in English')
            ->and($fresh->translationFor('vi')->title)->toBe('Bản đang đăng');
    });
});

it('refuses a held save that adds or removes one of the post’s other categories', function (string $edit): void {
    [$author, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($author, $editor, $edit): void {
        $root = pcCollection();
        [$news, $events] = array_map(function (array $c) use ($root): Taxonomy {
            $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => $c[0], 'parent_id' => $root->id]);
            $category->translations()->create(['locale' => 'vi', 'name' => $c[1], 'slug' => $c[2]]);

            return $category;
        }, [[0, 'Bản tin', 'ban-tin'], [1, 'Sự kiện', 'su-kien']]);
        $post = pcLivePost($root, $author, $editor);
        $post->assignCategories($edit === 'adds' ? [$news->id] : [$news->id, $events->id]);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);
        $filed = $post->fresh()->taxonomies()->orderBy('taxonomies.id')->pluck('taxonomies.id')->all();

        Livewire::actingAs($author)->test(Editor::class, ['ulid' => $post->ulid])
            ->set('title', 'Bản sửa chờ duyệt')
            ->call('toggleCategory', $events->ulid)
            ->call('save')
            ->assertHasErrors('categoryIds')
            ->assertSee(__('entries.ed.change_cats'));
        expect(pcChange($post))->toBeNull()
            ->and($post->fresh()->taxonomies()->orderBy('taxonomies.id')->pluck('taxonomies.id')->all())->toBe($filed);
    });
})->with(['adds', 'removes']);

it('folds a change whose address in one language another item took meanwhile: that language keeps its address, the others move', function (): void {
    [$author, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($author, $editor): void {
        DB::connection('pgsql_admin')->table('site_locales')->insert([
            'site_id' => 1, 'locale' => 'en', 'is_default' => false, 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $news = pcCollection();
        $news->translations()->create(['locale' => 'en', 'name' => 'News', 'slug' => 'news']);
        $post = pcLivePost($news, $author, $editor);
        $post->translations()->create(['locale' => 'en', 'title' => 'Live', 'slug' => 'live-en', 'status' => 'draft', 'body' => ['blocks' => []]]);
        app(PublishEntry::class)->handle($post->fresh(), 'en', $editor);
        app(SaveEntryDraft::class)->handle($post->fresh(), $editor, 'en', ['slug' => 'new-en']);
        app(SaveEntryDraft::class)->handle($post->fresh(), $editor, 'vi', ['slug' => 'bai-moi']);

        // another post takes the English address the change would give, after the change was saved
        $other = pcLivePost($news, $author, $editor, 'bai-khac');
        $other->translations()->create(['locale' => 'en', 'title' => 'Other', 'slug' => 'new-en', 'status' => 'draft', 'body' => ['blocks' => []]]);
        app(PublishEntry::class)->handle($other->fresh(), 'en', $editor);
        $taken = SiteRoute::query()->where('entry_id', $other->id)->where('locale', 'en')->value('path');
        $before = SiteRoute::query()->where('entry_id', $post->id)->where('locale', 'en')->value('path');

        app(TransitionEntryStatus::class)->handle($post->fresh(), EntryStatus::Draft, $editor);

        $fresh = $post->fresh();
        expect($fresh->status)->toBe('draft')
            ->and(pcChange($post))->toBeNull()
            ->and($fresh->translationFor('en')->slug)->toBe('new-en')
            ->and(SiteRoute::query()->where('entry_id', $post->id)->where('locale', 'en')->value('path'))->toBe($before)
            ->and(SiteRoute::query()->where('entry_id', $other->id)->where('locale', 'en')->value('path'))->toBe($taken)
            ->and(SiteRoute::query()->where('entry_id', $post->id)->where('locale', 'vi')->value('path'))->toBe('/tin-tuc/bai-moi');
    });
});

it('puts a cover picked in the shared «Chọn ảnh» dialog into the change of a held post, the live cover untouched', function (): void {
    [$author, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($author, $editor): void {
        $author->givePermissionTo('media.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $post = pcLivePost(pcCollection(), $author, $editor);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);
        $cover = Media::query()->create([
            'collection' => 'library', 'slug' => 'bia-cho-duyet', 'hash' => str_repeat('8', 64),
            'storage_key' => 'a/library/bia-cho-duyet.png', 'disk' => 'r2', 'mime' => 'image/png', 'size' => 1024,
        ]);
        $liveMeta = $post->fresh()->meta;

        Livewire::actingAs($author->fresh())->test(Editor::class, ['ulid' => $post->ulid])
            ->call('openCoverPicker')->call('togglePickerItem', $cover->id)->call('insertPicked')
            ->assertSet('coverUlid', $cover->ulid)
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('bs-toast', text: __('entries.chg.t_saved_wf'));

        // named by its row in the change, no old key beside it
        expect(data_get(pcChange($post)?->content, 'shared.cover_media_id'))->toBe($cover->id)
            ->and(data_get(pcChange($post)?->content, 'shared.meta.image_url'))->toBeNull()
            ->and($post->fresh()->meta)->toBe($liveMeta)
            ->and($post->fresh()->cover_media_id)->toBeNull();
    });
});

it('opens and compares a held change that names its cover by id, with no old key, and none when that file is in the Trash', function (): void {
    [$author, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($author, $editor): void {
        $post = pcLivePost(pcCollection(), $author, $editor);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);
        $cover = Media::query()->create([
            'collection' => 'library', 'slug' => 'bia-theo-ma', 'hash' => str_repeat('5', 64),
            'storage_key' => 'a/library/bia-theo-ma.png', 'disk' => 'r2', 'mime' => 'image/png', 'size' => 1024,
        ]);
        Livewire::actingAs($author->fresh())->test(Editor::class, ['ulid' => $post->ulid])
            ->set('title', 'Bản sửa chờ duyệt')->call('save')->assertHasNoErrors();
        // the change names the cover by id only (as converted covers will be); the live post has none
        $change = pcChange($post);
        $content = $change->content;
        $content['shared']['cover_media_id'] = $cover->id;
        unset($content['shared']['meta']['image_url']);
        $change->forceFill(['content' => $content])->saveQuietly();
        $coverRow = fn ($editor) => collect($editor->viewData('changeBand')['diff']['rows'])->firstWhere('label', __('entries.chg.f_cover'));

        $open = Livewire::actingAs($author->fresh())->test(Editor::class, ['ulid' => $post->ulid])
            ->assertSet('coverUlid', $cover->ulid);
        expect($coverRow($open))->toMatchArray(['now' => __('entries.chg.cover_other'), 'seen' => __('entries.chg.diff_empty')]);

        // a file in the Trash is no cover, even beside an old key: nothing to compare with the live post
        $cover->delete();
        $content['shared']['meta']['image_url'] = 'a/library/anh-cu.png';
        $change->forceFill(['content' => $content])->saveQuietly();
        $open = Livewire::actingAs($author->fresh())->test(Editor::class, ['ulid' => $post->ulid])
            ->assertSet('coverUlid', '');
        expect($coverRow($open))->toBeNull();
    });
});

it('writes nothing when an unchanged older plain-text body is saved on a held post', function (): void {
    [$author, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($author, $editor): void {
        $post = pcLivePost(pcCollection(), $author, $editor);
        $post->translationFor('vi')->update(['body' => ['blocks' => [['type' => 'richtext', 'props' => ['heading' => 'Mở đầu', 'paragraphs' => ['Đoạn một.']]]]]]);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);

        Livewire::actingAs($author)->test(Editor::class, ['ulid' => $post->ulid])
            ->assertSet('converted', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('bs-toast', text: __('entries.chg.t_same'));
        expect(pcChange($post))->toBeNull();
    });
});

it('authorizes every change method on its own', function (string $method, array $args): void {
    [$author, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($author, $editor, $method, $args): void {
        $post = pcLivePost(pcCollection(), $author, $editor);
        app(SaveEntryDraft::class)->handle($post->fresh(), $author, 'vi', ['title' => 'Bản sửa']);
        $page = Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid]);
        $editor->syncRoles([]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $page->call($method, ...$args)->assertForbidden();
        expect(pcChange($post))->not->toBeNull();
    });
})->with([
    'applyChange' => ['applyChange', []],
    'discardChange' => ['discardChange', []],
    'askApplyChange' => ['askApplyChange', []],
    'askDiscardChange' => ['askDiscardChange', []],
    'openChangeDiff' => ['openChangeDiff', []],
    'openChangeReturn' => ['openChangeReturn', []],
    'returnChange' => ['returnChange', []],
    'submitChange' => ['submitChange', []],
    'moveChange' => ['moveChange', ['submit']],
    'reloadChange' => ['reloadChange', []],
    'confirmReloadChange' => ['confirmReloadChange', []],
]);

it('keeps an offline page under its parent through the editor’s «Trang cha» and a drag, when a live sub-page’s edits are held back', function (string $road): void {
    [, $editor] = pcCast();
    $writer = User::factory()->create();
    app(Tenancy::class)->enter(1, function () use ($writer, $editor, $road): void {
        grantRole($writer, 'writer', ['entries.view', 'entries.edit.any']);
        $about = pcLivePage($editor, 'gioi-thieu', null);
        pcLivePage($editor, 'doi-ngu', $about);
        $services = pcLivePage($editor, 'dich-vu', null);
        app(TransitionEntryStatus::class)->handle($about->fresh(), EntryStatus::Draft, $editor);
        $routes = SiteRoute::query()->orderBy('id')->get(['entry_id', 'locale', 'path'])->toArray();
        $why = __('entries.move_refused_subpage_held');

        if ($road === 'editor') {
            Livewire::actingAs($writer->fresh())->test(Editor::class, ['ulid' => $about->ulid])
                ->set('parentUlid', $services->ulid)
                ->call('save')
                ->assertHasErrors('parentUlid')
                ->assertDispatched('bs-toast', text: $why);
        } else {
            Livewire::actingAs($writer->fresh())->test(Index::class)
                ->call('movePage', $about->ulid, $services->ulid, 'child', null)
                ->assertDispatched('bs-toast', text: $why.' '.__('entries.move_tree_kept'));
        }
        expect($about->fresh()->parent_id)->toBeNull()
            ->and(SiteRoute::query()->orderBy('id')->get(['entry_id', 'locale', 'path'])->toArray())->toBe($routes);
    });
})->with(['editor', 'drag']);

it('judges a moved branch’s live sub-pages in the same number of queries however many there are', function (): void {
    [, $editor] = pcCast();
    $this->actingAs($editor);
    app(Tenancy::class)->enter(1, function () use ($editor): void {
        // flat page addresses (the default): the move rewrites no route, so only the checks are counted
        $page = fn (?Entry $parent = null, ?int $author = null): Entry => Entry::query()->create(['type' => 'page', 'status' => 'published', 'author_id' => $author, 'parent_id' => $parent?->id]);
        // the same move each time: a page, alone under its parent, to a parent with no sub-pages yet
        $move = function (int $subPages) use ($page, $editor): int {
            $top = $page($page());
            $target = $page();
            for ($i = 0; $i < $subPages; $i++) {
                $page($top, $i % 2 === 0 ? null : $editor->id);   // two authors among them
            }
            $queries = countQueries(fn () => app(MovePage::class)->into($top, $target));
            expect($top->fresh()->parent_id)->toBe($target->id);

            return $queries;
        };
        $move(2);   // the member's rights are read once, before counting

        expect($move(12))->toBe($move(2));
    });
});

it('asks the right to publish of each author among the sub-pages, for a member who edits only their own', function (string $case): void {
    $writer = User::factory()->create();
    $other = User::factory()->create();
    $this->actingAs($writer);
    app(Tenancy::class)->enter(1, function () use ($writer, $other, $case): void {
        grantRole($writer, 'own-publisher', ['entries.view', 'entries.edit.own', 'entries.publish']);
        $about = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $writer->id]);
        $target = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $writer->id]);
        Entry::query()->create(['type' => 'page', 'status' => 'published', 'author_id' => $writer->id, 'parent_id' => $about->id]);
        Entry::query()->create(['type' => 'page', 'status' => 'published', 'author_id' => $case === 'all theirs' ? $writer->id : $other->id, 'parent_id' => $about->id]);

        if ($case === 'all theirs') {
            app(MovePage::class)->into($about, $target);
            expect($about->fresh()->parent_id)->toBe($target->id);

            return;
        }
        expect(fn () => app(MovePage::class)->into($about, $target))
            ->toThrow(fn (PageMoveRejectedException $e) => expect($e->reason)->toBe('subpage_held'));
        expect($about->fresh()->parent_id)->toBeNull();
    });
})->with(['all theirs', 'one by someone else']);

/** «Giới thiệu» with «Đội ngũ» under it, both live in Vietnamese and English, then English taken off the page with a new link. */
function pcPagesWithEnglishOff(User $editor): array
{
    DB::connection('pgsql_admin')->table('site_locales')->insert([
        'site_id' => 1, 'locale' => 'en', 'is_default' => false, 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $about = pcLivePage($editor, 'gioi-thieu', null);
    $team = pcLivePage($editor, 'doi-ngu', $about);
    foreach ([[$about, 'about'], [$team, 'team']] as [$page, $slug]) {
        $page->translations()->create(['locale' => 'en', 'title' => ucfirst($slug), 'slug' => $slug, 'status' => 'draft', 'body' => ['blocks' => []]]);
        app(PublishEntry::class)->handle($page->fresh(), 'en', $editor);
    }
    // English leaves the page (the page stays live in Vietnamese); its link changes while it is off
    $about->fresh()->translationFor('en')->update(['status' => 'draft', 'slug' => 'about-us']);

    return [$about->fresh(), $team->fresh()];
}

it('moves a live page’s sub-pages in a language that goes live again on its own', function (string $road): void {
    [, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($editor, $road): void {
        [$about, $team] = pcPagesWithEnglishOff($editor);
        $teamVi = SiteRoute::query()->where('entry_id', $team->id)->where('locale', 'vi')->value('path');
        $before = SiteRoute::query()->where('entry_id', $team->id)->where('locale', 'en')->value('path');
        expect($before)->toEndWith('/about/team');

        if ($road === 'publish') {
            app(PublishEntry::class)->handle($about, 'en', $editor);
        } else {
            // a change in English applied by a publisher: the page's English goes live with it
            app(SaveEntryDraft::class)->handle($about, $editor, 'en', ['title' => 'About us']);
            app(ApplyEntryDraft::class)->handle($about->fresh(), $editor, pcChange($about)->version());
        }

        $after = SiteRoute::query()->where('entry_id', $team->id)->where('locale', 'en')->value('path');
        expect($about->fresh()->translationFor('en')->status)->toBe('published')
            ->and($after)->toEndWith('/about-us/team')
            ->and(Redirect::query()->where('locale', 'en')->where('from_path', $before)->where('to_path', $after)->exists())->toBeTrue()
            ->and(SiteRoute::query()->where('entry_id', $team->id)->where('locale', 'vi')->value('path'))->toBe($teamVi);
    });
})->with(['publish', 'apply']);

it('saves a body-only edit of a held post into its change, from an older or a formatted body', function (string $body): void {
    [$author, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($author, $editor, $body): void {
        $post = pcLivePost(pcCollection(), $author, $editor);
        $post->translationFor('vi')->update(['body' => ['blocks' => [['type' => 'richtext', 'props' => ['heading' => 'Mở đầu', 'paragraphs' => ['Đoạn một.']]]]]]);
        if ($body === 'formatted') {
            // opened and saved once in the rich editor by someone who may publish: stored as it writes it
            Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid])->call('save')->assertHasNoErrors();
        }
        $live = $post->fresh()->translationFor('vi')->body;
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);

        $page = Livewire::actingAs($author)->test(Editor::class, ['ulid' => $post->ulid])
            ->assertSet('converted', $body === 'older');
        $html = (string) $page->get('bodyHtml');
        expect($html)->toContain('Đoạn một.');
        $page->set('bodyHtml', str_replace('Đoạn một.', 'Đoạn một đã sửa.', $html))
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('bs-toast', text: __('entries.chg.t_saved_wf'));

        expect(json_encode(pcChange($post)?->content['locales']['vi']['body'] ?? null, JSON_UNESCAPED_UNICODE))->toContain('Đoạn một đã sửa.')
            ->and($post->fresh()->translationFor('vi')->body)->toBe($live);
    });
})->with(['older', 'formatted']);

it('rolls a scheduled page back when its sub-page’s new address is taken, nothing moved', function (): void {
    [, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($editor): void {
        $about = pcLivePage($editor, 'gioi-thieu', null);
        $team = pcLivePage($editor, 'doi-ngu', $about);
        app(SaveEntryDraft::class)->handle($about, $editor, 'vi', ['slug' => 've-chung-toi']);
        app(TransitionEntryStatus::class)->handle($about->fresh(), EntryStatus::Draft, $editor);
        $other = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $editor->id]);
        SiteRoute::query()->create(['locale' => 'vi', 'path' => '/ve-chung-toi/doi-ngu', 'entry_id' => $other->id]);
        $about->fresh()->update(['status' => 'scheduled', 'published_at' => now()->subMinute()]);
        $redirects = Redirect::query()->count();

        expect(fn () => app(PublishScheduledEntry::class)->handle($about->fresh()))
            ->toThrow(fn (RouteConflictException $e) => expect($e->path)->toBe('/ve-chung-toi/doi-ngu')->and($e->subPageOf($about->id))->toBeTrue());
        expect($about->fresh()->status)->toBe('scheduled')
            ->and(SiteRoute::query()->where('entry_id', $team->id)->pluck('path')->all())->toBe(['/gioi-thieu/doi-ngu'])
            ->and(Redirect::query()->count())->toBe($redirects);
    });
});

// A page never resolves an approval workflow (EntryWorkflowResolver), so «Cập nhật website» is the move that meets it
it('says a page’s change that would move a sub-page to a taken address in «Chưa cập nhật được website», naming the address, nothing moved', function (): void {
    [, $editor] = pcCast();
    $writer = User::factory()->create();
    app(Tenancy::class)->enter(1, function () use ($writer, $editor): void {
        grantRole($writer, 'writer', ['entries.view', 'entries.edit.any']);
        $about = pcLivePage($editor, 'gioi-thieu', null);
        $team = pcLivePage($editor, 'doi-ngu', $about);
        Livewire::actingAs($writer->fresh())->test(Editor::class, ['ulid' => $about->ulid])
            ->set('slug', 've-chung-toi')->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('bs-toast', text: __('entries.chg.t_saved'));
        $other = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $editor->id]);
        SiteRoute::query()->create(['locale' => 'vi', 'path' => '/ve-chung-toi/doi-ngu', 'entry_id' => $other->id]);
        $redirects = Redirect::query()->count();
        $before = pcStored($about);

        Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $about->ulid])
            ->call('askApplyChange')
            ->call('applyChange')
            ->assertHasNoErrors()   // neither the line above the form nor the link field: the page's own link is free
            ->assertSet('changeApplyAsk', false)
            ->assertSet('refusal', [
                'title' => __('entries.refuse.t_apply'),
                'text' => __('entries.change_err_subpage_path_taken', ['path' => '/ve-chung-toi/doi-ngu']),
                'reload' => false,
            ])
            ->assertSeeHtml('data-entry-refusal')
            ->assertSee(__('entries.refuse.t_apply'))
            ->assertSee('/ve-chung-toi/doi-ngu')
            ->assertDontSeeHtml('data-refusal-reload');

        expect(pcStored($about))->toBe($before)
            ->and($about->fresh()->translationFor('vi')->slug)->toBe('gioi-thieu')
            ->and(SiteRoute::query()->where('entry_id', $about->id)->pluck('path')->all())->toBe(['/gioi-thieu'])
            ->and(SiteRoute::query()->where('entry_id', $team->id)->pluck('path')->all())->toBe(['/gioi-thieu/doi-ngu'])
            ->and(Redirect::query()->count())->toBe($redirects);
    });
});

it('names a sub-page’s taken address in another language as visitors see it, with that language when the editor is open in another', function (string $open, string $clash): void {
    [, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($editor, $open, $clash): void {
        DB::connection('pgsql_admin')->table('site_locales')->insert([
            'site_id' => 1, 'locale' => 'en', 'is_default' => false, 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $about = pcLivePage($editor, 'gioi-thieu', null);
        $team = pcLivePage($editor, 'doi-ngu', $about);
        foreach ([[$about, 'about'], [$team, 'team']] as [$page, $slug]) {
            $page->translations()->create(['locale' => 'en', 'title' => ucfirst($slug), 'slug' => $slug, 'status' => 'draft', 'body' => ['blocks' => []]]);
            app(PublishEntry::class)->handle($page->fresh(), 'en', $editor);
        }
        // one change in both languages: the Vietnamese edit moves nothing, the English link moves «Team»
        app(SaveEntryDraft::class)->handle($about->fresh(), $editor, 'vi', ['title' => 'Giới thiệu công ty']);
        app(SaveEntryDraft::class)->handle($about->fresh(), $editor, 'en', ['slug' => 'about-us']);
        app(SaveEntryDraft::class)->handle($about->fresh(), $editor, 'vi', ['slug' => 've-chung-toi']);
        $other = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $editor->id]);
        SiteRoute::query()->create(['locale' => $clash, 'path' => $clash === 'en' ? '/about-us/team' : '/ve-chung-toi/doi-ngu', 'entry_id' => $other->id]);
        $url = $clash === 'en' ? '/en/about-us/team' : '/ve-chung-toi/doi-ngu';
        $routes = SiteRoute::query()->whereIn('entry_id', [$about->id, $team->id])->orderBy('id')->pluck('path')->all();
        $redirects = Redirect::query()->count();
        $before = pcStored($about);

        $text = $open === $clash
            ? __('entries.change_err_subpage_path_taken', ['path' => $url])
            : __('entries.change_err_subpage_path_taken_lang', ['path' => $url, 'lang' => __($clash === 'en' ? 'entries.language_en' : 'entries.language_vi')]);
        Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $about->ulid] + ($open === 'en' ? ['locale' => 'en'] : []))
            ->call('askApplyChange')
            ->call('applyChange')
            ->assertHasNoErrors()
            ->assertSet('refusal', ['title' => __('entries.refuse.t_apply'), 'text' => $text, 'reload' => false])
            ->assertSee($url);

        expect($text)->toContain($url)
            ->and(str_contains($text, (string) __($clash === 'en' ? 'entries.language_en' : 'entries.language_vi')))->toBe($open !== $clash)
            ->and(str_contains($text, '/en/'))->toBe($clash === 'en')
            ->and(pcStored($about))->toBe($before)
            ->and(SiteRoute::query()->whereIn('entry_id', [$about->id, $team->id])->orderBy('id')->pluck('path')->all())->toBe($routes)
            ->and(Redirect::query()->count())->toBe($redirects);
    });
})->with([
    'clash in English, open in Vietnamese' => ['vi', 'en'],
    'clash in English, open in English' => ['en', 'en'],
    'clash in Vietnamese, open in English' => ['en', 'vi'],
]);

it('says which sub-page address is taken when a page cannot go live again, never blaming its own free link', function (string $road): void {
    [, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($editor, $road): void {
        $about = pcLivePage($editor, 'gioi-thieu', null);
        $team = pcLivePage($editor, 'doi-ngu', $about);
        app(SaveEntryDraft::class)->handle($about, $editor, 'vi', ['slug' => 've-chung-toi']);
        app(TransitionEntryStatus::class)->handle($about->fresh(), EntryStatus::Draft, $editor);
        $other = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $editor->id]);
        SiteRoute::query()->create(['locale' => 'vi', 'path' => '/ve-chung-toi/doi-ngu', 'entry_id' => $other->id]);
        $said = __('entries.subpage_path_taken', ['path' => '/ve-chung-toi/doi-ngu']);

        match ($road) {
            'editor' => expect(Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $about->ulid])
                ->call('publish')
                ->assertHasNoErrors('slug')
                ->assertHasErrors('workflow')
                ->errors()->first('workflow'))->toBe($said),
            'row menu' => Livewire::actingAs($editor)->test(Index::class)
                ->call('changeStatus', $about->ulid, 'published')
                ->assertDispatched('bs-toast', text: $said),
            'bulk' => Livewire::actingAs($editor)->test(Index::class)
                ->set('selected', [$about->ulid])
                ->call('bulkPublish')
                ->assertDispatched('bs-toast', text: __('entries.bulk_published', ['count' => 0]).' '.__('entries.bulk_path_taken', ['count' => 1])),
        };

        expect($about->fresh()->status)->toBe('draft')
            ->and(SiteRoute::query()->where('entry_id', $team->id)->pluck('path')->all())->toBe(['/gioi-thieu/doi-ngu']);
    });
})->with(['editor', 'row menu', 'bulk']);

it('says which sub-page address is taken when a live page’s link is changed in the list or an older version is restored, nothing moved', function (string $road): void {
    [, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($editor, $road): void {
        $about = pcLivePage($editor, 'gioi-thieu', null);
        $team = pcLivePage($editor, 'doi-ngu', $about);
        $other = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $editor->id]);

        if ($road === 'list link') {
            SiteRoute::query()->create(['locale' => 'vi', 'path' => '/ve-chung-toi/doi-ngu', 'entry_id' => $other->id]);
            Livewire::actingAs($editor)->test(Index::class)
                ->call('saveSlug', $about->ulid, 've-chung-toi')
                ->assertDispatched('bs-toast', text: __('entries.subpage_path_taken', ['path' => '/ve-chung-toi/doi-ngu']));
            $kept = ['gioi-thieu', '/gioi-thieu', '/gioi-thieu/doi-ngu'];
        } else {
            // the page went to a new link, its sub-page with it; the sub-page's old address is taken since
            $first = EntryRevision::query()->where('entry_id', $about->id)->where('locale', 'vi')->orderBy('id')->firstOrFail();
            Livewire::actingAs($editor)->test(Index::class)->call('saveSlug', $about->ulid, 've-chung-toi');
            Redirect::query()->where('from_path', '/gioi-thieu/doi-ngu')->delete();
            SiteRoute::query()->create(['locale' => 'vi', 'path' => '/gioi-thieu/doi-ngu', 'entry_id' => $other->id]);
            $refused = null;
            try {
                app(RestoreEntryRevision::class)->handle($first, $editor);
            } catch (RevisionRestoreRejectedException $e) {
                $refused = $e;
            }
            expect($refused?->userMessage())->toBe(__('entries.revision_err_subpage_path_taken', ['path' => '/gioi-thieu/doi-ngu']));
            $kept = ['ve-chung-toi', '/ve-chung-toi', '/ve-chung-toi/doi-ngu'];
        }

        expect($about->fresh()->translationFor('vi')->slug)->toBe($kept[0])
            ->and(SiteRoute::query()->where('entry_id', $about->id)->pluck('path')->all())->toBe([$kept[1]])
            ->and(SiteRoute::query()->where('entry_id', $team->id)->pluck('path')->all())->toBe([$kept[2]]);
    });
})->with(['list link', 'older version']);

/*
 * «Chưa làm được» (board 07): a save, a send, «Cập nhật website» / «Duyệt đăng» or «Bỏ thay đổi» of the
 * pending change refused opens one dialog — the move as its title, the reason in plain words, «Đóng»
 * (and «Tải lại» when the change moved on elsewhere) — and writes nothing.
 */

/** The pending change as stored, to compare before and after a refusal. */
function pcStored(Entry $entry): array
{
    $change = pcChange($entry);

    return [
        'change' => $change === null ? null : [$change->version(), $change->workflow_state],
        'live' => $entry->fresh()->translationFor('vi')->title,
        'revisions' => pcRevisions($entry),
        'logs' => WorkflowLog::query()->where('entry_id', $entry->id)->count(),
    ];
}

/** @return array{title: string, text: string, reload: bool} */
function pcRefusal(string $move, string $reason): array
{
    return ['title' => __('entries.refuse.t_'.$move), 'text' => __('entries.change_err_'.$reason), 'reload' => $reason === 'change_stale'];
}

it('shows «Chưa sửa được» in the dialog when a sent change is saved, with only «Đóng», writing nothing', function (): void {
    [$author, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($author, $editor): void {
        $post = pcLivePost(pcCollection(), $author, $editor);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);
        Livewire::actingAs($author)->test(Editor::class, ['ulid' => $post->ulid])
            ->set('title', 'Bản đã gửi')->call('submitChange')->assertHasNoErrors();
        $before = pcStored($post);

        $form = Livewire::actingAs($author)->test(Editor::class, ['ulid' => $post->ulid])
            ->set('title', 'Gõ thêm sau khi gửi')->call('save')
            ->assertHasNoErrors()
            ->assertSet('refusal', pcRefusal('locked', 'change_locked'))
            ->assertSeeHtml('data-entry-refusal')
            ->assertSeeHtml('role="alertdialog"')
            ->assertSeeHtml('aria-describedby="entry-refusal-d"')
            ->assertSee(__('entries.refuse.t_locked'))
            ->assertSee(__('entries.change_err_change_locked'))
            ->assertSeeHtml('data-refusal-close')
            ->assertDontSeeHtml('data-refusal-reload');
        expect(pcStored($post))->toBe($before)
            ->and(pcChange($post)->content['locales']['vi']['title'])->toBe('Bản đã gửi');

        // «Đóng»: the dialog goes, the typed text stays
        $form->call('closeRefusal')
            ->assertSet('refusal', null)
            ->assertDontSeeHtml('data-entry-refusal')
            ->assertSet('title', 'Gõ thêm sau khi gửi');
        expect(pcStored($post))->toBe($before);

        // «Gửi duyệt thay đổi» of the sent change: the same «Chưa sửa được»
        $form->call('submitChange')
            ->assertHasNoErrors()
            ->assertSet('refusal', pcRefusal('locked', 'change_locked'));
        expect(pcStored($post))->toBe($before);
    });
});

it('titles a stale tab’s refused save or send by its move, and «Tải lại» asks, then loads the newer change into the form', function (string $method, string $move): void {
    [$author, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($author, $editor, $method, $move): void {
        $post = pcLivePost(pcCollection(), $author, $editor);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);
        $old = Livewire::actingAs($author)->test(Editor::class, ['ulid' => $post->ulid]);
        app(SaveEntryDraft::class)->handle($post->fresh(), $editor, 'vi', ['title' => 'Bản của tab khác']);
        $before = pcStored($post);

        $old->set('title', 'Bản của tab cũ')->call($method)
            ->assertHasNoErrors()
            ->assertSet('refusal', pcRefusal($move, 'change_stale'))
            ->assertSee(__('entries.refuse.t_'.$move))
            ->assertSeeHtml('data-refusal-reload')
            ->assertSee(__('entries.refuse.reload'));
        expect(pcStored($post))->toBe($before)
            ->and(pcChange($post)->content['locales']['vi']['title'])->toBe('Bản của tab khác');

        // «Tải lại» with typed text: «Tải bản mới nhất?» names the field it replaces; nothing loaded or written yet
        $old->call('reloadChange')
            ->assertSet('refusal', null)
            ->assertSet('changeReloadAsk', true)
            ->assertSet('reloadReplaces', __('entries.ed.title_post'))
            ->assertSet('title', 'Bản của tab cũ')
            ->assertSeeHtml('data-entry-reload-ask')
            ->assertSee(__('entries.refuse.reload_title'))
            ->assertSee(__('entries.refuse.reload_line', ['fields' => __('entries.ed.title_post')]))
            ->assertSee(__('entries.refuse.reload_ok'))
            ->assertSee(__('entries.refuse.reload_stay'))
            ->assertNotDispatched('bs-toast');
        expect(pcStored($post))->toBe($before);

        // «Tải bản mới nhất»: the form shows the other member's text and has seen exactly that version
        $old->call('confirmReloadChange')
            ->assertSet('changeReloadAsk', false)
            ->assertSet('reloadReplaces', null)
            ->assertSet('title', 'Bản của tab khác')
            ->assertSet('changeSeen', pcChange($post)->version())
            ->assertDontSeeHtml('data-entry-reload-ask')
            ->assertDispatched('admin-editor-saved')
            ->assertDispatched('bs-toast', text: __('entries.refuse.reloaded'));
        expect(pcStored($post))->toBe($before);

        // the next save goes into the change as it stands now: the other member's text is kept
        $old->set('excerpt', 'Thêm sau khi tải lại')->call('save')->assertHasNoErrors()->assertSet('refusal', null);
        expect(pcChange($post)->content['locales']['vi'])->toMatchArray(['title' => 'Bản của tab khác', 'excerpt' => 'Thêm sau khi tải lại']);
    });
})->with([
    'Lưu thay đổi' => ['save', 'save'],
    'Gửi duyệt thay đổi' => ['submitChange', 'submit'],
]);

it('keeps the typed text on «Ở lại», and loads nothing unless a refusal offered «Tải lại»', function (): void {
    [$author, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($author, $editor): void {
        $post = pcLivePost(pcCollection(), $author, $editor);
        $old = Livewire::actingAs($author)->test(Editor::class, ['ulid' => $post->ulid]);
        app(SaveEntryDraft::class)->handle($post->fresh(), $editor, 'vi', ['title' => 'Bản của tab khác']);
        $seen = $old->get('changeSeen');

        // no refusal on screen: «Tải lại» and the question's answer do nothing, even with the question opened by the browser
        $old->set('title', 'Bản của tab cũ')
            ->call('reloadChange')
            ->assertSet('changeReloadAsk', false)->assertSet('reloadReplaces', null)
            ->assertSet('changeSeen', $seen)->assertSet('title', 'Bản của tab cũ')
            ->assertNotDispatched('bs-toast')
            ->set('changeReloadAsk', true)->call('confirmReloadChange')
            ->assertSet('changeSeen', $seen)->assertSet('title', 'Bản của tab cũ')
            ->assertNotDispatched('bs-toast');

        // refused as stale, «Tải lại», «Ở lại»: the typed text stays, nothing is loaded, and the save is still refused
        $old->call('save')->assertSet('refusal', pcRefusal('save', 'change_stale'))
            ->call('reloadChange')->assertSet('changeReloadAsk', true)
            ->call('keepTypedText')
            ->assertSet('changeReloadAsk', false)->assertSet('reloadReplaces', null)
            ->assertSet('title', 'Bản của tab cũ')->assertSet('changeSeen', $seen)
            ->assertDontSeeHtml('data-entry-reload-ask')
            ->call('confirmReloadChange')
            ->assertSet('title', 'Bản của tab cũ')->assertSet('changeSeen', $seen);
        $old->call('save')->assertSet('refusal', pcRefusal('save', 'change_stale'));
        expect(pcChange($post)->content['locales']['vi']['title'])->toBe('Bản của tab khác');

        // every field with typed edits is named, once each, in the form's order
        $old->set('excerpt', 'Mô tả gõ thêm')->call('save')->call('reloadChange')
            ->assertSet('reloadReplaces', __('entries.ed.title_post').', '.__('admin.seo.excerpt_label'));

        // a picture, category, tag or status is not typed text: one plain group name, never a field's own name
        $old->call('keepTypedText')->set('coverAlt', 'Mô tả ảnh')->call('save')->call('reloadChange')
            ->assertSet('reloadReplaces', __('entries.ed.title_post').', '.__('admin.seo.excerpt_label').', '.__('entries.refuse.reload_choices'))
            ->assertDontSee(__('entries.ed.card_publish').' sẽ');
    });
});

it('does nothing on «Tải lại» while a refusal that offers no reload is showing', function (): void {
    [$author, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($author, $editor): void {
        $post = pcLivePost(pcCollection(), $author, $editor);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);
        Livewire::actingAs($author)->test(Editor::class, ['ulid' => $post->ulid])
            ->set('title', 'Bản đã gửi')->call('submitChange')->assertHasNoErrors();
        $before = pcStored($post);

        $form = Livewire::actingAs($author)->test(Editor::class, ['ulid' => $post->ulid])
            ->set('title', 'Gõ thêm sau khi gửi')->call('save')
            ->assertSet('refusal', pcRefusal('locked', 'change_locked'));
        $seen = $form->get('changeSeen');
        $form->call('reloadChange')
            ->assertSet('refusal', pcRefusal('locked', 'change_locked'))
            ->assertSet('changeReloadAsk', false)->assertSet('reloadReplaces', null)
            ->assertSet('changeSeen', $seen)->assertSet('title', 'Gõ thêm sau khi gửi')
            ->assertSeeHtml('data-entry-refusal')->assertDontSeeHtml('data-entry-reload-ask')
            ->assertNotDispatched('bs-toast')->assertNotDispatched('admin-editor-saved');
        expect(pcStored($post))->toBe($before);
    });
});

it('hands the reloaded text to the rich text editor and names «Nội dung» in the question on a prose post', function (): void {
    [$author, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($author, $editor): void {
        $prose = static fn (string $html): array => ['blocks' => [['type' => 'richtext_html', 'props' => ['html' => $html]]]];
        $post = pcLivePost(pcCollection(), $author, $editor, 'bai-van-ban', $prose('<p>Bản đang đăng</p>'));
        $old = Livewire::actingAs($author)->test(Editor::class, ['ulid' => $post->ulid])->assertSet('prose', true);
        app(SaveEntryDraft::class)->handle($post->fresh(), $editor, 'vi', ['body' => $prose('<p>Nội dung của tab khác</p>')]);

        $old->set('bodyHtml', '<p>Tôi gõ ở tab cũ</p>')->call('save')
            ->assertSet('refusal', pcRefusal('save', 'change_stale'))
            ->call('reloadChange')
            ->assertSet('reloadReplaces', __('entries.ed.body_label'))
            ->assertSee(__('entries.refuse.reload_line', ['fields' => __('entries.ed.body_label')]))
            ->assertNotDispatched('admin-editor-set-html')
            ->call('confirmReloadChange')
            ->assertSet('bodyHtml', '<p>Nội dung của tab khác</p>')
            ->assertDispatched('admin-editor-set-html', target: 'bodyHtml', html: '<p>Nội dung của tab khác</p>');
    });
});

it('says what happened on the toast: the newest change loaded, or the change gone and the website version shown', function (): void {
    [$author, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($author, $editor): void {
        $post = pcLivePost(pcCollection(), $author, $editor);
        app(SaveEntryDraft::class)->handle($post->fresh(), $author, 'vi', ['title' => 'Bản đã lưu']);
        $old = Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid])->assertSet('title', 'Bản đã lưu');
        app(DiscardEntryDraft::class)->handle($post->fresh(), $author, pcChange($post)->version());
        expect(pcChange($post))->toBeNull();

        $old->call('applyChange')
            ->assertSet('refusal', pcRefusal('apply', 'change_stale'))
            ->call('reloadChange')
            ->assertSet('title', 'Bản đang đăng')->assertSet('changeSeen', null)
            ->assertDispatched('bs-toast', text: __('entries.refuse.reloaded_gone'))
            ->assertNotDispatched('bs-toast', text: __('entries.refuse.reloaded'));
    });
});

it('approves, updates the website with or discards, after «Tải lại», the change the form now shows', function (string $road): void {
    [$author, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($author, $editor, $road): void {
        $post = pcLivePost(pcCollection(), $author, $editor);
        if ($road === 'approve') {
            app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);
            Livewire::actingAs($author)->test(Editor::class, ['ulid' => $post->ulid])
                ->set('title', 'Bản đã gửi')->call('submitChange')->assertHasNoErrors();
        } else {
            app(SaveEntryDraft::class)->handle($post->fresh(), $author, 'vi', ['title' => 'Bản đã gửi']);
        }
        $form = Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid])->assertSet('title', 'Bản đã gửi');
        $form->call($road === 'discard' ? 'askDiscardChange' : 'askApplyChange');
        // edited elsewhere after this form read it
        $change = pcChange($post);
        $content = $change->content;
        $content['locales']['vi']['title'] = 'Bản chưa xem';
        $change->update(['content' => $content]);

        $form->call($road === 'discard' ? 'discardChange' : 'applyChange')
            ->assertSet('refusal', pcRefusal($road, 'change_stale'));
        // nothing typed: «Tải lại» loads at once, without asking
        $form->call('reloadChange')
            ->assertSet('changeReloadAsk', false)
            ->assertSet('refusal', null)
            ->assertSet('title', 'Bản chưa xem')
            ->assertSet('changeSeen', pcChange($post)->version())
            ->assertDispatched('bs-toast', text: __('entries.refuse.reloaded'));

        if ($road === 'discard') {
            $form->call('askDiscardChange')->assertSet('changeDiscardAsk', true)->call('discardChange')->assertSet('refusal', null);
            expect(pcChange($post))->toBeNull()
                ->and($post->fresh()->translationFor('vi')->title)->toBe('Bản đang đăng');

            return;
        }
        $form->call('askApplyChange')->assertSet('changeApplyAsk', true)->call('applyChange')->assertSet('refusal', null);
        expect(pcChange($post))->toBeNull()
            ->and($post->fresh()->translationFor('vi')->title)->toBe('Bản chưa xem');
    });
})->with(['apply', 'approve', 'discard']);

it('titles a stale tab’s refused «Cập nhật» «Chưa cập nhật được website», with «Tải lại»', function (): void {
    [$author, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($author, $editor): void {
        $post = pcLivePost(pcCollection(), $author, $editor);
        // opened by a member who may publish, with no change yet: the bar's «Cập nhật» writes live
        $tab = Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid]);
        app(SaveEntryDraft::class)->handle($post->fresh(), $author, 'vi', ['title' => 'Bản của người viết']);
        $before = pcStored($post);

        $tab->set('title', 'Bản của tab cũ')->call('publish')
            ->assertSet('refusal', pcRefusal('apply', 'change_stale'))
            ->assertSeeHtml('data-refusal-reload');
        expect(pcStored($post))->toBe($before);
    });
});

it('sends focus back to something shown when «Chưa làm được» or «Tải bản mới nhất?» leaves', function (): void {
    [$author, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($author, $editor): void {
        $post = pcLivePost(pcCollection(), $author, $editor);
        $old = Livewire::actingAs($author)->test(Editor::class, ['ulid' => $post->ulid]);
        app(SaveEntryDraft::class)->handle($post->fresh(), $editor, 'vi', ['title' => 'Bản của tab khác']);
        // the button pressed may be gone or hidden: the band's first move, else the bar's main button, else its «⋯»
        $fallback = '"[data-change-band] [data-change-act]:not([aria-disabled])", "[data-guide=main]", "[data-guide=main-b]", "[data-guide=more]"';

        $old->set('title', 'Bản của tab cũ')->call('save')
            ->assertSeeHtml('data-entry-refusal')->assertSeeHtml(e($fallback))
            ->call('reloadChange')
            ->assertSeeHtml('data-entry-reload-ask')->assertSeeHtml(e($fallback));
    });
});

/*
 * The other reasons «Chưa làm được» says, each forced through the real editor and the real Cms action.
 * A change made to the website while the dialog's move is on its way (the post taken off it, a workflow
 * switched on) lands between the form's read and the action's lock: the action is resolved after it.
 */
function pcMeanwhile(string $action, Closure $meanwhile): void
{
    $real = app($action);
    app()->bind($action, static function () use ($real, $meanwhile): object {
        $meanwhile();

        return $real;
    });
}

it('says each other refused reason in the dialog, titled by the move, writing nothing', function (string $reason): void {
    [$author, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($author, $editor, $reason): void {
        $category = pcCollection();
        $post = pcLivePost($category, $author, $editor);
        $move = 'apply';
        $edit = static function (EntryDraft $change, Closure $how): void {
            $content = $change->content;
            $change->update(['content' => $how($content)]);
        };
        switch ($reason) {
            case 'locale_disabled':
                DB::connection('pgsql_admin')->table('site_locales')->insert([
                    'site_id' => 1, 'locale' => 'en', 'is_default' => false, 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
                app(SaveEntryDraft::class)->handle($post->fresh(), $author, 'en', ['title' => 'English edit', 'slug' => 'english-edit']);
                // English switched off on the site after the change was written in it
                DB::connection('pgsql_admin')->table('site_locales')->where('site_id', 1)->where('locale', 'en')->update(['enabled' => false, 'is_public' => false]);
                app(SiteLocale::class)->forget();
                break;
            case 'content_invalid':
                app(SaveEntryDraft::class)->handle($post->fresh(), $author, 'vi', ['title' => 'Bản sửa']);
                // a change no longer passing the editor's checks (its title gone)
                $edit(pcChange($post), static function (array $content): array {
                    $content['locales']['vi']['title'] = '';

                    return $content;
                });
                break;
            case 'category_governed':
                $move = 'approve';
                app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);
                $other = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 1]);
                $other->translations()->create(['locale' => 'vi', 'name' => 'Dự án', 'slug' => 'du-an']);
                ContentSection::query()->create([
                    'key' => 'projects', 'label' => 'Dự án', 'entry_type' => 'post', 'taxonomy_id' => $other->id,
                    'permission_prefix' => 'entries', 'sort_order' => 2,
                ]);
                Livewire::actingAs($author)->test(Editor::class, ['ulid' => $post->ulid])
                    ->set('title', 'Bản đã gửi')->call('submitChange')->assertHasNoErrors();
                // the change files the post under a category outside the workflow's section
                $edit(pcChange($post), static function (array $content) use ($other): array {
                    $content['shared']['primary_category_id'] = $other->id;

                    return $content;
                });
                break;
            default:
                app(SaveEntryDraft::class)->handle($post->fresh(), $author, 'vi', ['title' => 'Bản sửa']);
        }
        $form = Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid])
            ->call('askApplyChange')->assertSet('changeApplyAsk', true);
        if ($reason === 'not_live') {
            // taken off the website meanwhile
            pcMeanwhile(ApplyEntryDraft::class, static fn () => Entry::query()->whereKey($post->id)->update(['status' => 'draft']));
        }
        if ($reason === 'workflow_governed') {
            // an approval workflow switched on meanwhile
            pcMeanwhile(ApplyEntryDraft::class, static fn () => app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true));
        }
        $before = pcStored($post);

        $form->call('applyChange')
            ->assertHasNoErrors('workflow')
            ->assertSet('refusal', pcRefusal($move, $reason))
            ->assertSeeHtml('data-entry-refusal')
            ->assertSee(__('entries.refuse.t_'.$move))
            ->assertDontSeeHtml('data-refusal-reload');
        expect(pcStored($post))->toBe($before)
            ->and($post->fresh()->translationFor('vi')->title)->toBe('Bản đang đăng');
    });
})->with(['not_live', 'content_invalid', 'category_governed', 'locale_disabled', 'workflow_governed']);

it('says a change’s taken address in the dialog on «Duyệt đăng» too, and keeps it under the link field', function (): void {
    [$author, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($author, $editor): void {
        $category = pcCollection();
        $post = pcLivePost($category, $author, $editor);
        pcLivePost($category, $author, $editor, 'bai-2');
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);
        // the address is judged when the change goes live (it may be free by then), not when it is saved
        Livewire::actingAs($author)->test(Editor::class, ['ulid' => $post->ulid])
            ->set('slug', 'bai-2')->call('submitChange')->assertHasNoErrors();
        $form = Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid])
            ->call('askApplyChange')->assertSet('changeApplyAsk', true);
        $before = pcStored($post);

        $form->call('applyChange')
            ->assertHasNoErrors('workflow')
            ->assertHasErrors(['slug' => __('entries.change_err_slug_taken')])
            ->assertSet('refusal', pcRefusal('approve', 'slug_taken'));
        expect(pcStored($post))->toBe($before)
            ->and($post->fresh()->translationFor('vi')->slug)->toBe('bai-1');
    });
});

it('takes a held save of a taken address into the change, no dialog, and says it when the change goes live', function (): void {
    [$author, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($author, $editor): void {
        $category = pcCollection();
        $post = pcLivePost($category, $author, $editor);
        pcLivePost($category, $author, $editor, 'bai-2');

        // a save never puts the change's address on the website, so SaveEntryDraft never refuses it as taken
        Livewire::actingAs($author)->test(Editor::class, ['ulid' => $post->ulid])
            ->set('slug', 'bai-2')->call('save')
            ->assertHasNoErrors()
            ->assertSet('refusal', null);
        expect(pcChange($post)->content['locales']['vi']['slug'])->toBe('bai-2');

        Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid])
            ->call('askApplyChange')->call('applyChange')
            ->assertHasErrors(['slug' => __('entries.change_err_slug_taken')])
            ->assertSet('refusal', pcRefusal('apply', 'slug_taken'));
        expect($post->fresh()->translationFor('vi')->slug)->toBe('bai-1');
    });
});

it('shows «Chưa cập nhật được website» / «Chưa duyệt đăng được» when the change moved on before it went live, the website untouched', function (bool $workflow): void {
    [$author, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($author, $editor, $workflow): void {
        $post = pcLivePost(pcCollection(), $author, $editor);
        if ($workflow) {
            app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);
            Livewire::actingAs($author)->test(Editor::class, ['ulid' => $post->ulid])
                ->set('title', 'Bản đã gửi')->call('submitChange')->assertHasNoErrors();
        } else {
            app(SaveEntryDraft::class)->handle($post->fresh(), $author, 'vi', ['title' => 'Bản đã gửi']);
        }
        $form = Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid])
            ->call('askApplyChange')->assertSet('changeApplyAsk', true);
        // edited elsewhere after this form read it
        $change = pcChange($post);
        $content = $change->content;
        $content['locales']['vi']['title'] = 'Bản chưa xem';
        $change->update(['content' => $content]);
        $before = pcStored($post);

        $form->call('applyChange')
            ->assertHasNoErrors()
            ->assertSet('changeApplyAsk', false)
            ->assertSet('refusal', pcRefusal($workflow ? 'approve' : 'apply', 'change_stale'))
            ->assertSee(__($workflow ? 'entries.refuse.t_approve' : 'entries.refuse.t_apply'));
        expect(pcStored($post))->toBe($before)
            ->and($post->fresh()->translationFor('vi')->title)->toBe('Bản đang đăng');
    });
})->with([
    'no workflow: Cập nhật website' => [false],
    'workflow: Duyệt đăng' => [true],
]);

it('shows «Chưa bỏ được thay đổi» when a sent change is thrown away, or one already gone, the change kept', function (): void {
    [$author, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($author, $editor): void {
        $post = pcLivePost(pcCollection(), $author, $editor);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);
        $writer = Livewire::actingAs($author)->test(Editor::class, ['ulid' => $post->ulid])
            ->set('title', 'Bản đã gửi')->call('submitChange')->assertHasNoErrors();
        $before = pcStored($post);

        $writer->call('askDiscardChange')->assertSet('changeDiscardAsk', true)
            ->call('discardChange')
            ->assertHasNoErrors()
            ->assertSet('changeDiscardAsk', false)
            ->assertSet('refusal', pcRefusal('discard', 'change_pending'))
            ->assertDontSeeHtml('data-refusal-reload');
        expect(pcStored($post))->toBe($before);

        // a change thrown away elsewhere: the question is not asked, the dialog offers «Tải lại»
        $review = Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid]);
        EntryDraft::query()->where('entry_id', $post->id)->delete();
        $gone = pcStored($post);
        $review->call('askDiscardChange')
            ->assertSet('changeDiscardAsk', false)
            ->assertSet('refusal', pcRefusal('discard', 'change_stale'))
            ->call('reloadChange')
            ->assertSet('refusal', null)
            ->assertSet('changeSeen', null);
        expect(pcStored($post))->toBe($gone);
    });
});

it('says a change’s taken address in the dialog when it is put live, and keeps it under the link field, nothing written', function (): void {
    [$author, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($author, $editor): void {
        $category = pcCollection();
        $post = pcLivePost($category, $author, $editor);
        // the author may not publish: the new link waits in the change, and another post takes it meanwhile
        Livewire::actingAs($author)->test(Editor::class, ['ulid' => $post->ulid])
            ->set('slug', 'bai-2')->call('save')->assertHasNoErrors();
        pcLivePost($category, $author, $editor, 'bai-2');
        $form = Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid])
            ->call('askApplyChange')->assertSet('changeApplyAsk', true);
        $before = pcStored($post);

        $form->call('applyChange')
            ->assertHasNoErrors('workflow')
            ->assertHasErrors(['slug' => __('entries.change_err_slug_taken')])
            ->assertSet('refusal', pcRefusal('apply', 'slug_taken'));
        expect(pcStored($post))->toBe($before)
            ->and($post->fresh()->translationFor('vi')->slug)->toBe('bai-1');
    });
});

it('keeps a refused «Trả lại» on the line above the form (the board draws no dialog for it)', function (): void {
    [$author, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($author, $editor): void {
        $post = pcLivePost(pcCollection(), $author, $editor);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);
        Livewire::actingAs($author)->test(Editor::class, ['ulid' => $post->ulid])
            ->set('title', 'Bản đã gửi')->call('submitChange')->assertHasNoErrors();
        $review = Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid]);
        $change = pcChange($post);
        $content = $change->content;
        $content['locales']['vi']['title'] = 'Bản chưa xem';
        $change->update(['content' => $content]);
        $before = pcStored($post);

        $review->call('openChangeReturn')->set('changeNote', 'Sửa lại')->call('returnChange')
            ->assertHasErrors(['workflow' => __('entries.change_err_change_stale_page')])
            ->assertSet('refusal', null);
        expect(pcStored($post))->toBe($before);
    });
});

it('never lets the browser set the refusal, or the fields «Tải lại» would replace', function (string $property, mixed $value): void {
    [$author, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($author, $editor, $property, $value): void {
        $post = pcLivePost(pcCollection(), $author, $editor);
        Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $post->ulid])->set($property, $value);
    });
})->with([
    'refusal' => ['refusal', ['title' => 'x', 'text' => 'y', 'reload' => true]],
    'reloadReplaces' => ['reloadReplaces', 'x'],
])->throws(CannotUpdateLockedPropertyException::class);

/** The site also in English; «Dịch vụ» > «Logo» (con) and a top page «Thiết kế», all live in Vietnamese and English with the English links services/logo/logo. @return array{0: Entry, 1: Entry, 2: Entry} */
function pcEnglishTree(User $editor): array
{
    DB::connection('pgsql_admin')->table('site_locales')->insert([
        'site_id' => 1, 'locale' => 'en', 'is_default' => false, 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $services = pcLivePage($editor, 'dich-vu', null);
    $kid = pcLivePage($editor, 'logo-con', $services);
    $page = pcLivePage($editor, 'thiet-ke', null);
    foreach ([[$services, 'services'], [$kid, 'logo'], [$page, 'logo']] as [$entry, $slug]) {
        $entry->translations()->create(['locale' => 'en', 'title' => ucfirst($slug), 'slug' => $slug, 'status' => 'draft', 'body' => ['blocks' => []]]);
        app(PublishEntry::class)->handle($entry->fresh(), 'en', $editor);
    }

    return [$services->fresh(), $kid->fresh(), $page->fresh()];
}

it('says a sub-page’s taken address with its /en prefix when a page is put live in English from the editor', function (): void {
    [, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($editor): void {
        DB::connection('pgsql_admin')->table('site_locales')->insert([
            'site_id' => 1, 'locale' => 'en', 'is_default' => false, 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $about = pcLivePage($editor, 'gioi-thieu', null);
        $team = pcLivePage($editor, 'doi-ngu', $about);
        foreach ([[$about, 'about'], [$team, 'team']] as [$page, $slug]) {
            $page->translations()->create(['locale' => 'en', 'title' => ucfirst($slug), 'slug' => $slug, 'status' => 'draft', 'body' => ['blocks' => []]]);
            app(PublishEntry::class)->handle($page->fresh(), 'en', $editor);
        }
        app(SaveEntryDraft::class)->handle($about->fresh(), $editor, 'en', ['slug' => 'about-us']);
        app(TransitionEntryStatus::class)->handle($about->fresh(), EntryStatus::Draft, $editor);
        $other = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $editor->id]);
        SiteRoute::query()->create(['locale' => 'en', 'path' => '/about-us/team', 'entry_id' => $other->id]);

        expect(Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $about->ulid, 'locale' => 'en'])
            ->call('publish')
            ->assertHasNoErrors('slug')
            ->assertHasErrors('workflow')
            ->errors()->first('workflow'))->toBe(__('entries.subpage_path_taken', ['path' => '/en/about-us/team']));
    });
});

it('says a sub-page’s taken address with its /en prefix when an older English version is restored', function (): void {
    [, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($editor): void {
        [$services, $kid] = pcEnglishTree($editor);
        $first = EntryRevision::query()->where('entry_id', $services->id)->where('locale', 'en')->orderBy('id')->firstOrFail();
        // the English link moves on, the sub-page with it; the sub-page's old address is taken since
        $services->translationFor('en')->update(['slug' => 'our-services']);
        app(RouteSync::class)->sync($services, 'en', 'our-services');
        app(RouteSync::class)->syncDescendants($services, 'en');
        Redirect::query()->where('from_path', '/services/logo')->delete();
        $other = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $editor->id]);
        SiteRoute::query()->create(['locale' => 'en', 'path' => '/services/logo', 'entry_id' => $other->id]);

        $refused = null;
        try {
            app(RestoreEntryRevision::class)->handle($first, $editor);
        } catch (RevisionRestoreRejectedException $e) {
            $refused = $e;
        }

        expect($refused?->userMessage())->toBe(__('entries.revision_err_subpage_path_taken', ['path' => '/en/services/logo']))
            ->and(SiteRoute::query()->where('entry_id', $kid->id)->where('locale', 'en')->value('path'))->toBe('/our-services/logo');
    });
});

it('says the address a page move would take with its /en prefix, in the refusal and on the place «Chuyển đến…» locks', function (): void {
    [, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($editor): void {
        [$services, , $page] = pcEnglishTree($editor);

        $refused = null;
        try {
            app(MovePage::class)->handle($page, $services, 0);
        } catch (PageMoveRejectedException $e) {
            $refused = $e;
        }
        expect($refused?->path)->toBe('/en/services/logo')
            ->and(PageMoveWords::why($refused))->toBe(__('entries.move_path_taken', ['path' => '/en/services/logo']));

        $targets = Livewire::actingAs($editor)->test(Index::class)->instance()->moveTargets($page->ulid);
        $by = collect($targets['opts'] ?? [])->keyBy(fn (array $o): string => $o['k'] ?? 'top');
        expect($by[$services->ulid]['why'])->toBe(__('entries.move_path_taken_label', ['path' => '/en/services/logo']));
    });
});

it('names the same language in the move refusal and on the «Chuyển đến…» lock when the move clashes in two', function (): void {
    [, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($editor): void {
        // Japanese is enabled before English, so the website's order is vi, ja, en
        DB::connection('pgsql_admin')->table('site_locales')->insert([
            'site_id' => 1, 'locale' => 'ja', 'is_default' => false, 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        [$services, , $page] = pcEnglishTree($editor);
        // the page's Japanese translation is written after its English one, so stored order (vi, en, ja) differs from the website's (vi, ja, en)
        foreach ([[$services, 'dich-vu-ja'], [$page, 'thiet-ke-ja']] as [$entry, $slug]) {
            $entry->translations()->create(['locale' => 'ja', 'title' => $slug, 'slug' => $slug, 'status' => 'draft', 'body' => ['blocks' => []]]);
            app(PublishEntry::class)->handle($entry->fresh(), 'ja', $editor);
        }
        // English is taken already (the sub-page «logo» stands at /services/logo); Japanese is taken too, and it comes first in the website's order
        $other = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $editor->id]);
        SiteRoute::query()->create(['locale' => 'ja', 'path' => '/dich-vu-ja/thiet-ke-ja', 'entry_id' => $other->id]);

        $refused = null;
        try {
            app(MovePage::class)->handle($page->fresh(), $services->fresh(), 0);
        } catch (PageMoveRejectedException $e) {
            $refused = $e;
        }
        $by = collect(Livewire::actingAs($editor)->test(Index::class)->instance()->moveTargets($page->ulid)['opts'] ?? [])->keyBy(fn (array $o): string => $o['k'] ?? 'top');

        expect($refused?->path)->toBe('/ja/dich-vu-ja/thiet-ke-ja')
            ->and($by[$services->ulid]['why'])->toBe(__('entries.move_path_taken_label', ['path' => '/ja/dich-vu-ja/thiet-ke-ja']));
    });
});

it('shows the «new path» label of a page that exists only in English with its /en prefix', function (): void {
    [, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($editor): void {
        [$services] = pcEnglishTree($editor);
        $solo = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $editor->id]);
        $solo->translations()->create(['locale' => 'en', 'title' => 'Solo', 'slug' => 'solo', 'status' => 'draft', 'body' => ['blocks' => []]]);
        app(PublishEntry::class)->handle($solo->fresh(), 'en', $editor);

        $by = collect(Livewire::actingAs($editor)->test(Index::class)->instance()->moveTargets($solo->ulid)['opts'] ?? [])->keyBy(fn (array $o): string => $o['k'] ?? 'top');

        expect($by[$services->ulid]['path'])->toBe('/dich-vu/solo')
            ->and($by[$services->ulid]['label'])->toBe(__('entries.mv_becomes_path', ['n' => 2, 'path' => '/en/services/solo']));
    });
});

it('shows the «new path» label of a page without the default language in the website’s first other language, not the alphabetically first', function (): void {
    [, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($editor): void {
        // the website's order is vi, ja, en: Japanese comes before English although «en» sorts first
        DB::connection('pgsql_admin')->table('site_locales')->insert([
            'site_id' => 1, 'locale' => 'ja', 'is_default' => false, 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        [$services] = pcEnglishTree($editor);
        $services->translations()->create(['locale' => 'ja', 'title' => 'ja', 'slug' => 'dich-vu-ja', 'status' => 'draft', 'body' => ['blocks' => []]]);
        $solo = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $editor->id]);
        foreach (['en' => 'solo', 'ja' => 'solo-ja'] as $lc => $slug) {
            $solo->translations()->create(['locale' => $lc, 'title' => $slug, 'slug' => $slug, 'status' => 'draft', 'body' => ['blocks' => []]]);
        }

        $by = collect(Livewire::actingAs($editor)->test(Index::class)->instance()->moveTargets($solo->ulid)['opts'] ?? [])->keyBy(fn (array $o): string => $o['k'] ?? 'top');

        expect($by[$services->ulid]['label'])->toBe(__('entries.mv_becomes_path', ['n' => 2, 'path' => '/ja/dich-vu-ja/solo-ja']))
            ->and($by[$services->ulid]['path'])->toBe('/dich-vu/solo-ja');
    });
});

it('shows no path in the move label when the page\'s own-language address does not change, and names a candidate in the website’s language order', function (): void {
    [, $editor] = pcCast();
    app(Tenancy::class)->enter(1, function () use ($editor): void {
        // the website's order is vi, ja, en; page addresses are nested
        SiteSetting::query()->updateOrCreate(['group_name' => 'permalinks', 'key' => 'pages'], ['value' => ['pattern' => 'nested']]);
        DB::connection('pgsql_admin')->table('site_locales')->insert([
            'site_id' => 1, 'locale' => 'ja', 'is_default' => false, 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $make = function (array $titles, ?Entry $parent = null) use ($editor): Entry {
            $entry = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $editor->id, 'parent_id' => $parent?->id]);
            foreach ($titles as $lc => $slug) {
                $entry->translations()->create(['locale' => $lc, 'title' => $slug, 'slug' => $slug, 'status' => 'draft', 'body' => ['blocks' => []]]);
            }

            return $entry;
        };
        // P: vi «p», ja «pj». Q: no Vietnamese, en «q-en», ja «p». The page exists only in Japanese, under P.
        $p = $make(['vi' => 'p', 'ja' => 'pj']);
        $q = $make(['en' => 'q-en', 'ja' => 'p']);
        $solo = $make(['ja' => 'solo-ja'], $p);
        // a candidate that lacks the page's language
        $none = $make(['vi' => 'khong-ja']);

        $by = collect(Livewire::actingAs($editor)->test(Index::class)->instance()->moveTargets($solo->ulid)['opts'] ?? [])->keyBy(fn (array $o): string => $o['k'] ?? 'top');

        // moving under Q changes the Japanese address /ja/pj/solo-ja to /ja/p/solo-ja
        expect($by[$q->ulid]['label'])->toBe(__('entries.mv_becomes_path', ['n' => 2, 'path' => '/ja/p/solo-ja']))
            // Q is named by its Japanese title (the website's next language), not the alphabetically first English one
            ->and($by[$q->ulid]['name'])->toBe('p')
            // a parent without Japanese: the address stops at that parent, /ja/solo-ja
            ->and($by[$none->ulid]['label'])->toBe(__('entries.mv_becomes_path', ['n' => 2, 'path' => '/ja/solo-ja']));

        // from the top level, a parent without Japanese changes nothing in the Japanese address
        $top = $make(['ja' => 'top-ja']);
        $by = collect(Livewire::actingAs($editor)->test(Index::class)->instance()->moveTargets($top->ulid)['opts'] ?? [])->keyBy(fn (array $o): string => $o['k'] ?? 'top');
        expect($by[$none->ulid]['label'])->toBe(__('entries.mv_becomes', ['n' => 2]));
    });
});
