<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

/**
 * A customer's contact phone (tenants.contact_phone) in its three forms: what staff type (any of
 * "0901 234 567", "090-123-4567", "+84 90 123 4567", "0084 901234567"), what is stored (E.164,
 * "+84901234567") and what staff read ("0901 234 567" for the home country, the stored form for
 * any other). The home country is config/customers.php `phone_country_code`, so another market
 * changes it without touching code, as long as that country dials a leading 0 at home as Vietnam
 * does (the national form is "0" + the number); a number with its own + or 00 prefix keeps its
 * country. Hotlines (1800 / 1900) are not contact phones and are refused.
 */
final class ContactPhone
{
    /** The stored form of what was typed, or null when it is not a phone number. */
    public static function normalize(string $typed): ?string
    {
        // a copied number may carry no-break or other Unicode spaces; "(+84) 90…" brackets its code
        $typed = trim((string) preg_replace('/[\p{Z}\t]+/u', ' ', $typed));
        $typed = (string) preg_replace('/^\(\s*(\+\d{1,3})\s*\)/', '$1', $typed);
        // "+84 (0)90…": the bracketed trunk 0 is not dialled after a country code
        $typed = (string) preg_replace('/^((?:\+|00)\d{1,3})\s*\(\s*0\s*\)/', '$1', $typed);
        if (preg_match('/\A[\d\s().+\-]+\z/', $typed) !== 1 || substr_count($typed, '+') > 1 || strpos($typed, '+') > 0) {
            return null;
        }

        $digits = (string) preg_replace('/\D/', '', $typed);
        $home = self::homeCode();
        $number = match (true) {
            str_starts_with($typed, '+') => '+'.$digits,
            str_starts_with($digits, '00') => '+'.substr($digits, 2),
            str_starts_with($digits, '0') => '+'.$home.substr($digits, 1),
            default => null,
        };
        if ($number === null) {
            return null;
        }

        // "+84 0901…": one trunk 0 typed after the code is dropped, as when it is bracketed
        foreach (array_unique([$home, '84']) as $code) {
            if (str_starts_with($number, '+'.$code.'0')) {
                $number = '+'.$code.substr($number, strlen($code) + 2);
                break;
            }
        }
        if (preg_match('/\A\+[1-9]\d{7,14}\z/', $number) !== 1) {
            return null;
        }
        // a Vietnamese number after +84: a mobile is 9 digits from 3, 5, 7, 8 or 9; a landline 10 from 2
        if (str_starts_with($number, '+84') && preg_match('/\A\+84(?:2\d{9}|[35789]\d{8})\z/', $number) !== 1) {
            return null;
        }

        return $number;
    }

    /** How staff read a stored number: the home country in its national form, any other as stored. */
    public static function display(string $stored): string
    {
        $home = '+'.self::homeCode();
        if (! str_starts_with($stored, $home)) {
            return $stored;
        }
        $national = '0'.substr($stored, strlen($home));

        // a landline (11 digits with its 0: "028 3812 3456") groups 3-4-4, a mobile 4-3-3
        return strlen($national) === 11
            ? substr($national, 0, 3).' '.substr($national, 3, 4).' '.substr($national, 7)
            : trim(substr($national, 0, 4).' '.substr($national, 4, 3).' '.substr($national, 7));
    }

    /**
     * The digits to look for inside stored numbers when staff search: at least three digits, a
     * leading 0 read as the home country code (so "0901" finds +84901…); null when too few.
     */
    public static function searchDigits(string $query): ?string
    {
        $digits = (string) preg_replace('/\D/', '', $query);
        if (strlen($digits) < 3) {
            return null;
        }

        $digits = str_starts_with($digits, '0') && ! str_starts_with($digits, '00') ? self::homeCode().substr($digits, 1) : ltrim($digits, '0');

        return strlen($digits) >= 3 ? $digits : null;
    }

    private static function homeCode(): string
    {
        $raw = config('customers.phone_country_code');
        $code = is_int($raw) || is_string($raw) ? (string) preg_replace('/\D/', '', (string) $raw) : '';

        return $code !== '' ? $code : '84';
    }
}
