<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use App\Modules\Rbac\Support\RoleLabel;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Models\Site;

/**
 * What the bar on top of a website's admin says while platform staff are signed in as one of its
 * members: the member, the customer and the member's role, and when the session ends. Read per
 * request from the host's session (SupportSession) — nothing is kept on this object.
 */
final readonly class SupportBar
{
    public function __construct(
        private SupportSession $support,
        private CurrentSite $current,
    ) {}

    /**
     * @return array{member: string, customer: string, role: string|null, ends_at: int}|null
     */
    public function facts(): ?array
    {
        $session = $this->support->current();
        $user = auth()->user();
        if ($session === null || ! $user instanceof User || ! $this->current->hasId() || $session['site_id'] !== $this->current->id()) {
            return null;
        }

        $site = Site::query()->with('tenant:id,name')->find($this->current->id(), ['id', 'name', 'tenant_id']);
        $customer = $site instanceof Site ? Cast::string($site->tenant?->getAttribute('name')) : '';
        if ($customer === '' && $site instanceof Site) {
            $customer = Cast::string($site->getAttribute('name'));
        }

        return [
            'member' => SupportSession::labelOf($user),
            'customer' => $customer,
            'role' => RoleLabel::topOf($user),
            'ends_at' => $session['expires_at'],
        ];
    }
}
