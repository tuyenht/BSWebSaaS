<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

/**
 * Typed text as it may be stored: without the control characters a person never means to type.
 * PostgreSQL refuses a NUL in text and JSON (the save would fail with a server error), and the other
 * control characters only ever arrive pasted or forged. The editors run every text field they save
 * through here before validating it, so the checks see what is stored.
 */
final class PlainText
{
    /**
     * A one-line field (a title, a link): control characters are removed, and line breaks and tabs
     * become one space. The ends are trimmed.
     */
    public static function line(string $value): string
    {
        $value = self::replace('/[^\P{Cc}\t\n\r\x{0B}\x{0C}\x{85}]+/u', '', $value);

        return trim(self::replace('/[\t\n\r\x{0B}\x{0C}\x{85}]+/u', ' ', $value));
    }

    /**
     * A multi-line field (an excerpt, block text): control characters are removed, except line breaks
     * (written as \n) and tabs. The ends are trimmed when $trim is true.
     */
    public static function lines(string $value, bool $trim = true): string
    {
        $value = self::replace('/[^\P{Cc}\t\n]+/u', '', self::replace('/\r\n?/u', "\n", $value));

        return $trim ? trim($value) : $value;
    }

    /**
     * Every string inside a nested array cleaned as multi-line text (keys and other values kept), for
     * structured content such as the page blocks. Text is not trimmed: spacing inside markup stays.
     *
     * @template T of array<array-key, mixed>
     *
     * @param  T  $values
     * @return T
     */
    public static function linesIn(array $values): array
    {
        array_walk_recursive($values, static function (mixed &$value): void {
            if (is_string($value)) {
                $value = self::lines($value, trim: false);
            }
        });

        return $values;
    }

    /** preg_replace that leaves text it cannot read as UTF-8 as it was, instead of emptying it. */
    private static function replace(string $pattern, string $replacement, string $value): string
    {
        return preg_replace($pattern, $replacement, $value) ?? $value;
    }
}
