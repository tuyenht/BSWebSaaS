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
