<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

use App\Modules\Shared\Support\Cast;

/**
 * A customer's contact phone (tenants.contact_phone) in its three forms: what staff type (any of
 * "0901 234 567", "090-123-4567", "+84 90 123 4567", "0084 901234567"), what is stored (E.164,
 * "+84901234567") and what staff read ("0901 234 567" for the home country, the stored form for
 * any other). The home country is config/customers.php `phone_country_code`, so another market
 * changes it without touching code; a number with its own + or 00 prefix keeps its country.
 */
final class ContactPhone
{
    /** The stored form of what was typed, or null when it is not a phone number. */
    public static function normalize(string $typed): ?string
    {
        // "+84 (0)90…": the bracketed trunk 0 is not dialled after a country code
        $typed = (string) preg_replace('/^(\s*(?:\+|00)\d{1,3})\s*\(\s*0\s*\)/', '$1', trim($typed));
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

        // "+84 0901…": the trunk 0 typed after the home code is dropped, as when it is bracketed
        if ($number !== null && str_starts_with($number, '+'.$home.'0')) {
            $number = '+'.$home.substr($number, strlen($home) + 2);
        }
        if ($number === null || preg_match('/\A\+[1-9]\d{7,14}\z/', $number) !== 1) {
            return null;
        }
        // a Vietnamese number has a known length after +84: 9 digits (mobile), 10 (landline, 02…)
        if (str_starts_with($number, '+84') && ! in_array(strlen($number) - 3, [9, 10], true)) {
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
        $code = (string) preg_replace('/\D/', '', Cast::string(config('customers.phone_country_code')));

        return $code !== '' ? $code : '84';
    }
}
