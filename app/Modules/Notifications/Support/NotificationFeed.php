<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Models\SiteNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A person's notifications on the current site — what the bell and the Notifications page read.
 * Always scoped to the reader (user_id) on top of the site's RLS. Lists page by keyset (newest
 * first, id tie-break, cursor = the last item's ulid — no bigint leaves the server), and the
 * unread badge uses the partial unread index.
 */
final class NotificationFeed
{
    public function unreadCount(User $user): int
    {
        return SiteNotification::query()->where('user_id', $user->getKey())->whereNull('read_at')->count();
    }

    /**
     * The unread count, stopping at $cap: the bell on every admin page reads "99+" from 100 on,
     * so one query that walks at most $cap entries of the partial unread index is enough.
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
     * already on screen, drawn again). The reader's slice of index site_notifications_feed
     * (site_id, user_id, id DESC) is walked newest first; a group or the unread filter is checked on
     * the way (no index leads with them). A cursor that is not the reader's, or is gone, matches nothing.
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

    /**
     * How many notices each tab holds, each count stopping at $cap (the tab then reads "99+"), in one
     * statement: every count walks at most $cap entries of the reader's slice that match.
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

    /**
     * Mark every unread notice of the reader read. $stamp (a timestamp with microseconds) is the
     * read time they all get, so "Hoàn tác" can put back exactly these (restoreRead).
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

    /** "Hoàn tác" after "Đánh dấu đã đọc tất cả": the notices it marked (its $stamp) are unread again. */
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
