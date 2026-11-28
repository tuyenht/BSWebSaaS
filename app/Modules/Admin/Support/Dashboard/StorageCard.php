<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Dashboard;

use App\Modules\Shared\Support\Cast;
use Illuminate\Support\Number;

/**
 * The «Dung lượng thư viện» card of "Tổng quan" (design board 02) put into figures, from the bytes
 * the media library's meter reads (MediaStorage::usage(): the running total `media.bytes` against the
 * plan's `media.storage_mb`).
 *
 * The percent is the library meter's: both figures to a tenth of a megabyte, rounded down, so the
 * card says «Sắp đầy» (80 % or more) exactly when the library's meter does. It is not capped: a
 * website above its plan (while uploads past the plan are not refused) reads more than 100 %. A plan
 * with no room at all reads 100 %. Sizes are in GB as the board writes them: megabytes over a
 * thousand, to one decimal («1,2 GB», «2 GB»).
 */
final class StorageCard
{
    /** The share of the plan from which the card warns, in percent. */
    public const int WARN_AT = 80;

    private const int MB = 1048576;

    /**
     * @return array{pct: int, warn: bool, used: string, limit: string}
     */
    public static function figures(int $usedBytes, int $limitBytes, string $locale): array
    {
        $used = max(0, $usedBytes);
        $limit = max(0, $limitBytes);
        $usedTenths = (int) round($used * 10 / self::MB);
        $limitTenths = (int) round($limit * 10 / self::MB);
        $pct = $limitTenths === 0 ? 100 : intdiv($usedTenths * 100, $limitTenths);

        return [
            'pct' => $pct,
            'warn' => $pct >= self::WARN_AT,
            'used' => self::gigabytes($used, $locale),
            'limit' => self::gigabytes($limit, $locale),
        ];
    }

    private static function gigabytes(int $bytes, string $locale): string
    {
        $gb = round($bytes / self::MB / 1000, 1);

        return __('dash.st_gb', ['n' => Cast::string(Number::format($gb, maxPrecision: 1, locale: $locale))]);
    }
}
