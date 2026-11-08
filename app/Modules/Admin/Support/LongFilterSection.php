<?php

namespace App\Modules\Admin\Support;

use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\VietnameseFold;

/**
 * A long Filter section: past six options only the first five (and every chosen one) show, with a
 * "Xem thêm N …" button for the rest; past fifteen the section has its own search box, which narrows
 * the options (accents and case ignored) but never hides a chosen one.
 */
final class LongFilterSection
{
    /** Past this many options the first {@see self::HEAD} show. */
    public const int SHORT = 6;

    public const int HEAD = 5;

    /** Past this many options the section gets a search box. */
    public const int SEARCHABLE = 15;

    /**
     * @param  list<array<string, mixed>>  $options  each with a string `label` and a bool `active`
     * @param  bool  $showAll  the member asked for the rest
     * @param  string  $words  what the search box holds
     * @return array{options: list<array<string, mixed>>, searchable: bool, hidden: int}
     */
    public static function fit(array $options, bool $showAll, string $words): array
    {
        $searchable = count($options) > self::SEARCHABLE;
        $needle = trim($words);
        $list = $options;
        if ($searchable && $needle !== '') {
            $folded = VietnameseFold::text(mb_strtolower($needle));
            $list = array_values(array_filter($options, fn (array $o): bool => ($o['active'] ?? false) === true
                || str_contains(VietnameseFold::text(mb_strtolower(Cast::string($o['label'] ?? ''))), $folded)));
        }
        if (count($list) > self::SHORT && ! $showAll) {
            $shown = array_values(array_filter($list, fn (array $o, int $i): bool => $i < self::HEAD || ($o['active'] ?? false) === true, ARRAY_FILTER_USE_BOTH));

            return ['options' => $shown, 'searchable' => $searchable, 'hidden' => count($list) - count($shown)];
        }

        return ['options' => $list, 'searchable' => $searchable, 'hidden' => 0];
    }
}
