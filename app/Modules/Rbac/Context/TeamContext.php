<?php

namespace App\Modules\Rbac\Context;

/**
 * Shared holder for the spatie team-context override. State lives HERE (a container
 * singleton), not on the resolver — because spatie's PermissionRegistrar constructs
 * its own resolver via `new` (bypassing the container), so the resolver must be
 * stateless and read the override from this single shared instance. Reset per
 * request by FlushRbacTeamContext (Octane hygiene).
 */
final class TeamContext
{
    private ?int $teamId = null;

    private bool $forced = false;

    public function force(?int $teamId): void
    {
        $this->teamId = $teamId;
        $this->forced = true;
    }

    public function isForced(): bool
    {
        return $this->forced;
    }

    public function teamId(): ?int
    {
        return $this->teamId;
    }

    /**
     * @return array{0: int|null, 1: bool}
     */
    public function snapshot(): array
    {
        return [$this->teamId, $this->forced];
    }

    /**
     * @param  array{0: int|null, 1: bool}  $state
     */
    public function restore(array $state): void
    {
        [$this->teamId, $this->forced] = $state;
    }

    public function forget(): void
    {
        $this->teamId = null;
        $this->forced = false;
    }
}
