<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Context;

use App\Modules\Tenancy\Models\Site;

/**
 * Resolves the current site's default locale — the single source the storefront and
 * the admin both read, so the read/write/render paths never disagree on which
 * translation a site means. Falls back to 'vi' with no site context or no value.
 */
final class SiteLocale
{
    public function __construct(private readonly CurrentSite $current) {}

    public function default(): string
    {
        if (! $this->current->hasId()) {
            return 'vi';
        }

        $locale = Site::query()->whereKey($this->current->id())->value('default_locale');

        return is_string($locale) && $locale !== '' ? $locale : 'vi';
    }
}
