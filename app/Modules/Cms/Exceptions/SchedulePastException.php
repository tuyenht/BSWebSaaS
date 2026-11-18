<?php

declare(strict_types=1);

namespace App\Modules\Cms\Exceptions;

use InvalidArgumentException;

/**
 * A publish time for «Hẹn giờ đăng» that is missing or not after now (it may have passed while the row
 * was locked). Its own class so a screen can put THIS on the time field: a broad catch of
 * InvalidArgumentException would also swallow an unrelated programming error as "the time is past".
 * Still an InvalidArgumentException, so callers that catch that keep working.
 */
final class SchedulePastException extends InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct(__('entries.err_schedule_in_past'));
    }
}
