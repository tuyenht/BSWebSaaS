<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Actions;

use App\Modules\Catalog\Support\ContactPageSetting;
use App\Modules\Tenancy\Models\SiteSetting;

/**
 * Records which page is the current site's contact page (the setting the product page's quote
 * button follows). Runs inside the site's tenant context. It only fills a missing value: a site
 * whose setting already names a page keeps it.
 */
final class LinkContactPage
{
    /** True when the setting was written, false when the site already had one. */
    public function handle(string $entryUlid): bool
    {
        $setting = SiteSetting::query()->firstOrCreate(
            ['group_name' => ContactPageSetting::GROUP, 'key' => ContactPageSetting::KEY],
            ['value' => ['entry' => $entryUlid]],
        );

        return $setting->wasRecentlyCreated;
    }
}
