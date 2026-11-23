<?php

declare(strict_types=1);

namespace App\Modules\Forms\Console;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Forms\Support\LeadNotices;
use App\Modules\Forms\Support\LeadRetention;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delete for good, website by website, the leads of «Khách liên hệ» older than the website's
 * «Thời hạn lưu giữ liên hệ» (LeadRetention: 12, 24 or 36 months, 24 when never set, nothing for
 * «Không tự xóa»; a shortened period waits LeadRetention::WAIT_DAYS), and the spam both sent and
 * last changed over LeadRetention::SPAM_DAYS ago. Leads in the Trash are left to leads:purge-trash.
 * With each lead go its search words (lead_search_terms, ON DELETE CASCADE) and the bell's «new
 * lead» notices of it (LeadNotices).
 *
 * Every chunk of at most CHUNK rows is its own transaction, entered in the website's context
 * (Tenancy::enter: SET LOCAL + RLS), so no transaction ever holds more than CHUNK deleted rows and
 * their locks, whatever a website keeps: the rows are read oldest first through the kept leads'
 * indexes (form_submissions_kept_created_idx, form_submissions_kept_status_created_idx) and locked
 * (FOR UPDATE) until their chunk commits. The spam walk moves on by a (created_at, ulid) cursor, so
 * an old lead marked spam recently is passed over once, not read again by every chunk; the age walk
 * moves on the same way, so no chunk scans past the index entries of the rows deleted before it. A
 * chunk that deletes nothing ends the website's walk. The first transaction of a website also reads
 * its setting (one indexed site_settings read); a website with nothing to delete costs that one
 * transaction.
 *
 * One audit row per website and kind that lost anything (`forms.leads.retention_purged` with the
 * count and the months, `forms.leads.spam_purged` with the count and the days), never a visitor's
 * data. A website that fails is reported and skipped; the others go on and the command then fails.
 * Re-running deletes nothing twice. Scheduled daily.
 */
final class PurgeOldLeads extends Command
{
    private const int CHUNK = 200;

    #[\Override]
    protected $signature = 'leads:purge-old';

    #[\Override]
    protected $description = 'Delete for good the leads older than each website\'s retention period, and old spam';

    public function handle(Tenancy $tenancy): int
    {
        $now = CarbonImmutable::now();
        $spamCutoff = LeadRetention::spamCutoff($now);
        $totals = ['old' => 0, 'spam' => 0];
        $failed = 0;

        if ($spamCutoff !== null) {
            foreach ($tenancy->siteIds() as $siteId) {
                $site = ['old' => 0, 'spam' => 0, 'months' => null];
                try {
                    $this->purgeSite($tenancy, $siteId, $now, $spamCutoff, $site);
                } catch (Throwable $e) {
                    $failed++;
                    Log::error('leads:purge-old could not purge a website.', ['site_id' => $siteId]);
                    report($e);
                } finally {
                    try {
                        $this->audit($tenancy, $siteId, $site);
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
                $totals['old'] += $site['old'];
                $totals['spam'] += $site['spam'];
            }
        }

        $this->info("Purged {$totals['old']} lead(s) past their website's retention period and {$totals['spam']} spam lead(s).");
        if ($failed > 0) {
            $this->error("{$failed} website(s) could not be purged (see the error log).");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{old: int, spam: int, months: int|null}  $site  counts so far, kept up to date chunk by chunk
     */
    private function purgeSite(Tenancy $tenancy, int $siteId, CarbonImmutable $now, CarbonImmutable $spamCutoff, array &$site): void
    {
        $cutoff = false;
        $oldCursor = null;
        $cursor = null;
        $spamOnly = false;
        do {
            $done = Cast::int($tenancy->enter($siteId, function () use ($siteId, $now, $spamCutoff, &$cutoff, &$oldCursor, &$cursor, &$spamOnly, &$site): int {
                if ($cutoff === false) {
                    $setting = LeadRetention::current();
                    if (! $setting['valid']) {
                        Log::warning('leads:purge-old deleted nothing for age on a website: its retention setting is not one of the choices.', ['site_id' => $siteId]);
                    }
                    $site['months'] = LeadRetention::inForce($setting, $now);
                    $cutoff = LeadRetention::cutoff($site['months'], $now);
                }
                if (! $spamOnly && $cutoff !== null) {
                    $chunk = FormSubmission::query()
                        ->where('site_id', $siteId)
                        ->whereNull('deleted_at')
                        ->where('created_at', '<', $cutoff)
                        ->when($oldCursor !== null, fn ($q) => $q->whereRaw('(created_at, ulid) > (?::timestamptz, ?)', $oldCursor))
                        ->orderBy('created_at')->orderBy('ulid')
                        ->limit(self::CHUNK)
                        ->lockForUpdate()
                        ->toBase()
                        ->get(['id', 'ulid', 'created_at']);
                    if ($chunk->isNotEmpty()) {
                        $last = $chunk->last();
                        $oldCursor = [Cast::string($last->created_at ?? null), Cast::string($last->ulid ?? null)];
                        $deleted = $this->delete($chunk->all());
                        $site['old'] += $deleted;

                        return $deleted;
                    }
                }
                $spamOnly = true;

                $spam = FormSubmission::query()
                    ->where('site_id', $siteId)
                    ->where('status', 'spam')
                    ->whereNull('deleted_at')
                    ->where('created_at', '<', $spamCutoff)
                    ->where('updated_at', '<', $spamCutoff)
                    ->when($cursor !== null, fn ($q) => $q->whereRaw('(created_at, ulid) > (?::timestamptz, ?)', $cursor))
                    ->orderBy('created_at')->orderBy('ulid')
                    ->limit(self::CHUNK)
                    ->lockForUpdate()
                    ->toBase()
                    ->get(['id', 'ulid', 'created_at']);
                if ($spam->isEmpty()) {
                    return 0;
                }
                $last = $spam->last();
                $cursor = [Cast::string($last->created_at ?? null), Cast::string($last->ulid ?? null)];
                $deleted = $this->delete($spam->all());
                $site['spam'] += $deleted;

                return $deleted;
            }));
        } while ($done > 0);
    }

    /**
     * @param  array<int, object>  $rows  rows with id, ulid and created_at
     */
    private function delete(array $rows): int
    {
        LeadNotices::deleteFor($rows);

        return FormSubmission::query()->whereIn('id', array_map(fn (object $row): int => Cast::int($row->id ?? null), $rows))->toBase()->delete();
    }

    /**
     * @param  array{old: int, spam: int, months: int|null}  $site
     */
    private function audit(Tenancy $tenancy, int $siteId, array $site): void
    {
        if ($site['old'] === 0 && $site['spam'] === 0) {
            return;
        }
        $tenancy->enter($siteId, function () use ($site): void {
            if ($site['old'] > 0) {
                Audit::record('forms.leads.retention_purged', ['meta' => ['count' => $site['old'], 'months' => $site['months'], 'scheduled' => true]]);
            }
            if ($site['spam'] > 0) {
                Audit::record('forms.leads.spam_purged', ['meta' => ['count' => $site['spam'], 'days' => LeadRetention::SPAM_DAYS, 'scheduled' => true]]);
            }
        });
    }
}
