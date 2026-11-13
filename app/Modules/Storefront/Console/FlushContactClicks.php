<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Console;

use App\Modules\Shared\Support\EdgeCachePause;
use App\Modules\Storefront\Support\ContactClickCounter;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Theme\Chrome\FloatingContact;
use App\Modules\Theme\Theming\StorefrontSettings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns the Valkey-buffered contact button taps (ContactClickCounter) into contact_click_daily rows.
 * Only the websites marked dirty are visited, --batch at a time, and at most as many as were marked
 * when the run began (a website tapped meanwhile waits for the next run). Each website's buffer is
 * renamed away in one step, grouped by the website's calendar day (its own timezone), then added to
 * its rows with ONE upsert inside its tenant context (RLS) — clicks add up, never overwrite. A
 * website's own channel (`c1`, `c2`, …) counts only while the website's floating contact setting
 * still has it; the built-in channels always count.
 *
 * Each run first sweeps what an earlier run left behind (ContactClickCounter explains the keys): a
 * website whose taps wait in a live hash or a taken key is marked dirty again, so they drain; taps
 * that were being written when that run stopped are dropped, because their write may have
 * committed. A run that finds its taken taps claimed by another run counts the site as failed and
 * writes nothing: the taps are written once, by the run that claimed them.
 *
 * A website whose rows cannot be written gets its taken taps back in its working key and is marked
 * dirty again after the run, so the next run tries again and nothing is lost; one failing website
 * never stops the others. A website that no longer exists has its taps dropped, once. The tally
 * changes nothing a visitor sees: it runs inside EdgeCachePause. Scheduled every five minutes
 * (routes/console.php).
 */
final class FlushContactClicks extends Command
{
    /** Rows per INSERT; a website flushed every five minutes has far fewer. */
    private const int ROWS_PER_INSERT = 1000;

    /** SQLSTATE of a foreign key violation: the website's row is gone. */
    private const string FOREIGN_KEY_VIOLATION = '23503';

    #[\Override]
    protected $signature = 'contact:flush-clicks {--batch=500 : Websites taken off the dirty set at a time}';

    #[\Override]
    protected $description = 'Flush the Valkey-buffered contact button taps into contact_click_daily';

    public function handle(Tenancy $tenancy, ContactClickCounter $counter, EdgeCachePause $pause, SiteTime $time, StorefrontSettings $settings): int
    {
        $batch = max(1, is_numeric($this->option('batch')) ? (int) $this->option('batch') : 500);
        $rows = 0;
        $sites = 0;
        $failed = 0;
        $gone = 0;
        /** @var array<int, true> $again websites to mark dirty once this run is over */
        $again = [];

        try {
            $this->sweep($counter);

            $budget = $counter->dirtyCount();
            while ($budget > 0) {
                $taken = $counter->popDirty(min($batch, $budget));
                if ($taken === []) {
                    break;
                }
                $budget -= count($taken);

                foreach ($taken as $siteId) {
                    $claimed = false;
                    try {
                        $buffer = $counter->take($siteId);
                        if ($buffer['more']) {
                            $again[$siteId] = true;
                        }
                        if ($buffer['counts'] === []) {
                            continue;
                        }

                        $counter->claim($siteId);
                        $claimed = true;
                        $rows += $pause->during(fn (): int => $tenancy->enter(
                            $siteId,
                            fn (): int => $this->store($siteId, $buffer['counts'], $time->zone(), $settings),
                        ));
                        $sites++;
                    } catch (Throwable $e) {
                        if ($claimed && $e instanceof QueryException && $e->getCode() === self::FOREIGN_KEY_VIOLATION) {
                            // The website's row is gone: its taps have nowhere to go, ever. Dropped below, logged once.
                            Log::warning('contact clicks: dropped the taps of a website that no longer exists', ['site_id' => $siteId]);
                            $gone++;
                        } else {
                            // Nothing was written (the write rolled back, or never started): the taps go
                            // back to the working key, and the next run takes them first.
                            report($e);
                            $this->error("Site {$siteId}: {$e->getMessage()}");
                            $failed++;
                            $again[$siteId] = true;
                            if ($claimed) {
                                $this->release($counter, $siteId);
                            }

                            continue;
                        }
                    }

                    $this->finish($counter, $siteId);
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
        if ($gone > 0) {
            $this->warn("Dropped the taps of {$gone} site(s) that no longer exist.");
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * What an earlier run left: taps in a live hash or a working key drain again (marked dirty: a
     * run killed mid-batch had already taken its websites off the dirty set); taps whose write may
     * have committed are dropped, never written twice.
     */
    private function sweep(ContactClickCounter $counter): void
    {
        $left = $counter->leftovers();
        $waiting = array_values(array_unique([...$left['live'], ...$left['taken']]));
        if ($waiting !== []) {
            $counter->markDirty(...$waiting);
        }

        if ($left['applying'] !== []) {
            $counter->dropClaims($left['applying']);
            Log::warning('contact clicks: dropped taps whose write may already have committed', ['sites' => count($left['applying'])]);
        }
    }

    /** Put a failed write's taps back in the working key for the next run. */
    private function release(ContactClickCounter $counter, int $siteId): void
    {
        try {
            $counter->release($siteId);
        } catch (Throwable $e) {
            // The taps stay in the applying key, which the next run drops: lost, never counted twice.
            report($e);
        }
    }

    /**
     * The claimed taps are written (or dropped): delete them. If Valkey fails here the write has
     * already committed, so the website is NOT marked dirty again: the applying key left behind is
     * dropped by the next run's sweep, never written a second time.
     */
    private function finish(ContactClickCounter $counter, int $siteId): void
    {
        try {
            $counter->done($siteId);
        } catch (Throwable $e) {
            report($e);
            $this->warn("Site {$siteId}: the written taps could not be cleared; the next run drops them.");
        }
    }

    /**
     * Add a website's buffered taps to its rows: grouped by the website's day, channel and
     * placement, then one upsert (per ROWS_PER_INSERT rows). A field the counter never writes is
     * dropped, and so is a website's own channel its floating contact setting no longer has
     * (logged as a count). Returns how many rows were written.
     *
     * @param  array<string, int>  $counts  buffered field → taps
     */
    private function store(int $siteId, array $counts, string $zone, StorefrontSettings $settings): int
    {
        $saved = self::savedCustomKeys($settings->get('storefront', 'floating_contact'));
        // The memo is keyed by website: a run over thousands of websites must not keep them all.
        $settings->forget();

        /** @var array<string, array{0: string, 1: string, 2: string, 3: int}> $grouped */
        $grouped = [];
        $unsaved = 0;
        foreach ($counts as $field => $count) {
            $tap = ContactClickCounter::parse($field);
            if ($tap === null || $count <= 0) {
                continue;
            }
            if (! isset(FloatingContact::CHANNELS[$tap['channel']]) && ! isset($saved[$tap['channel']])) {
                $unsaved++;

                continue;
            }
            $day = CarbonImmutable::createFromTimestamp($tap['at'], $zone)->toDateString();
            $key = $day.'|'.$tap['channel'].'|'.$tap['placement'];
            $grouped[$key] = [$day, $tap['channel'], $tap['placement'], ($grouped[$key][3] ?? 0) + $count];
        }
        if ($unsaved > 0) {
            Log::notice('contact clicks: dropped taps on channels the website has not saved', ['site_id' => $siteId, 'fields' => $unsaved]);
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

    /**
     * The website's own channel keys (`c1`, `c2`, …) in its saved floating contact setting
     * (`channels`: a list of {key, on, value, …}, FloatingContact), switched on or not: a channel
     * switched off keeps the taps it had.
     *
     * @return array<string, true>
     */
    private static function savedCustomKeys(mixed $raw): array
    {
        $channels = is_array($raw) && is_array($raw['channels'] ?? null) ? $raw['channels'] : [];
        $keys = [];
        foreach ($channels as $channel) {
            $key = is_array($channel) ? ($channel['key'] ?? null) : null;
            if (is_string($key) && preg_match(FloatingContact::CUSTOM_KEY, $key) === 1) {
                $keys[$key] = true;
            }
        }

        return $keys;
    }
}
