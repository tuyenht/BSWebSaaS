<?php

declare(strict_types=1);

namespace App\Modules\Cms\Console;

use App\Modules\Cms\Models\EntryDraft;
use App\Modules\Cms\Models\EntryTranslation;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\SiteResolver;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Throwable;

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
 * Two passes per site, each inside Tenancy::enter. The first reads a chunk of rows by keyset on id,
 * without a lock, and finds the ones the cleaning would change (a dry run stops there and counts
 * them). The second cleans each of those in its own short transaction: it re-reads that one row
 * with FOR UPDATE and cleans the value it holds now, so an editor's save made in between is kept
 * (that save was cleaned by the cast already, so the row simply ends up unchanged) and never
 * overwritten with the old body. At most one row lock is held at a time and entries is never
 * locked, so this cannot deadlock with an editor, who locks the entry first. "changed" counts the
 * rows the second pass wrote.
 *
 * Idempotent: a second run changes nothing. A row that cannot be cleaned is named (site, column,
 * row id) and skipped; the sweep carries on with the next rows and sites and then exits with an
 * error, so look at those rows and run it again. A picture the cleaning removes may still be
 * listed as used until `media:reconcile-content-usage` runs.
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

        /** @var array<string, class-string<Model>> $sources column => the model that holds it */
        $sources = [
            'body' => EntryTranslation::class,
            'content' => EntryDraft::class,
        ];

        $total = 0;
        $failed = 0;
        $visited = 0;
        foreach ($siteIds as $siteId) {
            $checked = 0;
            $changed = 0;
            $siteFailed = 0;
            foreach ($sources as $column => $model) {
                $after = 0;
                do {
                    /** @var array{0: int, 1: int, 2: list<int>, 3: int} $chunk */
                    $chunk = $tenancy->enter($siteId, fn (): array => $this->findDirty($model, $column, $after, $siteId));
                    [$after, $rows, $dirty, $unreadable] = $chunk;
                    $checked += $rows;
                    $siteFailed += $unreadable;

                    if ($dryRun) {
                        $changed += count($dirty);
                    } else {
                        foreach ($dirty as $id) {
                            try {
                                $changed += $tenancy->enter($siteId, fn (): bool => $this->cleanRow($model, $column, $id)) ? 1 : 0;
                            } catch (Throwable $e) {
                                // Caught outside the row's transaction, so it has rolled back and the next row starts clean.
                                $this->reportRow($siteId, $column, $id, $e);
                                $siteFailed++;
                            }
                        }
                    }
                } while ($rows === self::CHUNK);
            }

            $this->line("Site {$siteId}: {$checked} checked, {$changed} {$verb}".($siteFailed > 0 ? ", {$siteFailed} could not be cleaned." : '.'));
            $total += $changed;
            $failed += $siteFailed;
            $visited++;
        }

        $this->info("{$total} {$verb} on {$visited} site(s).");

        if ($failed > 0) {
            $this->error("{$failed} row(s) could not be cleaned (listed above); the others were.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Pass 1, no lock and no write: the ids in the next chunk whose value the cast would change.
     *
     * @param  class-string<Model>  $model
     * @return array{0: int, 1: int, 2: list<int>, 3: int} the last id read, rows read, ids that would change, rows that could not be read
     */
    private function findDirty(string $model, string $column, int $after, int $siteId): array
    {
        $rows = $model::query()->where('id', '>', $after)->orderBy('id')->limit(self::CHUNK)->get(['id', $column]);

        $dirty = [];
        $unreadable = 0;
        foreach ($rows as $row) {
            $id = Cast::int($row->getKey());
            try {
                if ($this->assignBack($row, $column)) {
                    $dirty[] = $id;
                }
            } catch (Throwable $e) {
                $this->reportRow($siteId, $column, $id, $e);
                $unreadable++;
            }
        }

        $last = $rows->last();

        return [$last === null ? $after : Cast::int($last->getKey()), $rows->count(), $dirty, $unreadable];
    }

    /**
     * Pass 2, one row in its own transaction: re-read it under a row lock and clean the value it
     * holds now. False when that value is already clean (an editor saved it meanwhile) or the row is gone.
     *
     * @param  class-string<Model>  $model
     */
    private function cleanRow(string $model, string $column, int $id): bool
    {
        $row = $model::query()->whereKey($id)->lockForUpdate()->first(['id', $column]);
        if ($row === null || ! $this->assignBack($row, $column)) {
            return false;
        }

        $row->timestamps = false;
        $row->saveQuietly();

        return true;
    }

    /** Assigns the column back through its cast, which cleans it; true when that changed the value. */
    private function assignBack(Model $row, string $column): bool
    {
        $row->setAttribute($column, $row->getAttribute($column));

        return $row->isDirty($column);
    }

    /** Names the row and the kind of failure only: a database error's message can carry the body it tried to write. */
    private function reportRow(int $siteId, string $column, int $id, Throwable $e): void
    {
        $reason = $e instanceof QueryException
            ? 'database error (SQLSTATE '.$e->getCode().')'
            : class_basename($e).': '.Str::limit(Str::squish($e->getMessage()), 160);

        $this->error("Site {$siteId}: {$column} row {$id} could not be cleaned: {$reason}");
    }
}
