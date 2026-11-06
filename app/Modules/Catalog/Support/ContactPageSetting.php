<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Support;

/**
 * Which page of a site is its contact page, for the product page's quote button: the page's entry
 * ulid, kept in the site setting `catalog` / `contact_page` as {"entry": "<ulid>"}. The page is
 * named by its entry, never by its path, so renaming its slug or giving another language its own
 * slug does not lose it. The storefront reads the value with its other settings
 * (StorefrontSettings, one query for the page); this class only names the setting and reads the
 * ulid out of the stored value.
 */
final class ContactPageSetting
{
    public const string GROUP = 'catalog';

    public const string KEY = 'contact_page';

    /** The contact page's entry ulid out of the stored value, or null when there is none. */
    public static function ulid(mixed $value): ?string
    {
        $entry = is_array($value) ? ($value['entry'] ?? null) : null;

        return is_string($entry) && $entry !== '' ? $entry : null;
    }
}
