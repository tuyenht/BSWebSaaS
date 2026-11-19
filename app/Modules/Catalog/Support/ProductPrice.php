<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

use DateTimeInterface;

/**
 * A product's amounts as a person types them and as the admin shows them: whole đồng (D5, an
 * integer, never a float), written the Vietnamese way with a dot between thousands (1.250.000).
 *
 * Typed text is read strictly, so a slip never stores a wrong amount: spaces and the currency mark
 * (₫, đ, VND) are dropped; what is left is digits alone, or digits grouped by threes with one kind of
 * separator ("1.250.000", "1,250,000"). Anything else — a decimal part ("1.250.000,50"), a letter, a
 * minus sign, mixed separators — is refused rather than guessed at.
 */
final class ProductPrice
{
    /** The longest amount, in digits (9.999.999.999.999 ₫): the board's limit, well inside bigint. */
    public const int AMOUNT_DIGITS = 13;

    /** The largest stock count, in digits: inside the integer column. */
    public const int STOCK_DIGITS = 7;

    /** Whether the text is blank or a whole number this reader accepts (at most $digits significant digits). */
    public static function readable(?string $typed, int $digits = self::AMOUNT_DIGITS): bool
    {
        return self::digits($typed) !== null && strlen(ltrim((string) self::digits($typed), '0')) <= $digits;
    }

    /** Whether the text has the shape of a whole number, however long. */
    public static function wellFormed(?string $typed): bool
    {
        return self::digits($typed) !== null;
    }

    /** The whole number the text holds, or null when it is blank or not readable (check readable() first). */
    public static function parse(?string $typed, int $digits = self::AMOUNT_DIGITS): ?int
    {
        $plain = self::digits($typed);
        if ($plain === null || $plain === '' || ! self::readable($typed, $digits)) {
            return null;
        }

        return (int) $plain;
    }

    /** An amount the Vietnamese way: 1.250.000. */
    public static function format(int $amount): string
    {
        return number_format($amount, 0, ',', '.');
    }

    /**
     * Whether visitors see the sale price at $now: a sale price below the price, inside its start and
     * end (either may be open). The products list asks the same in SQL (ProductListQuery::ON_SALE).
     */
    public static function saleApplies(?int $price, ?int $sale, ?DateTimeInterface $from, ?DateTimeInterface $to, DateTimeInterface $now): bool
    {
        return $price !== null && $sale !== null && $sale < $price
            && ($from === null || $from <= $now)
            && ($to === null || $to >= $now);
    }

    /**
     * The digits of the text ('' for a blank one), or null when the text is not a whole number.
     */
    private static function digits(?string $typed): ?string
    {
        $text = preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', (string) $typed) ?? '';
        $text = preg_replace('/^(?:₫|đ|vnđ|vnd)|(?:₫|đ|vnđ|vnd)$/iu', '', $text) ?? '';
        if ($text === '') {
            return '';
        }
        if (preg_match('/^\d+$/', $text) === 1) {
            return $text;
        }
        if (preg_match('/^\d{1,3}(?:([.,])\d{3})(?:\1\d{3})*$/', $text) === 1) {
            return str_replace(['.', ','], '', $text);
        }

        return null;
    }
}
