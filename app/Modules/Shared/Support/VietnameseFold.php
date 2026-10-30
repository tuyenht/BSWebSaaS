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
