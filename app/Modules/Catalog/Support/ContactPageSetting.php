<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

use App\Modules\Tenancy\Context\SiteSettings;

/**
 * Which page of the current site is its contact page, for the product page's quote button: the
 * page's entry ulid, kept in the site setting `catalog` / `contact_page` as {"entry": "<ulid>"}.
 * The page is named by its entry, never by its path, so renaming its slug or giving another
 * language its own slug does not lose it. Reads go through SiteSettings, so only this site's row
 * is ever seen.
 */
final readonly class ContactPageSetting
{
    public const string GROUP = 'catalog';

    public const string KEY = 'contact_page';

    public function __construct(private SiteSettings $settings) {}

    /** The contact page's entry ulid, or null when the site has none set. */
    public function ulid(): ?string
    {
        $entry = $this->settings->value(self::GROUP, self::KEY)['entry'] ?? null;

        return is_string($entry) && $entry !== '' ? $entry : null;
    }
}
