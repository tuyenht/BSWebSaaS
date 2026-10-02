<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Domains;

use App\Modules\Tenancy\Models\Domain;

/**
 * A site always has exactly one primary domain. When the domain that was primary goes away, the
 * site's platform subdomain — which cannot be removed — takes over, so the site never ends up
 * without an address. Call inside the transaction that removes the old primary.
 */
final class PrimaryDomain
{
    public static function fallBackToSubdomain(int $siteId): void
    {
        $hasPrimary = Domain::query()->where('site_id', $siteId)->where('is_primary', true)->exists();

        if ($hasPrimary) {
            return;
        }

        $subdomain = Domain::query()
            ->where('site_id', $siteId)
            ->where('kind', 'subdomain')
            ->orderBy('id')
            ->first();

        if ($subdomain !== null) {
            $subdomain->forceFill(['is_primary' => true])->save();
        }
    }
}
