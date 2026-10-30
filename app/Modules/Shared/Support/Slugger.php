<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Transliterator;

/**
 * Link paths (slugs) from a name in any writing system. `Str::slug` alone drops every letter it cannot
 * map to ASCII, so a Chinese, Thai or Korean name came out empty and a mixed name lost half its
 * words. Text with letters outside the Latin script is first read out in Latin letters through ICU
 * (`Any-Latin; Latin-ASCII`); text already in Latin script takes the same route as before, so a
 * stored [a-z0-9-] slug never changes.
 */
final class Slugger
{
    /** A letter that is not Latin: the signal that the text needs ICU before Str::slug. */
    private const NON_LATIN_LETTER = '/(?![\p{Latin}])\p{L}/u';

    /**
     * @param  int  $max  the longest slug wanted; a longer one is cut at a '-' (no trailing '-')
     */
    public static function make(string $text, ?string $locale = null, int $max = 200): string
    {
        $normalized = class_exists('Normalizer') ? \Normalizer::normalize($text, \Normalizer::FORM_C) : $text;
        $text = is_string($normalized) ? $normalized : $text;

        if (preg_match(self::NON_LATIN_LETTER, $text) === 1 && class_exists(Transliterator::class)) {
            $transliterator = Transliterator::create('Any-Latin; Latin-ASCII');
            $latin = $transliterator?->transliterate($text);
            if (is_string($latin)) {
                $text = $latin;
            }
        }

        return self::cut(Str::slug($text), $max);
    }

    /**
     * A link for a blank slug field: made from the title/name, then made free by appending -2, -3…
     * An empty string when the text leaves nothing usable (only symbols) or its Latin reading
     * would be wrong (kanji in Japanese): the caller then reports the missing slug instead of
     * inventing one.
     *
     * @param  \Closure(string): array<array-key, string>  $takenAmong  the candidates among `$base` and `$base-N` already in use
     */
    public static function fromTitle(string $title, ?string $locale, \Closure $takenAmong): string
    {
        if (self::needsManualInput($title, $locale)) {
            return '';
        }

        // Leave room for a "-NN" suffix inside the 200-character column.
        $base = self::make($title, $locale, 190);
        if ($base === '') {
            return '';
        }

        return self::free($base, $takenAmong);
    }

    /**
     * $base itself when free, else the lowest free `$base-N` (N from 2). One call to $takenAmong
     * answers for every candidate, so the cost does not grow with the number already taken.
     *
     * @param  \Closure(string): array<array-key, string>  $takenAmong  given $base, the used values among `$base` and `$base-N`
     */
    public static function free(string $base, \Closure $takenAmong): string
    {
        $taken = array_flip(array_map(strval(...), array_values($takenAmong($base))));

        if (! isset($taken[$base])) {
            return $base;
        }

        $n = 2;
        while (isset($taken[$base.'-'.$n])) {
            $n++;
        }

        return $base.'-'.$n;
    }

    /**
     * The values of $column (with $prefix removed) that equal `$prefix$base` or start with
     * `$prefix$base-`: one query, for [free()]. The LIKE pattern is escaped, so a '%' or '_' in the
     * text matches only itself.
     *
     * @param  Builder<*>  $query  already narrowed to the rows that count (language, other owners…)
     * @return list<string>
     */
    public static function takenAmong(Builder $query, string $column, string $base, string $prefix = ''): array
    {
        $full = $prefix.$base;
        $like = addcslashes($full.'-', '\\%_').'%';

        $values = $query
            ->where(fn (Builder $q) => $q->where($column, $full)->orWhere($column, 'like', $like))
            ->pluck($column)
            ->all();

        $taken = [];
        foreach ($values as $value) {
            if (is_string($value) && str_starts_with($value, $prefix)) {
                $taken[] = substr($value, strlen($prefix));
            }
        }

        return $taken;
    }

    /**
     * Whether the person has to type the path themselves: the text has letters but none survive, or
     * Japanese text with kanji (ICU reads kanji as Chinese, which would give a wrong path). Kana next
     * to kanji marks the text as Japanese even when no locale is known, as for a folder name.
     */
    public static function needsManualInput(string $text, ?string $locale = null): bool
    {
        if (trim($text) === '') {
            return false;
        }

        if (preg_match('/\p{Han}/u', $text) === 1) {
            $japanese = ($locale !== null && str_starts_with(strtolower($locale), 'ja'))
                || preg_match('/[\p{Hiragana}\p{Katakana}]/u', $text) === 1;
            if ($japanese) {
                return true;
            }
        }

        return preg_match('/\p{L}/u', $text) === 1 && self::make($text, $locale) === '';
    }

    private static function cut(string $slug, int $max): string
    {
        if ($max < 1) {
            return '';
        }

        if (strlen($slug) > $max) {
            $head = substr($slug, 0, $max);
            if ($slug[$max] !== '-' && ($dash = strrpos($head, '-')) !== false) {
                $head = substr($head, 0, $dash);
            }
            $slug = $head;
        }

        return trim($slug, '-');
    }
}
