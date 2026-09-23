<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Messages\SiteNotice;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Sends in-app notices on the current site — the one way content modules notify people. Never
 * notifies the person who caused the event. Recipients by permission are resolved in the site's
 * team (spatie teams = site_id), so only members of THIS site are reached.
 */
final class Notify
{
    /**
     * Everyone on the current site who holds $permission (through any of their roles).
     *
     * @param  array<string, mixed>  $data
     */
    public static function holdersOf(string $permission, string $kind, array $data, ?User $except = null): void
    {
        try {
            $users = User::permission($permission)
                ->when($except instanceof User, fn ($query) => $query->whereKeyNot($except?->getKey()))
                ->get();
        } catch (PermissionDoesNotExist) {
            return;   // a site whose permission set has not been synced yet has nobody to tell
        }

        Notification::send($users, new SiteNotice($kind, $data));
    }

    /**
     * One person, by id — skipped when they caused the event or no longer exist.
     *
     * @param  array<string, mixed>  $data
     */
    public static function user(?int $userId, string $kind, array $data, ?User $except = null): void
    {
        if ($userId === null || ($except instanceof User && $except->getKey() === $userId)) {
            return;
        }

        $user = User::query()->find($userId);
        if ($user instanceof User) {
            $user->notify(new SiteNotice($kind, $data));
        }
    }
}
