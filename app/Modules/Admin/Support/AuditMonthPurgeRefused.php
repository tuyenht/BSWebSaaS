<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use Illuminate\Validation\ValidationException;

/**
 * PurgeAuditMonth would not delete the month. The message sits under `month` as for any other
 * validation error; `reason` says why for callers that act on it: `kept` (the running month or
 * one of the 12 before it), `missing` (no such month, e.g. deleted a moment before), `busy`,
 * `unrecorded` or `invalid`.
 */
final class AuditMonthPurgeRefused extends ValidationException
{
    public string $reason = 'invalid';

    public static function because(string $reason, string $message): self
    {
        $e = self::withMessages(['month' => $message]);
        $e->reason = $reason;

        return $e;
    }
}
