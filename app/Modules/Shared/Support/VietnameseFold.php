<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

/**
 * Folds Vietnamese text for search that ignores diacritics and case: "Nguyễn Đức" and "nguyen duc"
 * fold to the same string. PostgreSQL here has no `unaccent` extension, so the database side uses
 * translate() with the very same letter table as PHP: a needle folded by text() matches a column
 * folded by sql(), whichever side the accents were on.
 *
 * Only the Vietnamese letters are folded (other scripts pass through unchanged, then lowercased),
 * which is what a Vietnamese admin types; a precomposed form is assumed on the database side, and
 * the needle is normalized to it first (a Mac keyboard may send combining marks).
 *
 * The trigram indexes on users.name and sites.name (2039_01_01_000100) are built on sql('name')
 * word for word, which is what lets the planner use them: changing the letter table means a
 * migration rebuilding those indexes (AuditLogIndexesTest fails until then).
 */
final class VietnameseFold
{
    private const string FROM = 'àáạảãâầấậẩẫăằắặẳẵèéẹẻẽêềếệểễìíịỉĩòóọỏõôồốộổỗơờớợởỡùúụủũưừứựửữỳýỵỷỹđ'
        .'ÀÁẠẢÃÂẦẤẬẨẪĂẰẮẶẲẴÈÉẸẺẼÊỀẾỆỂỄÌÍỊỈĨÒÓỌỎÕÔỒỐỘỔỖƠỜỚỢỞỠÙÚỤỦŨƯỪỨỰỬỮỲÝỴỶỸĐ';

    private const string TO = 'aaaaaaaaaaaaaaaaaeeeeeeeeeeeiiiiiooooooooooooooooouuuuuuuuuuuyyyyyd'
        .'AAAAAAAAAAAAAAAAAEEEEEEEEEEEIIIIIOOOOOOOOOOOOOOOOOUUUUUUUUUUUYYYYYD';

    /** @var array<string, string>|null */
    private static ?array $map = null;

    public static function text(string $text): string
    {
        $composed = class_exists('Normalizer') ? \Normalizer::normalize($text, \Normalizer::FORM_C) : false;
        if (is_string($composed)) {
            $text = $composed;
        }

        return mb_strtolower(strtr($text, self::map()));
    }

    /**
     * The SQL expression folding $expression (a column or cast written by the caller, never user
     * input): compare it with `LIKE` against a needle folded by text().
     *
     * @param  literal-string  $expression
     * @return literal-string
     */
    public static function sql(string $expression): string
    {
        return 'lower(translate('.$expression.", '".self::FROM."', '".self::TO."'))";
    }

    /**
     * The words of $expression folded by sql(), as a text-search vector (the 'simple'
     * configuration: every word kept, no stemming): match it with `@@ to_tsquery('simple', …)` and
     * a prefix query ("an:*") to find a word that starts with a few letters, which a trigram index
     * cannot serve. The word indexes of 2044_01_01_000000 are built on this expression word for
     * word.
     *
     * @param  literal-string  $expression
     * @return literal-string
     */
    public static function words(string $expression): string
    {
        return "to_tsvector('simple'::regconfig, ".self::sql($expression).')';
    }

    /** "50%_off" → "50\%\_off": a folded needle used inside LIKE '%…%' matches only itself. */
    public static function like(string $folded): string
    {
        return '%'.addcslashes($folded, '\\%_').'%';
    }

    /**
     * @return array<string, string>
     */
    private static function map(): array
    {
        return self::$map ??= array_combine(mb_str_split(self::FROM), mb_str_split(self::TO));
    }
}
