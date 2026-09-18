<?php

use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Admin\Livewire\Entries\Index;
use App\Modules\Cms\Actions\ApplyEntryWorkflowTransition;
use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Workflow\Actions\InstantiateWorkflow;
use Livewire\Livewire;

/*
 * The editor's "Trạng thái duyệt" block (design_handoff_workflow screen 2). Where an active
 * workflow governs a post's section, the tier-1 Publish button gives way to a stepper over the
 * flow's DATA-defined stages, an action bar holding exactly the transitions the engine allows
 * this user, a mandatory-note modal with hand-off, and the trail read from the engine's label
 * snapshots. Sections without a workflow — every section by default — keep tier-1 untouched.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/** A `posts` collection (post_cat category + bound section, category permalinks); returns the category id. */
function wfEditorCollection(): int
{
    $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
    $category->translations()->create(['locale' => 'vi', 'name' => 'Tin tức', 'slug' => 'tin-tuc']);

    ContentSection::query()->create([
        'key' => 'posts', 'label' => 'Tin tức', 'entry_type' => 'post', 'taxonomy_id' => $category->id,
        'permission_prefix' => 'entries', 'sort_order' => 1,
    ]);
    SiteSetting::query()->create(['group_name' => 'permalinks', 'key' => 'posts', 'value' => ['pattern' => 'category']]);

    return $category->id;
}

/** A draft post filed under the collection, authored by $author. */
function wfEditorPost(int $categoryId, User $author, string $slug = 'bai-1'): Entry
{
    $entry = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'author_id' => $author->id]);
    $entry->translations()->create([
        'locale' => 'vi', 'title' => 'Quy trình mới cho xưởng', 'slug' => $slug, 'status' => 'draft',
        'body' => ['blocks' => [['type' => 'richtext', 'props' => ['body' => 'Nội dung']]]],
    ]);
    $entry->assignCategories([$categoryId]);

    return $entry->fresh();
}

it('replaces the tier-1 publish button with the approval block on a governed post', function (): void {
    $author = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($author): void {
        grantRole($author, 'author', ['entries.view', 'entries.create', 'entries.edit.any', 'entries.submit']);
        $post = wfEditorPost(wfEditorCollection(), $author);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);

        Livewire::actingAs($author->fresh())
            ->test(Editor::class, ['ulid' => $post->ulid])
            ->assertDontSeeHtml('wire:click="publish"')
            ->assertSee(__('admin.wfb.title'))
            // Every stage of the flow, in its data order — nothing hard-coded.
            ->assertSeeInOrder(['Bản nháp', 'Chờ duyệt', 'Đã đăng', 'Lưu trữ'])
            // Only the transition THIS role may apply from the current stage.
            ->assertSee('Gửi duyệt')
            ->assertDontSee('Duyệt đăng')
            ->assertDontSee('Trả lại');
    });
});

it('keeps tier-1 publishing untouched where no workflow is active', function (): void {
    $author = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($author): void {
        grantRole($author, 'author', ['entries.view', 'entries.create', 'entries.edit.any', 'entries.submit']);
        $post = wfEditorPost(wfEditorCollection(), $author);
        // Configured but switched off: the opt-in is what counts.
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: false);

        Livewire::actingAs($author->fresh())
            ->test(Editor::class, ['ulid' => $post->ulid])
            ->assertSeeHtml('wire:click="publish"')
            ->assertDontSee(__('admin.wfb.title'));
    });
});

it('submits from the block: the post enters the flow and the trail shows the move', function (): void {
    $author = User::factory()->create(['name' => 'Trần Văn Nam']);

    app(Tenancy::class)->enter(1, function () use ($author): void {
        grantRole($author, 'author', ['entries.view', 'entries.create', 'entries.edit.any', 'entries.submit']);
        $post = wfEditorPost(wfEditorCollection(), $author);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);

        Livewire::actingAs($author->fresh())
            ->test(Editor::class, ['ulid' => $post->ulid])
            ->call('applyTransition', 'submit')
            ->assertHasNoErrors()
            ->assertSet('status', 'pending')
            // The trail row renders the actor + the snapshotted labels (action lower-cased, as
            // designed), and the author now has nothing to press: the ball is with a reviewer.
            ->assertSee('Trần Văn Nam')
            ->assertSee('gửi duyệt')
            ->assertSee('Bản nháp → Chờ duyệt')
            ->assertSee(__('admin.wfb.wait_line'));

        $post->refresh();
        expect($post->workflow_state)->toBe('pending')
            ->and($post->status)->toBe('pending')
            ->and(SiteRoute::query()->where('entry_id', $post->id)->exists())->toBeFalse();
    });
});

it('refuses a return without a reason and leaves the post where it was', function (): void {
    $author = User::factory()->create();
    $reviewer = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($author, $reviewer): void {
        grantRole($author, 'author', ['entries.submit']);
        grantRole($reviewer, 'reviewer', ['entries.view', 'entries.edit.any', 'entries.review']);
        $post = wfEditorPost(wfEditorCollection(), $author);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);
        app(ApplyEntryWorkflowTransition::class)->handle($post, 'vi', $author->fresh(), 'submit');

        Livewire::actingAs($reviewer->fresh())
            ->test(Editor::class, ['ulid' => $post->ulid])
            ->set('transitionNote', '   ')
            ->call('applyTransition', 'reject')
            ->assertHasErrors('transitionNote');

        expect($post->fresh()->workflow_state)->toBe('pending');
    });
});

it('returns the post with a reason and hands it to a chosen member', function (): void {
    $author = User::factory()->create(['name' => 'Người viết Alpha']);
    $reviewer = User::factory()->create(['name' => 'Biên tập Beta']);
    $viewer = User::factory()->create(['name' => 'Chỉ xem Gamma']);
    $stranger = User::factory()->create(['name' => 'Site khác Delta']);

    app(Tenancy::class)->enter(2, function () use ($stranger): void {
        grantRole($stranger, 'author', ['entries.submit']);   // may submit — on ANOTHER site
    });

    app(Tenancy::class)->enter(1, function () use ($author, $reviewer, $viewer): void {
        grantRole($author, 'author', ['entries.submit']);
        grantRole($reviewer, 'reviewer', ['entries.view', 'entries.edit.any', 'entries.review']);
        grantRole($viewer, 'viewer', ['entries.view']);
        $post = wfEditorPost(wfEditorCollection(), $author);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);
        app(ApplyEntryWorkflowTransition::class)->handle($post, 'vi', $author->fresh(), 'submit');

        $component = Livewire::actingAs($reviewer->fresh())
            ->test(Editor::class, ['ulid' => $post->ulid])
            // The hand-off list = members who can act at the DESTINATION stage (draft → submit),
            // on this site only.
            ->assertSeeHtml('<option value="'.$author->id.'">Người viết Alpha</option>')
            ->assertDontSee('Chỉ xem Gamma')
            ->assertDontSee('Site khác Delta')
            ->set('transitionNote', 'Thiếu ảnh máy CNC')
            ->set('assignTo', $author->id)
            ->call('applyTransition', 'reject')
            ->assertHasNoErrors()
            ->assertSet('status', 'draft')
            ->assertSee('Thiếu ảnh máy CNC')
            ->assertSee(__('admin.wfb.assigned_to', ['name' => 'Người viết Alpha']));

        $post->refresh();
        expect($post->workflow_state)->toBe('draft')
            ->and($post->assigned_to)->toBe($author->id);

        // The form is clean again for the next move.
        $component->assertSet('transitionNote', '')->assertSet('assignTo', null);
    });
});

it('rejects a forged hand-off target and ignores a stale one on a plain move', function (): void {
    $author = User::factory()->create();
    $reviewer = User::factory()->create();
    $stranger = User::factory()->create();

    app(Tenancy::class)->enter(2, function () use ($stranger): void {
        grantRole($stranger, 'author', ['entries.submit']);   // the right permission, the wrong site
    });

    app(Tenancy::class)->enter(1, function () use ($author, $reviewer, $stranger): void {
        grantRole($author, 'author', ['entries.view', 'entries.create', 'entries.edit.any', 'entries.submit']);
        grantRole($reviewer, 'reviewer', ['entries.view', 'entries.edit.any', 'entries.review']);
        $post = wfEditorPost(wfEditorCollection(), $author);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);
        app(ApplyEntryWorkflowTransition::class)->handle($post, 'vi', $author->fresh(), 'submit');

        // A users.id typed into the wire payload that is not in the offered list: refused, and
        // the post has not moved.
        Livewire::actingAs($reviewer->fresh())
            ->test(Editor::class, ['ulid' => $post->ulid])
            ->set('transitionNote', 'Thiếu ảnh')
            ->set('assignTo', $stranger->id)
            ->call('applyTransition', 'reject')
            ->assertHasErrors('assignTo');

        $post->refresh();
        expect($post->workflow_state)->toBe('pending')->and($post->assigned_to)->toBeNull();

        // Back with the author: a value left behind by a cancelled modal must not ride along on
        // a plain "submit" (which has no hand-off in the design).
        app(ApplyEntryWorkflowTransition::class)->handle($post, 'vi', $reviewer->fresh(), 'reject', 'Thiếu ảnh');

        Livewire::actingAs($author->fresh())
            ->test(Editor::class, ['ulid' => $post->ulid])
            ->set('assignTo', $reviewer->id)
            ->call('applyTransition', 'submit')
            ->assertHasNoErrors();

        $post->refresh();
        expect($post->workflow_state)->toBe('pending')->and($post->assigned_to)->toBeNull();
    });
});

it('approves into the public stage: status, route and the live chip', function (): void {
    $author = User::factory()->create();
    $approver = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($author, $approver): void {
        grantRole($author, 'author', ['entries.submit']);
        grantRole($approver, 'approver', ['entries.view', 'entries.edit.any', 'entries.approve']);
        $post = wfEditorPost(wfEditorCollection(), $author);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);
        app(ApplyEntryWorkflowTransition::class)->handle($post, 'vi', $author->fresh(), 'submit');

        Livewire::actingAs($approver->fresh())
            ->test(Editor::class, ['ulid' => $post->ulid])
            ->assertDontSee(__('admin.wfb.live_chip'))
            ->call('applyTransition', 'approve')
            ->assertHasNoErrors()
            ->assertSet('status', 'published')
            ->assertSee(__('admin.wfb.live_chip'));

        $post->refresh();
        expect($post->workflow_state)->toBe('published')
            ->and($post->status)->toBe('published')
            ->and(SiteRoute::query()->where('entry_id', $post->id)->value('path'))->toBe('/tin-tuc/bai-1');
    });

    $this->get('http://a.test/tin-tuc/bai-1')->assertOk();
});

it('shows a live pre-workflow post at the public stage — no submit button that would hide it', function (): void {
    $author = User::factory()->create();
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($author, $editor): void {
        grantRole($author, 'author', ['entries.view', 'entries.create', 'entries.edit.any', 'entries.submit']);
        grantRole($editor, 'editor', ['entries.view', 'entries.edit.any', 'entries.publish', 'entries.review', 'entries.approve']);
        $post = wfEditorPost(wfEditorCollection(), $author);
        app(PublishEntry::class)->handle($post, 'vi', $editor->fresh());   // live under tier-1
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);

        // The author: nothing to press (submit does not exist from the published stage), told
        // plainly that the post is live and that saved edits show at once.
        Livewire::actingAs($author->fresh())
            ->test(Editor::class, ['ulid' => $post->ulid])
            ->assertSee(__('admin.wfb.live_chip'))
            ->assertSee(__('admin.wfb.step_of', ['n' => 3, 'total' => 4, 'label' => 'Đã đăng']))
            ->assertDontSee(__('admin.wfb.pre_workflow'))
            ->assertDontSee('Gửi duyệt')
            ->assertSee(__('admin.wfb.wait_line_live'));

        // The editor: only the moves that exist from the published stage.
        Livewire::actingAs($editor->fresh())
            ->test(Editor::class, ['ulid' => $post->ulid])
            ->assertSee('Gỡ bài')
            ->assertSee('Lưu trữ')
            ->assertDontSee('Gửi duyệt')
            ->assertDontSee('Duyệt đăng')
            ->assertSee(__('admin.wfb.live_edit_hint'));

        expect($post->fresh()->status)->toBe('published');
    });

    $this->get('http://a.test/tin-tuc/bai-1')->assertOk();
});

it('turns a direct publish on a governed post into a plain-language error, not a 500', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.create', 'entries.edit.any', 'entries.publish']);
        $post = wfEditorPost(wfEditorCollection(), $editor);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);

        // A stale tab still holding the tier-1 button (the flow was switched on after it loaded).
        Livewire::actingAs($editor->fresh())
            ->test(Editor::class, ['ulid' => $post->ulid])
            ->call('publish')
            ->assertHasErrors('workflow')
            ->assertSet('status', 'draft');

        expect($post->fresh()->status)->toBe('draft')
            ->and(SiteRoute::query()->count())->toBe(0);
    });
});

it('creates and submits an unsaved post in one move — the picked category decides the flow', function (): void {
    $author = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($author): void {
        grantRole($author, 'author', ['entries.view', 'entries.create', 'entries.edit.any', 'entries.submit']);
        wfEditorCollection();
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);

        Livewire::actingAs($author->fresh())
            ->test(Editor::class, ['section' => 'posts'])
            ->assertDontSeeHtml('wire:click="publish"')
            ->assertSee('Gửi duyệt')
            ->set('title', 'Bài mới')
            ->set('slug', 'bai-moi')
            ->call('addBlock', 'richtext')
            ->call('applyTransition', 'submit')
            ->assertHasNoErrors()
            ->assertSet('status', 'pending');

        $entry = Entry::query()->where('type', 'post')->first();
        expect($entry)->not->toBeNull()
            ->and($entry->workflow_state)->toBe('pending')
            ->and($entry->translationFor('vi')->slug)->toBe('bai-moi');
    });
});

it('refuses a stale move once someone else has already advanced the post', function (): void {
    $author = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($author): void {
        grantRole($author, 'author', ['entries.view', 'entries.create', 'entries.edit.any', 'entries.submit']);
        $post = wfEditorPost(wfEditorCollection(), $author);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);

        $component = Livewire::actingAs($author->fresh())->test(Editor::class, ['ulid' => $post->ulid]);

        // Another tab submits first…
        app(ApplyEntryWorkflowTransition::class)->handle($post->fresh(), 'vi', $author->fresh(), 'submit');

        // …so this one's "submit" no longer matches the post's stage: refused, nothing written.
        $component->call('applyTransition', 'submit')
            ->assertHasErrors('workflow')
            ->assertSet('status', 'pending');

        expect($post->fresh()->workflow_state)->toBe('pending');
    });
});

it('pages the trail by keyset — "Xem thêm" appends the older moves', function (): void {
    $author = User::factory()->create();
    $reviewer = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($author, $reviewer): void {
        grantRole($author, 'author', ['entries.view', 'entries.create', 'entries.edit.any', 'entries.submit']);
        grantRole($reviewer, 'reviewer', ['entries.review']);
        $post = wfEditorPost(wfEditorCollection(), $author);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);
        $action = app(ApplyEntryWorkflowTransition::class);

        // 5 moves: submit / reject ×2, then a final submit.
        foreach (['Lần một', 'Lần hai'] as $note) {
            $action->handle($post->fresh(), 'vi', $author->fresh(), 'submit');
            $action->handle($post->fresh(), 'vi', $reviewer->fresh(), 'reject', $note);
        }
        $action->handle($post->fresh(), 'vi', $author->fresh(), 'submit');

        Livewire::actingAs($author->fresh())
            ->test(Editor::class, ['ulid' => $post->ulid])
            ->assertSee(__('admin.wfb.trail_count', ['count' => 5]))
            ->assertSee(__('admin.wfb.trail_more', ['count' => 2]))
            ->assertSee('Lần hai')
            ->assertDontSee('Lần một')          // the oldest reject sits on page 2
            ->call('loadMoreTrail')
            ->assertSee('Lần một')
            ->assertDontSee(__('admin.wfb.trail_more', ['count' => 2]));
    });
});

it('renders the governed editor on a flat query budget regardless of trail length or team size', function (): void {
    $author = User::factory()->create();
    $reviewer = User::factory()->create();

    $post = app(Tenancy::class)->enter(1, function () use ($author, $reviewer): Entry {
        grantRole($author, 'author', ['entries.view', 'entries.create', 'entries.edit.any', 'entries.submit']);
        grantRole($reviewer, 'reviewer', ['entries.view', 'entries.edit.any', 'entries.review']);
        $post = wfEditorPost(wfEditorCollection(), $author);
        app(InstantiateWorkflow::class)->handle('editorial', 'posts', active: true);
        app(ApplyEntryWorkflowTransition::class)->handle($post, 'vi', $author->fresh(), 'submit');

        return $post->fresh();
    });

    $url = "http://a.test/admin/content/{$post->ulid}/edit";
    // Warm the per-process caches (permission registrar etc.) so both measurements see the
    // same baseline and only the page's own queries differ.
    $this->actingAs($reviewer)->get($url)->assertOk();
    $lean = countQueries(fn () => $this->actingAs($reviewer)->get($url)->assertOk());

    app(Tenancy::class)->enter(1, function () use ($post, $author, $reviewer): void {
        // Eight more moves on the trail + six more members who could be handed the post.
        $action = app(ApplyEntryWorkflowTransition::class);
        foreach (range(1, 4) as $i) {
            $action->handle($post->fresh(), 'vi', $reviewer->fresh(), 'send_back', "Sửa {$i}");
            $action->handle($post->fresh(), 'vi', $author->fresh(), 'submit');
        }
        foreach (range(1, 6) as $i) {
            grantRole(User::factory()->create(), 'author', ['entries.submit']);
        }
    });

    // Granting roles flushes the permission cache; warm it again so the second measurement is
    // not charged for reloading it.
    $this->actingAs($reviewer)->get($url)->assertOk();
    $loaded = countQueries(fn () => $this->actingAs($reviewer)->get($url)->assertOk());

    expect($loaded)->toBe($lean);
});

it('toasts instead of erroring when the list tries to publish a governed post directly', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.edit.any', 'entries.publish']);
        $post = wfEditorPost(wfEditorCollection(), $editor);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);

        Livewire::actingAs($editor->fresh())
            ->test(Index::class, ['section' => 'posts'])
            ->call('changeStatus', $post->ulid, 'published')
            ->assertDispatched('bs-toast', text: __('entries.workflow_governed'));

        expect($post->fresh()->status)->toBe('draft');
    });
});

it('sums up a mixed bulk publish in one toast — governed posts counted, not published', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        grantRole($editor, 'editor', ['entries.view', 'entries.edit.any', 'entries.publish']);
        $governedPost = wfEditorPost(wfEditorCollection(), $editor);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);

        // A page carries no taxonomy, so no workflow can govern it: it publishes as always.
        $page = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'author_id' => $editor->id]);
        $page->translations()->create(['locale' => 'vi', 'title' => 'Liên hệ', 'slug' => 'lien-he', 'status' => 'draft', 'body' => ['blocks' => []]]);

        // A second toast would simply replace the first, so the two outcomes travel together.
        Livewire::actingAs($editor->fresh())
            ->test(Index::class)
            ->set('selected', [$page->ulid, $governedPost->ulid])
            ->call('bulkPublish')
            ->assertDispatched('bs-toast', text: __('entries.bulk_done_governed', ['count' => 1, 'governed' => 1]));

        expect($page->fresh()->status)->toBe('published')
            ->and($governedPost->fresh()->status)->toBe('draft');
    });
});
