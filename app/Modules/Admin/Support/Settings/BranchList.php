<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support\Settings;

use App\Modules\Media\Models\Media;
use App\Modules\Media\Models\Mediable;
use App\Modules\Shared\Support\Cast;
use App\Modules\Shared\Support\SafeLink;
use App\Modules\Theme\Chrome\StorefrontChrome;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one check of the website's branches and factories, the list kept in `storefront.footer`
 * under `factories` (the footer shows the first StorefrontChrome::MAX_FACTORIES of them; a branch
 * map and a branch card list read them all through StorefrontChrome::branches()). Run by the
 * content-pack loader, and meant for the settings screen too, so both store the same shape.
 *
 * checked() looks at the input only (no database): at most MAX_ITEMS items; each an object with
 * only the known fields (FIELDS) and a title; a `key` (a short slug, unique in the list); texts as
 * a plain string or a {locale: text} map of at most StorefrontChrome::MAX_FOOTER_TEXT characters;
 * a whole number of m²; a map link the footer may show; a map search text of at most
 * MAX_MAP_QUERY characters; latitude −90…90 and longitude −180…180 given together, with at most
 * MAX_DECIMALS decimals; `approximate` true or false.
 *
 * The picture (`image_media_id`) comes in as a library file key, never a row id: stored() looks the
 * key up through the site's media scope (BelongsToSite, RLS underneath) and keeps the id of a
 * picture of this site, so another site's file, one in the trash or a file that is not a picture
 * is refused (image_invalid). A whole number there is an id the list already holds (the stored
 * list saved again): it stays while it still names a picture of this site, the trash included,
 * and is dropped otherwise, so a list whose picture was purged still saves, that item without it.
 *
 * syncUsage() writes the library's "in use" rows of the pictures (`mediables`, owner the setting
 * row `site_setting`, collection COLLECTION), in the caller's transaction, so a factory photo is
 * never shown unused or offered for purging while the list names it.
 *
 * Refusals are SettingsChangeRejected with `field` = "factories", "factories.N" or
 * "factories.N.<field>" (N from 0) and `reason`: branches_invalid, branches_too_many,
 * branch_invalid, field_invalid, key_invalid, key_duplicate, title_required, text_invalid,
 * area_invalid, map_url_invalid, map_query_invalid, lat_invalid, lng_invalid,
 * coordinates_invalid, approximate_invalid, image_invalid.
 *
 * Holds no state: every method is static, so it is Octane-safe.
 */
final class BranchList
{
    /** The collection of the library's "in use" rows a branch picture is recorded under. */
    public const string COLLECTION = 'branch';

    public const int MAX_ITEMS = StorefrontChrome::MAX_BRANCHES;

    public const int MAX_MAP_QUERY = StorefrontChrome::MAX_MAP_QUERY;

    public const int MAX_DECIMALS = 7;

    /** The fields an item may carry: the footer's own, then the branch details. */
    public const array FIELDS = [
        'title', 'area_m2', 'name', 'address', 'map_url',
        'key', 'full_name', 'locality', 'image_media_id', 'map_query', 'lat', 'lng', 'approximate',
    ];

    private const array TEXTS = ['title', 'name', 'address', 'full_name', 'locality'];

    /**
     * @return list<array<string, mixed>> the items as given (null = no list)
     *
     * @throws SettingsChangeRejected
     */
    public static function checked(mixed $factories): array
    {
        if ($factories === null) {
            return [];
        }
        if (! is_array($factories) || ! array_is_list($factories)) {
            throw new SettingsChangeRejected('branches_invalid', 'factories');
        }
        if (count($factories) > self::MAX_ITEMS) {
            throw new SettingsChangeRejected('branches_too_many', 'factories');
        }

        $mapHosts = Cast::strings(config('cms.map_hosts'));
        $keys = [];
        $items = [];
        foreach ($factories as $index => $item) {
            $at = "factories.{$index}";
            if (! is_array($item) || ($item !== [] && array_is_list($item))) {
                throw new SettingsChangeRejected('branch_invalid', $at);
            }
            foreach (array_keys($item) as $field) {
                if (! in_array($field, self::FIELDS, true)) {
                    throw new SettingsChangeRejected('field_invalid', "{$at}.{$field}");
                }
            }

            $key = $item['key'] ?? null;
            if ($key !== null) {
                if (! is_string($key) || preg_match(StorefrontChrome::BRANCH_KEY_PATTERN, $key) !== 1) {
                    throw new SettingsChangeRejected('key_invalid', "{$at}.key");
                }
                if (isset($keys[$key])) {
                    throw new SettingsChangeRejected('key_duplicate', "{$at}.key");
                }
                $keys[$key] = true;
            }

            foreach (self::TEXTS as $field) {
                if (! self::isText($item[$field] ?? null)) {
                    throw new SettingsChangeRejected('text_invalid', "{$at}.{$field}");
                }
            }
            if (! self::hasText($item['title'] ?? null)) {
                throw new SettingsChangeRejected('title_required', "{$at}.title");
            }

            $area = $item['area_m2'] ?? null;
            if ($area !== null && ! (is_int($area) && $area >= 1 && $area <= 10_000_000)) {
                throw new SettingsChangeRejected('area_invalid', "{$at}.area_m2");
            }

            $mapUrl = $item['map_url'] ?? null;
            if ($mapUrl !== null) {
                $href = SafeLink::href($mapUrl, $mapHosts);
                if ($href === null || str_starts_with($href, '/')) {
                    throw new SettingsChangeRejected('map_url_invalid', "{$at}.map_url");
                }
            }

            $query = $item['map_query'] ?? null;
            if ($query !== null && ! (is_string($query) && trim($query) !== '' && mb_strlen($query) <= self::MAX_MAP_QUERY && preg_match('/[\x00-\x1F\x7F]/u', $query) === 0)) {
                throw new SettingsChangeRejected('map_query_invalid', "{$at}.map_query");
            }

            self::coordinates($item, $at);

            if (array_key_exists('approximate', $item) && ! is_bool($item['approximate'])) {
                throw new SettingsChangeRejected('approximate_invalid', "{$at}.approximate");
            }

            $image = $item['image_media_id'] ?? null;
            if ($image !== null && ! (is_int($image) && $image >= 1) && ! (is_string($image) && trim($image) !== '')) {
                throw new SettingsChangeRejected('image_invalid', "{$at}.image_media_id");
            }

            /** @var array<string, mixed> $item every field is one of FIELDS */
            $items[] = $item;
        }

        return $items;
    }

    /**
     * The list as it is stored: checked(), then every picture turned into the id of a picture of
     * this site (see the class note). At most two queries, none when no item has a picture.
     *
     * @return list<array<string, mixed>>
     *
     * @throws SettingsChangeRejected
     */
    public static function stored(mixed $factories): array
    {
        $items = self::checked($factories);

        $keys = [];
        $ids = [];
        foreach ($items as $item) {
            $image = $item['image_media_id'] ?? null;
            if (is_string($image)) {
                $keys[] = trim($image);
            } elseif (is_int($image)) {
                $ids[] = $image;
            }
        }

        $byKey = [];
        if ($keys !== []) {
            $found = Media::query()->whereIn('storage_key', array_values(array_unique($keys)))
                ->where('mime', 'like', 'image/%')
                ->get(['id', 'storage_key']);
            foreach ($found as $media) {
                $byKey[$media->storage_key] = $media->id;
            }
        }
        $kept = $ids === [] ? [] : array_flip(self::visibleImages($ids));

        foreach ($items as $index => $item) {
            $image = $item['image_media_id'] ?? null;
            if (is_string($image)) {
                $items[$index]['image_media_id'] = $byKey[trim($image)] ?? throw new SettingsChangeRejected('image_invalid', "factories.{$index}.image_media_id");
            } elseif (is_int($image) && ! isset($kept[$image])) {
                unset($items[$index]['image_media_id']);
            }
        }

        return $items;
    }

    /**
     * Make the setting row's "in use" rows match the pictures its stored list names: rows for
     * pictures no longer named are removed, missing ones added (idempotent). Ids that do not name a
     * picture of this site are skipped. Runs inside the caller's transaction.
     */
    public static function syncUsage(int $settingId, mixed $factories): void
    {
        $named = [];
        foreach (Cast::arr($factories) as $item) {
            $image = Cast::arr($item)['image_media_id'] ?? null;
            if (is_int($image) && $image >= 1) {
                $named[] = $image;
            }
        }
        $mediaIds = $named === [] ? [] : self::visibleImages($named);

        $rows = fn (): Builder => Mediable::query()
            ->where('mediable_type', 'site_setting')
            ->where('mediable_id', $settingId)
            ->where('collection', self::COLLECTION);

        $rows()->when($mediaIds !== [], fn (Builder $query) => $query->whereNotIn('media_id', $mediaIds))->delete();
        if ($mediaIds === []) {
            return;
        }

        $recorded = [];
        foreach ($rows()->pluck('media_id') as $id) {
            $recorded[] = Cast::int($id);
        }
        foreach (array_diff($mediaIds, $recorded) as $id) {
            Mediable::query()->createOrFirst([
                'media_id' => $id,
                'mediable_type' => 'site_setting',
                'mediable_id' => $settingId,
                'collection' => self::COLLECTION,
            ]);
        }
    }

    /**
     * The ids among $ids that name a picture of this site, the trash included (a restorable
     * picture keeps its place). One query, site-scoped.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private static function visibleImages(array $ids): array
    {
        $visible = [];
        foreach (Media::withTrashed()->whereIn('id', array_values(array_unique($ids)))->where('mime', 'like', 'image/%')->pluck('id') as $id) {
            $visible[] = Cast::int($id);
        }

        return $visible;
    }

    /**
     * Latitude and longitude come together: both numbers in range with at most MAX_DECIMALS
     * decimals, or neither.
     *
     * @param  array<array-key, mixed>  $item
     *
     * @throws SettingsChangeRejected
     */
    private static function coordinates(array $item, string $at): void
    {
        $lat = $item['lat'] ?? null;
        $lng = $item['lng'] ?? null;
        if ($lat !== null && ! self::degrees($lat, 90)) {
            throw new SettingsChangeRejected('lat_invalid', "{$at}.lat");
        }
        if ($lng !== null && ! self::degrees($lng, 180)) {
            throw new SettingsChangeRejected('lng_invalid', "{$at}.lng");
        }
        if (($lat === null) !== ($lng === null)) {
            throw new SettingsChangeRejected('coordinates_invalid', $lat === null ? "{$at}.lat" : "{$at}.lng");
        }
    }

    private static function degrees(mixed $value, int $limit): bool
    {
        if (! is_int($value) && ! is_float($value)) {
            return false;
        }
        if (! is_finite((float) $value) || abs($value) > $limit) {
            return false;
        }
        if (is_int($value)) {
            return true;
        }

        // The shortest decimal that reads back as this number, the form JSON stores: 21.0655337 has
        // 7 decimals. A form with an exponent (1.0e-13) is too fine by itself.
        $digits = json_encode($value);
        if (! is_string($digits) || preg_match('/\A-?\d+(?:\.(\d+))?\z/', $digits, $m) !== 1) {
            return false;
        }

        return strlen($m[1] ?? '') <= self::MAX_DECIMALS;
    }

    /** A plain string, or a {locale: text} map of strings; each at most the footer's length. */
    private static function isText(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        $texts = is_array($value) && ! array_is_list($value) ? $value : [$value];
        foreach ($texts as $locale => $text) {
            if (! is_string($locale) && is_array($value)) {
                return false;
            }
            if (! is_string($text) || mb_strlen(trim($text)) > StorefrontChrome::MAX_FOOTER_TEXT) {
                return false;
            }
        }

        return true;
    }

    private static function hasText(mixed $value): bool
    {
        foreach (is_array($value) ? $value : [$value] as $text) {
            if (is_string($text) && trim($text) !== '') {
                return true;
            }
        }

        return false;
    }
}
