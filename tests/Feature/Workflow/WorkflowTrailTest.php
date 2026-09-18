<?php

use App\Modules\Cms\Models\Entry;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Workflow\Models\Workflow;
use App\Modules\Workflow\Services\WorkflowEngine;
use App\Modules\Workflow\Services\WorkflowTrail;

/*
 * The read side of the approval trail (design_handoff_workflow screen 2 "Lịch sử duyệt"). It
 * renders what the engine SNAPSHOTTED into workflow_log.labels at write time — never a live join
 * onto states, which would print raw keys once a template swap renamed a stage — and pages by
 * keyset on (created_at, id), newest first, so "Xem thêm" never re-reads what is already shown.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/** draft → pending → published (+ reject with a note); call inside a tenant context. */
function trailWorkflow(): Workflow
{
    $workflow = Workflow::query()->create([
        'key' => 'admin_approval', 'name' => 'Duyệt trước khi đăng',
        'applies_to_type' => 'tin-tuc', 'template' => 'admin_approval', 'is_active' => true,
    ]);

    foreach ([
        ['key' => 'draft', 'label' => 'Bản nháp', 'sort_order' => 0, 'is_initial' => true],
        ['key' => 'pending', 'label' => 'Chờ duyệt', 'sort_order' => 1],
        ['key' => 'published', 'label' => 'Đã đăng', 'sort_order' => 2, 'is_published' => true],
    ] as $state) {
        $workflow->states()->create($state);
    }

    foreach ([
        ['key' => 'submit', 'label' => 'Gửi duyệt', 'from_state' => 'draft', 'to_state' => 'pending', 'required_permission' => 'entries.submit'],
        ['key' => 'reject', 'label' => 'Trả lại', 'from_state' => 'pending', 'to_state' => 'draft', 'required_permission' => 'entries.review', 'requires_note' => true],
        ['key' => 'approve', 'label' => 'Duyệt đăng', 'from_state' => 'pending', 'to_state' => 'published', 'required_permission' => 'entries.approve'],
    ] as $transition) {
        $workflow->transitions()->create($transition);
    }

    return $workflow;
}

it('reads the snapshotted labels newest-first with the actor name and note', function (): void {
    $author = User::factory()->create(['name' => 'Trần Văn Nam']);
    $editor = User::factory()->create(['name' => 'Ngô Thị Hà']);

    app(Tenancy::class)->enter(1, function () use ($author, $editor): void {
        grantRole($author, 'author', ['entries.submit']);
        grantRole($editor, 'editor', ['entries.review', 'entries.approve']);
        $workflow = trailWorkflow();
        $entryId = Entry::query()->create(['type' => 'post', 'status' => 'draft'])->id;
        $engine = app(WorkflowEngine::class);

        $engine->apply($workflow, 'submit', 'draft', $author->fresh(), $entryId, 'vi');
        $engine->apply($workflow, 'reject', 'pending', $editor->fresh(), $entryId, 'vi', 'Thiếu ảnh máy CNC');

        // A later rename must NOT rewrite history: the trail shows the label that was current
        // when the move happened.
        $workflow->states()->where('key', 'pending')->update(['label' => 'Chờ biên tập']);

        $page = app(WorkflowTrail::class)->forEntry($entryId, limit: 3);

        expect($page['total'])->toBe(2)
            ->and($page['rows'])->toHaveCount(2)
            ->and($page['rows'][0])->toMatchArray([
                'actor' => 'Ngô Thị Hà', 'transition' => 'Trả lại', 'from' => 'Chờ duyệt', 'to' => 'Bản nháp', 'note' => 'Thiếu ảnh máy CNC',
            ])
            ->and($page['rows'][1])->toMatchArray([
                'actor' => 'Trần Văn Nam', 'transition' => 'Gửi duyệt', 'from' => 'Bản nháp', 'to' => 'Chờ duyệt', 'note' => null,
            ]);
    });
});

it('pages by keyset — the next page starts strictly after the cursor and never repeats a row', function (): void {
    $author = User::factory()->create();
    $editor = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($author, $editor): void {
        grantRole($author, 'author', ['entries.submit']);
        grantRole($editor, 'editor', ['entries.review']);
        $workflow = trailWorkflow();
        $entryId = Entry::query()->create(['type' => 'post', 'status' => 'draft'])->id;
        $engine = app(WorkflowEngine::class);

        // 5 moves: submit / reject / submit / reject / submit.
        for ($i = 0; $i < 2; $i++) {
            $engine->apply($workflow, 'submit', 'draft', $author->fresh(), $entryId, 'vi');
            $engine->apply($workflow, 'reject', 'pending', $editor->fresh(), $entryId, 'vi', "Lần {$i}");
        }
        $engine->apply($workflow, 'submit', 'draft', $author->fresh(), $entryId, 'vi');

        $trail = app(WorkflowTrail::class);
        $first = $trail->forEntry($entryId, limit: 3);
        $second = $trail->forEntry($entryId, limit: 3, after: $first['cursor']);

        $ids = array_merge(array_column($first['rows'], 'id'), array_column($second['rows'], 'id'));

        expect($first['total'])->toBe(5)
            ->and($first['rows'])->toHaveCount(3)
            ->and($second['rows'])->toHaveCount(2)
            ->and($second['cursor'])->toBeNull()   // nothing left after the last row
            ->and($ids)->toBe(array_unique($ids))
            ->and($ids)->toBe(collect($ids)->sortDesc()->values()->all());   // newest first throughout
    });
});

it('names a departed actor plainly instead of breaking the row', function (): void {
    $author = User::factory()->create(['name' => 'Người sắp nghỉ']);

    app(Tenancy::class)->enter(1, function () use ($author): void {
        grantRole($author, 'author', ['entries.submit']);
        $workflow = trailWorkflow();
        $entryId = Entry::query()->create(['type' => 'post', 'status' => 'draft'])->id;

        app(WorkflowEngine::class)->apply($workflow, 'submit', 'draft', $author->fresh(), $entryId, 'vi');
        $author->delete();   // actor_id → NULL (soft reference, D4)

        $row = app(WorkflowTrail::class)->forEntry($entryId)['rows'][0];
        expect($row['actor'])->toBeNull()
            ->and($row['transition'])->toBe('Gửi duyệt');
    });
});

it('never leaks another site trail', function (): void {
    $author = User::factory()->create();
    $tenancy = app(Tenancy::class);

    $foreignEntryId = $tenancy->enter(2, function () use ($author): int {
        grantRole($author, 'author', ['entries.submit']);
        $workflow = trailWorkflow();
        $entryId = Entry::query()->create(['type' => 'post', 'status' => 'draft'])->id;
        app(WorkflowEngine::class)->apply($workflow, 'submit', 'draft', $author->fresh(), $entryId, 'vi');

        return $entryId;
    });

    $tenancy->enter(1, function () use ($foreignEntryId): void {
        $page = app(WorkflowTrail::class)->forEntry($foreignEntryId);
        expect($page['rows'])->toBe([])->and($page['total'])->toBe(0);
    });
});
