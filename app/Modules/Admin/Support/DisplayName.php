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

    /**
     * The two letters an avatar shows (design board 01, the account button): a Vietnamese name is
     * written family name first, so the letters are the first of the last two words («Nguyễn Ngọc
     * Anh» → «NA»); a one-word name gives its first two letters («Tuyền» → «TU»). Diacritics stay
     * («Đặng Ánh» → «ĐÁ»), a letter typed as a base plus a combining mark stays one letter, and the
     * result is upper case. An empty name gives ''.
     */
    public static function initials(string $name): string
    {
        $normalized = \Normalizer::normalize($name, \Normalizer::FORM_C);
        $name = trim(is_string($normalized) && $normalized !== '' ? $normalized : $name);
        $words = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false || $words === []) {
            return '';
        }

        $first = static fn (string $word, int $length): string => (string) (grapheme_substr($word, 0, $length) ?: mb_substr($word, 0, $length));
        $letters = count($words) > 1
            ? $first($words[count($words) - 2], 1).$first($words[count($words) - 1], 1)
            : $first($words[0], 2);

        return mb_strtoupper($letters);
    }
}
