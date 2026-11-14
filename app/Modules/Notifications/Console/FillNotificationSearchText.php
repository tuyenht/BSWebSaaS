<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Console;

use App\Modules\Notifications\Models\SiteNotification;
use App\Modules\Notifications\Support\NotificationSearchText;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fill site_notifications.search_text (the Notifications page's search) from each notice's facts,
 * with the very same NotificationSearchText::for() the model uses when a notice is written.
 *
 * - Default: only the notices not filled yet (search_text IS NULL): the ones written before the
 *   column existed, or by workers still on the old code during the deploy. Run once after the
 *   deploy that adds the column, after the Octane reload (docs/runbooks/prod-deploy.md).
 * - --all: every notice, writing only the ones whose line differs; for when the facts searched
 *   (NotificationSearchText::FACTS) or the folding change.
 *
 * Site by site in each site's tenant context (RLS), by id in chunks, one transaction per chunk, so a
 * site with many notices is never one long transaction. A row is written only while its data is
 * still what was read, so a notice whose data changed meanwhile (and whose line the model rewrote)
 * keeps the newer line. Safe to re-run: a second run fills 0. A site that fails is logged and
 * skipped; the command then exits non-zero.
 */
final class FillNotificationSearchText extends Command
{
    #[\Override]
    protected $signature = 'notifications:search-text
        {--site= : Only this site (its id); default every site}
        {--all : Check every notice, not only the ones not filled yet}
        {--chunk=1000 : Notices read per step}';

    #[\Override]
    protected $description = 'Fill the search text of in-app notifications from their facts (safe to re-run)';

    public function handle(Tenancy $tenancy): int
    {
        $chunk = max(1, min(5000, Cast::int($this->option('chunk'), 1000)));
        $all = (bool) $this->option('all');
        $only = $this->option('site');
        $siteIds = $only !== null ? [Cast::int($only)] : $tenancy->siteIds();
        $sites = 0;
        $written = 0;
        $failed = 0;

        foreach ($siteIds as $siteId) {
            $sites++;
            try {
                $after = 0;
                do {
                    [$read, $after, $changed] = $tenancy->enter($siteId, fn (): array => $this->step($siteId, $after, $chunk, $all));
                    $written += $changed;
                } while ($read === $chunk);
            } catch (Throwable $e) {
                $failed++;
                Log::error('Filling the search text of notifications failed for a site', ['site_id' => $siteId, 'exception' => $e]);
                $this->error("Site {$siteId}: {$e->getMessage()}");
            }
        }

        $this->info("Wrote the search text of {$written} notification(s) on {$sites} site(s).");
        if ($failed > 0) {
            $this->error("Failed on {$failed} site(s); see the log.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * One chunk of $siteId after id $after: [rows read, last id read, rows written].
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function step(int $siteId, int $after, int $chunk, bool $all): array
    {
        $query = SiteNotification::query()->where('id', '>', $after)->orderBy('id')->limit($chunk);
        if (! $all) {
            $query->whereNull('search_text');
        }
        $rows = $query->toBase()->get(['id', 'data', 'search_text']);

        $values = [];
        $bindings = [];
        $last = $after;
        foreach ($rows as $row) {
            $last = Cast::int($row->id);
            $raw = Cast::string($row->data, '{}');
            $data = json_decode($raw, true);
            $text = NotificationSearchText::for(is_array($data) ? $data : []);
            if ($row->search_text !== $text) {
                $values[] = '(?::bigint, ?::jsonb, ?::text)';
                array_push($bindings, $last, $raw, $text);
            }
        }

        $changed = 0;
        if ($values !== []) {
            // Explicit site_id on top of RLS (the house rule for every tenant query); the data check
            // keeps a line the model rewrote meanwhile for newer data.
            $changed = DB::update(
                'UPDATE site_notifications AS n SET search_text = v.t FROM (VALUES '.implode(', ', $values).') AS v (id, d, t)
                 WHERE n.site_id = ? AND n.id = v.id AND n.data = v.d AND n.search_text IS DISTINCT FROM v.t',
                [...$bindings, $siteId],
            );
        }

        return [$rows->count(), $last, $changed];
    }
}
