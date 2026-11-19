<?php

use App\Modules\Cms\Actions\MovePage;
use App\Modules\Cms\Models\Entry;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

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
