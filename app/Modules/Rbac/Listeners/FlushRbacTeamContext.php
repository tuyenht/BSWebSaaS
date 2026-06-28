<?php

namespace App\Modules\Rbac\Listeners;

use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Support\PlatformAccess;

/**
 * Octane hygiene (mirrors Gate #1's FlushTenancyContext): clear any team-context
 * override and the PlatformAccess isSuperAdmin memo at the end of every
 * request/task/tick so neither can bleed into the next one on the same worker.
 */
final class FlushRbacTeamContext
{
    public function handle(object $event): void
    {
        app(TeamContext::class)->forget();
        app(PlatformAccess::class)->forgetMemo();
    }
}
