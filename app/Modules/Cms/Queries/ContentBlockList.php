<?php

declare(strict_types=1);

namespace App\Modules\Cms\Queries;

use App\Modules\Cms\Models\ContentBlock;
use App\Modules\Cms\Models\ContentBlockTranslation;
use App\Modules\Shared\Support\Cast;
use Illuminate\Support\Facades\DB;

/**
 * The block library's list reads, within the current site (BelongsToSite + RLS):
 *  - page(): newest change first, in keyset pages on (site_id, updated_at DESC, id DESC), filtered
 *    by status and region. The cursor names the last row of a page by its time as stored and its
 *    ulid, never its bigint id, so it may go to the browser; the next page looks the anchor's id up
 *    by ulid (one indexed read on this website) and keeps the tie-break on id. An anchor deleted
 *    between pages is treated as the highest id at its time: a block changed at that same instant
 *    may show again, none is skipped.
 *  - counts(): how many blocks each status × region holds, for the status tabs and region chips, in
 *    one GROUP BY (a website keeps at most `cms.blocks.max_per_site` blocks).
 *  - locales(): the languages each listed block has content in, in one whereIn read.
 * "Dùng ở N nơi" comes from ContentBlockUsages::countsFor(), also one read for the whole page.
 */
final readonly class ContentBlockList
{
    public const int PAGE_SIZE = 50;

    /**
     * @param  array{at: string, ulid: string}|null  $after  the cursor of the previous page
     * @return array{rows: list<ContentBlock>, next: array{at: string, ulid: string}|null}
     */
    public function page(?string $status = null, ?string $region = null, ?array $after = null, int $limit = self::PAGE_SIZE): array
    {
        $limit = max(1, $limit);
        $anchorId = null;
        if ($after !== null) {
            $found = ContentBlock::query()->where('ulid', $after['ulid'])->value('id');
            $anchorId = $found === null ? PHP_INT_MAX : Cast::int($found);
        }
        $rows = ContentBlock::query()
            ->select(['id', 'ulid', 'key', 'name', 'region', 'status', 'updated_at'])
            ->selectRaw('updated_at::text as list_at')
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when($region !== null, fn ($query) => $query->where('region', $region))
            ->when($after !== null, fn ($query) => $query->whereRaw('(updated_at, id) < (?::timestamptz, ?)', [$after['at'] ?? '', $anchorId]))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();

        $next = null;
        if ($rows->count() > $limit) {
            $rows = $rows->take($limit);
            $last = $rows->last();
            if ($last instanceof ContentBlock) {
                $next = ['at' => Cast::string($last->getAttribute('list_at')), 'ulid' => $last->ulid];
            }
        }

        return ['rows' => array_values($rows->all()), 'next' => $next];
    }

    /**
     * Blocks per status and region, every pair present (0 when none).
     *
     * @return array<string, array<string, int>> status → region → blocks
     */
    public function counts(): array
    {
        $counts = array_fill_keys(ContentBlock::STATUSES, array_fill_keys(ContentBlock::REGIONS, 0));
        $rows = ContentBlock::query()
            ->select(['status', 'region', DB::raw('count(*) as blocks')])
            ->groupBy('status', 'region')
            ->toBase()
            ->get();
        foreach ($rows as $row) {
            $status = Cast::string($row->status);
            $region = Cast::string($row->region);
            if (isset($counts[$status][$region])) {
                $counts[$status][$region] = Cast::int($row->blocks);
            }
        }

        return $counts;
    }

    /**
     * @param  list<int>  $blockIds
     * @return array<int, list<string>> block id → its languages, sorted (blocks with none are absent)
     */
    public function locales(array $blockIds): array
    {
        if ($blockIds === []) {
            return [];
        }

        $out = [];
        $rows = ContentBlockTranslation::query()
            ->whereIn('content_block_id', $blockIds)
            ->orderBy('locale')
            ->toBase()
            ->get(['content_block_id', 'locale']);
        foreach ($rows as $row) {
            $out[Cast::int($row->content_block_id)][] = Cast::string($row->locale);
        }

        return $out;
    }
}
