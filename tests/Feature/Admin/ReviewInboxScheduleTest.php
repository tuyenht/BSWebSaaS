<?php

use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Admin\Livewire\Entries\Index;
use App\Modules\Admin\Livewire\Entries\ReviewInbox;
use App\Modules\Cms\Actions\ApplyEntryWorkflowTransition;
use App\Modules\Cms\Actions\ApproveEntryForLater;
use App\Modules\Cms\Actions\RecordEntryRevision;
use App\Modules\Cms\Actions\RestoreEntryRevision;
use App\Modules\Cms\Actions\TransitionEntryStatus;
use App\Modules\Cms\Enums\RevisionKind;
use App\Modules\Cms\Exceptions\RevisionRestoreRejectedException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryRevision;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Models\SiteNotification;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Workflow\Actions\InstantiateWorkflow;
use App\Modules\Workflow\Exceptions\InvalidTransitionException;
use App\Modules\Workflow\Models\Workflow;
use App\Modules\Workflow\Models\WorkflowLog;
use App\Modules\Workflow\Models\WorkflowTransition;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * «Khi nào đăng?» → «Hẹn giờ đăng» in the review inbox's first-approval dialog (board 17): the post is
 * approved now and goes live later — and only the workflow engine ever puts it live. The approval moves
 * no stage; at the time, entries:publish-due applies the publishing move AS the approver, rights judged
 * again. Anything that changed meanwhile (the right, the flow, the approver's account) keeps it offline.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    DB::connection('pgsql_admin')->statement('truncate site_notifications restart identity');
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/** A `posts` section on $site governed by the bundled approval flow; returns its category id. */
function laterSection(int $site = 1): int
{
    return app(Tenancy::class)->enter($site, function (): int {
        $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
        $category->translations()->create(['locale' => 'vi', 'name' => 'Tin tức', 'slug' => 'tin-tuc']);
        ContentSection::query()->create([
            'key' => 'posts', 'label' => 'Tin tức', 'entry_type' => 'post', 'taxonomy_id' => $category->id,
            'permission_prefix' => 'entries', 'sort_order' => 1,
        ]);
        SiteSetting::query()->create(['group_name' => 'permalinks', 'key' => 'posts', 'value' => ['pattern' => 'category']]);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);

        return $category->id;
    });
}

/** A post by $author under $categoryId, submitted so it waits at `pending`. Inside the site's context. */
function laterPost(int $categoryId, User $author, string $slug = 'ban-tin'): Entry
{
    $entry = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'author_id' => $author->id]);
    $entry->translations()->create([
        'locale' => 'vi', 'title' => 'Bản tin hẹn giờ', 'slug' => $slug, 'status' => 'draft',
        'body' => ['blocks' => [['type' => 'richtext', 'props' => ['body' => 'Nội dung bài']]]],
    ]);
    $entry->assignCategories([$categoryId]);
    app(ApplyEntryWorkflowTransition::class)->handle($entry->fresh(), 'vi', $author->fresh(), 'submit');

    return $entry->fresh();
}

/** «Đăng lúc» as the dialog sends it: tomorrow at $hour in the website's clock. */
function laterAt(int $hour = 9): string
{
    return app(SiteTime::class)->now()->addDay()->setTime($hour, 0)->format('Y-m-d\TH:i');
}

/** The `workflow.scheduled` sentences $user was sent on $site, oldest first. */
function laterNotices(int $site, User $user): array
{
    return app(Tenancy::class)->enter($site, fn (): array => SiteNotification::query()
        ->where('user_id', $user->id)->where('kind', 'workflow.scheduled')->orderBy('id')
        ->get()->map(fn (SiteNotification $n): string => (string) ($n->data['sentence'] ?? ''))->all());
}

/** People on site 1: a writer, and an approver (who may also review). */
function laterPeople(): array
{
    $author = User::factory()->create(['name' => 'Người viết']);
    $approver = User::factory()->create(['name' => 'Người duyệt']);
    app(Tenancy::class)->enter(1, function () use ($author, $approver): void {
        grantRole($author, 'author', ['entries.create', 'entries.edit.own', 'entries.submit']);
        grantRole($approver, 'approver', ['entries.review', 'entries.approve']);
    });

    return [$author->fresh(), $approver->fresh()];
}

it('approves a post for a later time without moving its stage, and the sweep publishes it through the engine as the approver', function (): void {
    [$author, $approver] = laterPeople();
    $categoryId = laterSection();

    $post = app(Tenancy::class)->enter(1, function () use ($author, $approver, $categoryId): Entry {
        $post = laterPost($categoryId, $author);
        $logBefore = WorkflowLog::query()->where('entry_id', $post->id)->count();
        $at = laterAt();

        Livewire::actingAs($approver)->test(ReviewInbox::class)
            ->call('open', $post->ulid)
            ->assertSeeHtml('data-inbox-ap')
            ->assertSee(__('admin.inbox.ap_group'))
            ->assertSee(__('admin.inbox.ap_hint', ['zone' => 'GMT+7']))
            ->call('approveLater', 'approve', $at)
            ->assertReturned(null)
            ->assertHasNoErrors()
            ->assertDispatched('bs-toast')
            ->assertDontSee('Bản tin hẹn giờ');   // decided: it waits for nobody now

        $fresh = $post->fresh();
        expect($fresh->status)->toBe('scheduled')
            ->and($fresh->workflow_state)->toBe('pending')
            ->and($fresh->scheduled_by)->toBe($approver->id)
            ->and(app(SiteTime::class)->forDisplay($fresh->published_at)?->format('Y-m-d\TH:i'))->toBe($at)
            ->and(WorkflowLog::query()->where('entry_id', $post->id)->count())->toBe($logBefore)
            ->and(SiteRoute::query()->where('entry_id', $post->id)->exists())->toBeFalse();

        return $fresh;
    });
    expect(laterNotices(1, $author))->toBe(['approved']);

    // Not yet: the sweep leaves it.
    $this->artisan('entries:publish-due')->assertSuccessful();
    expect(app(Tenancy::class)->enter(1, fn () => $post->fresh()->status))->toBe('scheduled');

    $this->travel(2)->days();
    $this->artisan('entries:publish-due')->expectsOutputToContain('Published 1 scheduled')->assertSuccessful();

    app(Tenancy::class)->enter(1, function () use ($post, $approver): void {
        $fresh = $post->fresh();
        $move = WorkflowLog::query()->where('entry_id', $post->id)->orderByDesc('id')->first();
        expect($fresh->status)->toBe('published')
            ->and($fresh->workflow_state)->toBe('published')
            ->and($fresh->scheduled_by)->toBeNull()
            ->and($move?->transition_key)->toBe('approve')
            ->and($move?->actor_id)->toBe($approver->id)
            ->and(SiteRoute::query()->where('entry_id', $post->id)->exists())->toBeTrue();
    });
    expect(laterNotices(1, $author))->toBe(['approved', 'published']);
});

it('refuses a missing or past time with the field error and changes nothing', function (): void {
    [$author, $approver] = laterPeople();
    $categoryId = laterSection();

    app(Tenancy::class)->enter(1, function () use ($author, $approver, $categoryId): void {
        $post = laterPost($categoryId, $author);
        $past = app(SiteTime::class)->now()->subHour()->format('Y-m-d\TH:i');

        Livewire::actingAs($approver)->test(ReviewInbox::class)
            ->call('open', $post->ulid)
            ->call('approveLater', 'approve', '')->assertReturned(__('entries.sched_err_empty'))
            ->call('approveLater', 'approve', $past)->assertReturned(__('entries.sched_err_past'))
            ->assertNotDispatched('bs-toast');

        expect($post->fresh()->status)->toBe('pending')
            ->and($post->fresh()->scheduled_by)->toBeNull();
    });
});

it('refuses a tampered move or post, and a reviewer without the right to publish', function (): void {
    [$author, $approver] = laterPeople();
    $reviewer = User::factory()->create();
    $categoryId = laterSection();

    app(Tenancy::class)->enter(1, function () use ($author, $approver, $reviewer, $categoryId): void {
        grantRole($reviewer, 'reviewer', ['entries.review']);
        $post = laterPost($categoryId, $author);

        // A move that does not publish (and asks a reason), and one that does not exist.
        Livewire::actingAs($approver)->test(ReviewInbox::class)
            ->call('open', $post->ulid)
            ->call('approveLater', 'reject', laterAt())->assertHasErrors('inbox')
            ->call('approveLater', 'nope', laterAt())->assertHasErrors('inbox');
        // A post that is not open in this inbox.
        Livewire::actingAs($approver)->test(ReviewInbox::class)
            ->call('open', '01JUNKUNKNOWNULID000000000')
            ->call('approveLater', 'approve', laterAt())->assertHasErrors('inbox');
        // Seeing the post is not approving it.
        Livewire::actingAs($reviewer->fresh())->test(ReviewInbox::class)
            ->call('open', $post->ulid)
            ->assertDontSeeHtml('data-inbox-ap')
            ->call('approveLater', 'approve', laterAt())->assertHasErrors('inbox');
        expect(fn () => app(ApproveEntryForLater::class)->approve($post->fresh(), 'vi', $reviewer->fresh(), 'approve', now()->addDay()))
            ->toThrow(AuthorizationException::class);

        expect($post->fresh()->status)->toBe('pending')
            ->and($post->fresh()->scheduled_by)->toBeNull();
    });
});

it('keeps the post offline and back in the queue when the approver loses the right before the time', function (): void {
    [$author, $approver] = laterPeople();
    $categoryId = laterSection();

    $post = app(Tenancy::class)->enter(1, function () use ($author, $approver, $categoryId): Entry {
        $post = laterPost($categoryId, $author);
        app(ApproveEntryForLater::class)->approve($post, 'vi', $approver, 'approve', now()->addHour());
        // Their role is changed to review only.
        $approver->syncRoles([]);
        grantRole($approver, 'reviewer-only', ['entries.review']);

        return $post;
    });

    $this->travel(2)->hours();
    $this->artisan('entries:publish-due')->expectsOutputToContain('1 approved entr(y/ies) held back')->assertSuccessful();

    app(Tenancy::class)->enter(1, function () use ($post): void {
        $fresh = $post->fresh();
        expect($fresh->status)->toBe('pending')
            ->and($fresh->workflow_state)->toBe('pending')
            ->and($fresh->scheduled_by)->toBeNull()
            ->and($fresh->published_at)->toBeNull()
            ->and(SiteRoute::query()->where('entry_id', $post->id)->exists())->toBeFalse();
        // back where someone who may approve it sees it
        $other = User::factory()->create();
        grantRole($other, 'approver', ['entries.review', 'entries.approve']);
        Livewire::actingAs($other->fresh())->test(ReviewInbox::class)->assertSee('Bản tin hẹn giờ');
    });
    expect(laterNotices(1, $author))->toBe(['approved', 'failed_right'])
        ->and(laterNotices(1, $approver))->toBe(['failed_right']);
});

it('keeps the post offline when the flow changes before the time', function (string $change): void {
    [$author, $approver] = laterPeople();
    $categoryId = laterSection();

    $post = app(Tenancy::class)->enter(1, function () use ($author, $approver, $categoryId, $change): Entry {
        $post = laterPost($categoryId, $author);
        app(ApproveEntryForLater::class)->approve($post, 'vi', $approver, 'approve', now()->addHour());
        $workflow = Workflow::query()->firstOrFail();
        match ($change) {
            'move removed' => $workflow->transitions()->where('key', 'approve')->delete(),
            'move asks a reason' => $workflow->transitions()->where('key', 'approve')->update(['requires_note' => true]),
            'flow switched off' => $workflow->update(['is_active' => false]),
        };

        return $post;
    });

    $this->travel(2)->hours();
    $this->artisan('entries:publish-due')->expectsOutputToContain('1 approved entr(y/ies) held back')->assertSuccessful();

    app(Tenancy::class)->enter(1, function () use ($post): void {
        expect($post->fresh()->status)->toBe('pending')
            ->and($post->fresh()->scheduled_by)->toBeNull()
            ->and(SiteRoute::query()->where('entry_id', $post->id)->exists())->toBeFalse();
    });
    expect(laterNotices(1, $author))->toBe(['approved', 'failed_flow']);
})->with(['move removed', 'move asks a reason', 'flow switched off']);

it('cancels an approval for later back to waiting, without a stage move, only for someone who could approve it', function (): void {
    [$author, $approver] = laterPeople();
    $categoryId = laterSection();

    app(Tenancy::class)->enter(1, function () use ($author, $approver, $categoryId): void {
        $post = laterPost($categoryId, $author);
        app(ApproveEntryForLater::class)->approve($post, 'vi', $approver, 'approve', now()->addDay());
        $logBefore = WorkflowLog::query()->where('entry_id', $post->id)->count();

        // the writer may not take back an approval
        expect(fn () => app(TransitionEntryStatus::class)->unschedule($post->fresh(), $author))
            ->toThrow(AuthorizationException::class);
        expect($post->fresh()->status)->toBe('scheduled');

        expect(app(TransitionEntryStatus::class)->unschedule($post->fresh(), $approver))->toBeTrue();
        $fresh = $post->fresh();
        expect($fresh->status)->toBe('pending')
            ->and($fresh->workflow_state)->toBe('pending')
            ->and($fresh->scheduled_by)->toBeNull()
            ->and($fresh->published_at)->toBeNull()
            ->and(WorkflowLog::query()->where('entry_id', $post->id)->count())->toBe($logBefore);
        Livewire::actingAs($approver)->test(ReviewInbox::class)->assertSee('Bản tin hẹn giờ');
    });
    expect(laterNotices(1, $author))->toBe(['approved', 'cancelled']);
});

it('never publishes a post whose approver account is gone: the key goes NULL and the sweep skips and counts it', function (): void {
    [$author, $approver] = laterPeople();
    $categoryId = laterSection();

    $post = app(Tenancy::class)->enter(1, function () use ($author, $approver, $categoryId): Entry {
        $post = laterPost($categoryId, $author);
        app(ApproveEntryForLater::class)->approve($post, 'vi', $approver, 'approve', now()->addHour());

        return $post;
    });
    DB::connection('pgsql_admin')->table('users')->where('id', $approver->id)->delete();

    $this->travel(2)->hours();
    $this->artisan('entries:publish-due')->expectsOutputToContain('skipped 1 governed')->assertSuccessful();

    app(Tenancy::class)->enter(1, function () use ($post): void {
        expect($post->fresh()->status)->toBe('scheduled')
            ->and($post->fresh()->scheduled_by)->toBeNull()
            ->and(SiteRoute::query()->where('entry_id', $post->id)->exists())->toBeFalse();
    });
});

it('judges each site on its own: an approval, its rights and its sweep stay inside the post\'s website', function (): void {
    [$author, $approver] = laterPeople();
    $categoryA = laterSection(1);
    $categoryB = laterSection(2);
    $writerB = User::factory()->create();

    $postB = app(Tenancy::class)->enter(2, function () use ($writerB, $categoryB): Entry {
        grantRole($writerB, 'author', ['entries.create', 'entries.edit.own', 'entries.submit']);

        return laterPost($categoryB, $writerB->fresh(), 'ban-tin-b');
    });

    app(Tenancy::class)->enter(1, function () use ($author, $approver, $categoryA, $postB): void {
        laterPost($categoryA, $author);
        // site B's post is not in site A's inbox, so it cannot be approved from there
        Livewire::actingAs($approver)->test(ReviewInbox::class)
            ->call('open', (string) $postB->ulid)
            ->call('approveLater', 'approve', laterAt())->assertHasErrors('inbox');
    });

    // Site A's approver holds no role on site B: approving there is refused, judged in B's team.
    app(Tenancy::class)->enter(2, function () use ($approver, $postB): void {
        app(TeamContext::class)->forget();
        expect(fn () => app(ApproveEntryForLater::class)->approve($postB->fresh(), 'vi', $approver->fresh(), 'approve', now()->addHour()))
            ->toThrow(AuthorizationException::class);
        // planted as if it had been: the sweep judges the approver's right on site B, and holds it back
        Entry::query()->whereKey($postB->id)->update(['status' => 'scheduled', 'published_at' => now()->addHour(), 'scheduled_by' => $approver->id]);
    });

    $this->travel(2)->hours();
    $this->artisan('entries:publish-due')->expectsOutputToContain('1 approved entr(y/ies) held back')->assertSuccessful();

    app(Tenancy::class)->enter(2, function () use ($postB): void {
        expect($postB->fresh()->status)->toBe('pending')
            ->and(SiteRoute::query()->where('entry_id', $postB->id)->exists())->toBeFalse();
    });
    expect(laterNotices(2, $approver))->toBe(['failed_right'])
        ->and(laterNotices(1, $approver))->toBe([]);
});

it('locks the editor on a post approved for later, and «Duyệt đăng N bài» still publishes at once', function (): void {
    [$author, $approver] = laterPeople();
    $categoryId = laterSection();

    app(Tenancy::class)->enter(1, function () use ($author, $approver, $categoryId): void {
        $later = laterPost($categoryId, $author, 'sau');
        $now = laterPost($categoryId, $author, 'ngay');
        app(ApproveEntryForLater::class)->approve($later, 'vi', $approver, 'approve', now()->addDay());

        Livewire::actingAs($author)->test(Editor::class, ['ulid' => $later->ulid])
            ->set('title', 'Sửa lén')
            ->call('save')
            ->assertHasErrors('workflow');
        expect($later->fresh()->translationFor('vi')?->title)->toBe('Bản tin hẹn giờ');

        Livewire::actingAs($approver)->test(ReviewInbox::class)
            ->call('togglePick', (string) $now->ulid)
            ->call('togglePick', (string) $later->ulid)
            ->call('approvePicked');
        expect($now->fresh()->status)->toBe('published')
            ->and($now->fresh()->scheduled_by)->toBeNull()
            ->and($later->fresh()->status)->toBe('scheduled');
    });
});

it('offers «Hủy hẹn giờ» on a post approved for later in the list and the editor, naming the stage it goes back to', function (): void {
    [$author, $approver] = laterPeople();
    $editor = User::factory()->create(['name' => 'Biên tập']);
    app(Tenancy::class)->enter(1, fn () => grantRole($editor, 'editor', ['entries.view', 'entries.edit.any', 'entries.review', 'entries.approve']));
    $categoryId = laterSection();

    app(Tenancy::class)->enter(1, function () use ($author, $approver, $editor, $categoryId): void {
        $inList = laterPost($categoryId, $author, 'trong-danh-sach');
        $inEditor = laterPost($categoryId, $author, 'trong-trinh-sua');
        app(ApproveEntryForLater::class)->approve($inList, 'vi', $approver, 'approve', now()->addDay());
        app(ApproveEntryForLater::class)->approve($inEditor, 'vi', $approver, 'approve', now()->addDay());
        $editor = $editor->fresh();

        // the writer is offered no way to take back an approval
        expect(Livewire::actingAs($author)->test(Editor::class, ['ulid' => $inEditor->ulid])->viewData('scheduleBand'))->toBeNull();

        $band = Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $inEditor->ulid])->viewData('scheduleBand');
        expect($band)->not->toBeNull()
            ->and($band['sub'])->toContain('«Chờ duyệt»')
            ->and($band['ask']['text'])->toContain('«Chờ duyệt»');
        Livewire::actingAs($editor)->test(Editor::class, ['ulid' => $inEditor->ulid])
            ->call('unschedule')
            ->assertDispatched('bs-toast', fn (string $name, array $params): bool => str_contains((string) $params['text'], '«Chờ duyệt»'));
        expect($inEditor->fresh()->status)->toBe('pending');

        $list = Livewire::actingAs($editor)->test(Index::class, ['section' => 'posts']);
        $list->assertSeeHtml('data-unsched data-row="'.$inList->ulid.'"')
            ->call('unschedule', (string) $inList->ulid)
            ->assertDispatched('bs-toast', fn (string $name, array $params): bool => str_contains((string) $params['text'], '«Chờ duyệt»')
                && ! str_contains((string) $params['text'], '«'.__('entries.status_draft').'»'));
        expect($inList->fresh()->status)->toBe('pending')
            ->and($inList->fresh()->workflow_state)->toBe('pending');
    });
});

it('refuses the list\'s quick title and address edits on a post approved for later', function (): void {
    [$author, $approver] = laterPeople();
    $editor = User::factory()->create(['name' => 'Biên tập']);
    app(Tenancy::class)->enter(1, fn () => grantRole($editor, 'editor', ['entries.view', 'entries.edit.any', 'entries.review', 'entries.approve']));
    $categoryId = laterSection();

    app(Tenancy::class)->enter(1, function () use ($author, $approver, $editor, $categoryId): void {
        $post = laterPost($categoryId, $author);
        app(ApproveEntryForLater::class)->approve($post, 'vi', $approver, 'approve', now()->addDay());

        Livewire::actingAs($editor->fresh())->test(Index::class, ['section' => 'posts'])
            ->call('saveTitle', (string) $post->ulid, 'Sửa lén')
            ->assertDispatched('bs-toast', fn (string $name, array $params): bool => $params['text'] === __('entries.err_approved_later'))
            ->call('saveSlug', (string) $post->ulid, 'sua-len')
            ->assertDispatched('bs-toast', fn (string $name, array $params): bool => $params['text'] === __('entries.err_approved_later'));
        $t = $post->fresh()->translationFor('vi');
        expect($t?->title)->toBe('Bản tin hẹn giờ')
            ->and($t?->slug)->toBe('ban-tin')
            ->and($post->fresh()->status)->toBe('scheduled');
    });
});

it('never approves for later through a move that asks for a reason, and a move elsewhere drops the approved time', function (): void {
    [$author, $approver] = laterPeople();
    $categoryId = laterSection();

    app(Tenancy::class)->enter(1, function () use ($author, $approver, $categoryId): void {
        $post = laterPost($categoryId, $author);
        WorkflowTransition::query()->where('key', 'approve')->update(['requires_note' => true]);
        expect(fn () => app(ApproveEntryForLater::class)->approve($post->fresh(), 'vi', $approver, 'approve', now()->addDay()))
            ->toThrow(InvalidTransitionException::class);
        expect($post->fresh()->status)->toBe('pending');
        WorkflowTransition::query()->where('key', 'approve')->update(['requires_note' => false]);

        app(ApproveEntryForLater::class)->approve($post->fresh(), 'vi', $approver, 'approve', now()->addDay());
        app(ApplyEntryWorkflowTransition::class)->handle($post->fresh(), 'vi', $approver->fresh(), 'reject', 'Cần sửa lại phần mở đầu');
        $fresh = $post->fresh();
        expect($fresh->status)->toBe('draft')
            ->and($fresh->scheduled_by)->toBeNull()
            ->and($fresh->published_at)->toBeNull();
    });
});

it('keeps a post approved for later as it was approved: no older version restored, no move offered in the editor', function (): void {
    [$author, $approver] = laterPeople();
    $editor = User::factory()->create(['name' => 'Biên tập']);
    app(Tenancy::class)->enter(1, fn () => grantRole($editor, 'editor', ['entries.view', 'entries.edit.any', 'entries.review', 'entries.approve']));
    $categoryId = laterSection();

    app(Tenancy::class)->enter(1, function () use ($author, $approver, $editor, $categoryId): void {
        $post = laterPost($categoryId, $author);
        $translation = $post->translationFor('vi');
        $translation->update(['title' => 'Bản cũ']);
        app(RecordEntryRevision::class)->forLocale($post->fresh(), $translation->fresh(), RevisionKind::Edit, $author);
        $old = EntryRevision::query()->where('entry_id', $post->id)->orderByDesc('id')->firstOrFail();
        $translation->update(['title' => 'Bản tin hẹn giờ']);
        app(ApproveEntryForLater::class)->approve($post->fresh(), 'vi', $approver, 'approve', now()->addDay());

        expect(fn () => app(RestoreEntryRevision::class)->handle($old, $editor->fresh()))
            ->toThrow(RevisionRestoreRejectedException::class, 'approved_later');
        expect($post->fresh()->translationFor('vi')?->title)->toBe('Bản tin hẹn giờ');

        $panel = Livewire::actingAs($editor->fresh())->test(Editor::class, ['ulid' => $post->ulid])->viewData('workflow');
        expect($panel['actions'] ?? null)->toBe([]);
    });
});
