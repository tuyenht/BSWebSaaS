<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Support;

use App\Modules\Rbac\Models\Invitation;
use SensitiveParameter;

/**
 * An invitation just issued or refreshed, with the plain token that goes into its mail link —
 * the only moment the token exists outside the recipient's inbox (the row keeps its hash). The
 * caller that owns mail delivery sends it; nothing stores it.
 */
final readonly class IssuedInvitation
{
    public function __construct(
        public Invitation $invitation,
        #[SensitiveParameter] public string $token,
    ) {}
}
