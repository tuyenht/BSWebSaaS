<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Workflow\Exceptions\InvalidTransitionException;
use App\Modules\Workflow\Models\Workflow;
use App\Modules\Workflow\Models\WorkflowLog;
use App\Modules\Workflow\Services\WorkflowEngine;
use Illuminate\Auth\Access\AuthorizationException;

/*
 * Tier-2 workflow engine (design.md §15.4). Moves a subject between DATA-defined states; the
 * guard on every edge is a PERMISSION, so "who may move content" stays an RBAC answer and a site
 * can redefine its stages without a deploy. Default-OFF: no active workflow → null → callers keep
 * tier-1 permission-driven publishing, so existing sites are untouched.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/**
 * An `editorial` workflow for `tin-tuc`: draft → pending → published, plus a reject edge back to
 * draft that demands a note (the "phản biện" path). Call inside a tenant context.
 */
function editorialWorkflow(bool $active = true): Workflow
{
    $workflow = Workflow::query()->create([
        'key' => 'editorial',
        'name' => 'Quy trình biên tập',
        'applies_to_type' => 'tin-tuc',
        'template' => 'editorial',
        'is_active' => $active,
    ]);

    foreach ([
        ['key' => 'draft', 'label' => 'Bản nháp', 'sort_order' => 0, 'is_initial' => true],
        ['key' => 'pending', 'label' => 'Chờ duyệt', 'sort_order' => 1],
        ['key' => 'published', 'label' => 'Đã đăng', 'sort_order' => 2, 'is_published' => true, 'is_terminal' => true],
    ] as $state) {
        $workflow->states()->create($state);
    }

    foreach ([
        ['key' => 'submit', 'label' => 'Gửi duyệt', 'from_state' => 'draft', 'to_state' => 'pending', 'required_permission' => 'entries.submit'],
        ['key' => 'approve', 'label' => 'Duyệt đăng', 'from_state' => 'pending', 'to_state' => 'published', 'required_permission' => 'entries.approve'],
        ['key' => 'reject', 'label' => 'Yêu cầu sửa', 'from_state' => 'pending', 'to_state' => 'draft', 'required_permission' => 'entries.review', 'requires_note' => true],
    ] as $transition) {
        $workflow->transitions()->create($transition);
    }

    return $workflow;
}

/** A post to move through the workflow — the engine only ever sees its id. */
function workflowEntry(): int
{
    return Entry::query()->create(['type' => 'post', 'status' => 'draft'])->id;
}

it('is default-OFF — a site with no active workflow resolves to null so tier-1 publishing stands', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        // Nothing configured at all.
        expect(app(WorkflowEngine::class)->activeFor('tin-tuc'))->toBeNull();

        // Configured but not switched on — the opt-in, not the mere existence, is what counts.
        editorialWorkflow(active: false);
        expect(app(WorkflowEngine::class)->activeFor('tin-tuc'))->toBeNull();
    });
});

it('resolves the active workflow only for the content type it governs', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        editorialWorkflow();

        expect(app(WorkflowEngine::class)->activeFor('tin-tuc'))->not->toBeNull();
        expect(app(WorkflowEngine::class)->activeFor('san-pham'))->toBeNull();
    });
});

it("never leaks another site's workflow — approval config is tenant-isolated", function (): void {
    app(Tenancy::class)->enter(1, fn () => editorialWorkflow());

    app(Tenancy::class)->enter(2, function (): void {
        // Site B must not inherit site A's gate — nor even see its config rows (RLS).
        expect(app(WorkflowEngine::class)->activeFor('tin-tuc'))->toBeNull();
        expect(Workflow::query()->count())->toBe(0);
    });
});

it('offers an actor only the transitions their permissions actually allow', function (): void {
    $author = User::factory()->create();
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($author, $editor): void {
        $workflow = editorialWorkflow();
        $engine = app(WorkflowEngine::class);

        grantRole($author, 'author', ['entries.submit']);
        grantRole($editor, 'editor', ['entries.submit', 'entries.review', 'entries.approve']);

        // From draft both can submit…
        expect($engine->allowedTransitions($workflow, 'draft', $author->fresh())->pluck('key')->all())->toBe(['submit']);

        // …but from pending the author is offered nothing, while the editor gets both edges.
        expect($engine->allowedTransitions($workflow, 'pending', $author->fresh())->all())->toBe([]);
        expect($engine->allowedTransitions($workflow, 'pending', $editor->fresh())->pluck('key')->sort()->values()->all())
            ->toBe(['approve', 'reject']);
    });
});

it('applies a permitted transition and writes an audit row', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        $workflow = editorialWorkflow();
        grantRole($editor, 'editor', ['entries.approve']);
        $entryId = workflowEntry();

        $destination = app(WorkflowEngine::class)->apply(
            $workflow, 'approve', 'pending', $editor->fresh(), $entryId, 'vi',
        );

        // The engine returns the destination; persisting it is the caller's job.
        expect($destination->key)->toBe('published');
        expect($destination->is_published)->toBeTrue();

        $log = WorkflowLog::query()->firstOrFail();
        expect($log->entry_id)->toBe($entryId);
        expect($log->transition_key)->toBe('approve');
        expect($log->from_state)->toBe('pending');
        expect($log->to_state)->toBe('published');
        expect($log->actor_id)->toBe($editor->id);
        expect($log->locale)->toBe('vi');
    });
});

it('rejects a transition the actor lacks the permission for — and logs nothing', function (): void {
    $author = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($author): void {
        $workflow = editorialWorkflow();
        grantRole($author, 'author', ['entries.submit']);   // no approve
        $entryId = workflowEntry();

        expect(fn () => app(WorkflowEngine::class)->apply($workflow, 'approve', 'pending', $author->fresh(), $entryId))
            ->toThrow(AuthorizationException::class);

        expect(WorkflowLog::query()->count())->toBe(0);
    });
});

it("rejects an edge that does not start from the subject's current state (stale tab replay)", function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        $workflow = editorialWorkflow();
        grantRole($editor, 'editor', ['entries.approve']);

        // `approve` runs pending → published; the content is back in draft.
        expect(fn () => app(WorkflowEngine::class)->apply($workflow, 'approve', 'draft', $editor->fresh(), workflowEntry()))
            ->toThrow(InvalidTransitionException::class);

        expect(WorkflowLog::query()->count())->toBe(0);
    });
});

it('rejects an unknown transition key', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        $workflow = editorialWorkflow();
        grantRole($editor, 'editor', ['entries.approve']);

        expect(fn () => app(WorkflowEngine::class)->apply($workflow, 'teleport', 'draft', $editor->fresh(), workflowEntry()))
            ->toThrow(InvalidTransitionException::class);
    });
});

it('enforces the note on an edge that demands one, and stores it trimmed', function (): void {
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($editor): void {
        $workflow = editorialWorkflow();
        grantRole($editor, 'editor', ['entries.review']);
        $entryId = workflowEntry();

        // Whitespace is not feedback.
        expect(fn () => app(WorkflowEngine::class)->apply($workflow, 'reject', 'pending', $editor->fresh(), $entryId, 'vi', '   '))
            ->toThrow(InvalidTransitionException::class);
        expect(WorkflowLog::query()->count())->toBe(0);

        app(WorkflowEngine::class)->apply($workflow, 'reject', 'pending', $editor->fresh(), $entryId, 'vi', '  Thiếu nguồn ảnh  ');

        expect(WorkflowLog::query()->firstOrFail()->note)->toBe('Thiếu nguồn ảnh');
    });
});

it('records an assignment so the reviewer inbox can find the work', function (): void {
    $author = User::factory()->create();
    $reviewer = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($author, $reviewer): void {
        $workflow = editorialWorkflow();
        grantRole($author, 'author', ['entries.submit']);

        app(WorkflowEngine::class)->apply(
            $workflow, 'submit', 'draft', $author->fresh(), workflowEntry(), 'vi', null, $reviewer->id,
        );

        expect(WorkflowLog::query()->firstOrFail()->assigned_to)->toBe($reviewer->id);
    });
});
