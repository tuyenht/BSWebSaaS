<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Console;

use App\Modules\Shared\Support\EdgeCachePause;
use App\Modules\Storefront\Support\ContactClickCounter;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Context\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Turns the Valkey-buffered contact button taps (ContactClickCounter) into contact_click_daily rows.
 * Only the websites marked dirty are visited, --batch at a time, and at most as many as were marked
 * when the run began (a website tapped meanwhile waits for the next run). Each website's buffer is
 * renamed away in one step, grouped by the website's calendar day (its own timezone), then added to
 * its rows with ONE upsert inside its tenant context (RLS) — clicks add up, never overwrite.
 *
 * A website whose rows cannot be written keeps its taken taps in its working key and is marked
 * dirty again after the run, so the next run tries again and nothing is lost; one failing website
 * never stops the others. The tally changes nothing a visitor sees: it runs inside EdgeCachePause.
 * Scheduled every five minutes (routes/console.php).
 */
final class FlushContactClicks extends Command
{
    /** Rows per INSERT; a website flushed every five minutes has far fewer. */
    private const int ROWS_PER_INSERT = 1000;

    #[\Override]
    protected $signature = 'contact:flush-clicks {--batch=500 : Websites taken off the dirty set at a time}';

    #[\Override]
    protected $description = 'Flush the Valkey-buffered contact button taps into contact_click_daily';

    public function handle(Tenancy $tenancy, ContactClickCounter $counter, EdgeCachePause $pause, SiteTime $time): int
    {
        $batch = max(1, is_numeric($this->option('batch')) ? (int) $this->option('batch') : 500);
        $rows = 0;
        $sites = 0;
        $failed = 0;
        /** @var array<int, true> $again websites to mark dirty once this run is over */
        $again = [];

        try {
            $budget = $counter->dirtyCount();
            while ($budget > 0) {
                $taken = $counter->popDirty(min($batch, $budget));
                if ($taken === []) {
                    break;
                }
                $budget -= count($taken);

                foreach ($taken as $siteId) {
                    try {
                        $buffer = $counter->take($siteId);
                        if ($buffer['more']) {
                            $again[$siteId] = true;
                        }
                        if ($buffer['counts'] === []) {
                            continue;
                        }

                        $rows += $pause->during(fn (): int => $tenancy->enter(
                            $siteId,
                            fn (): int => $this->store($siteId, $buffer['counts'], $time->zone()),
                        ));
                        $counter->done($siteId);
                        $sites++;
                    } catch (Throwable $e) {
                        // The taps stay in the website's working key: the next run takes them first.
                        report($e);
                        $this->error("Site {$siteId}: {$e->getMessage()}");
                        $again[$siteId] = true;
                        $failed++;
                    }
                }
            }
        } catch (Throwable $e) {
            report($e);
            $this->error("The buffer could not be read: {$e->getMessage()}");
            $failed++;
        }

        if ($again !== []) {
            try {
                $counter->markDirty(...array_keys($again));
            } catch (Throwable $e) {
                report($e);
                $failed++;
            }
        }

        $this->info("Flushed {$rows} contact click row(s) on {$sites} site(s), {$failed} failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Add a website's buffered taps to its rows: grouped by the website's day, channel and
     * placement, then one upsert (per ROWS_PER_INSERT rows). A field the counter never writes is
     * dropped. Returns how many rows were written.
     *
     * @param  array<string, int>  $counts  buffered field → taps
     */
    private function store(int $siteId, array $counts, string $zone): int
    {
        /** @var array<string, array{0: string, 1: string, 2: string, 3: int}> $grouped */
        $grouped = [];
        foreach ($counts as $field => $count) {
            $tap = ContactClickCounter::parse($field);
            if ($tap === null || $count <= 0) {
                continue;
            }
            $day = CarbonImmutable::createFromTimestamp($tap['at'], $zone)->toDateString();
            $key = $day.'|'.$tap['channel'].'|'.$tap['placement'];
            $grouped[$key] = [$day, $tap['channel'], $tap['placement'], ($grouped[$key][3] ?? 0) + $count];
        }

        foreach (array_chunk(array_values($grouped), self::ROWS_PER_INSERT) as $chunk) {
            $bindings = [];
            foreach ($chunk as [$day, $channel, $placement, $clicks]) {
                array_push($bindings, $siteId, $day, $channel, $placement, $clicks);
            }
            $values = implode(', ', array_fill(0, count($chunk), '(?, ?::date, ?, ?, ?)'));

            DB::insert(<<<SQL
                insert into contact_click_daily (site_id, day, channel, placement, clicks)
                values {$values}
                on conflict (site_id, day, channel, placement)
                do update set clicks = contact_click_daily.clicks + excluded.clicks, updated_at = now()
                SQL, $bindings);
        }

        return count($grouped);
    }
}
