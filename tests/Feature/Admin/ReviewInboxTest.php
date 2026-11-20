<?php

use App\Modules\Admin\Livewire\Entries\Editor;
use App\Modules\Admin\Livewire\Entries\ReviewInbox;
use App\Modules\Cms\Actions\ApplyEntryWorkflowTransition;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Models\Taxonomy;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Workflow\Actions\InstantiateWorkflow;
use App\Modules\Workflow\Models\Workflow;
use App\Modules\Workflow\Models\WorkflowLog;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * The review inbox (design_handoff_workflow screen 3) — where people who APPROVE work, as
 * opposed to the editor where people who WRITE work. It asks for entries.review/approve and
 * never for edit rights: that is the whole point of separating the two in the editorial flow.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/** A `posts` collection, governed by the bundled approval flow unless told otherwise; returns the primary category id. */
function inboxCollection(bool $approvalFlow = true): int
{
    $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
    $category->translations()->create(['locale' => 'vi', 'name' => 'Tin tức', 'slug' => 'tin-tuc']);

    ContentSection::query()->create([
        'key' => 'posts', 'label' => 'Tin tức', 'entry_type' => 'post', 'taxonomy_id' => $category->id,
        'permission_prefix' => 'entries', 'sort_order' => 1,
    ]);
    SiteSetting::query()->create(['group_name' => 'permalinks', 'key' => 'posts', 'value' => ['pattern' => 'category']]);
    if ($approvalFlow) {
        app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);
    }

    return $category->id;
}

/** A post filed under $categoryId, submitted by $author so it sits at `pending`. */
function submittedPost(int $categoryId, User $author, string $title = 'Bản tin', string $slug = 'ban-tin'): Entry
{
    $entry = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'author_id' => $author->id]);
    $entry->translations()->create([
        'locale' => 'vi', 'title' => $title, 'slug' => $slug, 'status' => 'draft',
        'body' => ['blocks' => [['type' => 'richtext', 'props' => ['body' => 'Nội dung bài']]]],
    ]);
    $entry->assignCategories([$categoryId]);
    app(ApplyEntryWorkflowTransition::class)->handle($entry->fresh(), 'vi', $author->fresh(), 'submit');

    return $entry->fresh();
}

it('lets an approve-only role work — no edit rights, and the editor stays shut to them', function (): void {
    $author = User::factory()->create(['name' => 'Người viết']);
    $approver = User::factory()->create(['name' => 'Người duyệt']);

    app(Tenancy::class)->enter(1, function () use ($author, $approver): void {
        grantRole($author, 'author', ['entries.create', 'entries.edit.own', 'entries.submit']);
        // No entries.edit.any, no entries.view: approving is a different job.
        grantRole($approver, 'approver', ['entries.approve', 'entries.review']);
        $post = submittedPost(inboxCollection(), $author);

        Livewire::actingAs($approver->fresh())
            ->test(ReviewInbox::class)
            ->assertSee('Bản tin')
            ->assertSee('Người viết')
            ->call('open', $post->ulid)
            ->assertSee('Nội dung bài')
            ->call('apply', 'approve')
            ->assertHasNoErrors();

        $post->refresh();
        expect($post->status)->toBe('published')
            ->and($post->workflow_state)->toBe('published')
            ->and(SiteRoute::query()->where('entry_id', $post->id)->value('path'))->toBe('/tin-tuc/ban-tin');

        // The same person still cannot open the editor: they approve, they do not write.
        Livewire::actingAs($approver->fresh())
            ->test(Editor::class, ['ulid' => $post->ulid])
            ->assertForbidden();
    });

    $this->get('http://a.test/tin-tuc/ban-tin')->assertOk();
});

it('shows only what is waiting for me — not drafts, not live posts, not another site', function (): void {
    $author = User::factory()->create();
    $reviewer = User::factory()->create();
    $tenancy = app(Tenancy::class);

    $tenancy->enter(2, function (): void {
        $other = User::factory()->create();
        $categoryId = inboxCollection();
        submittedPost($categoryId, tap($other, fn (User $u) => grantRole($u, 'author', ['entries.edit.own', 'entries.submit']))->fresh(), 'Bài site khác', 'bai-site-khac');
    });

    $tenancy->enter(1, function () use ($author, $reviewer): void {
        grantRole($author, 'author', ['entries.edit.own', 'entries.submit']);
        grantRole($reviewer, 'reviewer', ['entries.review', 'entries.approve']);
        $categoryId = inboxCollection();

        $waiting = submittedPost($categoryId, $author, 'Đang chờ duyệt', 'dang-cho');

        // An untouched draft: the author's own work in progress.
        $draft = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'author_id' => $author->id]);
        $draft->translations()->create(['locale' => 'vi', 'title' => 'Bản nháp riêng', 'slug' => 'ban-nhap', 'status' => 'draft', 'body' => ['blocks' => []]]);
        $draft->assignCategories([$categoryId]);

        // One already approved and live.
        $live = submittedPost($categoryId, $author, 'Đã lên sóng', 'da-len-song');
        app(ApplyEntryWorkflowTransition::class)->handle($live, 'vi', $reviewer->fresh(), 'approve');

        Livewire::actingAs($reviewer->fresh())
            ->test(ReviewInbox::class)
            ->assertSee('Đang chờ duyệt')
            ->assertDontSee('Bản nháp riêng')
            ->assertDontSee('Đã lên sóng')
            ->assertDontSee('Bài site khác');

        expect($waiting->fresh()->workflow_state)->toBe('pending');
    });
});

it('separates "assigned to me" from the shared queue', function (): void {
    $author = User::factory()->create();
    $reviewer = User::factory()->create();
    $colleague = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($author, $reviewer, $colleague): void {
        grantRole($author, 'author', ['entries.edit.own', 'entries.submit']);
        grantRole($reviewer, 'reviewer', ['entries.review', 'entries.approve']);
        grantRole($colleague, 'colleague', ['entries.review', 'entries.approve']);
        $categoryId = inboxCollection();

        $mine = submittedPost($categoryId, $author, 'Giao cho tôi', 'giao-cho-toi');
        $mine->update(['assigned_to' => $reviewer->id]);
        submittedPost($categoryId, $author, 'Của cả nhóm', 'cua-ca-nhom');

        $component = Livewire::actingAs($reviewer->fresh())->test(ReviewInbox::class);

        // The shared queue holds both; "assigned to me" holds only the hand-off.
        $component->assertSee('Giao cho tôi')->assertSee('Của cả nhóm')
            ->call('setTab', 'mine')
            ->assertSee('Giao cho tôi')
            ->assertDontSee('Của cả nhóm');

        // A colleague sees the shared one but not someone else's hand-off.
        Livewire::actingAs($colleague->fresh())
            ->test(ReviewInbox::class)
            ->call('setTab', 'mine')
            ->assertDontSee('Giao cho tôi')
            ->assertDontSee('Của cả nhóm');
    });
});

it('demands a reason before sending a post back, and records who asked', function (): void {
    $author = User::factory()->create();
    $reviewer = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($author, $reviewer): void {
        grantRole($author, 'author', ['entries.edit.own', 'entries.submit']);
        grantRole($reviewer, 'reviewer', ['entries.review']);
        $post = submittedPost(inboxCollection(), $author);

        $component = Livewire::actingAs($reviewer->fresh())
            ->test(ReviewInbox::class)
            ->call('open', $post->ulid)
            ->set('note', '  ')
            ->call('apply', 'reject')
            ->assertHasErrors('note');

        expect($post->fresh()->workflow_state)->toBe('pending');

        $component->set('note', 'Thiếu ảnh máy CNC')
            ->call('apply', 'reject')
            ->assertHasNoErrors();

        $post->refresh();
        expect($post->workflow_state)->toBe('draft')
            ->and($post->status)->toBe('draft');

        // The trail keeps the reason for the writer to read in the editor.
        Livewire::actingAs($author->fresh())->test(ReviewInbox::class)->assertDontSee('Thiếu ảnh máy CNC');
        expect(WorkflowLog::query()->where('entry_id', $post->id)->where('transition_key', 'reject')->value('note'))
            ->toBe('Thiếu ảnh máy CNC');
    });
});

it('refuses the screen to someone who cannot review or approve', function (): void {
    $viewer = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($viewer): void {
        grantRole($viewer, 'viewer', ['entries.view']);
        inboxCollection();

        Livewire::actingAs($viewer->fresh())->test(ReviewInbox::class)->assertForbidden();
    });
});

it('says plainly when there is nothing to review', function (): void {
    $reviewer = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($reviewer): void {
        grantRole($reviewer, 'reviewer', ['entries.review', 'entries.approve']);
        inboxCollection();

        Livewire::actingAs($reviewer->fresh())
            ->test(ReviewInbox::class)
            ->assertSee(__('admin.inbox.empty_queue'))
            ->assertDontSee(__('admin.inbox.no_match'));
    });
});

it('renders the inbox on a flat query budget regardless of how many posts are waiting', function (): void {
    $author = User::factory()->create();
    $reviewer = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($author, $reviewer): void {
        grantRole($author, 'author', ['entries.edit.own', 'entries.submit']);
        grantRole($reviewer, 'reviewer', ['entries.review', 'entries.approve']);
        $categoryId = inboxCollection();
        submittedPost($categoryId, $author, 'Bài 1', 'bai-1');
    });

    $url = 'http://a.test/admin/content/review';
    $this->actingAs($reviewer)->get($url)->assertOk();            // warm the permission cache
    $lean = countQueries(fn () => $this->actingAs($reviewer)->get($url)->assertOk());

    app(Tenancy::class)->enter(1, function () use ($author): void {
        $category = Taxonomy::query()->where('vocabulary', 'post_cat')->firstOrFail();
        foreach (range(2, 7) as $i) {
            submittedPost($category->id, $author->fresh(), "Bài {$i}", "bai-{$i}");
        }
    });

    $loaded = countQueries(fn () => $this->actingAs($reviewer)->get($url)->assertOk());

    expect($loaded)->toBe($lean);
});

it('keeps the shared-queue count visible while the assigned-to-me tab is open', function (): void {
    $author = User::factory()->create();
    $reviewer = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($author, $reviewer): void {
        grantRole($author, 'author', ['entries.edit.own', 'entries.submit']);
        grantRole($reviewer, 'reviewer', ['entries.review', 'entries.approve']);
        $categoryId = inboxCollection();

        $mine = submittedPost($categoryId, $author, 'Giao tôi', 'giao-toi');
        $mine->update(['assigned_to' => $reviewer->id]);
        submittedPost($categoryId, $author, 'Của nhóm A', 'nhom-a');
        submittedPost($categoryId, $author, 'Của nhóm B', 'nhom-b');

        // On the "mine" tab the shared queue still says 3 — reading it as "the team queue is
        // empty" would be exactly the wrong conclusion.
        Livewire::actingAs($reviewer->fresh())
            ->test(ReviewInbox::class)
            ->call('setTab', 'mine')
            ->assertSee(__('admin.inbox.showing', ['shown' => 1, 'total' => 1]))
            ->assertSeeHtml('>3</span>')
            ->assertSee('Giao tôi')
            ->assertDontSee('Của nhóm A');
    });
});

it('does not mix content types that share a stage key', function (): void {
    $author = User::factory()->create();
    $newsOnly = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($author, $newsOnly): void {
        grantRole($author, 'author', ['entries.edit.own', 'entries.submit']);
        // Can approve, cannot review: in `admin_approval` that is only the pending→published edge.
        grantRole($newsOnly, 'approver', ['entries.approve']);

        $newsCategory = inboxCollection();
        submittedPost($newsCategory, $author, 'Tin chờ duyệt', 'tin-cho');

        // A second governed type whose flow also parks work at a stage named `pending`.
        $services = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
        $services->translations()->create(['locale' => 'vi', 'name' => 'Dịch vụ', 'slug' => 'dich-vu']);
        ContentSection::query()->create(['key' => 'services', 'label' => 'Dịch vụ', 'entry_type' => 'post', 'taxonomy_id' => $services->id, 'sort_order' => 2]);
        app(InstantiateWorkflow::class)->handle('editorial', 'services', active: true);
        submittedPost($services->id, $author, 'Dịch vụ chờ biên tập', 'dv-cho');

        // In `editorial`, pending→reviewing needs entries.review, which this person lacks — so
        // the services post is work they cannot do and must not be listed or counted.
        Livewire::actingAs($newsOnly->fresh())
            ->test(ReviewInbox::class)
            ->assertSee('Tin chờ duyệt')
            ->assertDontSee('Dịch vụ chờ biên tập');
    });
});

it('approves a post that exists only in a non-default language instead of failing', function (): void {
    $author = User::factory()->create();
    $reviewer = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($author, $reviewer): void {
        grantRole($author, 'author', ['entries.edit.own', 'entries.submit']);
        grantRole($reviewer, 'reviewer', ['entries.review', 'entries.approve']);
        $categoryId = inboxCollection();

        // Written in English on a Vietnamese-default site.
        $entry = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'author_id' => $author->id]);
        $entry->translations()->create([
            'locale' => 'en', 'title' => 'English only', 'slug' => 'english-only', 'status' => 'draft',
            'body' => ['blocks' => [['type' => 'richtext', 'props' => ['body' => 'Body text']]]],
        ]);
        $entry->assignCategories([$categoryId]);
        app(ApplyEntryWorkflowTransition::class)->handle($entry->fresh(), 'en', $author->fresh(), 'submit');

        Livewire::actingAs($reviewer->fresh())
            ->test(ReviewInbox::class)
            ->call('open', $entry->ulid)
            ->call('apply', 'approve')
            ->assertHasNoErrors();

        $entry->refresh();
        expect($entry->status)->toBe('published')
            ->and($entry->translationFor('en')->status)->toBe('published')
            ->and(SiteRoute::query()->where('entry_id', $entry->id)->value('locale'))->toBe('en');
    });
});

it('carries the reviewer down the list, not back to the top', function (): void {
    $author = User::factory()->create();
    $reviewer = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($author, $reviewer): void {
        grantRole($author, 'author', ['entries.edit.own', 'entries.submit']);
        grantRole($reviewer, 'reviewer', ['entries.review', 'entries.approve']);
        $categoryId = inboxCollection();

        // Submitted oldest → newest, so the list (updated_at desc) reads C, B, A.
        $a = submittedPost($categoryId, $author, 'Bài A', 'bai-a');
        $b = submittedPost($categoryId, $author, 'Bài B', 'bai-b');
        $c = submittedPost($categoryId, $author, 'Bài C', 'bai-c');

        Livewire::actingAs($reviewer->fresh())
            ->test(ReviewInbox::class)
            ->call('open', $b->ulid)
            ->call('apply', 'approve')
            ->assertHasNoErrors()
            // After handling B the reader must hold A — the one BELOW it — not C at the top.
            ->assertSet('current', $a->ulid);

        expect($b->fresh()->status)->toBe('published')
            ->and($c->fresh()->workflow_state)->toBe('pending');
    });
});

it('offers a send move only to a reviewer who could also edit the post', function (): void {
    $author = User::factory()->create(['name' => 'Người viết']);
    $deskhand = User::factory()->create();
    $copyEditor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($author, $deskhand, $copyEditor): void {
        grantRole($author, 'author', ['entries.edit.own', 'entries.submit']);
        // Both review, and both hold entries.submit; only one may edit the post.
        grantRole($deskhand, 'deskhand', ['entries.review', 'entries.submit']);
        grantRole($copyEditor, 'copy-editor', ['entries.review', 'entries.submit', 'entries.edit.any']);
        $categoryId = inboxCollection(approvalFlow: false);

        // A stage after the draft whose way on is a SEND (entries.submit), not an approval.
        $workflow = Workflow::query()->create([
            'key' => 'copy_desk', 'name' => 'Quy trình biên tập lại', 'applies_to_type' => 'posts', 'is_active' => true,
        ]);
        foreach ([
            ['key' => 'draft', 'label' => 'Bản nháp', 'is_initial' => true, 'sort_order' => 1],
            ['key' => 'copyedit', 'label' => 'Biên tập lại', 'sort_order' => 2],
            ['key' => 'pending', 'label' => 'Chờ duyệt', 'sort_order' => 3],
            ['key' => 'published', 'label' => 'Đã đăng', 'is_published' => true, 'sort_order' => 4],
        ] as $state) {
            $workflow->states()->create($state);
        }
        foreach ([
            ['key' => 'to_desk', 'label' => 'Gửi biên tập', 'from_state' => 'draft', 'to_state' => 'copyedit', 'required_permission' => 'entries.submit'],
            ['key' => 'send_on', 'label' => 'Gửi duyệt', 'from_state' => 'copyedit', 'to_state' => 'pending', 'required_permission' => 'entries.submit'],
            ['key' => 'send_back', 'label' => 'Trả lại', 'from_state' => 'copyedit', 'to_state' => 'draft', 'required_permission' => 'entries.review', 'requires_note' => true],
            ['key' => 'approve', 'label' => 'Duyệt đăng', 'from_state' => 'pending', 'to_state' => 'published', 'required_permission' => 'entries.approve'],
        ] as $transition) {
            $workflow->transitions()->create($transition);
        }

        $post = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'author_id' => $author->id]);
        $post->translations()->create([
            'locale' => 'vi', 'title' => 'Bản tin', 'slug' => 'ban-tin', 'status' => 'draft',
            'body' => ['blocks' => [['type' => 'richtext', 'props' => ['body' => 'Nội dung bài']]]],
        ]);
        $post->assignCategories([$categoryId]);
        app(ApplyEntryWorkflowTransition::class)->handle($post->fresh(), 'vi', $author->fresh(), 'to_desk');
        $post = $post->fresh();
        expect($post->workflow_state)->toBe('copyedit');

        // The post is in both queues and both may send it back, but sending it on is working on
        // it: a button the action would refuse is not shown to the one who cannot edit it.
        Livewire::actingAs($deskhand->fresh())
            ->test(ReviewInbox::class)
            ->call('open', $post->ulid)
            ->assertSee('Nội dung bài')
            ->assertSeeHtml('wire:key="act-send_back"')
            ->assertDontSeeHtml('wire:key="act-send_on"')
            ->call('apply', 'send_on')
            ->assertHasErrors('inbox');
        expect($post->fresh()->workflow_state)->toBe('copyedit');

        Livewire::actingAs($copyEditor->fresh())
            ->test(ReviewInbox::class)
            ->call('open', $post->ulid)
            ->assertSeeHtml('wire:key="act-send_back"')
            ->assertSeeHtml('wire:key="act-send_on"');
    });
});

it('opens the post a link names (?bai=…) — only one waiting for this reviewer, else just the inbox', function (): void {
    $author = User::factory()->create(['name' => 'Người viết']);
    $reviewer = User::factory()->create();
    $tenancy = app(Tenancy::class);

    $foreign = $tenancy->enter(2, function (): string {
        $other = User::factory()->create();
        grantRole($other, 'author', ['entries.edit.own', 'entries.submit']);

        return submittedPost(inboxCollection(), $other->fresh(), 'Bài site khác', 'bai-site-khac')->ulid;
    });

    $tenancy->enter(1, function () use ($author, $reviewer, $foreign): void {
        grantRole($author, 'author', ['entries.create', 'entries.edit.own', 'entries.submit']);
        grantRole($reviewer, 'reviewer', ['entries.review', 'entries.approve']);
        $category = inboxCollection();
        // the first one sent waits longest: seventh in the list, past its first five rows
        $oldest = submittedPost($category, $author->fresh(), 'Bài gửi sớm nhất', 'bai-som-nhat');
        Entry::query()->whereKey($oldest->id)->update(['updated_at' => now()->subDays(2)]);
        foreach (range(1, 6) as $i) {
            submittedPost($category, $author->fresh(), "Bài {$i}", "bai-{$i}");
        }
        $draft = Entry::query()->create(['type' => 'post', 'status' => 'draft', 'author_id' => $author->id]);
        $draft->translations()->create(['locale' => 'vi', 'title' => 'Nháp chưa gửi', 'slug' => 'nhap-chua-gui', 'status' => 'draft', 'body' => ['blocks' => []]]);
        $draft->assignCategories([$category]);

        Livewire::withQueryParams(['bai' => $oldest->ulid])->actingAs($reviewer->fresh())->test(ReviewInbox::class)
            ->assertSet('current', $oldest->ulid)
            ->assertSet('shown', 10)
            ->assertSee('Bài gửi sớm nhất')
            ->assertSee('Nội dung bài');

        foreach ([$foreign, $draft->ulid, 'khong-co-bai-nay', ''] as $ulid) {
            Livewire::withQueryParams(['bai' => $ulid])->actingAs($reviewer->fresh())->test(ReviewInbox::class)
                ->assertOk()
                ->assertSet('current', null)
                ->assertSet('shown', 5)
                ->assertDontSee('Bài site khác');
        }
    });
});

it('approves the ticked posts one engine move each, and lists the one that could not go live', function (): void {
    $author = User::factory()->create();
    $approver = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($author, $approver): void {
        grantRole($author, 'author', ['entries.edit.own', 'entries.submit']);
        grantRole($approver, 'approver', ['entries.review', 'entries.approve']);
        $categoryId = inboxCollection();
        // Two posts at the same address; the list (newest first) handles `$first` before `$clash`,
        // so once `$first` is live `$clash` cannot take the address and stays where it is.
        $clash = submittedPost($categoryId, $author, 'Bài trùng', 'trung-duong-dan');
        $other = submittedPost($categoryId, $author, 'Bài khác', 'bai-khac');
        $first = submittedPost($categoryId, $author, 'Bài đầu', 'trung-duong-dan');

        $inbox = Livewire::actingAs($approver->fresh())
            ->test(ReviewInbox::class)
            ->call('togglePick', $first->ulid)
            ->call('togglePick', $other->ulid)
            ->call('togglePick', $clash->ulid)
            ->assertSee(__('admin.inbox.picked', ['count' => 3]))
            ->assertSee(__('admin.inbox.bulk_ok', ['count' => 3]))
            ->assertSee(__('admin.inbox.bulk_why_all'))
            ->call('approvePicked')
            ->assertSet('picked', [])
            ->assertDispatched('bs-toast', text: __('admin.inbox.bulk_done', ['count' => 2]))
            ->assertSee(__('admin.inbox.bulk_failed', ['count' => 1]))
            ->assertSee('Bài trùng')
            ->assertSee(__('admin.inbox.err_slug'));

        expect($first->fresh()->status)->toBe('published')
            ->and($other->fresh()->status)->toBe('published')
            ->and($clash->fresh()->workflow_state)->toBe('pending')
            // One logged engine move per post that moved, by the approver — none for the clash.
            ->and(WorkflowLog::query()->where('transition_key', 'approve')->where('actor_id', $approver->id)->pluck('entry_id')->sort()->values()->all())
            ->toBe(collect([$first->id, $other->id])->sort()->values()->all());

        // The failure list stays until it is closed.
        $inbox->call('togglePick', $clash->ulid)->assertSee('Bài trùng')
            ->call('dismissBulk')->assertSet('bulkFailed', []);
    });
});

it('says how many of the ticked posts this person can publish, and leaves the rest where they are', function (): void {
    $author = User::factory()->create();
    $reviewer = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($author, $reviewer): void {
        grantRole($author, 'author', ['entries.edit.own', 'entries.submit']);
        grantRole($reviewer, 'reviewer', ['entries.review', 'entries.approve']);
        $categoryId = inboxCollection(approvalFlow: false);
        app(InstantiateWorkflow::class)->handle('editorial', 'posts', active: true);

        $atEdit = submittedPost($categoryId, $author, 'Chờ biên tập', 'cho-bien-tap');
        $atReview = submittedPost($categoryId, $author, 'Chờ duyệt cuối', 'cho-duyet-cuoi');
        app(ApplyEntryWorkflowTransition::class)->handle($atReview, 'vi', $reviewer->fresh(), 'to_review');

        Livewire::actingAs($reviewer->fresh())
            ->test(ReviewInbox::class)
            ->call('togglePick', $atEdit->ulid)
            ->call('togglePick', $atReview->ulid)
            ->assertSee(__('admin.inbox.bulk_why_some', ['ready' => 1, 'picked' => 2]))
            ->assertSee(__('admin.inbox.bulk_ok', ['count' => 1]))
            ->call('approvePicked')
            ->assertSet('bulkFailed', [])
            ->assertDispatched('bs-toast', text: __('admin.inbox.bulk_done', ['count' => 1]));

        expect($atReview->fresh()->status)->toBe('published')
            ->and($atEdit->fresh()->workflow_state)->toBe('pending');
    });
});

it('publishes nothing in bulk for someone whose step is not the publishing one', function (): void {
    $author = User::factory()->create();
    $reviewer = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($author, $reviewer): void {
        grantRole($author, 'author', ['entries.edit.own', 'entries.submit']);
        grantRole($reviewer, 'reviewer', ['entries.review']);   // may send back, may not publish
        $post = submittedPost(inboxCollection(), $author);

        Livewire::actingAs($reviewer->fresh())
            ->test(ReviewInbox::class)
            ->call('togglePick', $post->ulid)
            ->assertSee(__('admin.inbox.bulk_none_btn'))
            ->assertSee(__('admin.inbox.bulk_why_none'))
            ->call('approvePicked')
            ->assertHasErrors('inbox');

        expect($post->fresh()->workflow_state)->toBe('pending')
            ->and(WorkflowLog::query()->where('entry_id', $post->id)->where('transition_key', 'approve')->exists())->toBeFalse();
    });
});

it('ticks only posts that are waiting for this person on this website', function (): void {
    $reviewer = User::factory()->create();
    $tenancy = app(Tenancy::class);

    $elsewhere = $tenancy->enter(2, function (): Entry {
        $other = User::factory()->create();
        grantRole($other, 'author', ['entries.edit.own', 'entries.submit']);

        return submittedPost(inboxCollection(), $other->fresh(), 'Bài site khác', 'bai-site-khac');
    });

    $tenancy->enter(1, function () use ($reviewer, $elsewhere): void {
        grantRole($reviewer, 'reviewer', ['entries.review', 'entries.approve']);
        inboxCollection();

        Livewire::actingAs($reviewer->fresh())
            ->test(ReviewInbox::class)
            ->call('togglePick', $elsewhere->ulid)
            ->call('togglePick', 'not-a-post')
            ->assertSet('picked', [])
            ->call('approvePicked')
            ->assertHasErrors('inbox');
    });

    expect($tenancy->enter(2, fn (): string => (string) $elsewhere->fresh()->workflow_state))->toBe('pending');
});

it('hands a returned post only to a member of this website who can take the next step', function (): void {
    $author = User::factory()->create(['name' => 'An Người viết']);
    $reviewer = User::factory()->create();
    $bystander = User::factory()->create(['name' => 'Bình Chỉ xem']);
    $outsider = User::factory()->create(['name' => 'Chi Site khác']);
    $tenancy = app(Tenancy::class);

    $tenancy->enter(2, function () use ($outsider): void {
        grantRole($outsider, 'author', ['entries.edit.any', 'entries.submit']);
    });

    $tenancy->enter(1, function () use ($author, $reviewer, $bystander, $outsider): void {
        grantRole($author, 'author', ['entries.edit.own', 'entries.submit']);
        grantRole($reviewer, 'reviewer', ['entries.review', 'entries.approve']);
        grantRole($bystander, 'viewer', ['entries.view']);
        $post = submittedPost(inboxCollection(), $author);

        $inbox = Livewire::actingAs($reviewer->fresh())
            ->test(ReviewInbox::class)
            ->call('open', $post->ulid)
            ->call('askNote', 'reject')
            ->assertSet('noteOpen', true)
            ->assertSee(__('admin.inbox.note_return', ['label' => 'Bản nháp']))
            ->assertSeeHtml('id="inbox-assign"')
            ->assertSee('An Người viết')
            ->assertDontSee('Bình Chỉ xem')
            ->assertDontSee('Chi Site khác');

        // A users.id typed into the payload: someone of another website, then someone here without the right.
        foreach ([$outsider->id, $bystander->id] as $tampered) {
            $inbox->set('note', 'Thiếu ảnh')->set('assignTo', $tampered)->call('apply', 'reject')
                ->assertHasErrors('assignTo');
            expect($post->fresh()->workflow_state)->toBe('pending');
        }

        $inbox->set('assignTo', $author->id)->call('apply', 'reject')
            ->assertHasNoErrors()
            ->assertSet('noteOpen', false)
            ->assertDispatched('bs-toast', text: __('admin.inbox.moved_to', ['action' => 'Trả lại', 'name' => 'An Người viết']));

        $post->refresh();
        expect($post->workflow_state)->toBe('draft')
            ->and($post->assigned_to)->toBe($author->id);
    });
});

it('never carries a hand-off onto a move that asks no reason', function (): void {
    $author = User::factory()->create();
    $approver = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($author, $approver): void {
        grantRole($author, 'author', ['entries.edit.own', 'entries.submit']);
        grantRole($approver, 'approver', ['entries.review', 'entries.approve']);
        $post = submittedPost(inboxCollection(), $author);

        Livewire::actingAs($approver->fresh())
            ->test(ReviewInbox::class)
            ->call('open', $post->ulid)
            ->call('askNote', 'approve')                 // no reason is asked for going live
            ->assertSet('noteOpen', false)
            ->assertHasErrors('inbox')
            ->set('assignTo', $author->id)
            ->call('apply', 'approve')
            ->assertHasNoErrors();

        $post->refresh();
        expect($post->status)->toBe('published')
            ->and($post->assigned_to)->toBeNull();
    });
});

it('opens the reason dialog only for a move this person may make on the open post', function (): void {
    $author = User::factory()->create();
    $approver = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($author, $approver): void {
        grantRole($author, 'author', ['entries.edit.own', 'entries.submit']);
        grantRole($approver, 'approver', ['entries.approve']);   // publishes, may not send back
        $post = submittedPost(inboxCollection(), $author);

        Livewire::actingAs($approver->fresh())
            ->test(ReviewInbox::class)
            ->call('open', $post->ulid)
            ->call('askNote', 'reject')
            ->assertSet('noteOpen', false)
            ->assertHasErrors('inbox')
            ->set('note', 'Không được')
            ->call('apply', 'reject')
            ->assertHasErrors('inbox');

        expect($post->fresh()->workflow_state)->toBe('pending');
    });
});

it('shows «Chưa tải được hộp duyệt» with «Thử lại» when the queue cannot be read, then recovers', function (): void {
    $author = User::factory()->create();
    $reviewer = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($author, $reviewer): void {
        grantRole($author, 'author', ['entries.edit.own', 'entries.submit']);
        grantRole($reviewer, 'reviewer', ['entries.review', 'entries.approve']);
        $post = submittedPost(inboxCollection(), $author, 'Bài còn nguyên', 'bai-con-nguyen');
        $reviewer = $reviewer->fresh();

        // The queue read really fails on the server: a statement that errors aborts the transaction
        // it runs in, exactly as a timeout would. Only the read's savepoint may be lost by it.
        $failing = true;
        DB::connection()->beforeExecuting(function (string $query, array $bindings, Connection $connection) use (&$failing): void {
            if ($failing && str_contains($query, '"workflow_state" in')) {
                $connection->select('select 1 / 0');   // a QueryException, as a timeout raises
            }
        });

        $inbox = Livewire::actingAs($reviewer)
            ->test(ReviewInbox::class)
            ->assertOk()
            ->assertSee(__('admin.inbox.err_load_title'))
            ->assertSee(__('admin.inbox.retry'))
            ->assertDontSee('Bài còn nguyên');

        // The request's own transaction and its site context survived the failed read.
        expect(DB::transactionLevel())->toBeGreaterThan(0)
            ->and(DB::scalar("select current_setting('app.current_site_id', true)"))->toBe('1');

        $failing = false;
        $inbox->call('reloadInbox')
            ->assertDontSee(__('admin.inbox.err_load_title'))
            ->assertSee('Bài còn nguyên');

        // A bulk approval while the queue cannot be read moves nothing and keeps the ticks: the
        // posts are still waiting, only the read failed.
        $inbox->call('togglePick', $post->ulid)->assertSet('picked', [$post->ulid]);
        $failing = true;
        $inbox->call('approvePicked')
            ->assertSet('picked', [$post->ulid])
            ->assertSet('bulkFailed', [])
            ->assertSee(__('admin.inbox.err_load_title'));
        $failing = false;
        expect($post->fresh()->workflow_state)->toBe('pending');
    });
});

it('ticking more posts costs no query per ticked post', function (): void {
    $author = User::factory()->create();
    $approver = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($author, $approver): void {
        grantRole($author, 'author', ['entries.edit.own', 'entries.submit']);
        grantRole($approver, 'approver', ['entries.review', 'entries.approve']);
        $categoryId = inboxCollection();
        $posts = collect(range(1, 7))->map(fn (int $i): Entry => submittedPost($categoryId, $author->fresh(), "Bài {$i}", "bai-{$i}"));

        $inbox = Livewire::actingAs($approver->fresh())->test(ReviewInbox::class)
            ->call('togglePick', $posts[0]->ulid);
        $one = countQueries(fn () => $inbox->call('togglePick', $posts[1]->ulid));

        foreach ($posts->slice(2, 4) as $post) {
            $inbox->call('togglePick', $post->ulid);
        }
        $many = countQueries(fn () => $inbox->call('togglePick', $posts[6]->ulid));

        expect($many)->toBe($one);
    });
});

it('reports a ticked post that left the queue before the bulk approval instead of dropping it', function (): void {
    $author = User::factory()->create();
    $approver = User::factory()->create();
    $colleague = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($author, $approver, $colleague): void {
        grantRole($author, 'author', ['entries.edit.own', 'entries.submit']);
        grantRole($approver, 'approver', ['entries.review', 'entries.approve']);
        grantRole($colleague, 'approver', ['entries.review', 'entries.approve']);
        $categoryId = inboxCollection();
        $kept = submittedPost($categoryId, $author, 'Bài vẫn chờ', 'bai-van-cho');
        $taken = submittedPost($categoryId, $author, 'Bài đã có người duyệt', 'bai-da-duyet');

        $inbox = Livewire::actingAs($approver->fresh())
            ->test(ReviewInbox::class)
            ->call('togglePick', $kept->ulid)
            ->call('togglePick', $taken->ulid);

        // Meanwhile a colleague sends one back.
        app(ApplyEntryWorkflowTransition::class)->handle($taken->fresh(), 'vi', $colleague->fresh(), 'reject', 'Thiếu ảnh');

        $inbox->call('approvePicked')
            ->assertDispatched('bs-toast', text: __('admin.inbox.bulk_done', ['count' => 1]))
            ->assertSee(__('admin.inbox.bulk_failed', ['count' => 1]))
            ->assertSee('Bài đã có người duyệt')
            ->assertSee(__('admin.inbox.err_gone'));

        expect($kept->fresh()->status)->toBe('published')
            ->and($taken->fresh()->workflow_state)->toBe('draft');
    });
});

it('closes the reason dialog with its post gone, and shows why on the page', function (): void {
    $author = User::factory()->create();
    $reviewer = User::factory()->create();
    $colleague = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($author, $reviewer, $colleague): void {
        grantRole($author, 'author', ['entries.edit.own', 'entries.submit']);
        grantRole($reviewer, 'reviewer', ['entries.review', 'entries.approve']);
        grantRole($colleague, 'reviewer', ['entries.review', 'entries.approve']);
        $post = submittedPost(inboxCollection(), $author);

        $inbox = Livewire::actingAs($reviewer->fresh())
            ->test(ReviewInbox::class)
            ->call('open', $post->ulid)
            ->call('askNote', 'reject')
            ->assertSet('noteOpen', true);

        app(ApplyEntryWorkflowTransition::class)->handle($post->fresh(), 'vi', $colleague->fresh(), 'approve');

        $inbox->set('note', 'Thiếu ảnh')->call('apply', 'reject')
            ->assertHasErrors('inbox')
            ->assertSet('noteOpen', false)
            ->assertSet('noteKey', null)
            ->assertSee(__('admin.inbox.err_gone'));
    });
});

it('forgets the dialog when the browser closes it (Esc, ✕ or the backdrop)', function (): void {
    $author = User::factory()->create();
    $reviewer = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($author, $reviewer): void {
        grantRole($author, 'author', ['entries.edit.own', 'entries.submit']);
        grantRole($reviewer, 'reviewer', ['entries.review']);
        $post = submittedPost(inboxCollection(), $author);

        Livewire::actingAs($reviewer->fresh())
            ->test(ReviewInbox::class)
            ->call('open', $post->ulid)
            ->call('askNote', 'reject')
            ->set('assignTo', $author->id)
            ->set('noteOpen', false)
            ->assertSet('noteKey', null)
            ->assertSet('assignTo', null);
    });
});

it('asks the reason in the dialog for a publishing move that requires one', function (): void {
    $author = User::factory()->create();
    $approver = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($author, $approver): void {
        grantRole($author, 'author', ['entries.edit.own', 'entries.submit']);
        grantRole($approver, 'approver', ['entries.review', 'entries.approve']);
        $post = submittedPost(inboxCollection(), $author);
        Workflow::query()->firstOrFail()->transitions()->where('key', 'approve')->update(['requires_note' => true]);

        Livewire::actingAs($approver->fresh())
            ->test(ReviewInbox::class)
            ->call('open', $post->ulid)
            // Both moves now ask a reason: neither is a direct (confirmed) apply from the reader foot.
            ->assertSeeHtml('askNote(')
            ->assertDontSeeHtml('$wire.apply(')
            ->call('askNote', 'approve')
            ->assertSet('noteOpen', true)
            ->assertSee(__('entries.wf_ap_text'))
            ->assertDontSeeHtml('id="inbox-assign"')   // a live post waits in nobody's queue: no «Giao cho»
            ->set('note', 'Đã kiểm tra số liệu')
            ->set('assignTo', $author->id)              // nobody's queue holds a live post
            ->call('apply', 'approve')
            ->assertHasNoErrors();

        expect($post->fresh()->status)->toBe('published')
            ->and($post->fresh()->assigned_to)->toBeNull();
    });
});

it('never carries a reason typed in a closed dialog onto a move that asks none', function (): void {
    $author = User::factory()->create();
    $approver = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($author, $approver): void {
        grantRole($author, 'author', ['entries.edit.own', 'entries.submit']);
        grantRole($approver, 'approver', ['entries.review', 'entries.approve']);
        $post = submittedPost(inboxCollection(), $author);

        Livewire::actingAs($approver->fresh())
            ->test(ReviewInbox::class)
            ->call('open', $post->ulid)
            ->call('askNote', 'reject')
            ->set('note', 'Thiếu ảnh')
            ->set('noteOpen', false)                    // Esc
            ->assertSet('note', '')
            ->set('note', str_repeat('x', 2100))        // even a value left on the wire
            ->call('apply', 'approve')
            ->assertHasNoErrors();

        expect($post->fresh()->status)->toBe('published')
            ->and(WorkflowLog::query()->where('entry_id', $post->id)->where('transition_key', 'approve')->value('note'))->toBeNull();
    });
});
