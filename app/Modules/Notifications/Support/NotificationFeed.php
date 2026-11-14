<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Models\SiteNotification;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\VietnameseFold;
use App\Modules\Tenancy\Context\CurrentSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * A person's notifications on the current site — what the bell and the Notifications page read.
 * Always scoped to the reader (user_id) on top of the site's RLS. Lists page by keyset (newest
 * first, id tie-break, cursor = the last item's ulid — no bigint leaves the server), and the
 * unread ones (the bell, the "Chưa đọc" tab) walk the partial index site_notifications_unread_feed.
 */
final class NotificationFeed
{
    public function unreadCount(User $user): int
    {
        return SiteNotification::query()->where('user_id', $user->getKey())->whereNull('read_at')->count();
    }

    /**
     * The unread count, stopping at $cap: the bell on every admin page reads "99+" from 100 on,
     * so one query that walks at most $cap entries of site_notifications_unread_feed is enough.
     */
    public function unreadCountUpTo(User $user, int $cap): int
    {
        $unread = SiteNotification::query()->where('user_id', $user->getKey())->whereNull('read_at')->select('id')->limit($cap);

        return $unread->getQuery()->newQuery()->fromSub($unread, 'unread')->count();
    }

    /**
     * @return Collection<int, SiteNotification>
     */
    public function latest(User $user, int $limit = 20, ?string $afterUlid = null): Collection
    {
        $query = SiteNotification::query()
            ->where('user_id', $user->getKey())
            ->select(['id', 'ulid', 'kind', 'data', 'read_at', 'created_at'])
            ->orderByDesc('id')
            ->limit($limit);

        if ($afterUlid !== null) {
            $query->where('id', '<', SiteNotification::query()->where('user_id', $user->getKey())->where('ulid', $afterUlid)->select('id'));
        }

        return $query->get();
    }

    /**
     * One page of the Notifications page's list, newest first: $kinds narrows it to one group, $unread
     * to the unread ones. Keyset on id, never OFFSET: $after = the rows older than that notice (the
     * next page); $through = the rows from the newest down to and including that notice (the pages
     * already on screen, drawn again). Each shape reads the reader's slice of its own index, newest
     * first: every notice site_notifications_feed (site_id, user_id, id DESC), the unread ones
     * site_notifications_unread_feed (the same, WHERE read_at IS NULL), one group
     * site_notifications_kind_feed (site_id, user_id, kind, id DESC) when it is rare (a common group
     * may be found as fast by walking the feed, and the planner may do that). A cursor that is not
     * the reader's, or is gone, matches nothing.
     *
     * @param  list<string>|null  $kinds
     * @return Collection<int, SiteNotification>
     */
    public function page(User $user, ?array $kinds, bool $unread, int $limit, ?string $after = null, ?string $through = null): Collection
    {
        $query = $this->filtered($user, $kinds, $unread)
            ->select(['id', 'ulid', 'kind', 'data', 'read_at', 'created_at'])
            ->orderByDesc('id')
            ->limit($limit);

        if ($after !== null) {
            $query->where('id', '<', $this->idOf($user, $after));
        }
        if ($through !== null) {
            $query->where('id', '>=', $this->idOf($user, $through));
        }

        return $query->get();
    }

    /** How far back a search reaches: the 90 days notices are kept (notifications:prune). */
    public const int SEARCH_DAYS = 90;

    /** A search statement slower than this is logged (the signal to revisit how notices are searched). */
    public const int SEARCH_SLOW_MS = 150;

    /**
     * The search box: the reader's notices whose search line (NotificationSearchText) holds every one
     * of $words, newest first, narrowed like page() ($kinds, $unread): the id and ulid of at most
     * $limit. The walk reads the reader's slice of the same index as page() newest first and checks
     * each row's line (LIKE is not leakproof, so under RLS it is a row filter, never an index
     * condition), stopping at $limit matches. A notice older than SEARCH_DAYS is never found (a row
     * filter too); what bounds the walk is the daily prune, which keeps only those days. A word found
     * nowhere reads the reader's whole slice, which is why the statement's time is logged past
     * SEARCH_SLOW_MS (without the words: they may be a customer's name or phone). Measured on
     * 11.8 M notices as app_user under RLS, the heaviest reader (28,158 notices): a word found
     * nowhere 136 ms (median of 20 warm runs; 200 ms once that site's rows had been rewritten), a
     * common word 0.3 ms, a reader with ~200 notices 0.2 ms. Bounding the id by the window's oldest
     * notice instead (a subquery) made the planner read every row by bitmap and sort them, 173 ms
     * even for a common word, so the bound is a plain filter.
     *
     * @param  list<string>|null  $kinds
     * @param  list<string>  $words  folded, as NotificationSearchText::words() returns them
     * @return list<array{id: int, ulid: string}>
     */
    public function matches(User $user, ?array $kinds, bool $unread, array $words, int $limit): array
    {
        $query = $this->filtered($user, $kinds, $unread)
            ->where('created_at', '>=', now()->subDays(self::SEARCH_DAYS))
            ->select(['id', 'ulid'])
            ->orderByDesc('id')
            ->limit($limit);
        foreach ($words as $word) {
            $query->where('search_text', 'like', VietnameseFold::like($word));
        }

        $started = hrtime(true);
        $rows = $query->toBase()->get();
        $ms = (hrtime(true) - $started) / 1e6;
        if ($ms > self::SEARCH_SLOW_MS) {
            Log::warning('Notifications search was slow', [
                'ms' => round($ms, 1), 'site_id' => app(CurrentSite::class)->hasId() ? app(CurrentSite::class)->id() : null,
                'user_id' => $user->getKey(), 'words' => count($words), 'found' => $rows->count(), 'limit' => $limit,
            ]);
        }

        $found = [];
        foreach ($rows as $row) {
            $found[] = ['id' => Cast::int($row->id), 'ulid' => Cast::string($row->ulid)];
        }

        return $found;
    }

    /**
     * The notices matches() found, by their ids, newest first, with what the list shows (the
     * reader's own only).
     *
     * @param  list<int>  $ids
     * @return Collection<int, SiteNotification>
     */
    public function rows(User $user, array $ids): Collection
    {
        if ($ids === []) {
            return new Collection;
        }

        return SiteNotification::query()
            ->where('user_id', $user->getKey())
            ->whereIn('id', $ids)
            ->select(['id', 'ulid', 'kind', 'data', 'read_at', 'created_at'])
            ->orderByDesc('id')
            ->get();
    }

    /**
     * How many notices each tab holds, each count stopping at $cap (the tab then reads "99+"), in one
     * statement: every count reads at most $cap entries of the index that serves its tab (see page()).
     *
     * @param  array<string, array{kinds: list<string>|null, unread: bool}>  $tabs
     * @return array<string, int>
     */
    public function countsUpTo(User $user, int $cap, array $tabs): array
    {
        $query = DB::query();
        foreach ($tabs as $key => $tab) {
            $matches = $this->filtered($user, $tab['kinds'], $tab['unread'])->select('id')->limit($cap);
            $query->selectSub(DB::query()->fromSub($matches, 'm')->selectRaw('count(*)'), $key);
        }

        $row = (array) $query->first();
        $counts = [];
        foreach (array_keys($tabs) as $key) {
            $value = $row[$key] ?? 0;
            $counts[$key] = is_numeric($value) ? (int) $value : 0;
        }

        return $counts;
    }

    /**
     * Mark one of the reader's own notifications read; another person's ulid changes nothing.
     * Returns 1 when it was unread until now, else 0.
     */
    public function markRead(User $user, string $ulid): int
    {
        return SiteNotification::query()
            ->where('user_id', $user->getKey())
            ->where('ulid', $ulid)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /** How many notices "Hoàn tác" after "Đánh dấu đã đọc tất cả" puts back one by one. */
    public const int UNDO_EXACT_LIMIT = 200;

    /**
     * Mark every unread notice of the reader read, all with the read time $stamp (read_at keeps whole
     * seconds, rounded).
     */
    public function markAllRead(User $user, ?string $stamp = null): int
    {
        return SiteNotification::query()
            ->where('user_id', $user->getKey())
            ->whereNull('read_at')
            ->update(['read_at' => $stamp ?? now()]);
    }

    /** "Hoàn tác" after marking one read: it is unread again (the reader's own only). */
    public function markUnread(User $user, string $ulid): int
    {
        return SiteNotification::query()
            ->where('user_id', $user->getKey())
            ->where('ulid', $ulid)
            ->whereNotNull('read_at')
            ->update(['read_at' => null]);
    }

    /**
     * "Đánh dấu đã đọc tất cả" that "Hoàn tác" can undo exactly: up to UNDO_EXACT_LIMIT unread notices
     * are marked by their ulids, which come back so that undo puts back these and nothing else (a
     * notice that arrives meanwhile stays unread: the reader has not seen it). Above the limit every
     * unread notice is marked with $stamp and the ulids are null: undo then puts back the rows that
     * carry that second.
     *
     * @return array{0: int, 1: list<string>|null}
     */
    public function markAllReadForUndo(User $user, string $stamp): array
    {
        $ulids = array_values(SiteNotification::query()
            ->where('user_id', $user->getKey())
            ->whereNull('read_at')
            ->orderByDesc('id')
            ->limit(self::UNDO_EXACT_LIMIT + 1)
            ->pluck('ulid')
            ->map(fn (mixed $ulid): string => Cast::string($ulid))
            ->all());
        if (count($ulids) > self::UNDO_EXACT_LIMIT) {
            return [$this->markAllRead($user, $stamp), null];
        }
        if ($ulids === []) {
            return [0, []];
        }

        $count = SiteNotification::query()
            ->where('user_id', $user->getKey())
            ->whereIn('ulid', $ulids)
            ->whereNull('read_at')
            ->update(['read_at' => $stamp]);

        return [$count, $ulids];
    }

    /**
     * "Hoàn tác" after an exact "Đánh dấu đã đọc tất cả": these notices are unread again (the reader's own only).
     *
     * @param  list<string>  $ulids
     */
    public function markUnreadMany(User $user, array $ulids): int
    {
        if ($ulids === []) {
            return 0;
        }

        return SiteNotification::query()
            ->where('user_id', $user->getKey())
            ->whereIn('ulid', array_slice($ulids, 0, self::UNDO_EXACT_LIMIT))
            ->whereNotNull('read_at')
            ->update(['read_at' => null]);
    }

    /** "Hoàn tác" after a large "Đánh dấu đã đọc tất cả": the notices that carry its $stamp are unread again. */
    public function restoreRead(User $user, string $stamp): int
    {
        return SiteNotification::query()
            ->where('user_id', $user->getKey())
            ->where('read_at', $stamp)
            ->update(['read_at' => null]);
    }

    /**
     * The reader's notices on this site, narrowed to $kinds and/or the unread ones.
     *
     * @param  list<string>|null  $kinds
     * @return Builder<SiteNotification>
     */
    private function filtered(User $user, ?array $kinds, bool $unread): Builder
    {
        $query = SiteNotification::query()->where('user_id', $user->getKey());
        if ($kinds !== null) {
            $query->whereIn('kind', $kinds);
        }
        if ($unread) {
            $query->whereNull('read_at');
        }

        return $query;
    }

    /** @return Builder<SiteNotification> */
    private function idOf(User $user, string $ulid): Builder
    {
        return SiteNotification::query()->where('user_id', $user->getKey())->where('ulid', $ulid)->select('id');
    }
}
