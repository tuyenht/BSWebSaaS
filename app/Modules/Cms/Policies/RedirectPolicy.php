<?php

declare(strict_types=1);

namespace App\Modules\Cms\Policies;

use App\Modules\Cms\Routing\RedirectRules;
use App\Modules\Identity\Models\User;

/**
 * Defense layer #2 for the website's redirects («Chuyển hướng»): they are a site setting, so every
 * change needs `settings.manage` (team-scoped to the current site), as the other settings screens.
 *
 * A redirect that sends visitors to ANOTHER website also needs the website's `redirects.external`
 * switch on. super_admin passes every ability through Gate::before, so the switch is not a matter
 * of who asks: callers check RedirectRules::externalOn() as well (see Settings\Redirects).
 */
final class RedirectPolicy
{
    public function manage(User $user): bool
    {
        return $user->can('settings.manage');
    }

    /** Create or edit a redirect whose new address is on another website. */
    public function external(User $user): bool
    {
        return $this->manage($user) && app(RedirectRules::class)->externalOn();
    }
}
