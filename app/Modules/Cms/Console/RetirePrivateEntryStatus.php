<?php

declare(strict_types=1);

namespace App\Modules\Cms\Console;

use App\Modules\Cms\Actions\TransitionEntryStatus;
use App\Modules\Cms\Enums\EntryStatus;
use App\Modules\Cms\Models\Entry;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Moves the posts and pages still in the retired «Không công khai» state (`private`) to «Gỡ khỏi
 * website» (`archived`), once, after the screens stopped offering it. Visitors see no change: neither
 * state is served on the website. Each row goes through TransitionEntryStatus::retirePrivate (the
 * status engine: row lock, workflow stage cleared, one audit row naming the system), never a bulk
 * UPDATE. Trashed rows are moved too, so a restore never brings the old state back. The entries'
 * translations keep their own statuses.
 *
 * Site by site inside each site's tenant context, in short transactions of CHUNK rows walked by id.
 * Only rows still `private` under the row lock are moved, so a second run (or one while staff edit)
 * moves nothing. The moved ids are written to the log as each chunk commits and printed per site, so
 * they can be found again. A site that fails is reported and the run goes on to the next; the run then ends in failure.
 * --dry-run counts per site without writing.
 */
final class RetirePrivateEntryStatus extends Command
{
    private const int CHUNK = 200;

    #[\Override]
    protected $signature = 'cms:retire-private-status {--dry-run : Count per site, write nothing}';

    #[\Override]
    protected $description = 'Move posts and pages still «Không công khai» (private) to «Gỡ khỏi website» (archived); safe to re-run.';

    public function handle(Tenancy $tenancy, TransitionEntryStatus $transition): int
    {
        $dry = (bool) $this->option('dry-run');
        $total = 0;
        $siteCount = 0;
        $failed = 0;

        foreach ($tenancy->siteIds() as $siteId) {
            $siteCount++;
            $ids = [];
            try {
                if ($dry) {
                    $ids = $this->privateIds($tenancy, $siteId);
                } else {
                    $this->retireSite($tenancy, $transition, $siteId, $ids);
                }
            } catch (Throwable $e) {
                report($e);
                $failed++;
                // the chunks committed before the failure stay moved: counted and named here too
                $total += count($ids);
                $this->line("site {$siteId}: failed after moving ".count($ids).' entr(y/ies)'.($ids === [] ? '' : ': '.implode(', ', $ids)).': '.$e->getMessage());

                continue;
            }

            $total += count($ids);
            if ($ids === []) {
                continue;
            }
            $this->line("site {$siteId}: ".($dry ? 'would move ' : 'moved ').count($ids).' entr(y/ies) private → archived: '.implode(', ', $ids));
        }

        $this->info(($dry ? 'Dry run: would move ' : 'Moved ')."{$total} entr(y/ies) private → archived on {$siteCount} site(s)".($failed > 0 ? ", {$failed} site(s) failed." : '.'));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Every id of the site's entries still private (trashed ones included), read in chunks.
     *
     * @return list<int>
     */
    private function privateIds(Tenancy $tenancy, int $siteId): array
    {
        $all = [];
        $after = 0;
        do {
            $ids = $tenancy->enter($siteId, fn (): array => $this->chunk($after));
            array_push($all, ...$ids);
            $after = $ids === [] ? $after : end($ids);
        } while (count($ids) === self::CHUNK);

        return $all;
    }

    /**
     * Moves the site's private entries, a chunk per tenant transaction, adding the ids of each chunk
     * to $moved once it commits (so a failure in a later chunk still knows what was moved).
     *
     * @param  list<int>  $moved
     */
    private function retireSite(Tenancy $tenancy, TransitionEntryStatus $transition, int $siteId, array &$moved): void
    {
        $after = 0;
        do {
            [$read, $done] = $tenancy->enter($siteId, function () use ($after, $transition): array {
                $ids = $this->chunk($after);
                $done = [];
                foreach (Entry::query()->withTrashed()->whereKey($ids)->orderBy('id')->get() as $entry) {
                    if ($transition->retirePrivate($entry)) {
                        $done[] = Cast::int($entry->id);
                    }
                }

                return [$ids, $done];
            });
            // logged as each chunk commits, so a site that fails later still leaves the ids it moved
            if ($done !== []) {
                Log::info('cms:retire-private-status moved entries private → archived', ['site_id' => $siteId, 'entry_ids' => $done]);
            }
            array_push($moved, ...$done);
            $after = $read === [] ? $after : end($read);
        } while (count($read) === self::CHUNK);
    }

    /**
     * The next CHUNK ids of private entries after $after, in id order (inside a site's context).
     *
     * @return list<int>
     */
    private function chunk(int $after): array
    {
        return array_values(Entry::query()->withTrashed()
            ->where('status', EntryStatus::Private->value)
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit(self::CHUNK)
            ->pluck('id')
            ->map(static fn (mixed $id): int => Cast::int($id))
            ->all());
    }
}
