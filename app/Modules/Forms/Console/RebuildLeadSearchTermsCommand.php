<?php

declare(strict_types=1);

namespace App\Modules\Forms\Console;

use App\Modules\Forms\Support\LeadSearchTerms;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Compare the stored search words of leads (LeadSearchTerms) with the words their name, email and
 * phone make today, with the very same splitting, and write again the leads that differ. Safe to
 * re-run: a lead whose words are right is not written, so a second run corrects 0.
 *
 * Every lead of each site (spam and the leads of a form being deleted too), newest first in chunks
 * through form_submissions_live_created_idx (keyset on created_at, ulid, never OFFSET; the leads with
 * no time after them, by ulid on the same index), site by site in each site's tenant context. Run once
 * after the release that adds the table, and whenever the splitting changes. A chunk is compared
 * without locks; each lead that looks wrong is then read again FOR SHARE (an edit of it waits for the
 * short rewrite, and its own save then writes its words again; a visitor's new lead never waits) and
 * its words written from what it holds now. Each site with differences logs one warning (how many, up
 * to ten lead ulids, never a name or a phone). A site that fails is logged and skipped; the command
 * then exits non-zero.
 */
final class RebuildLeadSearchTermsCommand extends Command
{
    #[\Override]
    protected $signature = 'forms:search-terms
        {--site= : Only this site (its id); default every site}
        {--chunk=500 : Leads compared per step}';

    #[\Override]
    protected $description = 'Check the search words of contact leads against their name, email and phone, and write again the ones that differ (safe to re-run)';

    public function handle(Tenancy $tenancy): int
    {
        $chunk = max(1, Cast::int($this->option('chunk'), 500));
        $only = $this->option('site');
        $siteIds = $only !== null ? [Cast::int($only)] : $tenancy->siteIds();
        $sites = $checked = $corrected = $failed = 0;

        foreach ($siteIds as $siteId) {
            $sites++;
            $drifted = 0;
            $sample = [];
            try {
                foreach ([false, true] as $untimed) {
                    $after = null;
                    do {
                        [$read, $after, $wrong] = $tenancy->enter($siteId, fn (): array => $this->compare($siteId, $untimed, $after, $chunk));
                        $checked += $read;
                        if ($wrong !== []) {
                            $tenancy->enter($siteId, fn () => $this->rewrite($siteId, array_keys($wrong)));
                            $drifted += count($wrong);
                            $sample = array_slice([...$sample, ...array_values($wrong)], 0, 10);
                        }
                    } while ($read === $chunk);
                }
            } catch (Throwable $e) {
                $failed++;
                Log::error('Lead search words check failed for a site', ['site_id' => $siteId, 'exception' => $e]);
                $this->error("Site {$siteId}: {$e->getMessage()}");
            }
            if ($drifted > 0) {
                $corrected += $drifted;
                Log::warning('Lead search words differed from the name, email and phone', [
                    'site_id' => $siteId, 'leads' => $drifted, 'sample' => $sample,
                ]);
            }
        }

        $this->info("Checked {$checked} lead(s) on {$sites} site(s); corrected {$corrected}.");
        if ($failed > 0) {
            $this->error("Failed on {$failed} site(s); see the log.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * The next $limit leads of $siteId, newest first, after the lead $after (its created_at and ulid);
     * with $untimed, the leads with no created_at, by ulid. How many were read, where the next chunk
     * starts, and the ones whose stored words differ (id => ulid).
     *
     * @param  array{0: ?string, 1: string}|null  $after
     * @return array{0: int, 1: array{0: ?string, 1: string}|null, 2: array<int, string>}
     */
    private function compare(int $siteId, bool $untimed, ?array $after, int $limit): array
    {
        $query = DB::table('form_submissions')->where('site_id', $siteId);
        if ($untimed) {
            $query->whereNull('created_at');
            if ($after !== null) {
                $query->where('ulid', '<', $after[1]);
            }
        } else {
            $query->whereNotNull('created_at');
            if ($after !== null) {
                $query->whereRaw('(created_at, ulid) < (?::timestamptz, ?)', [$after[0], $after[1]]);
            }
        }
        $leads = $query->orderByDesc('created_at')->orderByDesc('ulid')->limit($limit)
            ->get(['id', 'ulid', 'created_at', 'name', 'email', 'phone']);
        if ($leads->isEmpty()) {
            return [0, $after, []];
        }
        $ids = array_values($leads->map(fn (object $lead): int => Cast::int($lead->id))->all());
        $stored = LeadSearchTerms::stored($siteId, $ids);
        $wrong = [];
        foreach ($leads as $lead) {
            $id = Cast::int($lead->id);
            $want = LeadSearchTerms::of(Cast::stringOrNull($lead->name), Cast::stringOrNull($lead->email), Cast::stringOrNull($lead->phone));
            if (($stored[$id] ?? []) !== $want) {
                $wrong[$id] = Cast::string($lead->ulid);
            }
        }

        $last = $leads->last();

        return [$leads->count(), [Cast::stringOrNull($last->created_at), Cast::string($last->ulid)], $wrong];
    }

    /**
     * Write again the words of these leads of $siteId from what they hold now, each locked FOR SHARE
     * first (an edit of its name, email or phone waits, then writes its own words); a lead deleted
     * since keeps no words (its rows went with it).
     *
     * @param  list<int>  $leadIds
     */
    private function rewrite(int $siteId, array $leadIds): void
    {
        $rows = DB::select('select id, name, email, phone from form_submissions where site_id = ? and id = any (?::bigint[]) order by id for share',
            [$siteId, '{'.implode(',', $leadIds).'}']);
        foreach ($rows as $row) {
            $row = (array) $row;
            LeadSearchTerms::write($siteId, Cast::int($row['id'] ?? 0), LeadSearchTerms::of(
                Cast::stringOrNull($row['name'] ?? null), Cast::stringOrNull($row['email'] ?? null), Cast::stringOrNull($row['phone'] ?? null),
            ));
        }
    }
}
