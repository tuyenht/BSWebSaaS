<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Dashboard;

use App\Modules\Media\Support\MediaStorage;
use App\Modules\Shared\Support\Bytes;

/**
 * The «Dung lượng thư viện» card of "Tổng quan" (design board 02) put into figures, from the bytes
 * the media library's meter reads (MediaStorage::usage(): the running total `media.bytes` against the
 * plan's `media.storage_mb`).
 *
 * The percent and the warning are the library meter's, from the one shared reading
 * (MediaStorage::share()): rounded down, at most 100 — a website above its plan (while uploads past
 * the plan are not refused) and a plan with no room both read 100 % — and «Sắp đầy» from 80 %,
 * exactly when the library's meter warns. Sizes are in GB as the board writes them: megabytes over
 * a thousand, to one decimal («1,2 GB», «2 GB»), through the shared Bytes formatter.
 *
 * No «full» state (the library's «chưa tải thêm được»): board 02 draws none, and it would only be
 * true while MediaStorage::enforcing() refuses uploads. Add it, from that switch, when it is turned on.
 */
final class StorageCard
{
    /**
     * @return array{pct: int, warn: bool, used: string, limit: string}
     */
    public static function figures(int $usedBytes, int $limitBytes, string $locale): array
    {
        $share = MediaStorage::share($usedBytes, $limitBytes);

        return [
            'pct' => $share['pct'],
            'warn' => $share['warn'],
            'used' => Bytes::gigabytes(max(0, $usedBytes), $locale),
            'limit' => Bytes::gigabytes(max(0, $limitBytes), $locale),
        ];
    }
}
