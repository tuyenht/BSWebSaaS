<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Context;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * The business identity a storefront page shows — name, legal name, address, contact, licence,
 * brand assets — in that page's language: one source for the header, the footer and the
 * Organization JSON-LD (design.md §14: NAP lives in site_profiles + site_profile_translations).
 * Returns the array shape the theme reads as `$brand`, or null when the site has neither source.
 *
 * TRANSITION (expand → migrate → contract). Sites still carry the older one-language
 * `general.brand` setting. In the site's DEFAULT language it keeps precedence, so no live page
 * changes by a single byte, and site_profiles fill only what it lacks. In ANY OTHER language the
 * translation of that language wins, then the default-language values. When the Settings screen
 * writes site_profiles and the NAP keys leave general.brand, the profile becomes the only source.
 * Logo, favicon and share image stay in general.brand: they are resolved media URLs, and Tenancy
 * cannot resolve media.
 *
 * One query, always: the profile joined with at most two translations (this language + the
 * default), the brand setting riding along as a scalar subquery, anchored on a constant row so a
 * site without a profile still gets its setting. Every table is filtered by site_id explicitly
 * (the tenant scope rule) on top of RLS.
 */
final readonly class SiteIdentity
{
    /** Per-language text, mapped from the translation columns (aliased t_*) to the theme's keys. */
    private const array LOCALIZED = [
        'name' => 't_brand_name',
        'legal_name' => 't_legal_name',
        'tagline' => 't_tagline',
        'description' => 't_description',
        'address_line' => 't_address_line',
        'representative' => 't_representative',
        'opening_hours' => 't_opening_hours',
        'license' => 't_license',
    ];

    public function __construct(
        private CurrentSite $current,
        private SiteLocale $locales,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function for(string $locale): ?array
    {
        if (! $this->current->hasId()) {
            return null;
        }

        $siteId = $this->current->id();
        $default = $this->locales->default();

        // Anchored on a one-row constant so the query returns a row even for a site with no
        // profile yet (the brand setting still rides along) — one query in every case.
        $rows = DB::query()
            ->fromRaw('(select 1) as anchor')
            ->leftJoin('site_profiles as p', fn (JoinClause $join) => $join->where('p.site_id', '=', $siteId))
            ->leftJoin('site_profile_translations as t', function (JoinClause $join) use ($locale, $default): void {
                $join->on('t.site_profile_id', '=', 'p.id')
                    ->on('t.site_id', '=', 'p.site_id')
                    ->whereIn('t.locale', array_values(array_unique([$locale, $default])));
            })
            ->select([
                'p.brand_name', 'p.legal_name', 'p.tax_code', 'p.business_license_no', 'p.representative',
                'p.email', 'p.phone', 'p.hotline', 'p.address_line', 'p.ward', 'p.province',
                'p.social_links', 'p.moit_registered', 'p.moit_url',
                't.locale as t_locale', 't.brand_name as t_brand_name', 't.legal_name as t_legal_name',
                't.tagline as t_tagline', 't.short_description as t_description', 't.address_line as t_address_line',
                't.representative as t_representative', 't.opening_hours_text as t_opening_hours',
                't.license_text as t_license',
            ])
            ->selectSub(fn (Builder $setting) => $this->brandSetting($setting)->where('s.site_id', $siteId), 'brand_setting')
            ->get();

        /** @var array<string, mixed> $first */
        $first = (array) $rows->first();
        $byLocale = [];
        foreach ($rows as $row) {
            /** @var array<string, mixed> $row */
            $row = (array) $row;
            if (is_string($row['t_locale'] ?? null)) {
                $byLocale[$row['t_locale']] = $row;
            }
        }

        // Each source is filtered BEFORE merging: a blank translation column must never erase a
        // value the profile holds.
        $brand = $this->decode($first['brand_setting'] ?? null);
        $identity = [...$this->filled($this->shared($first)), ...$this->filled($this->localized($byLocale[$default] ?? []))];

        if ($locale === $default) {
            $identity = [...$identity, ...$this->filled($brand)];
        } else {
            $own = $this->filled($this->localized($byLocale[$locale] ?? []));
            $identity = [...$identity, ...$this->filled($brand), ...$own];

            if (isset($own['address_line'])) {
                $identity['address'] = $this->address($identity);   // re-compose in this language
            }
        }

        $identity['address'] ??= $this->address($identity);

        return array_filter($identity, fn (mixed $value): bool => $value !== null) ?: null;
    }

    /**
     * Values every language shares, under the theme's keys.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function shared(array $row): array
    {
        $links = $this->decode($row['social_links'] ?? null);
        $links = array_values(array_filter($links, fn (mixed $url): bool => is_string($url) && $url !== ''));

        return [
            'name' => $row['brand_name'] ?? null,
            'legal_name' => $row['legal_name'] ?? null,
            'representative' => $row['representative'] ?? null,
            'address_line' => $row['address_line'] ?? null,
            'ward' => $row['ward'] ?? null,
            'province' => $row['province'] ?? null,
            'phone' => $row['phone'] ?? null,
            'hotline' => $row['hotline'] ?? null,
            'email' => $row['email'] ?? null,
            'tax_id' => $row['tax_code'] ?? null,
            'license' => $row['business_license_no'] ?? null,
            'social_links' => $links === [] ? null : $links,
            'moit_url' => ($row['moit_registered'] ?? false) ? ($row['moit_url'] ?? null) : null,
        ];
    }

    /**
     * One translation row's text, under the theme's keys.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function localized(array $row): array
    {
        $values = [];
        foreach (self::LOCALIZED as $key => $column) {
            $values[$key] = $row[$column] ?? null;
        }

        return $values;
    }

    /**
     * "Số nhà, đường, Phường/Xã, Tỉnh/Thành phố" from the parts that are set.
     *
     * @param  array<string, mixed>  $identity
     */
    private function address(array $identity): ?string
    {
        $parts = array_filter(
            [$identity['address_line'] ?? null, $identity['ward'] ?? null, $identity['province'] ?? null],
            fn (mixed $part): bool => is_string($part) && trim($part) !== '',
        );

        return $parts === [] ? null : implode(', ', $parts);
    }

    /** The general.brand setting value, on the site_settings alias `s`. */
    private function brandSetting(Builder $query): Builder
    {
        return $query->from('site_settings as s')
            ->select('s.value')
            ->where('s.group_name', 'general')
            ->where('s.key', 'brand')
            ->limit(1);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(mixed $json): array
    {
        if (! is_string($json) || $json === '') {
            return [];
        }

        $value = json_decode($json, true);
        if (! is_array($value)) {
            return [];
        }

        $decoded = [];
        foreach ($value as $key => $item) {
            $decoded[(string) $key] = $item;
        }

        return $decoded;
    }

    /**
     * Drop null and blank values, so a missing field never overrides one that is set.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<string, mixed>
     */
    private function filled(array $values): array
    {
        $filled = [];
        foreach ($values as $key => $value) {
            if ($value !== null && $value !== '' && $value !== []) {
                $filled[(string) $key] = $value;
            }
        }

        return $filled;
    }
}
