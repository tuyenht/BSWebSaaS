<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Policies\EntryPolicy;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use App\Modules\Workflow\Actions\InstantiateWorkflow;
use App\Modules\Workflow\Services\ReviewQueue;

/*
 * Which content is waiting for THIS person (design_handoff_workflow screen 3). The answer is a
 * set of stage keys derived from the actor's permissions, so the inbox filters entries with one
 * whereIn instead of walking them. An unsent draft (initial stage) and live content (published
 * stage) are never in a review queue, however the permissions fall.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/** A governable section + an active `editorial` flow over it (draft → pending → reviewing → published → archived). */
function editorialSection(string $key = 'posts'): void
{
    ContentSection::query()->firstOrCreate(
        ['key' => $key],
        ['label' => ucfirst($key), 'entry_type' => 'post'],
    );
    app(InstantiateWorkflow::class)->handle('editorial', $key, active: true);
}

it('gives a reviewer the stages their permissions can actually move content out of', function (): void {
    $reviewer = User::factory()->create();
    $approver = User::factory()->create();
    $author = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($reviewer, $approver, $author): void {
        grantRole($reviewer, 'reviewer', ['entries.review']);
        grantRole($approver, 'approver', ['entries.approve']);
        grantRole($author, 'author', ['entries.submit']);
        editorialSection();

        $queue = app(ReviewQueue::class);

        // review = to_review (pending→reviewing), request_changes (reviewing→draft),
        // send_back (pending→draft), retract (published→draft, excluded as a published stage).
        expect($queue->actionableStages($reviewer->fresh()))->toBe(['posts' => ['pending', 'reviewing']]);

        // approve = reviewing→published, archive = published→archived (published stage: excluded).
        expect($queue->actionableStages($approver->fresh()))->toBe(['posts' => ['reviewing']]);

        // submit starts at the INITIAL stage — an unsent draft is the author's own work, so the
        // author's inbox is empty rather than full of everyone's drafts.
        expect($queue->actionableStages($author->fresh()))->toBe([]);
    });
});

it('keeps each governed type separate and unions the keys for one whereIn', function (): void {
    $reviewer = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($reviewer): void {
        grantRole($reviewer, 'reviewer', ['entries.review']);
        editorialSection('posts');

        ContentSection::query()->firstOrCreate(['key' => 'services'], ['label' => 'Dịch vụ', 'entry_type' => 'post']);
        app(InstantiateWorkflow::class)->handle('admin_approval', 'services', active: true);

        $queue = app(ReviewQueue::class);

        expect($queue->actionableStages($reviewer->fresh()))->toBe([
            'posts' => ['pending', 'reviewing'],   // editorial
            'services' => ['pending'],             // admin_approval: reject pending→draft
        ]);
        expect($queue->actionableStageKeys($reviewer->fresh()))->toBe(['pending', 'reviewing']);
    });
});

it('ignores a workflow that is switched off', function (): void {
    $reviewer = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($reviewer): void {
        grantRole($reviewer, 'reviewer', ['entries.review']);
        ContentSection::query()->firstOrCreate(['key' => 'posts'], ['label' => 'Tin', 'entry_type' => 'post']);
        app(InstantiateWorkflow::class)->handle('editorial', 'posts', active: false);

        expect(app(ReviewQueue::class)->actionableStages($reviewer->fresh()))->toBe([]);
    });
});

it('never reaches across sites', function (): void {
    $reviewer = User::factory()->create();
    $tenancy = app(Tenancy::class);

    $tenancy->enter(2, function (): void {
        editorialSection('posts');   // another site runs a flow…
    });

    $tenancy->enter(1, function () use ($reviewer): void {
        grantRole($reviewer, 'reviewer', ['entries.review']);

        // …which must not appear here: workflows are tenant rows behind RLS.
        expect(app(ReviewQueue::class)->actionableStages($reviewer->fresh()))->toBe([]);
    });
});

it('opens the inbox for review or approve rights, and for nobody else', function (): void {
    $reviewer = User::factory()->create();
    $approver = User::factory()->create();
    $viewer = User::factory()->create();
    $author = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($reviewer, $approver, $viewer, $author): void {
        grantRole($reviewer, 'reviewer', ['entries.review']);
        grantRole($approver, 'approver', ['entries.approve']);
        grantRole($viewer, 'viewer', ['entries.view']);
        grantRole($author, 'author', ['entries.create', 'entries.edit.own', 'entries.submit']);

        $policy = new EntryPolicy;

        // Approving is not editing and not merely reading the list.
        expect($policy->review($reviewer->fresh()))->toBeTrue()
            ->and($policy->review($approver->fresh()))->toBeTrue()
            ->and($policy->review($viewer->fresh()))->toBeFalse()
            ->and($policy->review($author->fresh()))->toBeFalse();
    });
});

it('counts only entries sitting on an actionable stage', function (): void {
    $reviewer = User::factory()->create();
    $author = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($reviewer, $author): void {
        grantRole($reviewer, 'reviewer', ['entries.review']);
        grantRole($author, 'author', ['entries.submit']);
        editorialSection();

        // One waiting at `pending`, one still a draft, one already live.
        foreach ([['pending', 'pending'], ['draft', 'draft'], ['published', 'published']] as [$stage, $status]) {
            $entry = Entry::query()->create(['type' => 'post', 'status' => $status, 'workflow_state' => $stage]);
            $entry->translations()->create(['locale' => 'vi', 'title' => "Bài {$stage}", 'slug' => "bai-{$stage}", 'status' => $status, 'body' => ['blocks' => []]]);
        }

        $stages = app(ReviewQueue::class)->actionableStageKeys($reviewer->fresh());
        $waiting = Entry::query()->whereIn('workflow_state', $stages)->count();

        expect($waiting)->toBe(1);
    });
});
