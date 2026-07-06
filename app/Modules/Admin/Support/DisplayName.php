<?php

namespace App\Modules\Admin\Support;

/**
 * Short display form of a user's full name for tight chrome (topbar): at most two words,
 * so it never wraps the user block. `name` is one free-text column (no structured
 * family/given split exists), so this is a heuristic — the first two words — chosen to
 * match how owners enter names here ("An Vinh Owner" → "An Vinh"); the user keeps final
 * control by editing their name in Hồ sơ (Settings → security). Menus/greetings keep the
 * full name.
 */
final class DisplayName
{
    public static function short(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false || $words === []) {
            return trim($name);
        }

        return implode(' ', array_slice($words, 0, 2));
    }
}
