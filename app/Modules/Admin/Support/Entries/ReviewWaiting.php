<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Entries;

use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Routing\SectionResolver;
use App\Modules\Cms\Support\CategoryScopes;
use App\Modules\Identity\Models\User;
use App\Modules\Workflow\Services\ReviewQueue;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * The posts waiting for one person to act on them, newest movement first: what "Hộp duyệt" lists
 * and what the "Duyệt bài" card of Tổng quan counts. One reader, so both always agree on what is
 * waiting.
 *
 * Which posts wait is a question about STAGES (ReviewQueue): one `whereIn` over entries, then a
 * check per row that the stage is actionable for the post's own type — stage keys repeat across
 * templates ('pending' exists in both bundled flows), so a key match alone would show a reviewer
 * of "Tin tức" every "Dịch vụ" post on the same-named stage. A member limited to some categories
 * reviews only posts filed inside them.
 *
 * Bounded by the caller's $limit: the SQL reads at most that many rows (with the relations a row
 * shows), and `full` says the read stopped there, so a count drawn from it reads "at least".
 * Stateless (Octane): every per-request memo lives in the scoped services it asks.
 */
final readonly class ReviewWaiting
{
    /**
     * @return array{entries: EloquentCollection<int, Entry>, full: bool}
     */
    public function read(User $actor, int $limit): array
    {
        $stages = app(ReviewQueue::class)->actionableStages($actor);
        $keys = array_values(array_unique($stages === [] ? [] : array_merge(...array_values($stages))));

        if ($keys === []) {
            /** @var EloquentCollection<int, Entry> $empty */
            $empty = new EloquentCollection;

            return ['entries' => $empty, 'full' => false];
        }

        $query = Entry::query()->whereIn('workflow_state', $keys);
        app(CategoryScopes::class)->whereCovered($query, $actor);

        $rows = $query
            ->with(['translations', 'author', 'taxonomies.translations'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $sections = app(SectionResolver::class);
        $waiting = $rows->filter(function (Entry $entry) use ($stages, $sections): bool {
            $section = $sections->forEntry($entry)['key'] ?? null;

            return $section !== null && isset($stages[$section]) && in_array((string) $entry->workflow_state, $stages[$section], true);
        })->values();

        /** @var EloquentCollection<int, Entry> $waiting */
        return ['entries' => $waiting, 'full' => $rows->count() >= $limit];
    }
}
