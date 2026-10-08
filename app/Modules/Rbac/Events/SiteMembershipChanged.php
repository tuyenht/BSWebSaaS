<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Events;

use App\Modules\Identity\Models\User;

/**
 * A member's roles on a site changed: a role was granted or revoked (their last one included, when
 * they leave). Rbac only announces it; modules keeping per-member settings for the site re-check
 * them against the member's roles now. Dispatched synchronously inside the granting or revoking
 * transaction (not after commit), so those settings change with the role, or neither does.
 */
final readonly class SiteMembershipChanged
{
    public function __construct(
        public User $member,
        public int $siteId,
    ) {}
}
