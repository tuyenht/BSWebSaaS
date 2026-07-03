<?php

declare(strict_types=1);

namespace App\Modules\Audit\Facades;

use App\Modules\Audit\AuditService;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void record(string $action, array<string, mixed> $context = [])
 *
 * @see AuditService
 */
class Audit extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AuditService::class;
    }
}
