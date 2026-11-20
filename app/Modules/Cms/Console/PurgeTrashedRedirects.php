<?php

declare(strict_types=1);

namespace App\Modules\Cms\Console;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Cms\Models\Redirect;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Delete for good the redirects that have sat in the trash longer than Redirect::TRASH_DAYS days.
 * Scheduled daily.
 *
 * Runs site by site inside Tenancy::eachSite, like menus:purge-trash, so RLS and the model's scope
 * pick each site's own trash (a CLI has no site context otherwise). Ids are read first and deleted
 * in chunks, each with the site and the trash condition repeated, so a live redirect is never
 * touched. A redirect to another website gets an audit row (actor "system") in the same
 * transaction as its delete.
 */
final class PurgeTrashedRedirects extends Command
{
    private const int CHUNK = 1000;

    #[\Override]
    protected $signature = 'redirects:purge-trash';

    #[\Override]
    protected $description = 'Delete for good the redirects left in the trash longer than the retention window';

    public function handle(Tenancy $tenancy): int
    {
        $cutoff = now()->subDays(Redirect::TRASH_DAYS);
        $purged = 0;

        $tenancy->eachSite(function (int $siteId) use ($cutoff, &$purged): void {
            $ids = array_map(
                static fn (mixed $id): int => Cast::int($id),
                Redirect::onlyTrashed()->where('deleted_at', '<', $cutoff)->orderBy('id')->pluck('id')->all(),
            );

            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                DB::transaction(function () use ($siteId, $cutoff, $chunk, &$purged): void {
                    // forceDelete() skips the model's scopes, hence the explicit site and trash conditions.
                    $doomed = Redirect::query()->withTrashed()->where('site_id', $siteId)->whereNotNull('deleted_at')
                        ->where('deleted_at', '<', $cutoff)->whereKey($chunk);
                    // every removal of a redirect to another website leaves an audit row, this one too
                    foreach ((clone $doomed)->where('to_path', 'like', Redirect::EXTERNAL_PREFIX.'%')
                        ->get(['id', 'ulid', 'locale', 'from_path', 'to_path', 'status_code']) as $row) {
                        Audit::recordOrFail(Redirect::AUDIT_EXTERNAL_PURGED, [
                            'actor_label' => 'system',
                            'auditable_type' => Redirect::class,
                            'auditable_id' => $row->id,
                            'meta' => [
                                'name' => $row->from_path, 'was' => $row->to_path, 'ulid' => $row->ulid, 'locale' => $row->locale,
                                'from' => $row->from_path, 'to' => $row->to_path, 'status_code' => $row->status_code, 'trash_days' => Redirect::TRASH_DAYS,
                            ],
                        ]);
                    }
                    $purged += Cast::int($doomed->forceDelete());
                });
            }
        });

        $this->info("Purged {$purged} redirect(s) left in the trash over ".Redirect::TRASH_DAYS.' day(s).');

        return self::SUCCESS;
    }
}
