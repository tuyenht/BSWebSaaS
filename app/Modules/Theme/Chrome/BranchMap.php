<?php

declare(strict_types=1);

namespace App\Modules\Theme\Chrome;

use App\Modules\Media\Services\MediaLibraryUrls;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Theme\Components\ButtonLink;
use App\Modules\Theme\Theming\StorefrontSettings;

/**
 * The branches and factories of the site (the `storefront.footer.factories` list, read through
 * StorefrontChrome::branches) shaped for the pages that draw them: a map with a chooser beside a
 * form, and a list of cards. Every map address is built here from fixed Google Maps templates and
 * the stored text or coordinates, each value through rawurlencode; an address typed by an author is
 * never stored or printed, so there is no raw iframe address to inject into. The settings are the
 * request's one memoized read, so a page that only draws the map adds no query; the cards add one
 * (their pictures, in one batched lookup).
 */
final readonly class BranchMap
{
    private const string GOOGLE = 'https://www.google.com/maps';

    /** The card picture: shown 400 wide at most, offered at these widths. */
    private const int CARD_WIDTH = 640;

    private const array CARD_SRCSET_WIDTHS = [640, 960];

    public function __construct(
        private StorefrontSettings $settings,
        private SiteLocale $locales,
        private MediaLibraryUrls $media,
    ) {}

    /**
     * The branches that can be shown on a map (a search text or a coordinate pair), each with its
     * three map addresses. Pure but for the settings read (memoized per request): no query of its own.
     *
     * @return list<array{title: string, short: string, full_name: string, locality: string, area: string|null, role: string|null, address: string|null, approximate: bool, embed: string, dir: string, open: string}>
     */
    public function places(string $locale): array
    {
        $places = [];
        foreach ($this->branches($locale) as $branch) {
            $urls = self::urls($branch, $locale);
            if ($urls === null) {
                continue;
            }
            $places[] = [
                'title' => $branch['title'],
                'short' => self::short($branch['full_name'], $branch['title']),
                'full_name' => $branch['full_name'] ?? $branch['title'],
                'locality' => $branch['locality'] ?? '',
                'area' => self::area($branch['area']),
                'role' => $branch['name'],
                'address' => $branch['address'],
                'approximate' => $branch['approximate'],
            ] + $urls;
        }

        return $places;
    }

    /**
     * The branches for a list of cards, each with its picture (one lookup for all of them, none when no
     * branch has one).
     *
     * @return list<array{key: string|null, title: string, full_name: string, locality: string, area: string|null, role: string|null, address: string|null, image: string|null, srcset: string|null, alt: string, width: int, height: int}>
     */
    public function cards(string $locale): array
    {
        $branches = $this->branches($locale);
        $resolved = $this->media->resolve(array_column($branches, 'image_media_id'), self::CARD_WIDTH, self::CARD_SRCSET_WIDTHS);

        $cards = [];
        foreach ($branches as $branch) {
            $id = $branch['image_media_id'];
            $url = $id !== null ? ($resolved['urls'][$id] ?? null) : null;
            $dims = $url !== null ? ($resolved['dims'][$url] ?? null) : null;
            $cards[] = [
                'key' => $branch['key'],
                'title' => $branch['title'],
                'full_name' => $branch['full_name'] ?? $branch['title'],
                'locality' => $branch['locality'] ?? '',
                'area' => self::area($branch['area']),
                'role' => $branch['name'],
                'address' => $branch['address'],
                'image' => $url,
                'srcset' => $url !== null ? ($resolved['srcsets'][$url] ?? null) : null,
                'alt' => $url !== null && ($resolved['alts'][$url] ?? '') !== '' ? $resolved['alts'][$url] : ($branch['full_name'] ?? $branch['title']),
                'width' => $dims[0] ?? 4,
                'height' => $dims[1] ?? 3,
            ];
        }

        return $cards;
    }

    /**
     * The site's hotline (else its main phone) as a `tel:` link, from the identity the page chrome read
     * for this request; null when there is none or the page was drawn without a chrome.
     */
    public static function callLink(): ?string
    {
        $brand = request()->attributes->get('sf_brand');
        if (! is_array($brand)) {
            return null;
        }
        foreach (['hotline', 'phone'] as $field) {
            $value = trim(Cast::string($brand[$field] ?? null));
            if ($value !== '' && ($href = ButtonLink::tel($value)) !== null) {
                return $href;
            }
        }

        return null;
    }

    /**
     * The three map addresses of a branch: the embedded map, the directions and the plain search. The
     * text to search is the branch's map query, else its coordinates; directions go to the coordinates
     * when it has them. Null when the branch has neither. A branch that is only approximately placed
     * zooms out one step.
     *
     * @param  array{map_query: string|null, lat: float|null, lng: float|null, approximate: bool}  $branch
     * @return array{embed: string, dir: string, open: string}|null
     */
    public static function urls(array $branch, string $locale): ?array
    {
        $pair = $branch['lat'] !== null && $branch['lng'] !== null ? self::number($branch['lat']).','.self::number($branch['lng']) : null;
        $search = $branch['map_query'] ?? $pair;
        if ($search === null) {
            return null;
        }

        return [
            'embed' => self::GOOGLE.'?q='.rawurlencode($search).'&hl='.rawurlencode($locale).'&z='.($branch['approximate'] ? 15 : 16).'&output=embed',
            'dir' => self::GOOGLE.'/dir/?api=1&destination='.rawurlencode($pair ?? $search),
            'open' => self::GOOGLE.'/search/?api=1&query='.rawurlencode($search),
        ];
    }

    /**
     * @return list<array{key: string|null, title: string, full_name: string|null, locality: string|null, area: int|null, name: string|null, address: string|null, map_url: string|null, map_query: string|null, lat: float|null, lng: float|null, approximate: bool, image_media_id: int|null}>
     */
    private function branches(string $locale): array
    {
        /** @var list<string> $mapHosts */
        $mapHosts = config('cms.map_hosts', []);

        return StorefrontChrome::branches($this->settings->get('storefront', 'footer'), $locale, $this->locales->default(), $mapHosts);
    }

    /** The chooser's short name: the full name up to its dash ("Nhà máy 1 – Xuân Sơn" reads "Nhà máy 1"), else the title. */
    private static function short(?string $fullName, string $title): string
    {
        $head = trim(explode(' – ', (string) $fullName, 2)[0]);

        return $head !== '' ? $head : $title;
    }

    private static function area(?int $area): ?string
    {
        return $area === null ? null : Cast::string(__('storefront.branch_area', ['area' => number_format($area, 0, ',', '.')]));
    }

    /** A coordinate with no trailing zeros, at most seven decimals ("21.2983367"). */
    private static function number(float $degrees): string
    {
        $text = rtrim(rtrim(number_format($degrees, 7, '.', ''), '0'), '.');

        return $text === '' || $text === '-0' ? '0' : $text;
    }
}
