<?php

declare(strict_types=1);

namespace App\Modules\Cms\Queries;

use App\Modules\Cms\Enums\RevisionKind;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryRevision;
use App\Modules\Shared\Support\Cast;
use Carbon\CarbonImmutable;
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

    /**
     * One page of the history as plain rows, for screens outside this module (the editor's «Lịch sử
     * phiên bản»): which version, why it was taken, when and by whom (null: no person, a loader).
     *
     * @param  string|null  $after  the `cursor` of the previous page; null for the first page
     * @return array{rows: list<array{ulid: string, kind: RevisionKind, at: CarbonImmutable, who: string|null}>, cursor: string|null}
     */
    public function pageFor(Entry $entry, string $locale, ?string $after = null, int $limit = self::PAGE): array
    {
        $page = $this->forEntry($entry, $locale, $after, $limit);

        return [
            'rows' => array_values($page['rows']->map(static fn (EntryRevision $row): array => self::plain($row))->all()),
            'cursor' => $page['cursor'],
        ];
    }

    /**
     * One version of this entry in this language, snapshot included, as plain data, and whether it is
     * the newest of that language now; null when the ulid is not this entry's version in that
     * language (another entry's, another language's, another site's, or gone from the history).
     *
     * @return array{ulid: string, kind: RevisionKind, at: CarbonImmutable, who: string|null, snapshot: array<string, mixed>, latest: bool}|null
     */
    public function versionOf(Entry $entry, string $locale, string $ulid): ?array
    {
        $revision = $this->findFor($entry, $locale, $ulid);
        if ($revision === null) {
            return null;
        }
        $newest = EntryRevision::query()->where('entry_id', $entry->id)->where('locale', $locale)
            ->orderByDesc('created_at')->orderByDesc('id')->value('ulid');

        return self::plain($revision) + ['snapshot' => $revision->snapshot, 'latest' => $newest === $revision->ulid];
    }

    /** This entry's revision in this language with that ulid, within the current site; null otherwise. */
    public function findFor(Entry $entry, string $locale, string $ulid): ?EntryRevision
    {
        return EntryRevision::query()->where('ulid', $ulid)->where('entry_id', $entry->id)->where('locale', $locale)->with('creator:id,name')->first();
    }

    /** @return array{ulid: string, kind: RevisionKind, at: CarbonImmutable, who: string|null} */
    private static function plain(EntryRevision $row): array
    {
        $name = $row->creator?->name;

        return ['ulid' => $row->ulid, 'kind' => $row->kind, 'at' => $row->created_at, 'who' => is_string($name) && $name !== '' ? $name : null];
    }

    /**
     * How many revisions one language of an entry has. Retention keeps that number small (the newest
     * cms.revisions.keep, plus younger ones), and the count reads the history index alone.
     */
    public function countFor(Entry $entry, string $locale): int
    {
        return EntryRevision::query()->where('entry_id', $entry->id)->where('locale', $locale)->count();
    }

    /**
     * Of these entries, the ones a person changed through the editor or a restore (a revision with
     * an author). Loader and seeder revisions have no author, so they never count.
     *
     * @param  list<int>  $entryIds
     * @return list<int>
     */
    public function editedByPeople(array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }

        return array_values(array_map(Cast::int(...), EntryRevision::query()->whereIn('entry_id', $entryIds)->whereNotNull('created_by')
            ->whereIn('kind', [RevisionKind::Edit->value, RevisionKind::Restore->value])->distinct()->pluck('entry_id')->all()));
    }

    /** The full revision (snapshot included) by its ulid, within the current site; null when there is none. */
    public function find(string $ulid): ?EntryRevision
    {
        return EntryRevision::query()->where('ulid', $ulid)->first();
    }
}
