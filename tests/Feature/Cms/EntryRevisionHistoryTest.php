<?php

use App\Modules\Cms\Enums\RevisionKind;
use App\Modules\Cms\Models\Entry;
use App\Modules\Cms\Models\EntryRevision;
use App\Modules\Cms\Queries\EntryRevisions;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\DB;

/*
 * Reading and keeping revision history: the list pages by keyset without loading snapshots, a
 * revision is found by ulid only inside its own site, the baseline command is idempotent, and
 * retention always keeps the newest N per entry and language (design.md §8).
 */

beforeEach(fn () => seedTenancy());

/** A page in the current site, with a vi and optionally an en translation. */
function historyPage(string $slug, string $status = 'published', array $locales = ['vi']): Entry
{
    $page = Entry::query()->create(['type' => 'page', 'status' => $status, 'meta' => []]);
    foreach ($locales as $locale) {
        $page->translations()->create([
            'locale' => $locale, 'title' => "T {$locale}", 'slug' => $slug,
            'status' => $status === 'published' ? 'published' : 'draft', 'body' => ['blocks' => []],
        ]);
    }

    return $page->fresh();
}

/** $count revisions for (entry, locale), the i-th one $count - i days old (oldest first). */
function seedRevisions(Entry $entry, string $locale, int $count, int $daysApart = 1): void
{
    for ($i = 0; $i < $count; $i++) {
        EntryRevision::query()->create([
            'entry_id' => $entry->id, 'locale' => $locale, 'kind' => RevisionKind::Edit,
            'snapshot' => ['v' => 1, 'title' => "r{$i}"],
            'created_at' => now()->subDays(($count - $i) * $daysApart),
        ]);
    }
}

it('pages the history newest first by keyset, without the snapshot, with a ulid cursor', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $page = historyPage('lich-su');
        seedRevisions($page, 'vi', 5);
        // Two rows in the same instant: the id tie-break keeps both, each exactly once.
        $tick = now()->subHour();
        foreach (['same-a', 'same-b'] as $title) {
            EntryRevision::query()->create(['entry_id' => $page->id, 'locale' => 'vi', 'kind' => RevisionKind::Edit, 'snapshot' => ['v' => 1, 'title' => $title], 'created_at' => $tick]);
        }
        seedRevisions($page, 'en', 2);
        $query = app(EntryRevisions::class);

        $seen = [];
        $cursor = null;
        $pages = 0;
        do {
            $result = $query->forEntry($page, 'vi', $cursor, 3);
            foreach ($result['rows'] as $row) {
                expect($row->getAttributes())->not->toHaveKey('snapshot')
                    ->and($row->locale)->toBe('vi');
                $seen[] = $row->ulid;
            }
            $cursor = $result['cursor'];
            expect($cursor === null || strlen($cursor) === 26)->toBeTrue();
            $pages++;
        } while ($cursor !== null);

        $expected = EntryRevision::query()->where('entry_id', $page->id)->where('locale', 'vi')
            ->orderByDesc('created_at')->orderByDesc('id')->pluck('ulid')->all();
        expect($pages)->toBe(3)
            ->and($seen)->toBe($expected)
            ->and(count($seen))->toBe(7)
            ->and($query->forEntry($page, 'vi', 'NOT-A-REAL-CURSOR')['rows'])->toBeEmpty();
    });
});

it('finds a revision by ulid only inside its own site', function (): void {
    $ulid = app(Tenancy::class)->enter(1, function (): string {
        $page = historyPage('rieng');
        seedRevisions($page, 'vi', 1);

        return EntryRevision::query()->where('entry_id', $page->id)->value('ulid');
    });

    app(Tenancy::class)->enter(1, function () use ($ulid): void {
        expect(app(EntryRevisions::class)->find($ulid)?->snapshot)->toMatchArray(['title' => 'r0']);
    });

    app(Tenancy::class)->enter(2, function () use ($ulid): void {
        expect(app(EntryRevisions::class)->find($ulid))->toBeNull()
            // RLS, not only the model scope: a raw read sees nothing of site 1 either.
            ->and(DB::table('entry_revisions')->where('ulid', $ulid)->count())->toBe(0);
    });
});

it('records one baseline per published entry language that has no revision, idempotently', function (): void {
    [$live, $withHistory, $draft, $trashed] = app(Tenancy::class)->enter(1, function (): array {
        $live = historyPage('song', 'published', ['vi', 'en']);
        $withHistory = historyPage('co-lich-su');
        seedRevisions($withHistory, 'vi', 1);
        $draft = historyPage('nhap', 'draft');
        // Published, then trashed: restoring it from the Trash later must find a baseline.
        $trashed = historyPage('thung-rac');
        $trashed->delete();

        return [$live, $withHistory, $draft, $trashed];
    });
    $otherSite = app(Tenancy::class)->enter(2, fn (): Entry => historyPage('site-b'));

    $this->artisan('cms:snapshot-published')
        ->expectsOutputToContain('Recorded 4 baseline revision(s) on 2 site(s), 0 failed.')
        ->assertSuccessful();

    app(Tenancy::class)->enter(1, function () use ($live, $withHistory, $draft, $trashed): void {
        $kinds = fn (Entry $entry): array => EntryRevision::query()->where('entry_id', $entry->id)->orderBy('locale')
            ->get()->map(fn (EntryRevision $r): string => $r->locale.':'.$r->kind->value)->all();

        expect($kinds($live))->toBe(['en:baseline', 'vi:baseline'])
            ->and($kinds($withHistory))->toBe(['vi:edit'])
            ->and($kinds($draft))->toBe([])
            ->and($kinds($trashed))->toBe(['vi:baseline'])
            ->and(EntryRevision::query()->where('entry_id', $live->id)->whereNotNull('created_by')->exists())->toBeFalse();
    });
    app(Tenancy::class)->enter(2, function () use ($otherSite): void {
        expect(EntryRevision::query()->where('entry_id', $otherSite->id)->count())->toBe(1);
    });

    $this->artisan('cms:snapshot-published')
        ->expectsOutputToContain('Recorded 0 baseline revision(s) on 2 site(s), 0 failed.')
        ->assertSuccessful();
});

it('keeps the newest N per entry and language and removes older ones only past the retention age', function (): void {
    config(['cms.revisions.keep' => 3, 'cms.revisions.days' => 10]);

    [$busy, $quiet, $fresh] = app(Tenancy::class)->enter(1, function (): array {
        $busy = historyPage('ban-ron', 'published', ['vi', 'en']);
        seedRevisions($busy, 'vi', 8, 5);   // 40, 35 … 5 days old
        seedRevisions($busy, 'en', 2, 30);  // fewer than N: all stay however old
        $quiet = historyPage('yen-tinh');
        seedRevisions($quiet, 'vi', 1, 400); // the only revision: never removed
        $fresh = historyPage('moi');
        seedRevisions($fresh, 'vi', 6);      // beyond N but all younger than 10 days

        return [$busy, $quiet, $fresh];
    });

    // A batch of 2 makes the sweep loop (2 + 2 + 1, then an empty round).
    $this->artisan('cms:prune-revisions', ['--batch' => 2])
        ->expectsOutputToContain('Removed 5 revision(s) beyond the newest 3 and older than 10 days on 2 site(s), 0 failed.')
        ->assertSuccessful();

    app(Tenancy::class)->enter(1, function () use ($busy, $quiet, $fresh): void {
        $titles = fn (Entry $entry, string $locale): array => EntryRevision::query()->where('entry_id', $entry->id)->where('locale', $locale)
            ->orderBy('created_at')->get()->map(fn (EntryRevision $r): string => $r->snapshot['title'])->all();

        // By recency: r7 5d, r6 10d, r5 15d | r4 20d … r0 40d — the five past the newest 3 are all
        // older than 10 days, so they go.
        expect($titles($busy, 'vi'))->toBe(['r5', 'r6', 'r7'])
            ->and($titles($busy, 'en'))->toBe(['r0', 'r1'])
            ->and($titles($quiet, 'vi'))->toBe(['r0'])
            ->and($titles($fresh, 'vi'))->toHaveCount(6);
    });
});

it('never removes the newest revision, even with a keep of zero', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $page = historyPage('cuoi-cung');
        seedRevisions($page, 'vi', 3, 200);
    });

    $this->artisan('cms:prune-revisions', ['--keep' => 0, '--days' => 1])->assertSuccessful();

    app(Tenancy::class)->enter(1, function (): void {
        expect(EntryRevision::query()->pluck('snapshot')->map(fn (array $s): string => $s['title'])->all())->toBe(['r2']);
    });
});

it('drops the history with the entry when it is purged, and keeps it while it is in the Trash', function (): void {
    app(Tenancy::class)->enter(1, function (): void {
        $page = historyPage('xoa');
        seedRevisions($page, 'vi', 2);

        $page->delete();
        expect(EntryRevision::query()->where('entry_id', $page->id)->count())->toBe(2);

        $page->forceDelete();
        expect(EntryRevision::query()->where('entry_id', $page->id)->count())->toBe(0);
    });
});
