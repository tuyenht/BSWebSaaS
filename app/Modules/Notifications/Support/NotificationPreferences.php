<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Registry\NotificationKinds;

/**
 * Whether a person gets a kind of notification on a channel. A locked kind (security) always
 * goes out; otherwise the person's own choice in `users.preferences.notifications.<kind>.<channel>`
 * wins, else the registry default. An unknown kind is never sent. Per-person settings live in
 * users.preferences (decision Q6 — never filtered in SQL, so no column).
 */
final class NotificationPreferences
{
    /**
     * @param  'in_app'|'mail'  $channel
     */
    public static function wants(User $user, string $kind, string $channel): bool
    {
        $defaults = NotificationKinds::all()[$kind] ?? null;
        if ($defaults === null) {
            return false;
        }

        if ($defaults['locked']) {
            return $defaults[$channel];
        }

        // Read the nested arrays directly: a kind key itself contains a dot (`lead.new`), which a
        // dot-path lookup would split into two levels and never find.
        $mine = $user->preferences['notifications'] ?? null;
        $choice = is_array($mine) && is_array($mine[$kind] ?? null) ? ($mine[$kind][$channel] ?? null) : null;

        return is_bool($choice) ? $choice : $defaults[$channel];
    }
}
