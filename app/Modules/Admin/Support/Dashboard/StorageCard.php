<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Dashboard;

use App\Modules\Media\Support\MediaStorage;
use App\Modules\Shared\Support\Cast;
use Illuminate\Support\Number;

/**
 * The «Dung lượng thư viện» card of "Tổng quan" (design board 02) put into figures, from the bytes
 * the media library's meter reads (MediaStorage::usage(): the running total `media.bytes` against the
 * plan's `media.storage_mb`).
 *
 * The percent and the warning are the library meter's, from the one shared reading
 * (MediaStorage::share()): rounded down, at most 100 — a website above its plan (while uploads past
 * the plan are not refused) and a plan with no room both read 100 % — and «Sắp đầy» from 80 %,
 * exactly when the library's meter warns. Sizes are in GB as the board writes them: megabytes over
 * a thousand, to one decimal («1,2 GB», «2 GB»).
 *
 * No «full» state (the library's «chưa tải thêm được»): board 02 draws none, and it would only be
 * true while MediaStorage::enforcing() refuses uploads. Add it, from that switch, when it is turned on.
 */
final class StorageCard
{
    private const int MB = 1048576;

    /**
     * @return array{pct: int, warn: bool, used: string, limit: string}
     */
    public static function figures(int $usedBytes, int $limitBytes, string $locale): array
    {
        $share = MediaStorage::share($usedBytes, $limitBytes);

        return [
            'pct' => $share['pct'],
            'warn' => $share['warn'],
            'used' => self::gigabytes(max(0, $usedBytes), $locale),
            'limit' => self::gigabytes(max(0, $limitBytes), $locale),
        ];
    }

    private static function gigabytes(int $bytes, string $locale): string
    {
        $gb = round($bytes / self::MB / 1000, 1);

        return __('dash.st_gb', ['n' => Cast::string(Number::format($gb, maxPrecision: 1, locale: $locale))]);
    }
}
