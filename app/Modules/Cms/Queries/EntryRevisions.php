<?php

declare(strict_types=1);

namespace App\Modules\Cms\Queries;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryRevision;
use Illuminate\Database\Eloquent\Collection;

/**
 * Read side of an entry's revision history (design.md §8).
 *
 * The list pages by keyset on (created_at DESC, id DESC) through the table's one index, never
 * OFFSET, and never selects `snapshot` — a layout page's body is large, and a list only needs who,
 * when and why. The cursor is the ulid of the last row shown, so no bigint id leaves the server; the
 * next page compares against that row's (created_at, id) in the same query.
 *
 * find() takes a ulid and returns the full revision only when it belongs to the current site
 * (global scope + RLS): another site's ulid is simply not found.
 *
 * It does NO authorization. Callers gate first through EntryPolicy on the revision's entry —
 * `view` to list or read a revision, `update` to restore one (RestoreEntryRevision checks that
 * itself).
 */
final readonly class EntryRevisions
{
    public const int PAGE = 20;

    /**
     * @param  string|null  $after  the `cursor` of the previous page; null for the first page
     * @return array{rows: Collection<int, EntryRevision>, cursor: string|null}
     */
    public function forEntry(Entry $entry, string $locale, ?string $after = null, int $limit = self::PAGE): array
    {
        $limit = max(1, $limit);

        $query = EntryRevision::query()
            ->where('entry_id', $entry->id)
            ->where('locale', $locale)
            ->with('creator:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($after !== null) {
            // An unknown cursor matches no row, so the comparison is NULL and the page is empty —
            // never a silent restart at page one.
            $query->whereRaw(
                '(entry_revisions.created_at, entry_revisions.id) < (select c.created_at, c.id from entry_revisions c where c.site_id = ? and c.ulid = ?)',
                [$entry->site_id, $after],
            );
        }

        // One row past the page answers "is there more?" without a second query.
        $rows = $query->limit($limit + 1)->get(['id', 'ulid', 'site_id', 'entry_id', 'locale', 'kind', 'created_by', 'created_at']);
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit)->values();
        $last = $rows->last();

        return [
            'rows' => $rows,
            'cursor' => $hasMore && $last instanceof EntryRevision ? $last->ulid : null,
        ];
    }

    /** The full revision (snapshot included) by its ulid, within the current site; null when there is none. */
    public function find(string $ulid): ?EntryRevision
    {
        return EntryRevision::query()->where('ulid', $ulid)->first();
    }
}
