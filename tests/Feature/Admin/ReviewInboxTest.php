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

/** A governed `posts` collection; returns the primary category id. */
function inboxCollection(): int
{
    $category = Taxonomy::query()->create(['vocabulary' => 'post_cat', 'position' => 0]);
    $category->translations()->create(['locale' => 'vi', 'name' => 'Tin tức', 'slug' => 'tin-tuc']);

    ContentSection::query()->create([
        'key' => 'posts', 'label' => 'Tin tức', 'entry_type' => 'post', 'taxonomy_id' => $category->id,
        'permission_prefix' => 'entries', 'sort_order' => 1,
    ]);
    SiteSetting::query()->create(['group_name' => 'permalinks', 'key' => 'posts', 'value' => ['pattern' => 'category']]);
    app(InstantiateWorkflow::class)->handle('admin_approval', 'posts', active: true);

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
        submittedPost($categoryId, tap($other, fn (User $u) => grantRole($u, 'author', ['entries.submit']))->fresh(), 'Bài site khác', 'bai-site-khac');
    });

    $tenancy->enter(1, function () use ($author, $reviewer): void {
        grantRole($author, 'author', ['entries.submit']);
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
        grantRole($author, 'author', ['entries.submit']);
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
        grantRole($author, 'author', ['entries.submit']);
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
        grantRole($author, 'author', ['entries.submit']);
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
