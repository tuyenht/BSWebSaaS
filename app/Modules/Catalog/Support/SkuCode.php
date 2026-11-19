<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

use App\Modules\Shared\Support\PlainText;

/**
 * The one spelling of a product code (SKU): upper-case, every run of spaces turned into «-».
 * The editor's input does the same as the member types; the server applies it again on save and
 * when it looks for another product holding the code, so a save without JavaScript stores the same
 * value and the unique index (which compares exactly) sees one spelling.
 */
final class SkuCode
{
    public const int MAX = 64;

    /** Trimmed, upper-cased, spaces to «-». Not cut: a value over MAX is refused by validation, never silently shortened. */
    public static function normalize(string $value): string
    {
        $value = mb_strtoupper(PlainText::line($value));

        return preg_replace('/\s+/u', '-', $value) ?? $value;
    }
}
