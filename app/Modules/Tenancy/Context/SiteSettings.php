<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Context;

use App\Modules\Tenancy\Models\SiteSetting;
use LogicException;

/**
 * Read surface over the current site's grouped settings (site_settings) for feature modules, which
 * must not reach into Tenancy\Models (app/Modules/README.md). One query, scoped to the current site
 * by BelongsToSite with RLS underneath; holds no state.
 *
 * Only the groups listed in READABLE_GROUPS can be read here. Some groups hold credentials (the
 * site's SMTP account in `email`, OAuth client secrets in `oauth_social`), encrypted but still not
 * for every module to load, so a group is readable only once it is added here on purpose; any
 * other group is a programming error and throws. The Admin shell keeps its own repository.
 */
final readonly class SiteSettings
{
    /** @var list<string> */
    public const array READABLE_GROUPS = ['catalog'];

    /**
     * The stored value of $group/$key, or null when the site has no such setting.
     *
     * @return array<array-key, mixed>|null
     */
    public function value(string $group, string $key): ?array
    {
        if (! in_array($group, self::READABLE_GROUPS, true)) {
            throw new LogicException("Site setting group '{$group}' is not readable through SiteSettings.");
        }

        $setting = SiteSetting::query()
            ->where('group_name', $group)
            ->where('key', $key)
            ->first(['value']);

        // Read untyped: a row whose JSON is a bare scalar must read as absent, not as a value.
        $value = $setting instanceof SiteSetting ? $setting->getAttribute('value') : null;

        return is_array($value) ? $value : null;
    }
}
