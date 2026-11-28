<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use Illuminate\Support\Number;

/**
 * Sizes in bytes as people read them, in the number format of the page's language: Vietnamese
 * «1,5 MB» and «1.024 KB», English «1.5 MB». The unit words come from the language files
 * (`bytes.kb` / `bytes.mb` / `bytes.gb`), the number from the locale.
 *
 * - size(): a file's size, as the media boards draw it (boards 12 and 13): from one mebibyte up in
 *   megabytes to one decimal («2,4 MB», «1,0 MB»), below it in whole kilobytes, at least «1 KB».
 * - megabytes(): a total against a plan's megabytes, to $decimals places at most, with no «,0»
 *   («312,4 MB», «500 MB»).
 * - gigabytes(): a total as the «Dung lượng thư viện» card writes it: megabytes over a thousand, to
 *   one decimal at most («1,2 GB», «2 GB»).
 */
final class Bytes
{
    private const int KB = 1024;

    private const int MB = 1048576;

    public static function size(int $bytes, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        if ($bytes < self::MB) {
            return __('bytes.kb', ['n' => self::number(max(1, (int) round(max(0, $bytes) / self::KB)), $locale, 0, null)]);
        }

        return __('bytes.mb', ['n' => self::number($bytes / self::MB, $locale, 1, null)]);
    }

    public static function megabytes(int $bytes, int $decimals = 1, ?string $locale = null): string
    {
        return __('bytes.mb', ['n' => self::number(round($bytes / self::MB, $decimals), $locale ?? app()->getLocale(), null, $decimals)]);
    }

    public static function gigabytes(int $bytes, ?string $locale = null): string
    {
        return __('bytes.gb', ['n' => self::number(round($bytes / self::MB / 1000, 1), $locale ?? app()->getLocale(), null, 1)]);
    }

    private static function number(int|float $value, string $locale, ?int $precision, ?int $maxPrecision): string
    {
        return Cast::string(Number::format($value, precision: $precision, maxPrecision: $maxPrecision, locale: $locale));
    }
}
