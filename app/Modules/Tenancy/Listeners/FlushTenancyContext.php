<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Listeners;

use App\Modules\Tenancy\Context\CurrentSite;

/**
 * Defense layer #3 (design.md §5): explicitly clear the "current site" singleton at
 * the end of every Octane request/task/tick — belt-and-suspenders on top of the
 * scoped binding's automatic flush, so request A never leaks into request B.
 */
final readonly class FlushTenancyContext
{
    public function __construct(private CurrentSite $current) {}

    public function handle(): void
    {
        $this->current->clear();
    }
}
