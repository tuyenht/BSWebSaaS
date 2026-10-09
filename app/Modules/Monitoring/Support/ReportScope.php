<?php

declare(strict_types=1);

namespace App\Modules\Monitoring\Support;

use Throwable;
use WeakMap;

/**
 * What the reporter remembers for the current request, job or command — a SCOPED binding, so
 * Octane drops it between requests and the queue worker between jobs. Never a static property.
 *
 * - which exceptions were already counted (a failed job or command is counted by its own event,
 *   and the same exception reaching report() a moment later must not count twice);
 * - the job or command being run, to name it in a notice, and the site a job runs for;
 * - a re-entry guard, so a failure inside the reporter can never report itself.
 */
final class ReportScope
{
    /** @var WeakMap<Throwable, true> */
    private WeakMap $counted;

    private bool $busy = false;

    private ?string $job = null;

    private ?int $jobSiteId = null;

    private ?string $command = null;

    public function __construct()
    {
        $this->counted = new WeakMap;
    }

    public function markCounted(Throwable $e): void
    {
        $this->counted[$e] = true;
    }

    public function wasCounted(Throwable $e): bool
    {
        return isset($this->counted[$e]);
    }

    /** Claim the reporter for one report; false when it is already running (re-entry). */
    public function enter(): bool
    {
        if ($this->busy) {
            return false;
        }

        return $this->busy = true;
    }

    public function leave(): void
    {
        $this->busy = false;
    }

    /** The job being run and the site it runs for (its own site id, read when it starts). */
    public function setJob(?string $job, ?int $siteId = null): void
    {
        $this->job = $job;
        $this->jobSiteId = $job !== null ? $siteId : null;
    }

    public function job(): ?string
    {
        return $this->job;
    }

    public function jobSiteId(): ?int
    {
        return $this->jobSiteId;
    }

    public function setCommand(?string $command): void
    {
        $this->command = $command;
    }

    public function command(): ?string
    {
        return $this->command;
    }
}
