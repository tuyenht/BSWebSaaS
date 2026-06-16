<?php

namespace App\Modules\Rbac\Listeners;

use App\Modules\Rbac\Context\TeamContext;

/**
 * Octane hygiene (mirrors Gate #1's FlushTenancyContext): clear any team-context
 * override at the end of every request/task/tick so it can never bleed into the
 * next one served by the same worker.
 */
final class FlushRbacTeamContext
{
    public function handle(object $event): void
    {
        app(TeamContext::class)->forget();
    }
}
