<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

/**
 * The platform switch "upgrade invitation" (owner 29/09; design.md §15.2). OFF by default: a
 * feature outside a website's plan stays hidden, or shows a neutral lock with no offer. ON — once
 * the plans are worth choosing between — the same feature shows locked with its benefit and a way
 * to see the plans.
 *
 * Stored with the other platform-wide settings (`platform_settings`, group `plans`). Read where a
 * locked feature is drawn; a screen showing several asks once and passes the answer down.
 */
final readonly class UpgradeInvitation
{
    private const string GROUP = 'plans';

    public function __construct(private PlatformSettings $settings) {}

    public function isOn(): bool
    {
        return ($this->settings->get(self::GROUP)['upgrade_invitation'] ?? false) === true;
    }

    public function set(bool $on): void
    {
        $this->settings->put(self::GROUP, 'config', ['upgrade_invitation' => $on] + ($this->settings->get(self::GROUP) ?? []));
    }
}
