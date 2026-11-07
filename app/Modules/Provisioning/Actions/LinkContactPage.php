<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Actions;

use App\Modules\Catalog\Support\ContactPageSetting;
use App\Modules\Cms\Models\Entry;
use App\Modules\Tenancy\Models\SiteSetting;

/**
 * Records which page is the current site's contact page (the setting the product page's quote
 * button follows). Runs inside the site's tenant context. A setting that names a page of the site
 * (a trashed page still counts) is never replaced; a missing one, or one whose page is gone for
 * good, is set to the given page.
 */
final class LinkContactPage
{
    public const string KEPT = 'kept';

    public const string LINKED = 'linked';

    public const string RELINKED = 'relinked';

    /**
     * linked: there was no value; relinked: the old page is gone; kept: the site already names a
     * page of its own.
     *
     * @return self::KEPT|self::LINKED|self::RELINKED
     */
    public function handle(string $entryUlid): string
    {
        $setting = SiteSetting::query()
            ->where('group_name', ContactPageSetting::GROUP)
            ->where('key', ContactPageSetting::KEY)
            ->first();

        if ($setting === null) {
            SiteSetting::query()->create([
                'group_name' => ContactPageSetting::GROUP,
                'key' => ContactPageSetting::KEY,
                'value' => ['entry' => $entryUlid],
            ]);

            return self::LINKED;
        }

        $current = ContactPageSetting::ulid($setting->value);
        if ($current !== null && Entry::withTrashed()->where('ulid', $current)->exists()) {
            return self::KEPT;
        }

        $setting->update(['value' => ['entry' => $entryUlid]]);

        return self::RELINKED;
    }
}
