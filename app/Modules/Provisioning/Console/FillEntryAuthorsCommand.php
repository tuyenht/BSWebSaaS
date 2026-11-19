<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Console;

use App\Modules\Cms\Models\Entry;
use App\Modules\Provisioning\Support\FirstOwner;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\Tenancy;
use App\Modules\Tenancy\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Names a creator («Người tạo») for posts and pages that were written with none: what a content
 * pack or a demo seed made before they named one. Each such entry gets the first member recorded
 * as having saved it (its oldest revision with a person behind it); one nobody ever saved gets the
 * website's first owner, as a pack writes now. No account is ever deleted, so an empty creator is
 * always one that was never recorded, not a person who left.
 *
 * Only entries whose creator is still empty are written (checked again in the UPDATE), so a second
 * run, or one while staff edit, reports 0. Trashed entries are filled too. Naming the creator is not
 * an edit: updated_at is left as it was. Site by site inside each site's tenant context, in short
 * transactions of CHUNK rows walked by id. A site with no owner keeps its empty creators and says so.
 * --dry-run counts without writing; --site limits the run to one site (its id).
 */
final class FillEntryAuthorsCommand extends Command
{
    private const int CHUNK = 500;

    #[\Override]
    protected $signature = 'site:fill-entry-authors {--site= : Only this site (its id); default every site} {--dry-run : Count, write nothing}';

    #[\Override]
    protected $description = 'Name a creator for posts and pages saved without one (first saver, else the first owner); safe to re-run.';

    public function handle(Tenancy $tenancy, FirstOwner $owner): int
    {
        $only = $this->option('site');
        if ($only !== null && ! ctype_digit(Cast::string($only))) {
            $this->error('--site must be a site id.');

            return self::FAILURE;
        }
        if ($only !== null && ! Site::query()->whereKey((int) $only)->exists()) {
            $this->error("No site with id {$only}.");

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $failed = 0;
        $total = 0;
        $ids = $only !== null ? [(int) $only] : $tenancy->siteIds();

        foreach ($ids as $siteId) {
            try {
                [$missing, $written, $ownerId] = $this->fillSite($tenancy, $owner, $siteId, $dry);
                $total += $written;
                if ($missing > 0 || $dry) {
                    $note = $ownerId === null && $missing > 0 ? ' (no owner: entries nobody saved stay empty)' : '';
                    $this->line("site {$siteId}: {$missing} without a creator, ".($dry ? 'would fill' : 'filled')." {$written}{$note}");
                }
            } catch (Throwable $e) {
                $failed++;
                $this->line("site {$siteId}: failed: ".$e->getMessage());
            }
        }

        $this->info(($dry ? 'Dry run: would fill ' : 'Filled ')."{$total} creator(s) on ".count($ids).' site(s).');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return array{0: int, 1: int, 2: int|null} [entries without a creator, written (or would be), owner id] */
    private function fillSite(Tenancy $tenancy, FirstOwner $owner, int $siteId, bool $dry): array
    {
        $ownerId = $tenancy->enter($siteId, fn (): ?int => $owner->id());
        $missing = 0;
        $written = 0;
        $after = 0;

        do {
            [$read, $done, $after] = $tenancy->enter($siteId, fn (): array => $this->fillChunk($siteId, $ownerId, $after, $dry));
            $missing += $read;
            $written += $done;
        } while ($read === self::CHUNK);

        return [$missing, $written, $ownerId];
    }

    /** @return array{0: int, 1: int, 2: int} [rows read, rows written (or would be), last id read] */
    private function fillChunk(int $siteId, ?int $ownerId, int $after, bool $dry): array
    {
        $ids = Entry::query()->withTrashed()
            ->whereNull('author_id')
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit(self::CHUNK)
            ->pluck('id')
            ->map(static fn (mixed $id): int => Cast::int($id))
            ->all();

        if ($ids === []) {
            return [0, 0, $after];
        }

        $in = implode(', ', array_fill(0, count($ids), '?'));
        $saver = 'SELECT r.created_by FROM entry_revisions r WHERE r.site_id = e.site_id AND r.entry_id = e.id AND r.created_by IS NOT NULL ORDER BY r.id LIMIT 1';

        if ($dry) {
            $count = DB::selectOne(
                "SELECT count(*) AS n FROM entries e WHERE e.site_id = ? AND e.author_id IS NULL AND e.id IN ({$in}) AND COALESCE(({$saver}), ?::bigint) IS NOT NULL",
                [$siteId, ...$ids, $ownerId],
            );

            return [count($ids), Cast::int(is_object($count) ? ($count->n ?? 0) : 0), (int) end($ids)];
        }

        $written = DB::update(
            "UPDATE entries e SET author_id = COALESCE(({$saver}), ?::bigint) WHERE e.site_id = ? AND e.author_id IS NULL AND e.id IN ({$in}) AND COALESCE(({$saver}), ?::bigint) IS NOT NULL",
            [$ownerId, $siteId, ...$ids, $ownerId],
        );

        return [count($ids), $written, (int) end($ids)];
    }
}
