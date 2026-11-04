<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\SiteIdentity;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Context\SiteTime;
use App\Modules\Tenancy\Models\Province;
use App\Modules\Tenancy\Models\SiteProfile;
use App\Modules\Tenancy\Models\SiteProfileTranslation;
use App\Modules\Tenancy\Models\SiteSetting;
use App\Modules\Tenancy\Models\Ward;
use App\Modules\Tenancy\Support\BusinessHours;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Reads and writes what the "Thông tin chung" settings screen edits: the site's business identity
 * (site_profiles, shared by every language, + site_profile_translations, one row per language),
 * its timezone and its storefront day format (site_settings group general).
 *
 * The form starts from what the storefront shows today (SiteIdentity), so the first save keeps
 * every value that still lives in the older general.brand setting. A save then writes the profile
 * and takes the NAP keys out of general.brand (logo, favicon and share image stay), which makes the
 * profile the one source from that save on (design.md §14 contract step). The full legal name and
 * the representative are shared values written to site_profiles; the company name the site shows
 * per language (the translation's legal_name, which may be shortened) has no field here and is
 * only ever filled from the older brand name, never overwritten.
 *
 * Every read and write runs in the request's site context: rows are found and created through
 * BelongsToSite (the site id never comes from the browser), with RLS underneath.
 */
final class GeneralSettingsStore
{
    /** The general.brand keys the profile now holds; a save removes them from the setting. */
    public const array BRAND_NAP_KEYS = ['name', 'legal_name', 'description', 'tagline', 'address', 'address_line', 'ward', 'district', 'province',
        'phone', 'hotline', 'email', 'tax_id', 'license', 'license_issued_at', 'social_links', 'representative', 'opening_hours'];

    /** The per-language fields, form key → translation column. */
    public const array LOCALIZED = [
        'name' => 'brand_name',
        'tagline' => 'tagline',
        'description' => 'short_description',
        'license_text' => 'license_text',
        'address_line' => 'address_line',
        'hours_note' => 'opening_hours_text',
    ];

    /** The social networks the screen edits (other keys already in social_links are kept). */
    public const array SOCIAL = ['facebook', 'youtube', 'tiktok', 'zalo'];

    /**
     * The form's starting values.
     *
     * @return array{i18n: array<string, array<string, string>>, shared: array<string, string|bool>}
     */
    public function load(): array
    {
        $locales = app(SiteLocale::class);
        $default = $locales->default();
        $shown = app(SiteIdentity::class)->for($default) ?? [];
        $profile = SiteProfile::query()->first();
        $rows = $profile === null ? collect() : SiteProfileTranslation::query()->where('site_profile_id', $profile->id)->get()->keyBy('locale');

        $text = static fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '';

        $i18n = [];
        foreach ($this->locales() as $locale) {
            $row = $rows->get($locale);
            foreach (self::LOCALIZED as $field => $column) {
                // The source language shows what the site shows; another language only its own text.
                $i18n[$locale][$field] = $locale === $default
                    ? $text($shown[self::shownKey($field)] ?? null)
                    : $text($row instanceof SiteProfileTranslation ? $row->getAttribute($column) : null);
            }
        }
        // A site that only ever had the address as one line keeps it in "Số nhà, đường", without the
        // ward and province it ends with (those are printed after it): saving must not drop or
        // repeat any part of the address the footer shows.
        if ($i18n[$default]['address_line'] === '' && $text($shown['address'] ?? null) !== '') {
            $i18n[$default]['address_line'] = self::streetOf($text($shown['address']), $text($shown['ward'] ?? null), $text($shown['province'] ?? null));
        }

        $hours = BusinessHours::from($shown['business_hours'] ?? null);
        $issued = $text($shown['license_issued_at'] ?? null);
        $issuedAt = $issued !== '' ? DateTimeImmutable::createFromFormat('!Y-m-d', substr($issued, 0, 10)) : false;
        $links = Cast::arr($shown['social_links'] ?? null);
        [$provinceId, $wardId] = $this->unitsOf($profile, $text($shown['province'] ?? null), $text($shown['ward'] ?? null));

        $shared = [
            'legalName' => $text($profile?->getAttribute('legal_name')) !== '' ? $text($profile?->getAttribute('legal_name')) : $text($shown['legal_name'] ?? null),
            'taxCode' => $text($shown['tax_id'] ?? null),
            'representative' => $text($profile?->getAttribute('representative')) !== '' ? $text($profile?->getAttribute('representative')) : $text($shown['representative'] ?? null),
            'licenseNo' => $text($shown['license'] ?? null),
            'licenseDate' => $issuedAt === false ? '' : $issuedAt->format('d/m/Y'),
            'moitOn' => (bool) $profile?->getAttribute('moit_registered'),
            'moitUrl' => $text($profile?->getAttribute('moit_url')),
            'phone' => $text($shown['phone'] ?? null) !== '' ? $text($shown['phone'] ?? null) : $text($shown['hotline'] ?? null),
            'email' => $text($shown['email'] ?? null),
            'provinceId' => $provinceId,
            'wardId' => $wardId,
            'days' => $hours->days ?? '',
            'opens' => $hours->opens ?? '08:00',
            'closes' => $hours->closes ?? '17:00',
            'timezone' => app(SiteTime::class)->zone(),
            'dateFormat' => app(SiteTime::class)->dateFormat(),
        ];
        foreach (self::SOCIAL as $network) {
            $shared[$network] = $text($links[$network] ?? null);
        }

        return ['i18n' => $i18n, 'shared' => $shared];
    }

    /**
     * Write a checked draft. $shared holds tidy values (licenseDate as Y-m-d or '', the province
     * and ward as catalog ids already checked to belong together).
     *
     * An untouched first save changes nothing a visitor sees and loses no stored value: what the
     * screen does not show (the networks it does not edit, a hotline of its own, the shortened
     * company name the site shows) is carried over, and the province and ward keep the wording the
     * site printed until another unit is picked.
     *
     * @param  array<string, array<string, string>>  $i18n
     * @param  array<string, mixed>  $shared
     */
    public function save(array $i18n, array $shared): void
    {
        $default = app(SiteLocale::class)->default();
        $orNull = static fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null;

        DB::transaction(function () use ($i18n, $shared, $default, $orNull): void {
            $text = static fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '';
            $shown = app(SiteIdentity::class)->for($default) ?? [];   // read before anything is written
            $brandRow = SiteSetting::query()->where('group_name', 'general')->where('key', 'brand')->first();
            $brand = is_array($brandRow?->value) ? $brandRow->value : [];

            $profile = SiteProfile::query()->firstOrNew();
            $fullLegalName = $text($profile->getAttribute('legal_name'));   // as stored, before this save
            $provinceId = $orNull($shared['provinceId'] ?? null);
            $wardId = $orNull($shared['wardId'] ?? null);
            $province = $provinceId !== null ? Province::query()->whereNull('valid_to')->find(Cast::int($provinceId)) : null;
            $ward = $province !== null && $wardId !== null
                ? Ward::query()->where('province_id', $province->id)->whereNull('valid_to')->find(Cast::int($wardId))
                : null;
            // The units the form started on: kept, they keep the wording the site printed.
            [$loadedProvinceId, $loadedWardId] = $this->unitsOf($profile, $text($shown['province'] ?? null), $text($shown['ward'] ?? null));
            $sameProvince = ($provinceId ?? '') === $loadedProvinceId;
            $sameWard = $sameProvince && ($wardId ?? '') === $loadedWardId;

            // A network this screen does not edit is kept: the one the site shows (the brand
            // setting, in the default language) first, then the profile's.
            $links = [];
            foreach (Cast::arr($brand['social_links'] ?? null) + Cast::arr($profile->getAttribute('social_links')) as $network => $url) {
                if (! in_array((string) $network, self::SOCIAL, true) && is_string($url) && $url !== '') {
                    $links[(string) $network] = $url;
                }
            }
            foreach (self::SOCIAL as $network) {
                if (($value = $orNull($shared[$network] ?? null)) !== null) {
                    $links[$network] = $value;
                }
            }
            $hours = BusinessHours::of(Cast::string($shared['days'] ?? null), Cast::stringOrNull($shared['opens'] ?? null), Cast::stringOrNull($shared['closes'] ?? null));
            $phone = $orNull($shared['phone'] ?? null);
            // One number on the screen. A hotline of its own (not the phone) is kept; one that was
            // the phone follows it.
            $shownPhone = $text($shown['phone'] ?? null);
            $shownHotline = $text($shown['hotline'] ?? null);
            $hotline = $shownPhone !== '' && $shownHotline !== '' && $shownHotline !== $shownPhone ? $shownHotline : $phone;

            $profile->fill([
                'brand_name' => $orNull($i18n[$default]['name'] ?? null),
                'legal_name' => $orNull($shared['legalName'] ?? null),
                'tax_code' => $orNull($shared['taxCode'] ?? null),
                'representative' => $orNull($shared['representative'] ?? null),
                'business_license_no' => $orNull($shared['licenseNo'] ?? null),
                'license_issued_at' => $orNull($shared['licenseDate'] ?? null),
                'moit_registered' => ($shared['moitOn'] ?? false) === true,
                'moit_url' => ($shared['moitOn'] ?? false) === true ? $orNull($shared['moitUrl'] ?? null) : null,
                'phone' => $phone,
                'hotline' => $hotline,
                'email' => $orNull($shared['email'] ?? null),
                'address_line' => $orNull($i18n[$default]['address_line'] ?? null),
                // The names are kept beside the ids: the storefront prints the address from them. A unit
                // left as it was keeps the wording the site shows ("TP Hà Nội"; also without a catalog);
                // one the user picks writes the catalog's name.
                'province_id' => $province?->id,
                'province' => $sameProvince ? $orNull($shown['province'] ?? null) : $province?->name,
                'ward_id' => $ward?->id,
                'ward' => $sameWard ? $orNull($shown['ward'] ?? null) : $ward?->name,
                'social_links' => $links,
                'business_hours' => $hours?->toArray(),
            ]);
            $profile->save();

            foreach ($this->locales() as $locale) {
                $values = [];
                foreach (self::LOCALIZED as $field => $column) {
                    $values[$column] = $orNull($i18n[$locale][$field] ?? null);
                }
                $row = SiteProfileTranslation::query()->firstOrNew(['site_profile_id' => $profile->id, 'locale' => $locale]);
                $row->fill($values);
                // The company name the site shows may be shortened (the translation's legal_name); the
                // screen edits the full name of the licence (the profile's). An older brand name that
                // differs from the full one is that shown name: it moves into the default language's
                // translation, never over one already set.
                $brandLegalName = $text($brand['legal_name'] ?? null);
                if ($locale === $default && $brandLegalName !== '' && $fullLegalName !== '' && $brandLegalName !== $fullLegalName
                    && $text($row->getAttribute('legal_name')) === '') {
                    $row->setAttribute('legal_name', $brandLegalName);
                }
                $row->save();
            }

            if ($brandRow !== null) {
                $brandRow->value = array_diff_key($brand, array_flip(self::BRAND_NAP_KEYS));
                $brandRow->save();
            }

            $settings = app(SettingsRepository::class);
            $settings->put('general', 'timezone', ['tz' => Cast::string($shared['timezone'] ?? null, SiteTime::DEFAULT_ZONE)]);
            $settings->put('general', 'date_format', ['format' => Cast::string($shared['dateFormat'] ?? null, 'auto')]);
        });
    }

    /**
     * The languages the screen edits: the site's languages, the default first.
     *
     * @return list<string>
     */
    public function locales(): array
    {
        $locales = app(SiteLocale::class);
        $default = $locales->default();
        $enabled = $locales->enabled();

        return array_values(array_unique([$default, ...$enabled]));
    }

    /** The SiteIdentity key a form field shows from. */
    private static function shownKey(string $field): string
    {
        return match ($field) {
            'hours_note' => 'opening_hours',
            default => $field,
        };
    }

    /**
     * The catalog ids the profile holds, else the units whose names match what the site shows
     * ("TP Hà Nội" finds "Thành phố Hà Nội"); '' when there is no match.
     *
     * @return array{0: string, 1: string}
     */
    private function unitsOf(?SiteProfile $profile, string $province, string $ward): array
    {
        $provinceId = is_numeric($profile?->getAttribute('province_id')) ? (int) $profile->getAttribute('province_id') : null;
        $wardId = is_numeric($profile?->getAttribute('ward_id')) ? (int) $profile->getAttribute('ward_id') : null;
        if ($provinceId !== null) {
            return [(string) $provinceId, $wardId !== null ? (string) $wardId : ''];
        }
        if ($province === '') {
            return ['', ''];
        }

        $key = self::unitKey($province);
        $match = Province::query()->whereNull('valid_to')->get(['id', 'name'])
            ->first(fn (Province $p): bool => self::unitKey($p->name) === $key);
        if ($match === null) {
            return ['', ''];
        }
        $wardMatch = $ward === '' ? null : Ward::query()->where('province_id', $match->id)->whereNull('valid_to')->where('name', $ward)->first(['id']);

        return [(string) $match->id, $wardMatch !== null ? (string) $wardMatch->id : ''];
    }

    /**
     * The street part of a one-line address: without the ward and the province it ends with, so
     * the address composed from the parts does not repeat them ("Số 1, Xã A, TP B" → "Số 1").
     */
    private static function streetOf(string $address, string $ward, string $province): string
    {
        foreach ([$province, $ward] as $part) {
            if ($part === '') {
                continue;
            }
            $street = (string) preg_replace('/\s*,\s*'.preg_quote($part, '/').'\s*\z/iu', '', $address);
            if ($street !== '') {
                $address = $street;
            }
        }

        return $address;
    }

    /** A province name without its kind ("Thành phố", "TP.", "Tỉnh"), lower-cased, for matching. */
    private static function unitKey(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = (string) preg_replace('/\A(thành phố|tp\.?|tỉnh)\s*/u', '', $name);

        return trim($name);
    }
}
