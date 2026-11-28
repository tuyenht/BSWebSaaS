<?php

declare(strict_types=1);

namespace App\Modules\Admin\Console;

use App\Modules\Admin\Support\MediaBackfill\BackfillPass;
use App\Modules\Admin\Support\MediaBackfill\MediaRefsBackfill;
use App\Modules\Media\Support\MediaKey;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Converts the media references content still holds by address (a storage key, or an address on
 * the media host) to the library row, per website (MediaRefsBackfill): entry covers, the image props
 * and "Tải tệp" buttons of blocks wherever blocks are stored, the heading decoration's mask, and the
 * brand images the general.brand setting still holds as addresses (they become the site profile's
 * pictures, the address leaving the setting, as the settings screen saves them).
 *
 * A dry run by default: per website and place it prints what it would convert, what is already
 * by row, what it keeps (a glyph or an outside address), what names no file of the website
 * (unmatched, with samples; left as it is), and how many converted values name a file in the Trash.
 * It also counts what it does not convert but the contract must see: an old key kept beside the new
 * shape (stale), blocks of a type no longer known (skipped), and the file addresses found in the
 * places only counted (found: every other setting among them).
 * A cover, block picture or brand image among those stops showing once converted (as block
 * pictures already do), so that number is printed apart, per website, before anything is written.
 * --apply writes.
 * The numbers also go to a JSON file (--output, else under storage/app/media-refs-by-id/).
 *
 * One website failing is reported and the rest still run; the command then ends with a failure
 * exit. Idempotent: a second run converts nothing. Never run it while media:rekey-site runs.
 *
 * Lives in Admin because it writes the content of several modules.
 *
 * @phpstan-import-type Line from BackfillPass
 */
final class MediaRefsById extends Command
{
    #[\Override]
    protected $signature = 'media:refs-by-id
        {--site= : The website\'s code or id, or "all"}
        {--dry-run : Only count, write nothing (the default)}
        {--apply : Write the converted references}
        {--output= : The JSON file the numbers go to (default: storage/app/media-refs-by-id/<time>-<mode>.json)}';

    #[\Override]
    protected $description = 'Name media by library row instead of address in stored content (dry run by default)';

    public function handle(Tenancy $tenancy, MediaRefsBackfill $backfill): int
    {
        $apply = $this->option('apply') === true;
        if ($apply && $this->option('dry-run') === true) {
            $this->error('Refused: --dry-run and --apply together. Give one of them.');

            return self::FAILURE;
        }
        $given = trim(Cast::string($this->option('site')));
        if ($given === '') {
            $this->error('Give --site=<code or id> or --site=all.');

            return self::FAILURE;
        }
        $sites = $given === 'all' ? $tenancy->siteIds() : array_filter([$this->site($given)]);
        if ($given !== 'all' && $sites === []) {
            $this->error('No website with that id or code.');

            return self::FAILURE;
        }

        $mode = $apply ? 'apply' : 'dry-run';
        $this->line($apply ? 'Converting media references to library rows.' : 'Dry run: counting media references to convert; nothing is written.');
        $report = ['mode' => $mode, 'started_at' => now()->toIso8601String(), 'sites' => [], 'totals' => ['converted' => 0, 'unmatched' => 0, 'found' => 0, 'stale' => 0, 'skipped' => 0, 'trash_stops_showing' => 0, 'failed_sites' => 0]];
        $failed = false;
        foreach ($sites as $siteId) {
            $code = Cast::string(Site::withTrashed()->whereKey($siteId)->value('code'));
            try {
                $result = $backfill->site($siteId, $apply);
            } catch (Throwable $e) {
                $failed = true;
                $report['sites'][$siteId] = ['code' => $code, 'error' => $e->getMessage()];
                $report['totals']['failed_sites']++;
                $this->error("Website #{$siteId} ({$code}) failed, nothing of its current batch was written: {$e->getMessage()}");

                continue;
            }
            $hidden = self::trashShown($result['lines']);
            $report['sites'][$siteId] = ['code' => $code, 'trash_stops_showing' => $hidden] + $result;
            $report['totals']['converted'] += array_sum(array_column($result['lines'], 'converted'));
            foreach (['unmatched', 'found', 'stale', 'skipped'] as $counter) {
                $report['totals'][$counter] += array_sum(array_column($result['lines'], $counter));
            }
            $report['totals']['trash_stops_showing'] += $hidden;
            $this->print($siteId, $code, $result, $hidden);
        }
        $report['finished_at'] = now()->toIso8601String();

        $path = $this->option('output');
        $path = is_string($path) && $path !== '' ? $path : storage_path('app/media-refs-by-id/'.now()->format('ymd-His')."-{$mode}.json");
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");

        $totals = $report['totals'];
        $this->line("Total: {$totals['converted']} converted, {$totals['unmatched']} unmatched, {$totals['found']} file addresses left in places only counted, {$totals['stale']} old keys kept beside the new shape, {$totals['skipped']} blocks of an unknown type, {$totals['trash_stops_showing']} pictures in the Trash that stop showing, {$totals['failed_sites']} websites failed.");
        $this->line("Numbers written to {$path}.");
        if (! $apply) {
            $this->line('Nothing was written. Run again with --apply to convert.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Converted covers, block pictures and brand images of the places visitors see that name a
     * file in the Trash: those stop showing. (A button's file or a mask in the Trash already showed
     * nothing.)
     *
     * @param  array<string, Line>  $lines
     */
    public static function trashShown(array $lines): int
    {
        $count = 0;
        foreach ($lines as $place => $line) {
            [$table, $kind] = array_pad(explode(' ', $place, 2), 2, '');
            if (in_array($table, MediaRefsBackfill::SHOWN, true) && (in_array($kind, ['cover', 'image'], true) || str_starts_with($place, MediaRefsBackfill::BRAND_PLACE.' '))) {
                $count += $line['trashed'];
            }
        }

        return $count;
    }

    /** @param  array{lines: array<string, Line>, rows: array<string, int>, covers: array{inserted: int, deleted: int}|null}  $result */
    private function print(int $siteId, string $code, array $result, int $hidden): void
    {
        $this->line("Website #{$siteId} ({$code}):");
        $lines = $result['lines'];
        if ($lines === []) {
            $this->line('  nothing names a file.');
        } else {
            $this->table(
                ['Place', 'Converted', 'Already by row', 'Kept (glyph / outside)', 'Unmatched', 'In the Trash', 'Found (not converted)', 'Stale', 'Skipped'],
                array_map(static fn (string $place, array $line): array => [$place, $line['converted'], $line['already'], $line['kept'], $line['unmatched'], $line['trashed'], $line['found'], $line['stale'], $line['skipped']], array_keys($lines), $lines),
            );
        }
        foreach ($lines as $place => $line) {
            foreach ($line['samples'] as $sample) {
                $this->warn("  unmatched in {$place}: {$sample} (left as it is)");
            }
            foreach ($line['trashed_samples'] as $sample) {
                $this->warn("  in the Trash in {$place}: {$sample}");
            }
            foreach ($line['notes'] as $note) {
                $this->warn("  in {$place}: {$note}");
            }
        }
        $rows = implode(', ', array_map(static fn (string $place, int $count): string => "{$place} {$count}", array_keys($result['rows']), $result['rows']));
        $this->line('  rows '.($result['covers'] === null ? 'to rewrite' : 'rewritten').": {$rows}.");
        if ($hidden > 0) {
            $this->warn("  {$hidden} covers, block pictures or brand images name a file in the Trash: they stop showing once converted.");
        }
        if ($result['covers'] !== null) {
            $this->line("  featured-picture usage: {$result['covers']['inserted']} added, {$result['covers']['deleted']} removed.");
        }
    }

    /** The website named by its code or its id (the Trash included): a value that can be a code is looked up as one first. */
    private function site(string $given): ?int
    {
        $byCode = preg_match(MediaKey::CODE_PATTERN, $given) === 1 ? Site::withTrashed()->where('code', $given)->value('id') : null;
        $id = $byCode ?? (ctype_digit($given) ? Site::withTrashed()->whereKey((int) $given)->value('id') : null);

        return $id === null ? null : Cast::int($id);
    }
}
