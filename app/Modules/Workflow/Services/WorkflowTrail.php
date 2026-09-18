<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Workflow\Models\WorkflowLog;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Read side of the approval trail — what a "Lịch sử duyệt" panel renders for one subject.
 *
 * Rows carry the labels the engine SNAPSHOTTED at write time (design.md §15.4 "trail tự mô tả"),
 * never a live join onto workflow_states: a template swap rebuilds those rows, so a join would
 * eventually print raw keys for moves that happened under the previous flow.
 *
 * Pages by keyset on (created_at DESC, id DESC) — the trail index is (site_id, entry_id,
 * created_at), and the id tie-break keeps two moves inside one clock tick from straddling a
 * page boundary. `total` is a per-subject count (a few rows, index-covered), not a live count
 * over the whole table.
 *
 * Runs inside the caller's tenant context (BelongsToSite + RLS scope the log to the site).
 */
final readonly class WorkflowTrail
{
    /** Microsecond-precise so the cursor round-trips the stored timestamptz exactly. */
    private const string CURSOR_TIME = 'Y-m-d\TH:i:s.uP';

    /**
     * @param  string|null  $after  the `cursor` of the previous page; null for the first page
     * @return array{
     *     rows: list<array{id: int, at: CarbonInterface, actor: string|null, transition: string, from: string, to: string, note: string|null}>,
     *     cursor: string|null,
     *     total: int,
     * }
     */
    public function forEntry(int $entryId, int $limit = 3, ?string $after = null): array
    {
        $query = WorkflowLog::query()
            ->where('entry_id', $entryId)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $cursor = $after !== null ? $this->decodeCursor($after) : null;
        if ($cursor !== null) {
            // Row-value comparison keeps the page boundary exact down to the microsecond + id.
            $query->whereRaw('(created_at, id) < (?, ?)', [$cursor[0]->format(self::CURSOR_TIME), $cursor[1]]);
        }

        // Fetch one past the page so "is there more?" costs no extra query.
        $logs = $query->limit($limit + 1)->get(['id', 'transition_key', 'from_state', 'to_state', 'labels', 'actor_id', 'note', 'created_at']);
        $hasMore = $logs->count() > $limit;
        $logs = $logs->take($limit);

        /** @var array<int, string> $actorNames */
        $actorNames = User::query()
            ->whereIn('id', $logs->pluck('actor_id')->filter()->unique()->all())
            ->pluck('name', 'id')
            ->all();

        $rows = [];
        foreach ($logs as $log) {
            $at = $log->created_at instanceof CarbonInterface ? $log->created_at : CarbonImmutable::now();
            $labels = $log->labels;

            $rows[] = [
                'id' => (int) $log->id,
                'at' => $at,
                'actor' => $log->actor_id !== null ? ($actorNames[$log->actor_id] ?? null) : null,
                // Fall back to the raw key only when the snapshot is missing (pre-provenance rows).
                'transition' => $labels['transition'] ?? $log->transition_key,
                'from' => $labels['from'] ?? $log->from_state,
                'to' => $labels['to'] ?? $log->to_state,
                'note' => $log->note,
            ];
        }

        $last = $rows === [] ? null : $rows[array_key_last($rows)];

        return [
            'rows' => $rows,
            'cursor' => $hasMore && $last !== null ? $this->encodeCursor($last['at'], $last['id']) : null,
            'total' => WorkflowLog::query()->where('entry_id', $entryId)->count(),
        ];
    }

    private function encodeCursor(CarbonInterface $at, int $id): string
    {
        return $at->format(self::CURSOR_TIME).'|'.$id;
    }

    /**
     * @return array{0: CarbonImmutable, 1: int}|null null for a malformed cursor (treated as page 1)
     */
    private function decodeCursor(string $cursor): ?array
    {
        $parts = explode('|', $cursor, 2);
        if (count($parts) !== 2 || ! ctype_digit($parts[1])) {
            return null;
        }

        try {
            return [CarbonImmutable::parse($parts[0]), (int) $parts[1]];
        } catch (\Exception) {
            return null;
        }
    }
}
