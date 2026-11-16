<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Support;

use App\Modules\Catalog\Support\ContactPageSetting;
use App\Modules\Shared\Support\SafeLink;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Theme\Rendering\PageRenderer;
use App\Modules\Theme\Theming\StorefrontSettings;
use Symfony\Component\HttpFoundation\Response;

/**
 * The site's 404 page: its own header, footer and menus around the "not here" message, a way home, a
 * way to the site's contact page (when the site names one), the site search box (while the site has
 * search, PageChrome's `search`), then the first links of its main menu. A real 404 status (never a
 * page that answers 200) and kept out of search engines; no canonical, share tags or alternates,
 * since there is no page to point at. One place for every storefront address that finds nothing.
 */
final readonly class NotFoundPage
{
    /** The most links the 404 page suggests from the main menu (the design draws five). */
    public const int SUGGESTIONS_MAX = 5;

    public function __construct(
        private PageRenderer $renderer,
        private SiteLocale $locale,
        private ProductDetail $products,
        private StorefrontSettings $settings,
    ) {}

    /** @param array<string, mixed> $chrome the page chrome (PageChrome) */
    public function respond(array $chrome): Response
    {
        $locale = is_string($chrome['lang'] ?? null) ? $chrome['lang'] : $this->locale->default();
        $extra = $chrome;
        $extra['hreflang'] = [];
        $extra['robots'] = 'noindex';
        $extra['contactUrl'] = $this->products->quoteLink($locale, ContactPageSetting::ulid($this->settings->get(ContactPageSetting::GROUP, ContactPageSetting::KEY)));
        $extra['suggestions'] = self::suggestions(
            is_array($chrome['menu'] ?? null) ? $chrome['menu'] : [],
            is_string($chrome['homeUrl'] ?? null) ? $chrome['homeUrl'] : '/',
        );

        return response($this->renderer->renderNotice(__('storefront.not_found.title'), $extra), 404);
    }

    /**
     * The "you may be looking for" links: the top level of the main menu already loaded for the header
     * (no query), keeping only the links to a page of this site and leaving out the home page (the page
     * has its own button for it), at most $max. Empty when the menu is.
     *
     * @param  list<mixed>|array<mixed>  $menu  the resolved primary menu tree
     * @return list<array{label: string, url: string}>
     */
    public static function suggestions(array $menu, string $homeUrl, int $max = self::SUGGESTIONS_MAX): array
    {
        $links = [];
        foreach ($menu as $item) {
            $url = is_array($item) ? ($item['url'] ?? null) : null;
            $label = is_array($item) ? ($item['label'] ?? null) : null;
            if (! is_string($url) || ! is_string($label) || trim($label) === '' || $url === $homeUrl || ! SafeLink::isSitePath($url)) {
                continue;
            }
            $links[] = ['label' => $label, 'url' => $url];
            if (count($links) === $max) {
                break;
            }
        }

        return $links;
    }
}
