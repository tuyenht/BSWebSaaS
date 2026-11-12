<?php

declare(strict_types=1);

namespace App\Modules\Admin\Errors;

use App\Modules\Tenancy\Context\SiteTime;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\FileBasedMaintenanceMode;
use Throwable;

/**
 * When the platform's maintenance is expected to end: the moment it began plus the wait given to
 * `php artisan down --retry=<seconds>`. A fixed time, not a countdown — every reload shows the same
 * one. Unknown (no wait given, or a store that does not keep the start) = null, and the page then
 * names no time. Reads only the maintenance store: no database, no site (it is platform-wide).
 */
final class MaintenanceWindow
{
    public static function expectedEnd(): ?CarbonImmutable
    {
        try {
            $mode = app()->maintenanceMode();
            if (! $mode->active() || ! $mode instanceof FileBasedMaintenanceMode) {
                return null;
            }
            $retry = $mode->data()['retry'] ?? null;
            $file = storage_path('framework/down');
            $began = is_file($file) ? filemtime($file) : false;
            if (! is_numeric($retry) || (int) $retry <= 0 || $began === false) {
                return null;
            }

            return CarbonImmutable::createFromTimestamp($began + (int) $retry, SiteTime::DEFAULT_ZONE);
        } catch (Throwable) {
            return null;
        }
    }
}
