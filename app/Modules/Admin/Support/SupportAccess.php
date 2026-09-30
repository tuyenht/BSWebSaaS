<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use App\Modules\Audit\Facades\Audit;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\SupportSession;
use Illuminate\Support\Facades\Gate;

/**
 * The website owner's switch "allow support staff to sign in as a member" (design.md v2.34), on by
 * default. Read and written in the current site's context. Staff signed in for support can never
 * flip it — they would be switching their own access back on.
 */
final readonly class SupportAccess
{
    private const string GROUP = 'support_access';

    public function __construct(
        private SettingsRepository $settings,
        private SupportSession $support,
    ) {}

    public function allowed(): bool
    {
        return (bool) (($this->settings->get(self::GROUP) ?? [])['enabled'] ?? true);
    }

    public function set(User $actor, bool $enabled): void
    {
        $this->support->forbid();
        Gate::forUser($actor)->authorize('settings.manage');

        $this->settings->put(self::GROUP, 'config', ['enabled' => $enabled]);
        Audit::record('settings.support_access.changed', ['meta' => ['enabled' => $enabled]]);
    }
}
