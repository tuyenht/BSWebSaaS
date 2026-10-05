<?php

declare(strict_types=1);

namespace App\Modules\Cms\Console;

use App\Modules\Cms\Actions\RecordEntryRevision;
use App\Modules\Cms\Enums\RevisionKind;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Shared\Support\ConcurrencyError;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Gives content that was public before revision history existed its first revision (kind
 * baseline), so the first edit after the upgrade already has a version to go back to. Run once in
 * the deploy that ships entry_revisions; idempotent — a (published entry, language) that already
 * has any revision is skipped, so re-running writes nothing. Published entries in the Trash are
 * included, so one restored from the Trash later has its baseline.
 *
 * Site by site inside each site's tenant context (Tenancy::eachSite), published entries read by
 * keyset in batches. Each row is written in its own savepoint and a failure is reported and
 * counted, so one bad row never rolls back its site or stops the sites after it. A concurrency
 * error (Shared\Support\ConcurrencyError) is the exception: it leaves the site's transaction
 * aborted, so it stops the run; being idempotent, the command is simply run again.
 */
final class SnapshotPublishedEntries extends Command
{
    private const int BATCH = 200;

    #[\Override]
    protected $signature = 'cms:snapshot-published';

    #[\Override]
    protected $description = 'Record a baseline revision for every published entry language that has no revision yet, on every site.';

    public function handle(Tenancy $tenancy, RecordEntryRevision $revisions): int
    {
        $written = 0;
        $failed = 0;

        $sites = $tenancy->eachSite(function () use ($revisions, &$written, &$failed): void {
            $after = 0;

            do {
                // Trashed ones too: restoring an entry from the Trash must find its baseline there.
                $entries = Entry::withTrashed()
                    ->where('status', 'published')
                    ->where('id', '>', $after)
                    ->orderBy('id')
                    ->limit(self::BATCH)
                    ->get()
                    ->keyBy('id');

                if ($entries->isEmpty()) {
                    break;
                }
                $after = (int) $entries->keys()->last();

                $translations = EntryTranslation::query()
                    ->whereIn('entry_id', $entries->keys()->all())
                    ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')
                        ->from('entry_revisions')
                        ->whereColumn('entry_revisions.site_id', 'entry_translations.site_id')
                        ->whereColumn('entry_revisions.entry_id', 'entry_translations.entry_id')
                        ->whereColumn('entry_revisions.locale', 'entry_translations.locale'))
                    ->orderBy('entry_id')
                    ->orderBy('locale')
                    ->get();

                foreach ($translations as $translation) {
                    $entry = $entries->get($translation->entry_id);
                    if (! $entry instanceof Entry) {
                        continue;
                    }

                    try {
                        $recorded = DB::transaction(fn (): bool => $revisions->forLocale($entry, $translation, RevisionKind::Baseline, null));
                        $written += $recorded ? 1 : 0;
                    } catch (Throwable $e) {
                        if (ConcurrencyError::is($e)) {
                            throw $e;   // the site's transaction is aborted: nothing more can run in it
                        }
                        report($e);
                        $failed++;
                    }
                }
            } while ($entries->count() === self::BATCH);
        });

        $this->info("Recorded {$written} baseline revision(s) on {$sites} site(s), {$failed} failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
