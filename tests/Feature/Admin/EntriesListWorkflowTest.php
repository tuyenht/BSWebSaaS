<?php

use App\Modules\Admin\Livewire\Entries\Index;
use App\Modules\Cms\Actions\ApplyEntryWorkflowTransition;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\MemberCategoryScope;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Notifications\SiteNotice;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Workflow\Actions\InstantiateWorkflow;
use App\Modules\Workflow\Models\WorkflowLog;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Js;
use Livewire\Livewire;

/*
 * The posts list in a section that runs an approval workflow: the row's status pill and the bulk
 * bar offer the engine's moves — the ones this member may make from the row's stage — and every
 * move goes through the engine. A move that needs a reason asks for it (and the server refuses one
 * without), the first approval asks first, and a bulk move counts the rows it could not move. In a
 * section with no workflow (every section by default) the list keeps its direct moves, and a
 * publisher may take a live post off the website, keeping its content (archived).
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
});

/** The `posts` section (post_cat category, category permalinks); returns the category id. */
function elwSection(): int
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

/** A post in the section at $status (a pending one stands at the workflow's pending stage). */
function elwPost(int $categoryId, User $author, string $slug, string $status = 'draft'): Entry
{
    $entry = Entry::query()->create([
        'type' => 'post', 'status' => $status, 'author_id' => $author->id,
        'workflow_state' => $status === 'pending' ? 'pending' : null,
        'published_at' => $status === 'published' ? now()->subDay() : null,
    ]);
    $entry->translations()->create([
        'locale' => 'vi', 'title' => 'Bài '.$slug, 'slug' => $slug, 'status' => $status,
        'body' => ['blocks' => [['type' => 'richtext', 'props' => ['body' => 'Nội dung']]]],
    ]);
    $entry->assignCategories([$categoryId]);

    return $entry->fresh() ?? $entry;
}

/** @return array{approver: User, sender: User, publisher: User} */
function elwMembers(): array
{
    $members = ['approver' => User::factory()->create(), 'sender' => User::factory()->create(), 'publisher' => User::factory()->create()];
    grantRole($members['approver'], 'elw-approver', ['entries.view', 'entries.edit.any', 'entries.publish', 'entries.submit', 'entries.review', 'entries.approve']);
    grantRole($members['sender'], 'elw-sender', ['entries.view', 'entries.create', 'entries.edit.own', 'entries.submit']);
    grantRole($members['publisher'], 'elw-publisher', ['entries.view', 'entries.edit.any', 'entries.publish']);

    return array_map(fn (User $u): User => $u->fresh() ?? $u, $members);
}

function elwGoverned(string $template = 'admin_approval'): void
{
    app(InstantiateWorkflow::class)->handle($template, 'posts', active: true);
}

it('offers on each row the moves this member may make from its stage', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $m = elwMembers();
        $cat = elwSection();
        $draft = elwPost($cat, $m['sender'], 'nhap');
        $pending = elwPost($cat, $m['sender'], 'cho', 'pending');
        elwGoverned();

        // the author of a draft sends it for review; nothing is offered on the pending row
        Livewire::actingAs($m['sender'])->test(Index::class, ['section' => 'posts'])
            ->assertSeeHtml('data-wf="submit" data-row="'.$draft->ulid.'"')
            ->assertDontSeeHtml('data-row="'.$pending->ulid.'"');

        // an approver decides the pending row: approve (asks first) and reject (asks for a reason)
        Livewire::actingAs($m['approver'])->test(Index::class, ['section' => 'posts'])
            ->assertSeeHtml('data-wf="approve" data-row="'.$pending->ulid.'" data-first')
            ->assertSeeHtml('data-wf="reject" data-row="'.$pending->ulid.'" data-note')
            ->assertSeeHtml('data-wf="submit" data-row="'.$draft->ulid.'"')
            ->assertDontSee(__('entries.status_menu_archived'));

        // a publisher without the review rights gets no move at all, and no direct publish
        Livewire::actingAs($m['publisher'])->test(Index::class, ['section' => 'posts'])
            ->assertDontSeeHtml('data-wf=')
            ->assertDontSeeHtml("changeStatus('".$draft->ulid."'");
    });
});

it('moves a row through the engine, and refuses a send-back without its reason', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $m = elwMembers();
        $cat = elwSection();
        $pending = elwPost($cat, $m['sender'], 'cho', 'pending');
        $other = elwPost($cat, $m['sender'], 'cho-2', 'pending');
        elwGoverned();

        $list = Livewire::actingAs($m['approver'])->test(Index::class, ['section' => 'posts']);

        $list->call('applyTransition', $pending->ulid, 'reject', '   ')
            ->assertDispatched('bs-toast', text: __('admin.wfb.note_missing'));
        expect($pending->fresh()->status)->toBe('pending')->and(WorkflowLog::query()->count())->toBe(0);

        $list->call('applyTransition', $pending->ulid, 'reject', 'Thiếu ảnh minh họa')
            ->assertDispatched('bs-toast', text: __('entries.wf_moved_note', ['label' => 'Trả lại']));
        expect($pending->fresh()->status)->toBe('draft')
            ->and(WorkflowLog::query()->value('note'))->toBe('Thiếu ảnh minh họa');

        $list->call('applyTransition', $other->ulid, 'approve')
            ->assertDispatched('bs-toast', text: __('entries.wf_moved', ['label' => 'Duyệt đăng']));
        expect($other->fresh()->status)->toBe('published');

        // a move from the wrong stage (a stale row) changes nothing and says so
        $list->call('applyTransition', $other->ulid, 'approve')
            ->assertDispatched('bs-toast', text: __('admin.wfb.err_stale'));

        // a member without the move's right is refused, the row left as it was
        $draft = elwPost($cat, $m['sender'], 'nhap');
        Livewire::actingAs($m['publisher'])->test(Index::class, ['section' => 'posts'])
            ->call('applyTransition', $draft->ulid, 'submit')
            ->assertDispatched('bs-toast', text: __('entries.refused_status'));
        expect($draft->fresh()->status)->toBe('draft')->and($draft->fresh()->workflow_state)->toBeNull();
    });
});

it('runs a bulk move through the engine row by row and counts the rows it skips', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $m = elwMembers();
        $cat = elwSection();
        $a = elwPost($cat, $m['sender'], 'a', 'pending');
        $b = elwPost($cat, $m['sender'], 'b', 'pending');
        $c = elwPost($cat, $m['sender'], 'c');
        elwGoverned();

        $list = Livewire::actingAs($m['approver'])->test(Index::class, ['section' => 'posts'])
            ->set('selected', [$a->ulid, $b->ulid, $c->ulid]);
        // the bar offers the moves without a reason, each counting the rows it would move
        $list->assertSeeHtml('data-bulk="tr-approve"')->assertDontSeeHtml('data-bulk="tr-reject"')
            ->assertDontSeeHtml('data-bulk="publish"')
            ->assertSee(__('entries.bulk_label_n', ['label' => 'Duyệt đăng', 'count' => 2]));

        $list->call('bulkTransition', 'approve')
            ->assertDispatched('bs-toast', text: __('entries.bulk_moved', ['label' => 'Duyệt đăng', 'count' => 2, 'noun' => __('entries.noun_post')]).' '.__('entries.bulk_skipped', ['count' => 1]));

        expect($a->fresh()->status)->toBe('published')->and($b->fresh()->status)->toBe('published')
            ->and($c->fresh()->status)->toBe('draft')
            ->and(WorkflowLog::query()->count())->toBe(2);

        // a move that needs a reason never runs in bulk
        $list->set('selected', [$a->ulid])->call('bulkTransition', 'retract');
        expect($a->fresh()->status)->toBe('published');
    });
});

it('takes a live post off the website from the pill, keeping its content, only for a member who may publish it', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $m = elwMembers();
        $cat = elwSection();
        $live = elwPost($cat, $m['sender'], 'live', 'published');
        $draft = elwPost($cat, $m['sender'], 'nhap');

        Livewire::actingAs($m['publisher'])->test(Index::class, ['section' => 'posts'])
            ->assertSeeHtml("changeStatus('".$live->ulid."', 'archived')")
            ->assertDontSeeHtml("changeStatus('".$draft->ulid."', 'archived')")
            ->assertSee(__('entries.status_menu_archived'))
            ->call('changeStatus', $live->ulid, 'archived')
            ->assertDispatched('bs-toast', text: __('entries.status_changed'));
        expect($live->fresh()->status)->toBe('archived')
            ->and($live->fresh()->translations()->value('body'))->not->toBeNull();

        // the sender may not publish: refused with the reason, the post stays live
        $other = elwPost($cat, $m['sender'], 'live-2', 'published');
        Livewire::actingAs($m['sender'])->test(Index::class, ['section' => 'posts'])
            ->assertDontSeeHtml("changeStatus('".$other->ulid."', 'archived')")
            ->call('changeStatus', $other->ulid, 'archived')
            ->assertDispatched('bs-toast', text: __('entries.refused_status'));
        expect($other->fresh()->status)->toBe('published');
    });
});

it('takes a governed live post off the website through the workflow\'s own move', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $m = elwMembers();
        $cat = elwSection();
        $live = elwPost($cat, $m['sender'], 'live', 'published');
        elwGoverned();

        Livewire::actingAs($m['approver'])->test(Index::class, ['section' => 'posts'])
            ->assertSeeHtml('data-wf="archive" data-row="'.$live->ulid.'"')
            ->assertDontSeeHtml("changeStatus('".$live->ulid."', 'archived')")
            ->call('applyTransition', $live->ulid, 'archive');

        $live->refresh();
        expect($live->status)->toBe('archived')->and($live->workflow_state)->toBe('archived')
            ->and($live->translations()->value('status'))->toBe('archived');
    });
});

it('keeps the direct moves where no workflow runs', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $m = elwMembers();
        $cat = elwSection();
        $a = elwPost($cat, $m['sender'], 'a');
        $b = elwPost($cat, $m['sender'], 'b');

        $list = Livewire::actingAs($m['publisher'])->test(Index::class, ['section' => 'posts'])
            ->assertDontSeeHtml('data-wf=')
            ->assertSeeHtml("changeStatus('".$a->ulid."', 'published')");
        $list->call('changeStatus', $a->ulid, 'published');
        expect($a->fresh()->status)->toBe('published');

        $list->set('selected', [$b->ulid])->assertSeeHtml('data-bulk="publish"')->call('bulkPublish');
        expect($b->fresh()->status)->toBe('published');

        // the engine path answers plainly when no workflow governs the row
        $list->call('applyTransition', $a->ulid, 'approve')
            ->assertDispatched('bs-toast', text: __('admin.wfb.err_not_governed'));
    });
});

it('archives through the engine\'s own move and tells no reviewer, in either template', function (string $template): void {
    app(Tenancy::class)->enter(1, function () use ($template): void {
        $m = elwMembers();
        $cat = elwSection();
        $reviewer = User::factory()->create();   // would hear of anything left waiting for review
        grantRole($reviewer, 'elw-reviewer', ['entries.view', 'entries.edit.any', 'entries.review', 'entries.approve']);
        $live = elwPost($cat, $m['sender'], 'live', 'published');
        elwGoverned($template);
        Notification::fake();

        app(ApplyEntryWorkflowTransition::class)->handle($live, 'vi', $m['approver'], 'archive', null);

        $live->refresh();
        expect($live->status)->toBe('archived')->and($live->workflow_state)->toBe('archived');
        Notification::assertNotSentTo([$reviewer, $m['sender']], SiteNotice::class,
            fn (SiteNotice $notice): bool => $notice->kind === 'workflow.pending_review');
        Notification::assertNothingSent();
    });
})->with(['admin_approval', 'editorial']);

it('asks first before a move takes a live post off the website, and only then', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $m = elwMembers();
        $cat = elwSection();
        $live = elwPost($cat, $m['sender'], 'live', 'published');
        $pending = elwPost($cat, $m['sender'], 'cho', 'pending');
        $draft = elwPost($cat, $m['sender'], 'nhap');
        elwGoverned();

        Livewire::actingAs($m['approver'])->test(Index::class, ['section' => 'posts'])
            ->assertSeeHtml('data-wf="archive" data-row="'.$live->ulid.'" data-off')
            ->assertSeeHtml(e((string) Js::from(__('entries.wf_off_title', ['title' => 'Bài live']))))
            ->assertSeeHtml(e((string) Js::from(__('entries.wf_off_text'))))
            // a live post's send-back asks for its reason instead; the other rows' moves never take a post offline
            ->assertSeeHtml('data-wf="retract" data-row="'.$live->ulid.'" data-note')
            ->assertSeeHtml('data-wf="approve" data-row="'.$pending->ulid.'" data-first')
            ->assertSeeHtml('data-wf="submit" data-row="'.$draft->ulid.'" x-on:click')
            ->assertDontSeeHtml('data-row="'.$pending->ulid.'" data-off')
            ->assertDontSeeHtml('data-row="'.$draft->ulid.'" data-off');
    });
});

it('asks first before a bulk move takes live posts off the website, and not for other bulk moves', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $m = elwMembers();
        $cat = elwSection();
        $live = elwPost($cat, $m['sender'], 'live', 'published');
        $pending = elwPost($cat, $m['sender'], 'cho', 'pending');
        elwGoverned();

        $ask = e((string) Js::from(__('entries.wf_off_bulk_title', ['count' => 1, 'noun' => __('entries.noun_post')])));
        $list = Livewire::actingAs($m['approver'])->test(Index::class, ['section' => 'posts'])
            ->set('selected', [$live->ulid, $pending->ulid]);
        $list->assertSeeHtml('data-bulk="tr-archive"')->assertSeeHtml($ask)
            ->assertSeeHtml(e((string) Js::from(__('entries.wf_off_ok'))));

        // the approve on the pending row alone takes nothing offline: the bar asks nothing (the live row's own pill still asks)
        $list->set('selected', [$pending->ulid])
            ->assertSeeHtml('data-bulk="tr-approve"')->assertDontSeeHtml($ask);
    });
});

it('refuses a workflow move it may not make and leaves the row as it was', function (): void {
    $otherSite = app(Tenancy::class)->enter(2, function (): Entry {
        $author = User::factory()->create();

        return elwPost(elwSection(), $author, 'site-b', 'pending');
    });

    app(Tenancy::class)->enter(1, function () use ($otherSite): void {
        $m = elwMembers();
        $cat = elwSection();
        $pending = elwPost($cat, $m['sender'], 'cho', 'pending');
        $trashed = elwPost($cat, $m['sender'], 'rac', 'pending');
        $trashed->delete();
        elwGoverned();
        $list = Livewire::actingAs($m['approver'])->test(Index::class, ['section' => 'posts']);

        // another site's row, and a row in the Trash: not on this list
        $list->call('applyTransition', $otherSite->ulid, 'approve')
            ->assertDispatched('bs-toast', text: __('entries.row_stale'));
        $list->call('applyTransition', $trashed->ulid, 'approve')
            ->assertDispatched('bs-toast', text: __('entries.row_stale'));
        expect(Entry::withTrashed()->find($trashed->id)->status)->toBe('pending');

        // a reason over the limit
        $list->call('applyTransition', $pending->ulid, 'reject', str_repeat('a', 2001))
            ->assertDispatched('bs-toast', text: __('entries.wf_note_too_long', ['max' => 2000]));
        expect($pending->fresh()->status)->toBe('pending')->and(WorkflowLog::query()->count())->toBe(0);
    });

    app(Tenancy::class)->enter(2, function () use ($otherSite): void {
        expect($otherSite->fresh()->status)->toBe('pending')->and($otherSite->fresh()->workflow_state)->toBe('pending');
    });
});

it('refuses an approver limited to other categories, on one row and in bulk', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $m = elwMembers();
        $cat = elwSection();
        $elsewhere = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 1]);
        $elsewhere->translations()->create(['locale' => 'vi', 'name' => 'Sự kiện', 'slug' => 'su-kien']);
        MemberCategoryScope::query()->create(['user_id' => $m['approver']->id, 'taxonomy_id' => $elsewhere->id]);
        app(CategoryScopes::class)->forget();
        $pending = elwPost($cat, $m['sender'], 'cho', 'pending');
        elwGoverned();
        $why = app(CategoryScopes::class)->refusal($m['approver']);

        $list = Livewire::actingAs($m['approver'])->test(Index::class, ['section' => 'posts']);
        $list->call('applyTransition', $pending->ulid, 'approve')
            ->assertDispatched('bs-toast', text: $why);
        expect($pending->fresh()->status)->toBe('pending');

        $list->set('selected', [$pending->ulid])->call('bulkTransition', 'approve')
            ->assertDispatched('bs-toast', text: __('entries.bulk_moved', ['label' => 'Duyệt đăng', 'count' => 0, 'noun' => __('entries.noun_post')]).' '.__('entries.bulk_skipped', ['count' => 1]));
        expect($pending->fresh()->status)->toBe('pending')->and(WorkflowLog::query()->count())->toBe(0);
    });
});

it('refuses taking a post off the website from someone who may not, from the Home page and from a draft', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $m = elwMembers();
        $cat = elwSection();
        $ownOnly = User::factory()->create();
        grantRole($ownOnly, 'elw-own-publisher', ['entries.view', 'entries.edit.own', 'entries.publish']);
        $live = elwPost($cat, $m['sender'], 'live', 'published');
        $draft = elwPost($cat, $m['sender'], 'nhap');

        // a publisher of their own posts only, on someone else's
        Livewire::actingAs($ownOnly->fresh())->test(Index::class, ['section' => 'posts'])
            ->call('changeStatus', $live->ulid, 'archived')
            ->assertDispatched('bs-toast', text: __('entries.refused_status'));
        expect($live->fresh()->status)->toBe('published');

        // a draft is not on the website (a stale menu)
        Livewire::actingAs($m['publisher'])->test(Index::class, ['section' => 'posts'])
            ->call('changeStatus', $draft->ulid, 'archived')
            ->assertDispatched('bs-toast', text: __('entries.row_stale'));
        expect($draft->fresh()->status)->toBe('draft');

        // the Home page stays on the website
        $owner = User::factory()->create();
        grantRole($owner, 'elw-owner', ['entries.view', 'entries.edit.any', 'entries.publish']);
        $home = Entry::query()->create(['type' => 'page', 'status' => 'published', 'author_id' => $owner->id, 'meta' => [], 'published_at' => now()->subDay()]);
        $home->translations()->create(['locale' => 'vi', 'title' => 'Trang chủ', 'slug' => 'home', 'status' => 'published', 'body' => ['blocks' => []]]);
        Livewire::actingAs($owner->fresh())->test(Index::class)
            ->call('changeStatus', $home->ulid, 'archived')
            ->assertDispatched('bs-toast', text: __('entries.skip_home_note'));
        expect($home->fresh()->status)->toBe('published');
    });
});
