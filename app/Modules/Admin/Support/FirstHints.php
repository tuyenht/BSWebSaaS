<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * First-time explanations a member can put away with "Đã hiểu". Each one is shown in full where it
 * applies until the member dismisses it; after that it lives in the section's (i) note instead. The
 * choice belongs to the member, not the device, so it is kept in `users.preferences.hints` (one
 * boolean per key) and follows them to every browser.
 *
 * Only the keys listed here can be stored: the browser names a key, the server checks it against
 * this list (HintPreferenceController), so the column never collects arbitrary names.
 */
final class FirstHints
{
    /** The language bar's sentence on the source language ("Đây là bản nguồn — …"). */
    public const string LANG_SOURCE = 'lang-src';

    /** The members list's line on the member's own row ("Nhóm quyền của bạn đổi ở …"). */
    public const string MEMBERS_YOU = 'members-you';

    /** @var list<string> */
    public const array KEYS = [self::LANG_SOURCE, self::MEMBERS_YOU];

    /** Whether the signed-in member (or $user) has dismissed the hint $key. */
    public static function dismissed(string $key, ?User $user = null): bool
    {
        $user ??= Auth::user();
        if (! $user instanceof User) {
            return false;
        }

        $hints = $user->preferences['hints'] ?? null;

        return is_array($hints) && ($hints[$key] ?? false) === true;
    }
}
