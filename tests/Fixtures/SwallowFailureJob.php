<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Modules\Tenancy\Jobs\WithinSite;
use App\Modules\Tenancy\Models\TenantProbe;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * A tenant job that writes, queues after-commit work, registers a rollback cleanup, then runs a
 * statement the database refuses and swallows the error, as a sweep that catches per row does.
 */
final class SwallowFailureJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $siteId) {}

    public function handle(): void
    {
        TenantProbe::create(['value' => 'from-job']);
        MarkAfterCommitJob::dispatch('swallowing-job');
        DB::afterRollBack(static fn () => Cache::put('rollback-cleanup:swallowing-job', true));

        try {
            DB::select('select 1/0');
        } catch (QueryException) {
            // swallowed on purpose
        }
    }

    /** @return array<int, WithinSite> */
    public function middleware(): array
    {
        return [new WithinSite($this->siteId)];
    }
}
