<?php

declare(strict_types=1);

namespace App\Modules\Cms\Console;

use App\Modules\Cms\Models\EntryDraft;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\SiteResolver;
use App\Modules\Tenancy\Context\Tenancy;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Cleans the formatted text already stored in page and post bodies again (ADR-19 point 7): every
 * entry_translations.body and every pending change's entry_drafts.content, site by site. The body
 * casts clean on every write, so this is for what they did not see — after the sanitizer's
 * allow-list is narrowed or the sanitizer is patched, and for bodies stored before the casts
 * existed. Run it with --dry-run first to see what would change.
 *
 * Each row goes through its model: the body is read and assigned back, so the cast's own cleaning
 * decides the new value and its comparison decides whether anything changed; only a row that
 * changed is written. The write is a quiet save with timestamps off: entry_translations and
 * entry_drafts have no observers (revisions are recorded by RecordEntryRevision, workflow moves by
 * the engine, both called explicitly by their actions, never from model events), and a quiet save
 * keeps any added later from treating this cleaning as an edit. updated_at stays, so lists and
 * "edited" times do not move. A pending change's content fingerprint (EntryDraft::version) does
 * change when its text changes, so a reviewer holding the old screen is asked to reload.
 *
 * Per site inside Tenancy::enter, by keyset on id, one short transaction per chunk of rows.
 * Idempotent: a second run changes nothing. A failure stops the run with what was written so far
 * kept; run it again. A picture the cleaning removes may still be listed as used until
 * `media:reconcile-content-usage` runs.
 */
final class ResanitizePageBodies extends Command
{
    private const int CHUNK = 100;

    #[\Override]
    protected $signature = 'cms:resanitize-bodies
        {--site= : Only the site that answers to this hostname (one of its verified domains)}
        {--dry-run : Count the bodies that would change, write nothing}';

    #[\Override]
    protected $description = 'Clean the formatted text stored in page and post bodies and pending changes again, per site (after a sanitizer change).';

    public function handle(Tenancy $tenancy, SiteResolver $sites): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $verb = $dryRun ? 'would change' : 'changed';

        $host = Cast::string($this->option('site'));
        if ($host !== '') {
            $resolved = $sites->resolve($host);
            if ($resolved === null) {
                $this->error("No site answers to '{$host}'. Nothing was changed.");

                return self::FAILURE;
            }
            $siteIds = [$resolved->siteId];
        } else {
            $siteIds = $tenancy->siteIds();
        }

        /** @var array<string, Closure(int): Collection<int, covariant Model>> $sources column => the next chunk of rows after an id */
        $sources = [
            'body' => fn (int $after): Collection => EntryTranslation::query()
                ->where('id', '>', $after)->orderBy('id')->limit(self::CHUNK)->get(['id', 'body']),
            'content' => fn (int $after): Collection => EntryDraft::query()
                ->where('id', '>', $after)->orderBy('id')->limit(self::CHUNK)->get(['id', 'content']),
        ];

        $total = 0;
        $visited = 0;
        foreach ($siteIds as $siteId) {
            $checked = 0;
            $changed = 0;
            foreach ($sources as $column => $next) {
                $after = 0;
                do {
                    [$after, $rows, $dirty] = $tenancy->enter($siteId, fn (): array => $this->cleanChunk($next($after), $column, $after, $dryRun));
                    $checked += $rows;
                    $changed += $dirty;
                } while ($rows === self::CHUNK);
            }

            $this->line("Site {$siteId}: {$checked} checked, {$changed} {$verb}.");
            $total += $changed;
            $visited++;
        }

        $this->info("{$total} {$verb} on {$visited} site(s).");

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, covariant Model>  $rows
     * @return array{0: int, 1: int, 2: int} the last id read, rows read, rows that (would) change
     */
    private function cleanChunk(Collection $rows, string $column, int $after, bool $dryRun): array
    {
        $dirty = 0;
        foreach ($rows as $row) {
            // Assigned back through the cast, which cleans it; its comparison says whether that changed anything.
            $row->setAttribute($column, $row->getAttribute($column));
            if (! $row->isDirty($column)) {
                continue;
            }

            $dirty++;
            if (! $dryRun) {
                $row->timestamps = false;
                $row->saveQuietly();
            }
        }

        $last = $rows->last();

        return [$last === null ? $after : Cast::int($last->getKey()), $rows->count(), $dirty];
    }
}
