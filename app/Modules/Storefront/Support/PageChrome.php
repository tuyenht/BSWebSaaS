<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Support;

use App\Modules\Catalog\Support\ProductIndexLink;
use App\Modules\Cms\Navigation\MenuResolver;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Tenancy\Context\SiteIdentity;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Theme\Chrome\FloatingContact;
use App\Modules\Theme\Chrome\SiteVerification;
use App\Modules\Theme\Chrome\StorefrontChrome;
use App\Modules\Theme\Theming\StorefrontSettings;

/**
 * The page chrome of a storefront page — its header, footer, menus and business identity — read
 * inside the site's own context like every page. One place for the public pages and for the admin's
 * preview of a page, so the preview wears exactly the site's own header and footer.
 */
final readonly class PageChrome
{
    public function __construct(
        private MenuResolver $nav,
        private PathBuilder $paths,
        private SiteIdentity $identity,
        private BrandImages $brandImages,
        private StorefrontSettings $settings,
        private SiteLocale $siteLocale,
    ) {}

    /**
     * The page chrome for the active locale: the optional rich header/footer (site settings,
     * normalized; null = the classic one, read through the same one-query settings reader the design
     * tokens use), the floating contact buttons, the business identity, the menus, the <html lang>
     * and the hreflang alternates the caller worked out (none for a page that was not found).
     *
     * @param  string  $requestPath  the prefixed path of the page, for the menus' active state
     * @param  list<array{hreflang: string, href: string}>  $hreflang
     * @return array<string, mixed>
     */
    public function for(string $locale, string $default, string $requestPath, array $hreflang): array
    {
        /** @var list<string> $mapHosts */
        $mapHosts = config('cms.map_hosts', []);
        $header = StorefrontChrome::header($this->settings->get('storefront', 'header'), $locale, $default);
        $footerRaw = $this->settings->get('storefront', 'footer');
        $footer = StorefrontChrome::footer($footerRaw, $locale, $default, $mapHosts);
        // The floating contact buttons / phone bottom bar; the product list is looked up only when
        // the bar's "Mua hàng" button is switched on (it is shown only when the site has one).
        $floating = $this->settings->get('storefront', 'floating_contact');
        $shopHref = FloatingContact::wantsShop($floating) ? app(ProductIndexLink::class)->for($locale) : null;

        // Business identity in the page's language (site_profiles + translations, the legacy
        // general.brand setting keeping precedence in the default language — SiteIdentity). The
        // menu's special links (call, Zalo, email, map) read their addresses from it too. The brand
        // images' addresses come from their media ids (BrandImages: one query, none when unset).
        $brand = $this->brandImages->apply($this->identity->for($locale));
        // The blocks of the page (the branch map's call button) read the hotline from here, with no read of their own.
        request()->attributes->set('sf_brand', $brand);

        // Header nav (active-state matches the prefixed request path; a missing per-locale
        // assignment falls back to the default-locale menu), the phone menu panel and, for the rich
        // footer, its link columns — all regions resolved together. The phone panel shows its own
        // menu, else the main one (whether it follows the main menu or was never set up).
        $menus = $this->nav->resolveMany(
            $footer === null ? ['primary', 'handheld'] : ['primary', 'handheld', $footer['region']],
            $locale, $default, $requestPath, $brand ?? [],
        );

        return [
            'menu' => $menus['primary'] ?? [],
            'mobileMenu' => ($menus['handheld'] ?? []) !== [] ? $menus['handheld'] : ($menus['primary'] ?? []),
            'footerMenu' => $footer === null ? [] : ($menus[$footer['region']] ?? []),
            'header' => $header,
            'footer' => $footer,
            // null = off or no channel to show.
            'floatingContact' => FloatingContact::for($floating, $locale, $shopHref),
            'lang' => $locale,
            'hreflang' => $hreflang,
            'dir' => StorefrontChrome::dir($locale),
            // Every public language, links from PathBuilder (the page's own translation, else that language's home page).
            'languages' => StorefrontChrome::languages($hreflang, $locale, $this->siteLocale->public(), fn (string $to): string => $this->paths->publicUrl('/', $to)),
            'brand' => $brand,
            // The branches with an address or a position, for the business's structured data (no query: the footer setting is already read).
            'branchPlaces' => self::branchPlaces(StorefrontChrome::branches($footerRaw, $locale, $default, $mapHosts)),
            // Site-ownership meta tags (Google, Bing, Facebook), read with the other storefront settings.
            'verification' => SiteVerification::tags($this->settings->get('storefront', 'verification')),
            // The logo and footer home links stay in the page's language (/en for an English page).
            'homeUrl' => $this->paths->publicUrl('/', $locale),
        ];
    }

    /**
     * schema.org Place entries for the branches that give an address or a position.
     *
     * @param  list<array<string, mixed>>  $branches
     * @return list<array<string, mixed>>
     */
    private static function branchPlaces(array $branches): array
    {
        $places = [];
        foreach ($branches as $branch) {
            $address = is_string($branch['address'] ?? null) && $branch['address'] !== '' ? $branch['address'] : null;
            $hasGeo = is_float($branch['lat'] ?? null) && is_float($branch['lng'] ?? null) && empty($branch['approximate']);
            if ($address === null && ! $hasGeo) {
                continue;
            }
            $name = is_string($branch['full_name'] ?? null) && $branch['full_name'] !== '' ? $branch['full_name'] : $branch['title'];
            $locality = is_string($branch['locality'] ?? null) && $branch['locality'] !== '' ? $branch['locality'] : null;
            $place = ['@type' => 'Place', 'name' => $name];
            if ($address !== null) {
                $place['address'] = array_filter(['@type' => 'PostalAddress', 'streetAddress' => $address, 'addressLocality' => $locality, 'addressCountry' => 'VN'], fn ($v): bool => $v !== null);
            }
            if ($hasGeo) {
                $place['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => $branch['lat'], 'longitude' => $branch['lng']];
            }
            $places[] = $place;
        }

        return $places;
    }
}
