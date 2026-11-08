<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

use DateTimeInterface;

/**
 * How long a product stays in the Trash: the one number the nightly purge and the products list share.
 */
final class ProductTrash
{
    /** Days a product is kept in the Trash before it is deleted for good. */
    public const int DAYS = 30;

    /** Days still left before the purge: 30 minus the whole days spent in the Trash, never below 0. */
    public static function daysLeft(DateTimeInterface $trashedAt, DateTimeInterface $now): int
    {
        $spent = intdiv($now->getTimestamp() - $trashedAt->getTimestamp(), 86400);

        return max(0, self::DAYS - $spent);
    }
}
