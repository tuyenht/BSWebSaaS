<?php

use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Admin\Livewire\Entries\Index;
use App\Modules\Admin\Livewire\Entries\ReviewInbox;
use App\Modules\Cms\Actions\ApplyEntryWorkflowTransition;
use App\Modules\Cms\Actions\ApproveEntryForLater;
use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Actions\RecordEntryRevision;
use App\Modules\Cms\Actions\RestoreEntryRevision;
use App\Modules\Cms\Actions\TransitionEntryStatus;
use App\Modules\Cms\Enums\EntryStatus;
use App\Modules\Cms\Enums\RevisionKind;
use App\Modules\Cms\Exceptions\RevisionRestoreRejectedException;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryRevision;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\EntryWorkflowResolver;
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

/** The sweep's one summary line, as entries:publish-due prints it (no failures, no site left over). */
function laterSweepSays(int $published, int $held, int $skipped = 0): string
{
    return "Published {$published} scheduled entr(y/ies), skipped {$skipped} governed by an approval workflow, 0 failed, 0 site(s) left for the next run after a lock conflict. {$held} approved entr(y/ies) held back and waiting for review again.";
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

        Livewire::actingAs($approver)->test(ReviewInbox::class)->set('tab', 'queue')
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

        Livewire::actingAs($approver)->test(ReviewInbox::class)->set('tab', 'queue')
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
        Livewire::actingAs($approver)->test(ReviewInbox::class)->set('tab', 'queue')
            ->call('open', $post->ulid)
            ->call('approveLater', 'reject', laterAt())->assertHasErrors('inbox')
            ->call('approveLater', 'nope', laterAt())->assertHasErrors('inbox');
        // A post that is not open in this inbox.
        Livewire::actingAs($approver)->test(ReviewInbox::class)->set('tab', 'queue')
            ->call('open', '01JUNKUNKNOWNULID000000000')
            ->call('approveLater', 'approve', laterAt())->assertHasErrors('inbox');
        // Seeing the post is not approving it.
        Livewire::actingAs($reviewer->fresh())->test(ReviewInbox::class)->set('tab', 'queue')
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
        Livewire::actingAs($other->fresh())->test(ReviewInbox::class)->set('tab', 'queue')->assertSee('Bản tin hẹn giờ');
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
})->with(['move removed', 'move asks a reason']);

it('publishes at its time, like any scheduled post, a post approved for later whose flow was switched off since', function (bool $approverGone): void {
    [$author, $approver] = laterPeople();
    $categoryId = laterSection();
    $at = now()->addHour();

    $post = app(Tenancy::class)->enter(1, function () use ($author, $approver, $categoryId, $at): Entry {
        $post = laterPost($categoryId, $author);
        app(ApproveEntryForLater::class)->approve($post, 'vi', $approver, 'approve', $at);
        Workflow::query()->firstOrFail()->update(['is_active' => false]);

        return $post;
    });
    if ($approverGone) {
        DB::connection('pgsql_admin')->table('users')->where('id', $approver->id)->delete();
    }

    $this->travel(2)->hours();
    $this->artisan('entries:publish-due')
        ->expectsOutput(laterSweepSays(published: 1, held: 0))
        ->assertSuccessful();

    app(Tenancy::class)->enter(1, function () use ($post, $at): void {
        $fresh = $post->fresh();
        expect($fresh->status)->toBe('published')
            ->and($fresh->scheduled_by)->toBeNull()
            ->and($fresh->workflow_state)->toBeNull()   // a flow switched back on reads it at its published stage
            ->and($fresh->published_at?->timestamp)->toBe($at->timestamp)   // the approved time, as a scheduled post keeps its slot
            ->and($fresh->translationFor('vi')?->status)->toBe('published')
            ->and(SiteRoute::query()->where('entry_id', $post->id)->exists())->toBeTrue();
    });
    // the author was told the time, and now that it went live
    expect(laterNotices(1, $author))->toBe(['approved', 'published']);
})->with(['approver kept' => false, 'approver deleted' => true]);

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
        Livewire::actingAs($approver)->test(ReviewInbox::class)->set('tab', 'queue')->assertSee('Bản tin hẹn giờ');
    });
    expect(laterNotices(1, $author))->toBe(['approved', 'cancelled']);
});

it('holds back a post whose approver account is gone while the flow is on: back to waiting, its author told, counted', function (): void {
    [$author, $approver] = laterPeople();
    $categoryId = laterSection();

    $post = app(Tenancy::class)->enter(1, function () use ($author, $approver, $categoryId): Entry {
        $post = laterPost($categoryId, $author);
        app(ApproveEntryForLater::class)->approve($post, 'vi', $approver, 'approve', now()->addHour());

        return $post;
    });
    DB::connection('pgsql_admin')->table('users')->where('id', $approver->id)->delete();

    // Before its time it already reads as waiting: back in the inbox of someone who may approve it, no «Hủy hẹn giờ».
    app(Tenancy::class)->enter(1, function () use ($post): void {
        expect($post->fresh()->scheduled_by)->toBeNull()
            ->and($post->fresh()->approvedForLater())->toBeFalse();
        $other = User::factory()->create();
        grantRole($other, 'approver', ['entries.review', 'entries.approve']);
        Livewire::actingAs($other->fresh())->test(ReviewInbox::class)->set('tab', 'queue')->assertSee('Bản tin hẹn giờ');
        expect(app(ApproveEntryForLater::class)->mayCancel($post->fresh(), $other->fresh()))->toBeFalse();
    });

    $this->travel(2)->hours();
    $this->artisan('entries:publish-due')
        ->expectsOutput(laterSweepSays(published: 0, held: 1))
        ->assertSuccessful();

    app(Tenancy::class)->enter(1, function () use ($post): void {
        $fresh = $post->fresh();
        expect($fresh->status)->toBe('pending')
            ->and($fresh->workflow_state)->toBe('pending')
            ->and($fresh->scheduled_by)->toBeNull()
            ->and($fresh->published_at)->toBeNull()
            ->and(SiteRoute::query()->where('entry_id', $post->id)->exists())->toBeFalse();
    });
    expect(laterNotices(1, $author))->toBe(['approved', 'failed_approver']);

    // held once: the next minute's run finds nothing due
    $this->artisan('entries:publish-due')->expectsOutput(laterSweepSays(published: 0, held: 0))->assertSuccessful();
    expect(laterNotices(1, $author))->toBe(['approved', 'failed_approver']);
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
        Livewire::actingAs($approver)->test(ReviewInbox::class)->set('tab', 'queue')
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

        Livewire::actingAs($approver)->test(ReviewInbox::class)->set('tab', 'queue')
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

it('sweeps two websites in one run and judges the approver on each: live where they hold the right, held where they do not', function (int $rightsOn): void {
    $approver = User::factory()->create(['name' => 'Người duyệt']);
    $categories = [1 => laterSection(1), 2 => laterSection(2)];
    $posts = [];
    foreach ([1, 2] as $site) {
        $writer = User::factory()->create();
        $posts[$site] = app(Tenancy::class)->enter($site, function () use ($site, $writer, $approver, $categories, $rightsOn): Entry {
            grantRole($writer, 'author', ['entries.create', 'entries.edit.own', 'entries.submit']);
            $post = laterPost($categories[$site], $writer->fresh(), 'ban-tin-'.$site);
            if ($site === $rightsOn) {
                grantRole($approver, 'approver', ['entries.review', 'entries.approve']);
                app(ApproveEntryForLater::class)->approve($post, 'vi', $approver->fresh(), 'approve', now()->addHour());
            } else {
                // planted as if approved here: the sweep must judge this website's rights, not the other's
                Entry::query()->whereKey($post->id)->update(['status' => 'scheduled', 'published_at' => now()->addHour(), 'scheduled_by' => $approver->id]);
            }

            return $post;
        });
    }

    $this->travel(2)->hours();
    $this->artisan('entries:publish-due')
        ->expectsOutput(laterSweepSays(published: 1, held: 1))
        ->assertSuccessful();

    $other = 3 - $rightsOn;
    app(Tenancy::class)->enter($rightsOn, function () use ($posts, $rightsOn): void {
        expect($posts[$rightsOn]->fresh()->status)->toBe('published')
            ->and(SiteRoute::query()->where('entry_id', $posts[$rightsOn]->id)->exists())->toBeTrue();
    });
    app(Tenancy::class)->enter($other, function () use ($posts, $other): void {
        expect($posts[$other]->fresh()->status)->toBe('pending')
            ->and(SiteRoute::query()->where('entry_id', $posts[$other]->id)->exists())->toBeFalse();
    });
    expect(laterNotices($other, $approver))->toBe(['failed_right'])
        ->and(laterNotices($rightsOn, $approver))->toBe([]);
})->with(['rights on the first website' => 1, 'rights on the second' => 2]);

it('lets a cancel and the sweep meet on one post, and two sweeps on another, without a late or double publication', function (): void {
    [$author, $approver] = laterPeople();
    $categoryId = laterSection();

    [$cancelled, $swept] = app(Tenancy::class)->enter(1, function () use ($author, $approver, $categoryId): array {
        $cancelled = laterPost($categoryId, $author, 'huy');
        $swept = laterPost($categoryId, $author, 'quet');
        app(ApproveEntryForLater::class)->approve($cancelled, 'vi', $approver, 'approve', now()->addHour());
        app(ApproveEntryForLater::class)->approve($swept, 'vi', $approver, 'approve', now()->addHour());

        return [$cancelled, $swept];
    });
    $this->travel(2)->hours();

    app(Tenancy::class)->enter(1, function () use ($cancelled, $swept, $approver): void {
        $later = app(ApproveEntryForLater::class);
        // Both rows read as due, as the sweep reads them; then «Hủy hẹn giờ» lands on one before the sweep gets to it.
        $staleCancelled = Entry::query()->findOrFail($cancelled->id);
        $firstRead = Entry::query()->findOrFail($swept->id);
        $secondRead = Entry::query()->findOrFail($swept->id);
        expect(app(TransitionEntryStatus::class)->unschedule($cancelled->fresh(), $approver))->toBeTrue()
            ->and($later->publishDue($staleCancelled))->toBe('not_due');
        expect($cancelled->fresh()->status)->toBe('pending')
            ->and(SiteRoute::query()->where('entry_id', $cancelled->id)->exists())->toBeFalse();

        // Two sweeps that read the same due row: the first puts it live, the second writes nothing.
        $logBefore = WorkflowLog::query()->where('entry_id', $swept->id)->count();
        expect($later->publishDue($firstRead))->toBe('published')
            ->and($later->publishDue($secondRead))->toBe('not_due')
            ->and(WorkflowLog::query()->where('entry_id', $swept->id)->count())->toBe($logBefore + 1)
            ->and(SiteRoute::query()->where('entry_id', $swept->id)->count())->toBe(1)
            // and a cancel that comes after it went live leaves it live
            ->and($later->cancel($swept->fresh(), $approver))->toBeFalse()
            ->and($swept->fresh()->status)->toBe('published');
    });

    $this->artisan('entries:publish-due')
        ->expectsOutput(laterSweepSays(published: 0, held: 0))
        ->assertSuccessful();
    expect(laterNotices(1, $author))->toBe(['approved', 'approved', 'cancelled', 'published']);
});

it('takes back an approval for later the «Hủy hẹn giờ» way when the status menu sends the post to a draft, its flow switched off since', function (): void {
    [$author, $approver] = laterPeople();
    $publisher = User::factory()->create(['name' => 'Biên tập']);
    $categoryId = laterSection();

    app(Tenancy::class)->enter(1, function () use ($author, $approver, $publisher, $categoryId): void {
        grantRole($publisher, 'publisher', ['entries.view', 'entries.edit.any', 'entries.publish']);
        $one = laterPost($categoryId, $author, 'mot');
        $two = laterPost($categoryId, $author, 'hai');
        $three = laterPost($categoryId, $author, 'ba');
        $four = laterPost($categoryId, $author, 'bon');
        foreach ([$one, $two, $three, $four] as $post) {
            app(ApproveEntryForLater::class)->approve($post, 'vi', $approver, 'approve', now()->addDay());
        }
        Workflow::query()->firstOrFail()->update(['is_active' => false]);
        $publisher = $publisher->fresh();

        // the writer may not take it back by this road either
        expect(fn () => app(TransitionEntryStatus::class)->handle($one->fresh(), EntryStatus::Draft, $author->fresh()))
            ->toThrow(AuthorizationException::class);
        expect($one->fresh()->approvedForLater())->toBeTrue();

        Livewire::actingAs($publisher)->test(Index::class, ['section' => 'posts'])
            ->call('changeStatus', (string) $one->ulid, 'draft')
            ->assertDispatched('bs-toast', fn (string $name, array $params): bool => $params['text'] === __('entries.status_changed'));
        Livewire::actingAs($publisher)->test(Index::class, ['section' => 'posts'])
            ->set('selected', [(string) $two->ulid])
            ->call('bulkDraft');
        foreach ([$one, $two] as $post) {
            $fresh = $post->fresh();
            expect($fresh->status)->toBe('draft')
                ->and($fresh->scheduled_by)->toBeNull()
                ->and($fresh->published_at)->toBeNull()
                ->and($fresh->workflow_state)->toBeNull();
        }

        // a new time from the status menu is the publisher's own schedule: no approval stands behind it
        app(TransitionEntryStatus::class)->handle($three->fresh(), EntryStatus::Scheduled, $publisher, now()->addDays(2));
        expect($three->fresh()->status)->toBe('scheduled')
            ->and($three->fresh()->scheduled_by)->toBeNull()
            ->and($three->fresh()->workflow_state)->toBeNull();

        // «Gỡ khỏi website» from the editor's status pick: cancelled first, then archived, and the notice says so
        app(TransitionEntryStatus::class)->handle($four->fresh(), EntryStatus::Archived, $publisher);
        expect($four->fresh()->status)->toBe('archived')
            ->and($four->fresh()->scheduled_by)->toBeNull()
            ->and($four->fresh()->published_at)->toBeNull();
    });
    expect(laterNotices(1, $author))->toBe(['approved', 'approved', 'approved', 'approved', 'cancelled_draft', 'cancelled_draft', 'cancelled_archived']);
});

it('asks the «Hủy hẹn giờ» right of a status-menu draft move on a post approved for later under its flow', function (): void {
    [$author, $approver] = laterPeople();
    $publisher = User::factory()->create(['name' => 'Xuất bản']);
    $chief = User::factory()->create(['name' => 'Tổng biên tập']);
    $categoryId = laterSection();

    app(Tenancy::class)->enter(1, function () use ($author, $approver, $publisher, $chief, $categoryId): void {
        // may publish outside a flow, may not approve inside it
        grantRole($publisher, 'publisher', ['entries.view', 'entries.edit.any', 'entries.publish']);
        grantRole($chief, 'chief', ['entries.view', 'entries.edit.any', 'entries.publish', 'entries.review', 'entries.approve']);
        $post = laterPost($categoryId, $author);
        app(ApproveEntryForLater::class)->approve($post, 'vi', $approver, 'approve', now()->addDay());

        expect(fn () => app(TransitionEntryStatus::class)->handle($post->fresh(), EntryStatus::Draft, $publisher->fresh()))
            ->toThrow(AuthorizationException::class);
        expect($post->fresh()->approvedForLater())->toBeTrue()
            ->and($post->fresh()->published_at)->not->toBeNull();

        app(TransitionEntryStatus::class)->handle($post->fresh(), EntryStatus::Draft, $chief->fresh());
        $fresh = $post->fresh();
        expect($fresh->status)->toBe('draft')
            ->and($fresh->scheduled_by)->toBeNull()
            ->and($fresh->published_at)->toBeNull()
            ->and($fresh->workflow_state)->toBeNull();
    });
    // it ends as a draft, and the author is told that, not that it waits at «Chờ duyệt»
    expect(laterNotices(1, $author))->toBe(['approved', 'cancelled_draft']);
});

it('holds a governed post scheduled with no approver back at its waiting stage, and leaves one whose stale stage is a published one', function (): void {
    [$author] = laterPeople();
    $categoryId = laterSection();

    [$waiting, $stale] = app(Tenancy::class)->enter(1, function () use ($author, $categoryId): array {
        $waiting = laterPost($categoryId, $author, 'cho');
        $stale = laterPost($categoryId, $author, 'cu');
        // as rows scheduled from the status menu while the flow was off, before that cleared the stage
        Entry::query()->whereKey($waiting->id)->update(['status' => 'scheduled', 'published_at' => now()->addHour(), 'workflow_state' => 'pending']);
        Entry::query()->whereKey($stale->id)->update(['status' => 'scheduled', 'published_at' => now()->addHour(), 'workflow_state' => 'published']);

        return [$waiting, $stale];
    });

    $this->travel(2)->hours();
    $this->artisan('entries:publish-due')->expectsOutput(laterSweepSays(published: 0, held: 1, skipped: 1))->assertSuccessful();

    app(Tenancy::class)->enter(1, function () use ($waiting, $stale): void {
        expect($waiting->fresh()->status)->toBe('pending')
            ->and($waiting->fresh()->workflow_state)->toBe('pending')
            ->and($stale->fresh()->status)->toBe('scheduled')
            ->and($stale->fresh()->workflow_state)->toBe('published')
            ->and(SiteRoute::query()->whereIn('entry_id', [$waiting->id, $stale->id])->exists())->toBeFalse();
    });
    expect(laterNotices(1, $author))->toBe(['failed_approver']);
});

it('takes a post approved for later from the flow\'s initial stage back to a draft when it is held or cancelled, and says so', function (): void {
    [$author, $approver] = laterPeople();
    $categoryId = laterSection();

    [$held, $cancelled] = app(Tenancy::class)->enter(1, function () use ($author, $approver, $categoryId): array {
        // a custom flow that lets an approver publish straight from the draft stage
        Workflow::query()->firstOrFail()->transitions()->create([
            'key' => 'quick', 'label' => 'Đăng thẳng', 'from_state' => 'draft', 'to_state' => 'published', 'required_permission' => 'entries.approve',
        ]);
        grantRole($approver, 'approver', ['entries.review', 'entries.approve', 'entries.edit.any']);
        $posts = [];
        foreach (['giu', 'huy'] as $slug) {
            $post = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'author_id' => $author->id]);
            $post->translations()->create(['locale' => 'vi', 'title' => 'Bản nháp hẹn giờ', 'slug' => $slug, 'status' => 'draft', 'body' => ['blocks' => []]]);
            $post->assignCategories([$categoryId]);
            app(ApproveEntryForLater::class)->approve($post->fresh(), 'vi', $approver->fresh(), 'quick', now()->addHour());
            expect($post->fresh()->workflow_state)->toBe('draft');
            $posts[] = $post->fresh();
        }

        // «Hủy hẹn giờ»: a draft again, not `pending` at a stage nobody reviews
        expect(app(ApproveEntryForLater::class)->cancel($posts[1], $approver->fresh()))->toBeTrue();
        expect($posts[1]->fresh()->status)->toBe('draft')
            ->and($posts[1]->fresh()->workflow_state)->toBe('draft');

        // the approver loses the right before the time
        $approver->syncRoles([]);
        grantRole($approver, 'reviewer-only', ['entries.review']);

        return $posts;
    });

    $this->travel(2)->hours();
    $this->artisan('entries:publish-due')->expectsOutput(laterSweepSays(published: 0, held: 1))->assertSuccessful();

    app(Tenancy::class)->enter(1, function () use ($held): void {
        $fresh = $held->fresh();
        expect($fresh->status)->toBe('draft')
            ->and($fresh->workflow_state)->toBe('draft')
            ->and($fresh->scheduled_by)->toBeNull()
            ->and($fresh->published_at)->toBeNull()
            ->and(SiteRoute::query()->where('entry_id', $held->id)->exists())->toBeFalse();
    });
    // the notices name the outcome: a draft to be sent for review, not «duyệt lại»
    expect(laterNotices(1, $author))->toBe(['approved', 'approved', 'cancelled_draft', 'failed_draft'])
        ->and(laterNotices(1, $approver))->toBe(['failed_draft'])
        ->and(__('notifications.workflow.scheduled.failed_draft', ['title' => 'x'], 'vi'))->toContain('bản nháp');
});

it('lets another approver approve for later again a post whose approver account was deleted before its time', function (): void {
    [$author, $approver] = laterPeople();
    $categoryId = laterSection();
    $other = User::factory()->create(['name' => 'Người duyệt mới']);

    $post = app(Tenancy::class)->enter(1, function () use ($author, $approver, $other, $categoryId): Entry {
        grantRole($other, 'approver', ['entries.review', 'entries.approve']);
        $post = laterPost($categoryId, $author);
        app(ApproveEntryForLater::class)->approve($post, 'vi', $approver, 'approve', now()->addHour());

        return $post;
    });
    DB::connection('pgsql_admin')->table('users')->where('id', $approver->id)->delete();

    app(Tenancy::class)->enter(1, function () use ($post, $other): void {
        expect($post->fresh()->status)->toBe('scheduled')
            ->and($post->fresh()->scheduled_by)->toBeNull();
        $at = laterAt(10);
        Livewire::actingAs($other->fresh())->test(ReviewInbox::class)->set('tab', 'queue')
            ->call('open', $post->ulid)
            ->call('approveLater', 'approve', $at)
            ->assertReturned(null)
            ->assertHasNoErrors();
        $fresh = $post->fresh();
        expect($fresh->status)->toBe('scheduled')
            ->and($fresh->scheduled_by)->toBe($other->id)
            ->and($fresh->workflow_state)->toBe('pending')
            ->and(app(SiteTime::class)->forDisplay($fresh->published_at)?->format('Y-m-d\TH:i'))->toBe($at);

        // still once only: approved for later again, it is not approved a second time
        expect(fn () => app(ApproveEntryForLater::class)->approve($post->fresh(), 'vi', $other->fresh(), 'approve', now()->addDays(3)))
            ->toThrow(InvalidTransitionException::class);
    });

    $this->travel(2)->days();
    $this->artisan('entries:publish-due')->expectsOutput(laterSweepSays(published: 1, held: 0))->assertSuccessful();
    app(Tenancy::class)->enter(1, function () use ($post, $other): void {
        expect($post->fresh()->status)->toBe('published')
            ->and(WorkflowLog::query()->where('entry_id', $post->id)->orderByDesc('id')->value('actor_id'))->toBe($other->id);
    });
});

it('clears the approved stage when a post approved for later is published at once with its flow off, so a flow switched back on reads it as live', function (): void {
    [$author, $approver] = laterPeople();
    $publisher = User::factory()->create(['name' => 'Biên tập']);
    $categoryId = laterSection();

    app(Tenancy::class)->enter(1, function () use ($author, $approver, $publisher, $categoryId): void {
        grantRole($publisher, 'publisher', ['entries.view', 'entries.edit.any', 'entries.publish']);
        $post = laterPost($categoryId, $author);
        app(ApproveEntryForLater::class)->approve($post, 'vi', $approver, 'approve', now()->addDay());
        $workflow = Workflow::query()->firstOrFail();
        $workflow->update(['is_active' => false]);

        app(PublishEntry::class)->handle($post->fresh(), 'vi', $publisher->fresh());
        $fresh = $post->fresh();
        expect($fresh->status)->toBe('published')
            ->and($fresh->scheduled_by)->toBeNull()
            ->and($fresh->workflow_state)->toBeNull();

        // the flow back on: the live post stands at its published stage, waiting for nobody
        $workflow->update(['is_active' => true]);
        expect(EntryWorkflowResolver::originIn($workflow->fresh(), $post->fresh()))->toBe('published');
        Livewire::actingAs($approver)->test(ReviewInbox::class)->set('tab', 'queue')->assertDontSee('Bản tin hẹn giờ');
    });
});

it('takes a post whose approved stage left its flow back to a draft, its languages with it', function (): void {
    [$author, $approver] = laterPeople();
    $categoryId = laterSection();

    $post = app(Tenancy::class)->enter(1, function () use ($author, $approver, $categoryId): Entry {
        $post = laterPost($categoryId, $author);
        expect($post->translationFor('vi')?->status)->toBe('pending');
        app(ApproveEntryForLater::class)->approve($post, 'vi', $approver, 'approve', now()->addHour());
        // the stage it was approved at is no longer one its flow defines: it reads at the flow's start
        Entry::query()->whereKey($post->id)->update(['workflow_state' => 'gone']);

        return $post;
    });

    $this->travel(2)->hours();
    $this->artisan('entries:publish-due')->expectsOutput(laterSweepSays(published: 0, held: 1))->assertSuccessful();

    app(Tenancy::class)->enter(1, function () use ($post): void {
        $fresh = $post->fresh();
        expect($fresh->status)->toBe('draft')
            ->and($fresh->translationFor('vi')?->status)->toBe('draft')
            ->and($fresh->scheduled_by)->toBeNull()
            ->and(SiteRoute::query()->where('entry_id', $post->id)->exists())->toBeFalse();
    });
    expect(laterNotices(1, $author))->toBe(['approved', 'failed_draft'])
        ->and(laterNotices(1, $approver))->toBe(['failed_draft']);
});
