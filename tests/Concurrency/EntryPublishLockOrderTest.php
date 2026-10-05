<?php

use App\Modules\Cms\Actions\PublishEntry;
use App\Modules\Cms\Actions\PublishScheduledEntry;
use App\Modules\Cms\Models\Entry;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/*
 * The publishing roads judge an entry (status, Trash, slot) on the row as it is now, so they must
 * take the row lock BEFORE re-reading it. Read first, lock second, and a publish racing a delete
 * or a "back to draft" judges the stale row and puts it live anyway. As in
 * WorkflowTransitionLockOrderTest, the query order is asserted rather than a real two-connection
 * race, which would be flaky.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

/**
 * Run $publish and return the SELECTs it issued against `entries`, in order.
 *
 * @return list<string>
 */
function entrySelectsDuring(Closure $publish): array
{
    /** @var list<string> $queries */
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    $publish();

    return array_values(array_filter(
        $queries,
        fn (string $sql): bool => str_starts_with(trim($sql), 'select') && str_contains($sql, 'from "entries"'),
    ));
}

it('locks a scheduled entry before re-reading it to decide whether it is still due', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $entry = Entry::query()->create(['type' => 'page', 'status' => 'scheduled', 'published_at' => now()->subMinute()]);
        $entry->translations()->create(['locale' => 'vi', 'title' => 'Hẹn giờ', 'slug' => 'hen-gio', 'status' => 'draft', 'body' => ['blocks' => []]]);
        $entry = $entry->fresh() ?? throw new RuntimeException('Entry vanished after creation.');

        $selects = entrySelectsDuring(function () use ($entry): void {
            app(PublishScheduledEntry::class)->handle($entry);
        });

        expect(count($selects))->toBeGreaterThanOrEqual(2);
        expect($selects[0])->toContain('for update');
        expect($selects[1])->not->toContain('for update');
    });
});

it('locks an entry before re-reading it to publish it from the editor', function (): void {
    $publisher = User::factory()->create();

    app(Tenancy::class)->enter(1, function () use ($publisher): void {
        grantRole($publisher, 'editor', ['entries.edit.any', 'entries.publish']);

        $entry = Entry::query()->create(['type' => 'page', 'status' => 'draft']);
        $entry->translations()->create(['locale' => 'vi', 'title' => 'Giới thiệu', 'slug' => 'gioi-thieu', 'status' => 'draft', 'body' => ['blocks' => []]]);
        $entry = $entry->fresh() ?? throw new RuntimeException('Entry vanished after creation.');
        $actor = $publisher->fresh() ?? throw new RuntimeException('Publisher vanished after creation.');

        $selects = entrySelectsDuring(function () use ($entry, $actor): void {
            app(PublishEntry::class)->handle($entry, 'vi', $actor);
        });

        expect(count($selects))->toBeGreaterThanOrEqual(2);
        expect($selects[0])->toContain('for update');
        expect($selects[1])->not->toContain('for update');
    });
});
