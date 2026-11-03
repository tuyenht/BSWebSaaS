<?php

declare(strict_types=1);

namespace App\Modules\Audit\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The database would not drop an audit log month. `reason`: `kept` (the running month or one of
 * the 12 before it), `busy` (the log stayed locked past the wait), `invalid` (not a month),
 * `missing` (the month no longer exists, e.g. removed a moment before by someone else) or
 * `unrecorded` (the database did not find the audit row just written: its clock or time zone
 * disagrees with the app's).
 */
final class AuditMonthRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct("Audit log month not dropped: {$reason}", 0, $previous);
    }
}
