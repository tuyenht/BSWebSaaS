<?php

declare(strict_types=1);

namespace App\Modules\Media\Support;

use App\Modules\Shared\Contracts\CounterReconciler;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Support\Facades\DB;

/**
 * Recounts the library's two totals (MediaStorage) for `counters:reconcile`: the master sizes of
 * every row the current website keeps, and of those in its Trash. count() walks the website's rows
 * by id over the (site_id, id) index, $chunk at a time, one grouped statement per chunk, with no
 * lock; recount() is the same sums as one statement, for the correction of a drifted website.
 */
final readonly class MediaStorageReconciler implements CounterReconciler
{
    public function __construct(private CurrentSite $current) {}

    public function metrics(): array
    {
        return [MediaStorage::BYTES, MediaStorage::TRASH];
    }

    public function count(int $chunk): array
    {
        $site = $this->current->id();
        $chunk = max(1, $chunk);
        $bytes = 0;
        $trash = 0;
        $after = 0;

        do {
            $row = (array) DB::selectOne(
                'SELECT count(*) AS n, coalesce(sum(size), 0) AS bytes, coalesce(sum(size) FILTER (WHERE deleted_at IS NOT NULL), 0) AS trash, max(id) AS last
                FROM (SELECT id, size, deleted_at FROM media WHERE site_id = ? AND id > ? ORDER BY id LIMIT ?) AS c',
                [$site, $after, $chunk],
            );
            $read = Cast::int($row['n'] ?? 0);
            $bytes += Cast::int($row['bytes'] ?? 0);
            $trash += Cast::int($row['trash'] ?? 0);
            $after = Cast::int($row['last'] ?? $after, $after);
        } while ($read === $chunk);

        return [MediaStorage::BYTES => $bytes, MediaStorage::TRASH => $trash];
    }

    public function recount(): array
    {
        return [
            'SELECT coalesce(sum(size), 0) AS m0, coalesce(sum(size) FILTER (WHERE deleted_at IS NOT NULL), 0) AS m1 FROM media WHERE site_id = ?',
            [$this->current->id()],
        ];
    }
}
