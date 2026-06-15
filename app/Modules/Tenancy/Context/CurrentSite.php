<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Context;

use RuntimeException;

/**
 * Holds the site resolved for the current request/job. Registered as a SCOPED
 * container binding so Octane flushes it between operations; the explicit
 * FlushTenancyContext listener is the belt-and-suspenders second layer.
 */
final class CurrentSite
{
    private ?int $siteId = null;

    public function setId(int $siteId): void
    {
        $this->siteId = $siteId;
    }

    public function id(): int
    {
        if ($this->siteId === null) {
            throw new RuntimeException('No current site has been resolved.');
        }

        return $this->siteId;
    }

    public function hasId(): bool
    {
        return $this->siteId !== null;
    }

    public function clear(): void
    {
        $this->siteId = null;
    }
}
