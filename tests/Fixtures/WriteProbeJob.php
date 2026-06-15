<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Modules\Tenancy\Jobs\WithinSite;
use App\Modules\Tenancy\Models\TenantProbe;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** A tenant-aware test job: writes a probe inside its own site context. */
final class WriteProbeJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $siteId, public string $value) {}

    public function handle(): void
    {
        TenantProbe::create(['value' => $this->value]);
    }

    /** @return array<int, WithinSite> */
    public function middleware(): array
    {
        return [new WithinSite($this->siteId)];
    }
}
