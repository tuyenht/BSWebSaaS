<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Entries;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Routing\SectionResolver;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Identity\Models\User;
use App\Modules\Workflow\Services\ReviewQueue;
use Closure;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * The posts waiting for one person to act on them, newest movement first: what "Hộp duyệt" lists
 * and what the "Duyệt bài" card of Tổng quan counts. One reader, so both always agree on what is
 * waiting.
 *
 * Which posts wait is a question about STAGES (ReviewQueue), asked per (section, stage) pair in
 * SQL before the limit — stage keys repeat across templates ('pending' exists in both bundled
 * flows), so a key match alone would show a reviewer of "Tin tức" every "Dịch vụ" post on the
 * same-named stage, and a cap applied before that filter would fill with rows that are not theirs.
 * The same check runs again per row (SectionResolver::forEntry, primed for the whole set) for the
 * older rows the SQL cannot judge. A member limited to some categories reviews only posts filed
 * inside them.
 *
 * Bounded by the caller's $limit: the SQL reads at most $limit + 1 rows, and `full` says it found
 * more than $limit (judged on the rows SQL returned, before the per-row check drops any, so a
 * dropped stale row never hides "more"), so a count drawn from it reads "at least".
 * The caller names the relations and columns its rows show. Stateless (Octane): every per-request
 * memo lives in the scoped services it asks.
 */
final readonly class ReviewWaiting
{
    /** What "Hộp duyệt" shows of each row (its reader reads every language). */
    public const array INBOX_RELATIONS = ['translations', 'author', 'taxonomies.translations'];

    /**
     * @param  array<int|string, string|Closure>  $with  the relations to load on each row
     * @param  list<string>  $columns  the entry columns to read
     * @param  array<string, list<string>>|null  $stages  ReviewQueue::actionableStages() when the caller has it
     * @return array{entries: EloquentCollection<int, Entry>, full: bool}
     */
    public function read(User $actor, int $limit, array $with = self::INBOX_RELATIONS, array $columns = ['*'], ?array $stages = null, ?int $assignedTo = null): array
    {
        $stages ??= app(ReviewQueue::class)->actionableStages($actor);
        $keys = array_values(array_unique($stages === [] ? [] : array_merge(...array_values($stages))));

        if ($keys === []) {
            /** @var EloquentCollection<int, Entry> $empty */
            $empty = new EloquentCollection;

            return ['entries' => $empty, 'full' => false];
        }

        $sections = app(SectionResolver::class);
        $query = Entry::query()->whereIn('workflow_state', $keys);
        $sections->whereOnSectionStage($query, $stages);
        app(CategoryScopes::class)->whereCovered($query, $actor);
        if ($assignedTo !== null) {
            $query->where('assigned_to', $assignedTo);
        }

        $rows = $query
            ->with($with)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get($columns === ['*'] ? ['*'] : array_map(fn (string $column): string => 'entries.'.$column, $columns));

        $sections->prime($rows);
        $waiting = $rows->filter(function (Entry $entry) use ($stages, $sections): bool {
            $section = $sections->forEntry($entry)['key'] ?? null;

            return $section !== null && isset($stages[$section]) && in_array((string) $entry->workflow_state, $stages[$section], true);
        })->values();

        /** @var EloquentCollection<int, Entry> $kept */
        $kept = $waiting->take($limit);

        return ['entries' => $kept, 'full' => $rows->count() > $limit];
    }
}
