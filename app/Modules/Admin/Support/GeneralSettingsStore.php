<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use App\Modules\Admin\Support\Settings\SettingsChangeRejected;
use App\Modules\Admin\Support\Settings\SettingsWrite;
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
 * per language (the translation's legal_name, which may be shortened) has no field here: it is
 * filled from the older brand name, and cleared when the full name changes.
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
        // A site that only ever had the address as one line (the older brand setting's `address`)
        // keeps it in "Số nhà, đường", without the ward, district and province it ends with (those
        // are printed after it): saving must not drop or repeat any part of the address the footer
        // shows. Once saved, the shown address is composed from the parts, so it is never copied
        // back: a street the user cleared stays empty.
        $brand = SiteSetting::query()->where('group_name', 'general')->where('key', 'brand')->value('value');
        if ($i18n[$default]['address_line'] === '' && is_array($brand) && $text($brand['address'] ?? null) !== '') {
            $i18n[$default]['address_line'] = self::streetOf($text($shown['address'] ?? null), [
                $text($shown['province'] ?? null), $text($shown['district'] ?? null), $text($shown['ward'] ?? null),
            ]);
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
     *
     * @throws SettingsChangeRejected busy: another save of the site's settings held the lock too long
     */
    public function save(array $i18n, array $shared): void
    {
        $default = app(SiteLocale::class)->default();
        $orNull = static fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null;

        SettingsWrite::run('general', function () use ($i18n, $shared, $default, $orNull): void {
            // The brand row is written back whole: lock it before anything reads it (the shown
            // identity below includes it), so a brand image save at the same time waits instead of
            // being undone.
            $brandRow = SiteSetting::query()->where('group_name', 'general')->where('key', 'brand')->lockForUpdate()->first();
            $text = static fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '';
            $shown = app(SiteIdentity::class)->for($default) ?? [];   // read before anything is written
            $brand = is_array($brandRow?->value) ? $brandRow->value : [];

            $profile = SiteProfile::query()->firstOrNew();
            $fullLegalName = $text($profile->getAttribute('legal_name'));   // as stored, before this save
            // The full name the form started from: the stored one, else the name the site shows (as load() does).
            $startLegalName = $fullLegalName !== '' ? $fullLegalName : $text($shown['legal_name'] ?? null);
            $newLegalName = $orNull($shared['legalName'] ?? null);
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

            // A network this screen does not edit is kept as the site shows it: in the default
            // language the brand setting's links, when it has any, replace the profile's whole set.
            $brandLinks = Cast::arr($brand['social_links'] ?? null);
            $links = [];
            foreach ($brandLinks !== [] ? $brandLinks : Cast::arr($profile->getAttribute('social_links')) as $network => $url) {
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
            // One number on the screen, compared by its digits ("0915 66 83 83" is "0915668383"). A
            // hotline of its own is kept; one that was the phone keeps its wording while the phone
            // stays the same number, and follows the phone once it changes.
            // "+84 915…" is "0915…": the country code counts as the leading 0, as the phone check does.
            $digits = static fn (?string $value): string => (string) preg_replace('/\A84/', '0', (string) preg_replace('/\D+/', '', (string) $value));
            $shownPhone = $text($shown['phone'] ?? null);
            $shownHotline = $text($shown['hotline'] ?? null);
            $ownHotline = $shownPhone !== '' && $shownHotline !== '' && $digits($shownHotline) !== $digits($shownPhone);
            $samePhone = $shownPhone !== '' && $shownHotline !== '' && $digits($phone) === $digits($shownPhone);
            $hotline = $ownHotline || $samePhone ? $shownHotline : $phone;

            $profile->fill([
                'brand_name' => $orNull($i18n[$default]['name'] ?? null),
                'legal_name' => $newLegalName,
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
                // A unit left as it was keeps its stored id too, even one since retired from the catalog.
                'province_id' => $province !== null ? $province->id : ($sameProvince ? $profile->getAttribute('province_id') : null),
                'province' => $sameProvince ? $orNull($shown['province'] ?? null) : $province?->name,
                'ward_id' => $ward !== null ? $ward->id : ($sameWard ? $profile->getAttribute('ward_id') : null),
                'ward' => $sameWard ? $orNull($shown['ward'] ?? null) : $ward?->name,
                'social_links' => $links,
                'business_hours' => $hours?->toArray(),
            ]);
            $profile->save();
            $textChanged = false;

            foreach ($this->locales() as $locale) {
                $values = [];
                foreach (self::LOCALIZED as $field => $column) {
                    $values[$column] = $orNull($i18n[$locale][$field] ?? null);
                }
                $row = SiteProfileTranslation::query()->firstOrNew(['site_profile_id' => $profile->id, 'locale' => $locale]);
                $row->fill($values);
                // The company name the site shows may be shortened (the translation's legal_name); the
                // screen edits the full name of the licence (the profile's). In the default language
                // the older brand name is what the site shows, ahead of any translation: it becomes
                // that language's shown name, or none when it is the full name being saved (the
                // translation then follows the profile).
                $brandLegalName = $text($brand['legal_name'] ?? null);
                if ($locale === $default && $brandLegalName !== '') {
                    $row->setAttribute('legal_name', $brandLegalName !== $startLegalName ? $brandLegalName : null);
                }
                $row->save();
                $textChanged = $textChanged || $row->wasChanged() || $row->wasRecentlyCreated;
            }
            // A new full name: every shown name was derived from the old one (the screen has no field
            // for it), so each language shows the new full name from now on.
            if ($startLegalName !== '' && $startLegalName !== $text($newLegalName)) {
                $textChanged = SiteProfileTranslation::query()->where('site_profile_id', $profile->id)->update(['legal_name' => null]) > 0 || $textChanged;
            }
            // A save that changed only one language's text still writes the profile row: the
            // section's "last saved" line (time and who, RecordsWhoSaved) is read from it.
            if ($textChanged && ! $profile->wasChanged() && ! $profile->wasRecentlyCreated) {
                $profile->touch();
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
        // Only units in force: a retired one loads as not picked, and its wording is kept on save.
        $provinceId = is_numeric($profile?->getAttribute('province_id')) ? (int) $profile->getAttribute('province_id') : null;
        $wardId = is_numeric($profile?->getAttribute('ward_id')) ? (int) $profile->getAttribute('ward_id') : null;
        if ($provinceId !== null) {
            if (! Province::query()->whereNull('valid_to')->whereKey($provinceId)->exists()) {
                return ['', ''];
            }
            $wardInForce = $wardId !== null
                && Ward::query()->where('province_id', $provinceId)->whereNull('valid_to')->whereKey($wardId)->exists();

            return [(string) $provinceId, $wardInForce ? (string) $wardId : ''];
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
     * The street part of a one-line address: without the province, district and ward it ends with
     * (in that order), so the address composed from the parts does not repeat them ("Số 1, Xã A,
     * Huyện B, TP C" → "Số 1"). Each part is cut after a comma; only an address written without
     * any comma is cut at a space, so a street named after its ward ("12 Đường Bến Nghé, …") keeps
     * its name. '' when nothing is left.
     *
     * @param  list<string>  $parts  province, district, ward
     */
    private static function streetOf(string $address, array $parts): string
    {
        $separator = str_contains($address, ',') ? '\s*,\s*' : '\s+';
        foreach ($parts as $part) {
            if ($part !== '') {
                $address = trim((string) preg_replace('/(?:\A|'.$separator.')'.preg_quote($part, '/').'\s*\z/iu', '', $address));
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
