<?php

use App\Modules\Admin\Livewire\Entries\Index;
use App\Modules\Cms\Actions\MovePage;
use App\Modules\Cms\Models\Entry;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Registry\PermissionRegistry;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\ContentSection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * Two moves of different pages can each pass the loop and depth checks on the tree they read and
 * together make a loop or a fourth level. MovePage therefore takes the site's page-tree lock BEFORE
 * it reads the page, its parent or any sibling; the checks then run on the tree as it is. As in the
 * other lock-order tests, the statement order is asserted rather than a real two-connection race,
 * which would be flaky (the crossing moves themselves are in MovePageTest).
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

it('takes the page-tree lock before it reads the tree', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $a = Entry::query()->create(['type' => 'page', 'status' => 'draft']);
        $b = Entry::query()->create(['type' => 'page', 'status' => 'draft']);
        $child = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'parent_id' => $a->id]);

        /** @var list<string> $queries */
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        app(MovePage::class)->handle($child, $b, 0);

        $lock = array_search(true, array_map(fn (string $sql): bool => str_contains($sql, 'pg_advisory_xact_lock'), $queries), true);
        $firstRead = array_search(true, array_map(fn (string $sql): bool => str_contains($sql, 'from "entries"'), $queries), true);

        expect($lock)->toBeInt()
            ->and($firstRead)->toBeInt()
            ->and($lock)->toBeLessThan($firstRead)
            ->and($child->fresh()?->parent_id)->toBe($b->id);
    });
});

it('locks per site, so two sites never wait on each other', function (): void {
    $keys = [];
    DB::listen(function (QueryExecuted $query) use (&$keys): void {
        if (str_contains(strtolower($query->sql), 'pg_advisory_xact_lock')) {
            $keys[] = $query->bindings[0] ?? null;
        }
    });

    foreach ([1, 2] as $site) {
        app(Tenancy::class)->enter($site, function (): void {
            $page = Entry::query()->create(['type' => 'page', 'status' => 'draft']);
            app(MovePage::class)->handle($page, null, 0);
        });
    }

    expect($keys)->toBe(['pages:1', 'pages:2']);
});

/**
 * The statements the callback runs, each with its bindings.
 *
 * @return list<array{0: string, 1: array<int, mixed>}>
 */
function plStatements(Closure $run): array
{
    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = [strtolower($query->sql), $query->bindings];
    });
    $run();

    return $statements;
}

/** The index of the first statement that matches, or null. @param list<array{0: string, 1: array<int, mixed>}> $statements */
function plFirst(array $statements, Closure $match): ?int
{
    foreach ($statements as $i => [$sql, $bindings]) {
        if ($match($sql, $bindings)) {
            return $i;
        }
    }

    return null;
}

function plPageLock(string $sql, array $bindings): bool
{
    return str_contains($sql, 'pg_advisory_xact_lock') && ($bindings[0] ?? null) === 'pages:1';
}

it('locks the page rows a move writes in id order, after the page-tree lock', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $b = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'position' => 1]);
        $a = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'position' => 0]);
        $page = Entry::query()->create(['type' => 'page', 'status' => 'draft', 'position' => 2]);

        $statements = plStatements(fn () => app(MovePage::class)->handle($page, $b, 0));
        $lock = plFirst($statements, plPageLock(...));
        // the ids a row lock names (whereKey inlines integer keys into the statement)
        $named = function (string $sql): array {
            preg_match('/"id" in \(([\d, ]+)\)/', $sql, $m);

            return array_map('intval', array_filter(explode(',', str_replace(' ', '', $m[1] ?? ''))));
        };
        $rows = plFirst($statements, fn (string $sql): bool => str_contains($sql, 'from "entries"') && str_contains($sql, 'for update')
            && in_array($page->id, $named($sql), true) && in_array($b->id, $named($sql), true));

        expect($lock)->toBeInt()->and($rows)->toBeInt()->and($lock)->toBeLessThan($rows)
            ->and($statements[$rows][0])->toContain('order by "id" asc');
        $ids = $named($statements[$rows][0]);
        $sorted = $ids;
        sort($sorted);
        expect($ids)->toBe($sorted)->and($ids)->toContain($a->id, $b->id, $page->id);
    });
});

it('puts a page in the Trash and brings it back under the page-tree lock, before it writes the row', function (): void {
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'owner', PermissionRegistry::TENANT_PERMISSIONS));

    app(Tenancy::class)->enter(1, function () use ($user): void {
        $page = Entry::query()->create(['type' => 'page', 'status' => 'draft']);
        $page->translations()->create(['locale' => 'vi', 'title' => 'T p', 'slug' => 'p', 'status' => 'draft', 'body' => ['blocks' => []]]);
        $list = Livewire::actingAs($user->fresh() ?? $user)->test(Index::class);
        $trashed = fn (string $sql): bool => str_starts_with(ltrim($sql), 'update "entries" set "deleted_at"');

        $statements = plStatements(fn () => $list->call('trash', $page->ulid));
        expect($page->fresh()?->trashed())->toBeTrue()
            ->and(plFirst($statements, plPageLock(...)))->toBeInt()->toBeLessThan(plFirst($statements, $trashed));

        $statements = plStatements(fn () => $list->call('restore', $page->ulid));
        expect($page->fresh()?->trashed())->toBeFalse()
            ->and(plFirst($statements, plPageLock(...)))->toBeInt()->toBeLessThan(plFirst($statements, $trashed));

        $list->set('selected', [$page->ulid]);
        $statements = plStatements(fn () => $list->call('bulkTrash'));
        expect($page->fresh()?->trashed())->toBeTrue()
            ->and(plFirst($statements, plPageLock(...)))->toBeInt()
            ->toBeLessThan(plFirst($statements, fn (string $sql): bool => str_contains($sql, 'from "entries"') && str_contains($sql, 'for update')));

        $statements = plStatements(fn () => $list->call('undoTrash'));
        expect($page->fresh()?->trashed())->toBeFalse()
            ->and(plFirst($statements, plPageLock(...)))->toBeInt()
            ->toBeLessThan(plFirst($statements, fn (string $sql): bool => str_contains($sql, 'from "entries"') && str_contains($sql, 'for update')));
    });
});

it('locks a page and its sub-pages in id order before it puts the branch in the Trash', function (): void {
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'owner', PermissionRegistry::TENANT_PERMISSIONS));

    app(Tenancy::class)->enter(1, function () use ($user): void {
        // the sub-page was made first, then moved under a newer page: its id is lower than its parent's
        $kid = Entry::query()->create(['type' => 'page', 'status' => 'draft']);
        $parent = Entry::query()->create(['type' => 'page', 'status' => 'draft']);
        $kid->forceFill(['parent_id' => $parent->id])->save();
        foreach ([[$parent, 'cha'], [$kid, 'con']] as [$row, $slug]) {
            $row->translations()->create(['locale' => 'vi', 'title' => 'T '.$slug, 'slug' => $slug, 'status' => 'draft', 'body' => ['blocks' => []]]);
        }
        $list = Livewire::actingAs($user->fresh() ?? $user)->test(Index::class);

        $statements = plStatements(fn () => $list->call('trash', $parent->ulid, true));
        expect($parent->fresh()?->trashed())->toBeTrue()->and($kid->fresh()?->trashed())->toBeTrue();

        // integer keys are written into the SQL, not bound: read the locked ids from the IN list
        $ids = function (string $sql): array {
            preg_match('/"entries"\."id" in \(([\d, ]+)\)/', $sql, $m);

            return array_map(intval(...), explode(',', $m[1] ?? ''));
        };
        $locked = plFirst($statements, fn (string $sql): bool => str_contains($sql, 'for update')
            && in_array($kid->id, $ids($sql), true) && in_array($parent->id, $ids($sql), true));
        $firstWrite = plFirst($statements, fn (string $sql): bool => str_starts_with(ltrim($sql), 'update "entries" set "deleted_at"'));
        expect($locked)->toBeInt()->and($firstWrite)->toBeInt()->and($locked)->toBeLessThan($firstWrite)
            ->and(plFirst($statements, plPageLock(...)))->toBeLessThan($locked)
            ->and($statements[$locked][0])->toContain('order by "id" asc');
    });
});

it('never takes the page-tree lock for a post', function (): void {
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'owner', PermissionRegistry::TENANT_PERMISSIONS));

    app(Tenancy::class)->enter(1, function () use ($user): void {
        ContentSection::query()->create(['key' => 'posts', 'label' => 'Tin tức', 'entry_type' => 'post', 'permission_prefix' => 'entries', 'sort_order' => 1]);
        $post = Entry::query()->create(['type' => 'post', 'status' => 'draft']);
        $post->translations()->create(['locale' => 'vi', 'title' => 'T p', 'slug' => 'p', 'status' => 'draft', 'body' => ['blocks' => []]]);
        $list = Livewire::actingAs($user->fresh() ?? $user)->test(Index::class, ['section' => 'posts']);

        $statements = plStatements(fn () => $list->call('trash', $post->ulid)->call('restore', $post->ulid));

        expect(plFirst($statements, plPageLock(...)))->toBeNull()
            ->and(plFirst($statements, fn (string $sql): bool => str_starts_with(ltrim($sql), 'update "entries" set "deleted_at"')))->toBeInt();
    });
});
