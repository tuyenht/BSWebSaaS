<?php

use App\Modules\Admin\Livewire\Entries\ReviewInbox;
use App\Modules\Cms\Actions\ApplyEntryWorkflowTransition;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Workflow\Actions\InstantiateWorkflow;
use App\Modules\Workflow\Models\WorkflowLog;
use Livewire\Livewire;

/*
 * «Duyệt đăng» in the review inbox (board 17) is always asked first: a post never published before gets
 * «Khi nào đăng?» (now, or approved for a later time in the website's clock), a live one the plain
 * «Duyệt đăng “…”?» with «Để tôi đọc lại đã» focused. The keyboard walks the list (↑ ↓) and Enter runs the
 * publishing move through the same buttons, so the question is never skipped. Every move goes through
 * the workflow engine (ApplyEntryWorkflowTransition), judged on this website only.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/** A `posts` section on the current site under the bundled approval flow ($template another one); returns its category id. */
function askSection(string $template = 'admin_approval'): int
{
    $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
    $category->translations()->create(['locale' => 'vi', 'name' => 'Tin tức', 'slug' => 'tin-tuc']);
    ContentSection::query()->create([
        'key' => 'posts', 'label' => 'Tin tức', 'entry_type' => 'post', 'taxonomy_id' => $category->id,
        'permission_prefix' => 'entries', 'sort_order' => 1,
    ]);
    SiteSetting::query()->create(['group_name' => 'permalinks', 'key' => 'posts', 'value' => ['pattern' => 'category']]);
    app(InstantiateWorkflow::class)->handle($template, 'posts', active: true);

    return $category->id;
}

/** A post by $author under $categoryId, submitted so it waits at the flow's first review stage. */
function askPost(int $categoryId, User $author, string $title, string $slug): Entry
{
    $entry = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'author_id' => $author->id]);
    $entry->translations()->create([
        'locale' => 'vi', 'title' => $title, 'slug' => $slug, 'status' => 'draft',
        'body' => ['blocks' => [['type' => 'richtext', 'props' => ['paragraphs' => ['Nội dung bài']]]]],
    ]);
    $entry->assignCategories([$categoryId]);
    app(ApplyEntryWorkflowTransition::class)->handle($entry->fresh(), 'vi', $author->fresh(), 'submit');

    return $entry->fresh();
}

/** A writer and an approver on $site. */
function askPeople(int $site = 1): array
{
    $author = User::factory()->create(['name' => 'Người viết']);
    $approver = User::factory()->create(['name' => 'Người duyệt']);
    app(Tenancy::class)->enter($site, function () use ($author, $approver): void {
        grantRole($author, 'author', ['entries.create', 'entries.edit.own', 'entries.submit']);
        grantRole($approver, 'approver', ['entries.review', 'entries.approve']);
    });

    return [$author->fresh(), $approver->fresh()];
}

/** The mark of the move Enter runs (the attribute, not the keyboard script that looks for it). */
const INBOX_PRIMARY_MARK = 'data-inbox-primary="';

/** The opening tag of the reader's button for move $key, or '' when there is none. */
function askButton(string $html, string $key): string
{
    // attribute values may hold a ">" (an arrow function): a quoted value is skipped whole
    $attrs = '(?:[^>"]|"[^"]*")*';

    return preg_match('/<button\b'.$attrs.'data-inbox-act="'.preg_quote($key, '/').'"'.$attrs.'>/s', $html, $m) === 1 ? $m[0] : '';
}

it('asks before a first publication with «Khi nào đăng?», never applying straight from the click', function (): void {
    [$author, $approver] = askPeople();

    app(Tenancy::class)->enter(1, function () use ($author, $approver): void {
        $post = askPost(askSection(), $author, 'Bài mới', 'bai-moi');

        $html = Livewire::actingAs($approver)->test(ReviewInbox::class)->set('tab', 'queue')
            ->call('open', $post->ulid)->html();

        $approve = askButton($html, 'approve');
        expect($approve)->toContain("\$dispatch('inbox-ap'", INBOX_PRIMARY_MARK, (string) $post->ulid)
            ->not->toContain('wire:click')
            ->and($html)->toContain('data-inbox-ap', e(__('admin.inbox.ap_group')), e(__('entries.wf_ap_cancel')))
            // the reason move is no publishing move: it is never the one Enter runs
            ->and(askButton($html, 'reject'))->not->toContain(INBOX_PRIMARY_MARK)
            ->and(substr_count($html, INBOX_PRIMARY_MARK))->toBe(1);
        // the dialog sends the post it named, now or at a later time
        expect($html)->toContain('$wire.apply(key, ulid)', '$wire.approveLater(key, this.value, ulid)');
        // Enter waits while any ↑ ↓ step is still on its way (a count, so two quick steps both hold it),
        // and on a phone list it opens the marked post instead of asking to publish one not yet seen
        expect($html)->toContain('inboxSteps++', 'inboxSteps--', 'if (this.inboxSteps > 0)', '.catch(() => {}).finally(() => { this.inboxSteps-- })', "matchMedia('(min-width: 64rem)')");
        expect($post->fresh()->status)->toBe('pending');
    });
});

it('asks before publishing the ticked posts the same way, holding the keys until it is answered', function (): void {
    [$author, $approver] = askPeople();

    app(Tenancy::class)->enter(1, function () use ($author, $approver): void {
        $post = askPost(askSection(), $author, 'Bài mới', 'bai-moi');

        $html = Livewire::actingAs($approver)->test(ReviewInbox::class)->set('tab', 'queue')
            ->call('togglePick', (string) $post->ulid)->html();

        $attrs = '(?:[^>"]|"[^"]*")*';
        expect(preg_match('/<button\b'.$attrs.'wire:target="approvePicked"'.$attrs.'>/s', $html, $m))->toBe(1)
            ->and($m[0])->toContain('inboxAsking = true', '$bsConfirm(', "icon: 'publish'", 'focusCancel: true', 'inboxAsking = false');
        expect($post->fresh()->status)->toBe('pending');
    });
});

it('asks «Duyệt đăng “…”?» before publishing a post that was live before, focused on «Để tôi đọc lại đã»', function (): void {
    [$author, $approver] = askPeople();

    app(Tenancy::class)->enter(1, function () use ($author, $approver): void {
        $post = askPost(askSection(), $author, 'Bài đã đăng', 'bai-da-dang');
        Entry::query()->whereKey($post->id)->update(['status' => 'published']);

        $html = Livewire::actingAs($approver)->test(ReviewInbox::class)->set('tab', 'queue')
            ->call('open', $post->ulid)->html();

        $approve = askButton($html, 'approve');
        // the question names the post, and the move carries it: a reader that moved on meanwhile applies nothing
        expect($approve)->toContain('$bsConfirm(', "icon: 'publish'", 'focusCancel: true', INBOX_PRIMARY_MARK, 'inboxAsking = true', 'inboxAsking = false')
            ->toMatch('/\$wire\.apply\([^)]*'.preg_quote((string) $post->ulid, '/').'/')
            ->not->toContain('wire:click')
            ->and($html)->not->toContain('data-inbox-ap');
    });
});

it('publishes a first post now through the workflow engine, which logs the move', function (): void {
    [$author, $approver] = askPeople();

    app(Tenancy::class)->enter(1, function () use ($author, $approver): void {
        $post = askPost(askSection(), $author, 'Bài mới', 'bai-moi');

        Livewire::actingAs($approver)->test(ReviewInbox::class)->set('tab', 'queue')
            ->call('open', $post->ulid)
            ->call('apply', 'approve')
            ->assertHasNoErrors()
            ->assertDispatched('bs-toast');

        $log = WorkflowLog::query()->where('entry_id', $post->id)->latest('id')->first();
        expect($post->fresh()->status)->toBe('published')
            ->and($post->fresh()->workflow_state)->toBe('published')
            ->and($log?->transition_key)->toBe('approve')
            ->and($log?->from_state)->toBe('pending')
            ->and($log?->actor_id)->toBe($approver->id)
            ->and(SiteRoute::query()->where('entry_id', $post->id)->value('path'))->toBe('/tin-tuc/bai-moi');
    });
});

it('approves a first post for a time in the website clock, stored in UTC, and refuses an empty or past time', function (): void {
    [$author, $approver] = askPeople();

    app(Tenancy::class)->enter(1, function () use ($author, $approver): void {
        $post = askPost(askSection(), $author, 'Bài hẹn giờ', 'bai-hen-gio');
        $local = app(SiteTime::class)->now()->addDay()->setTime(9, 30);
        $past = app(SiteTime::class)->now()->subHour()->format('Y-m-d\TH:i');

        Livewire::actingAs($approver)->test(ReviewInbox::class)->set('tab', 'queue')
            ->call('open', $post->ulid)
            ->call('approveLater', 'approve', '')->assertReturned(__('entries.sched_err_empty'))
            ->call('approveLater', 'approve', $past)->assertReturned(__('entries.sched_err_past'));
        expect($post->fresh()->status)->toBe('pending')
            ->and($post->fresh()->scheduled_by)->toBeNull();

        Livewire::actingAs($approver)->test(ReviewInbox::class)->set('tab', 'queue')
            ->call('open', $post->ulid)
            ->call('approveLater', 'approve', $local->format('Y-m-d\TH:i'))
            ->assertReturned(null)
            ->assertHasNoErrors();

        $fresh = $post->fresh();
        expect($fresh->status)->toBe('scheduled')
            ->and($fresh->scheduled_by)->toBe($approver->id)
            // the same instant as the website-clock time picked, never the wall-clock digits taken as UTC
            ->and($fresh->published_at?->getTimestamp())->toBe($local->getTimestamp())
            ->and(app(SiteTime::class)->forDisplay($fresh->published_at)?->format('Y-m-d H:i'))->toBe($local->format('Y-m-d H:i'));
    });
});

it('offers no publishing move to a reviewer without the right, and refuses one sent anyway', function (): void {
    [$author] = askPeople();
    $reviewer = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($author, $reviewer): void {
        grantRole($reviewer, 'reviewer', ['entries.review']);
        $post = askPost(askSection(), $author, 'Bài chờ', 'bai-cho');

        $inbox = Livewire::actingAs($reviewer->fresh())->test(ReviewInbox::class)->set('tab', 'queue')
            ->call('open', $post->ulid)->assertSet('current', (string) $post->ulid);
        expect($inbox->html())->not->toContain(INBOX_PRIMARY_MARK)->not->toContain('data-inbox-ap');

        $inbox->call('apply', 'approve')->assertHasErrors('inbox')
            ->call('approveLater', 'approve', app(SiteTime::class)->now()->addDay()->format('Y-m-d\TH:i'))->assertHasErrors('inbox');
        expect($post->fresh()->status)->toBe('pending')
            ->and(WorkflowLog::query()->where('entry_id', $post->id)->where('transition_key', 'approve')->exists())->toBeFalse();
    });
});

it('names no primary move at a step that does not publish', function (): void {
    [$author, $approver] = askPeople();

    app(Tenancy::class)->enter(1, function () use ($author, $approver): void {
        $post = askPost(askSection('editorial'), $author, 'Bài biên tập', 'bai-bien-tap');

        $html = Livewire::actingAs($approver)->test(ReviewInbox::class)->set('tab', 'queue')
            ->call('open', $post->ulid)->html();
        expect($html)->toContain('data-inbox-act="to_review"')->not->toContain(INBOX_PRIMARY_MARK);
    });
});

it('walks the list with the arrow keys without covering it on a phone, and «Bài sau» still opens the reader', function (): void {
    [$author, $approver] = askPeople();

    app(Tenancy::class)->enter(1, function () use ($author, $approver): void {
        $categoryId = askSection();
        askPost($categoryId, $author, 'Bài A', 'bai-a');
        askPost($categoryId, $author, 'Bài B', 'bai-b');

        $inbox = Livewire::actingAs($approver)->test(ReviewInbox::class)->set('tab', 'queue')->assertSet('detail', false);
        $first = $inbox->get('current');

        // ↓ from the keyboard: the next post is marked and read, the list stays in view
        $inbox->call('step', 1, true)->assertSet('detail', false);
        $second = $inbox->get('current');
        expect($second)->toBeString()->not->toBe($first);

        $inbox->call('step', -1, true)->assertSet('current', $first)->assertSet('detail', false)
            // the reader's own «Bài sau» opens what it steps to, as before
            ->call('step', 1)->assertSet('current', $second)->assertSet('detail', true)
            // and once the reader covers the screen, the keys keep it open
            ->call('step', -1, true)->assertSet('current', $first)->assertSet('detail', true);
    });
});

it('never steps onto, opens or publishes another website\'s post', function (): void {
    [$author, $approver] = askPeople(1);
    [$authorB] = askPeople(2);

    // made between this website's two posts, so a list that leaked it would put it in the middle
    [$categoryId, $first] = app(Tenancy::class)->enter(1, function () use ($author): array {
        $this->travel(-3)->minutes();
        $categoryId = askSection();

        return [$categoryId, askPost($categoryId, $author, 'Bài A1', 'bai-a1')];
    });
    $postB = app(Tenancy::class)->enter(2, function () use ($authorB): Entry {
        $this->travel(1)->minutes();

        return askPost(askSection(), $authorB, 'Bài B', 'bai-b');
    });

    app(Tenancy::class)->enter(1, function () use ($author, $approver, $categoryId, $first, $postB): void {
        $this->travel(1)->minutes();
        $second = askPost($categoryId, $author, 'Bài A2', 'bai-a2');
        $this->travelBack();
        $ours = [(string) $first->ulid, (string) $second->ulid];

        $inbox = Livewire::actingAs($approver)->test(ReviewInbox::class)->set('tab', 'queue')
            ->call('open', (string) $postB->ulid)
            ->assertDontSee('Bài B');
        $start = $inbox->get('current');
        expect($ours)->toContain($start);
        $other = $ours[0] === $start ? $ours[1] : $ours[0];

        // a step each way from there reaches this website's other post, never the one made between them elsewhere
        $seen = [$inbox->call('step', 1, true)->get('current'), $inbox->call('step', -1, true)->get('current')];
        expect($seen)->each->toBeIn($ours)->and($seen)->toContain($other);

        // and a move sent naming it applies nothing
        $inbox->call('open', $other)->assertSet('current', $other)
            ->call('apply', 'approve', (string) $postB->ulid)->assertHasErrors('inbox')
            ->call('apply', 'approve', $other)->assertHasNoErrors();
        expect(Entry::query()->where('ulid', $other)->value('status'))->toBe('published');
    });

    app(Tenancy::class)->enter(2, function () use ($postB): void {
        expect($postB->fresh()->status)->toBe('pending')
            ->and(WorkflowLog::query()->where('entry_id', $postB->id)->where('transition_key', 'approve')->exists())->toBeFalse();
    });
});

it('applies nothing when the question named another post than the one now open', function (): void {
    [$author, $approver] = askPeople();

    app(Tenancy::class)->enter(1, function () use ($author, $approver): void {
        $categoryId = askSection();
        $a = askPost($categoryId, $author, 'Bài A', 'bai-a');
        $b = askPost($categoryId, $author, 'Bài B', 'bai-b');

        Livewire::actingAs($approver)->test(ReviewInbox::class)->set('tab', 'queue')
            ->call('open', (string) $b->ulid)
            ->call('apply', 'approve', (string) $a->ulid)
            ->assertHasErrors(['inbox'])->assertSee(__('admin.inbox.err_moved'))
            ->assertSet('current', (string) $b->ulid)
            ->call('approveLater', 'approve', app(SiteTime::class)->now()->addDay()->format('Y-m-d\TH:i'), (string) $a->ulid)
            ->assertReturned(null)->assertHasErrors(['inbox'])
            ->assertSet('current', (string) $b->ulid);

        expect($a->fresh()->status)->toBe('pending')->and($b->fresh()->status)->toBe('pending')
            ->and(WorkflowLog::query()->whereIn('entry_id', [$a->id, $b->id])->where('transition_key', 'approve')->exists())->toBeFalse();
    });
});
