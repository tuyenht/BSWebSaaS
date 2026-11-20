<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire\Blocks\Concerns;

use App\Modules\Cms\Models\ContentBlock;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Entitlement\Entitlement;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * The gate of the block library screens (the list and the block editor), asked on every request
 * because the livewire/update endpoint skips the route: the plan's `blocks` module, then
 * ContentBlockPolicy::create — `blocks.manage`, for a member not limited to some categories (one
 * change shows on every page and menu that uses the block). The actions ask the policy again.
 */
trait GatesBlockScreens
{
    public function boot(): void
    {
        $this->authorizeLibrary();
    }

    private function authorizeLibrary(): void
    {
        abort_unless(in_array('blocks', app(Entitlement::class)->entitledModuleKeys(), true), 403);
        Gate::authorize('create', ContentBlock::class);
    }

    private function actingUser(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
