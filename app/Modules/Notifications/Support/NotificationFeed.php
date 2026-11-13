<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Models\SiteNotification;
use Illuminate\Support\Collection;

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

    /** Mark one of the reader's own notifications read; another person's ulid changes nothing. */
    public function markRead(User $user, string $ulid): void
    {
        SiteNotification::query()
            ->where('user_id', $user->getKey())
            ->where('ulid', $ulid)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function markAllRead(User $user): int
    {
        return SiteNotification::query()
            ->where('user_id', $user->getKey())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }
}
