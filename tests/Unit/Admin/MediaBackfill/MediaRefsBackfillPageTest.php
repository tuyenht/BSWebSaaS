<?php

use App\Modules\Admin\Support\MediaBackfill\MediaRefsBackfill;

/*
 * A batch's page starts at the cursor's first key with a lone `>=` bound, which only holds for a
 * key walked up: a walk whose first key is walked down is refused, never read wrong.
 */

it('refuses a walk whose first key is walked down', function (array $keys, ?array $cursor): void {
    expect(fn (): array => MediaRefsBackfill::page(1, 'entries', 'id', '', $keys, '', [], $cursor))
        ->toThrow(LogicException::class, 'must start with a key walked up');
})->with([
    'first page' => [[['id', 'id DESC']], null],
    'after a cursor' => [[['updated_at', 'updated_at desc nulls last'], ['id', 'id']], ['2026-11-26 00:00:00+00', 7]],
]);

it('builds every walk\'s page without a lock, from the first key up', function (): void {
    foreach (MediaRefsBackfill::WALKS as $table => $walks) {
        foreach ($walks as [$segment, $keys]) {
            [$sql, $bindings] = MediaRefsBackfill::page(1, $table, 'id', $segment, $keys, '', [], array_fill(0, count($keys), 1));
            expect($sql)->not->toContain('FOR NO KEY UPDATE')
                ->and($bindings[0])->toBe(1);
            if (count($keys) > 1) {
                expect($sql)->toContain("{$keys[0][0]} >= ?");
            }
        }
    }

    // a later key walked down is compared with <
    [$sql] = MediaRefsBackfill::page(1, 'entry_revisions', 'id', '', MediaRefsBackfill::WALKS['entry_revisions'][0][1], '', [], ['1', 'vi', 'x', 9]);
    expect($sql)->toContain('id < ?')->toContain('created_at < ?')->toContain('entry_id > ?');
});

it('locks and reads again only the given rows of the website, still in the segment', function (): void {
    [$sql, $bindings] = MediaRefsBackfill::locked(1, 'entries', 'id', "type = 'page'", 'x IS NOT NULL', [], [3, 5]);

    expect($sql)->toBe("SELECT id, CASE WHEN x IS NOT NULL THEN 1 ELSE 0 END AS hit FROM entries WHERE site_id = ? AND id = ANY(?::bigint[]) AND type = 'page' ORDER BY id FOR NO KEY UPDATE")
        ->and($bindings)->toBe([1, '{3,5}']);
});
