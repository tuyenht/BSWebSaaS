<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Jobs;

use App\Modules\Tenancy\Context\Tenancy;
use Closure;

/**
 * Job middleware so queued/console work gets the SAME isolation as HTTP requests
 * (design.md §5 — every entry point, not just HTTP). A tenant-aware job returns
 * `[new WithinSite($this->siteId)]` from its middleware() method.
 */
final class WithinSite
{
    public function __construct(private readonly int $siteId) {}

    public function handle(object $job, Closure $next): mixed
    {
        return app(Tenancy::class)->enter($this->siteId, fn () => $next($job));
    }
}
