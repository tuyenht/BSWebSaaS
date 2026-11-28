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
     * The letters an avatar shows, from the end of the name: a Vietnamese name is written family
     * name first, so the default is the first letter of each of the last two words («Nguyễn Ngọc
     * Anh» → «NA», design board 01's account button and board 23), and a one-word name gives its
     * first two letters («Tuyền» → «TU»). `fillOneWord: false` gives a one-word name one letter
     * (board 05's «Người tạo» disc); `words: 1` takes the last word's first letter only (board 17's
     * review history). Diacritics stay («Đặng Ánh» → «ĐÁ»), a letter typed as a base plus a
     * combining mark stays one letter, and the result is upper case. An empty name gives ''.
     */
    public static function initials(string $name, int $words = 2, bool $fillOneWord = true): string
    {
        $normalized = \Normalizer::normalize($name, \Normalizer::FORM_C);
        $name = trim(is_string($normalized) && $normalized !== '' ? $normalized : $name);
        $parts = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false || $parts === [] || $words < 1) {
            return '';
        }

        $first = static fn (string $word, int $length): string => (string) (grapheme_substr($word, 0, $length) ?: mb_substr($word, 0, $length));
        $letters = count($parts) < $words && $fillOneWord
            ? $first($parts[0], $words)
            : implode('', array_map(static fn (string $word): string => $first($word, 1), array_slice($parts, -$words)));

        return mb_strtoupper($letters);
    }
}
