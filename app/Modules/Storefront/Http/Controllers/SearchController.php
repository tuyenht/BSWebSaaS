<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Http\Controllers;

use App\Modules\Cms\Models\SiteRoute;
use App\Modules\Cms\Routing\PathBuilder;
use App\Modules\Shared\Support\SafeLink;
use App\Modules\Storefront\Http\Middleware\StorefrontSession;
use App\Modules\Storefront\Search\SearchPage;
use App\Modules\Storefront\Search\SearchText;
use App\Modules\Storefront\Support\Breadcrumbs;
use App\Modules\Storefront\Support\NotFoundPage;
use App\Modules\Storefront\Support\PageChrome;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Theme\Rendering\PageRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * The site search page, `/tim-kiem?q=…` (`/{locale}/tim-kiem` for a language other than the site's
 * default): the box with the words searched for, then the results in groups (SearchPage), or the
 * "nothing found" help, or, with nothing (or one letter) typed, the box and the site's main groups.
 * `/{xx}/tim-kiem` whose first part is not a public language of the site is an ordinary address (a post
 * under a two-letter category, a child page) and goes to the storefront's own handler.
 * A group's "Xem thêm" link (`nhom`, `sau`) shows that group's next rows. While the site has search
 * switched off the address is the site's own 404 page.
 *
 * The address is reserved for new content (PathBuilder::RESERVED_PATHS), but a site may hold a page,
 * post, category or product at it from before: then that content keeps the address. Its route is
 * looked up (one row by the unique (site_id, locale, path) index, only on this address) and, when the
 * storefront serves something there, that answer is returned as it is; content that is not shown (a
 * draft, an item in the trash) leaves the address to the search.
 *
 * Never cached and kept out of search engines (noindex, follow). Searches are limited per site and
 * visitor address (MAX_PER_MINUTE, counted in the cache store): past it a plain "wait a moment" page
 * answers 429. The words searched for are never stored with the address (the counter's key holds the
 * address alone) and never written to the log.
 */
final readonly class SearchController
{
    /** Searches one visitor address may run on one site per minute. */
    public const int MAX_PER_MINUTE = 30;

    public function __construct(
        private SiteLocale $locale,
        private PageChrome $pageChrome,
        private PageRenderer $renderer,
        private NotFoundPage $notFoundPage,
        private SearchPage $page,
        private PathBuilder $paths,
        private Breadcrumbs $crumbs,
        private CurrentSite $current,
    ) {}

    public function __invoke(Request $request, ?string $locale = null): Response
    {
        $default = $this->locale->default();
        if ($locale !== null) {
            $locale = strtolower($locale);
            if ($locale === $default) {
                // the default language has no prefix: one address per page
                $query = $request->getQueryString();

                return redirect()->to(PathBuilder::SEARCH_PATH.($query === null || $query === '' ? '' : '?'.$query), 301);
            }
            if (! $this->locale->isPublic($locale)) {
                // not a language of the site: an ordinary address (a post under a two-letter category, a
                // child page), served by the storefront like any other path
                return app(StorefrontController::class)($request);
            }
        }
        $locale ??= $default;

        // Content the site already had at this address keeps it (see the class comment).
        if (SiteRoute::query()->where('locale', $locale)->where('path', PathBuilder::SEARCH_PATH)->exists()) {
            $page = app(StorefrontController::class)($request);
            if ($page->getStatusCode() !== 404) {
                return $page;
            }
        }
        app()->setLocale($locale);

        $base = $this->paths->searchUrl($locale);
        $chrome = $this->pageChrome->for($locale, $default, $base, []);
        if (empty($chrome['search'])) {
            return $this->private($this->notFoundPage->respond($chrome));
        }

        $q = SearchText::from($request->query('q'));
        if ($q->searchable()) {
            $key = 'site-search:'.$this->current->id().'|'.$request->ip();
            if (RateLimiter::tooManyAttempts($key, self::MAX_PER_MINUTE)) {
                return $this->busy($chrome, RateLimiter::availableIn($key));
            }
            RateLimiter::hit($key, 60);
        }

        $group = $request->query('nhom');
        $after = $request->query('sau');
        $results = $q->searchable()
            ? $this->page->build($q, $locale, $base, is_string($group) ? $group : null, is_string($after) ? $after : null)
            : null;

        $quick = NotFoundPage::suggestions(is_array($chrome['menu'] ?? null) ? $chrome['menu'] : [], is_string($chrome['homeUrl'] ?? null) ? $chrome['homeUrl'] : '/', 6);
        $empty = $results !== null && $results['groups'] === [];
        $extra = $chrome + [
            'hreflang' => [],
            'robots' => 'noindex, follow',
            'crumbs' => $this->crumbs->visible([$this->crumbs->home($locale), $this->crumbs->crumb(__('storefront.search.crumb'), $base)], false),
            'query' => $q->text,
            'results' => $results,
            'quick' => $quick,
            'tips' => $empty ? $this->tips($q, $locale) : [],
            'cta' => $empty ? self::mainAction($chrome) : null,
        ];

        return $this->private(response($this->renderer->renderSearch(
            $empty ? __('storefront.search.none_title', ['q' => $q->text]) : __('storefront.search.title'),
            $extra,
        )));
    }

    /**
     * The "Bạn có thể thử" lines: fewer words (from two words typed: the first half of them), the spelling
     * (accents or none both work), and a product code when the site has one to show as the example.
     *
     * @return list<string>
     */
    private function tips(SearchText $q, string $locale): array
    {
        $typed = explode(' ', $q->text);
        $tips = [];
        if (count($typed) > 1) {
            $tips[] = __('storefront.search.tip_fewer', ['words' => implode(' ', array_slice($typed, 0, intdiv(count($typed) + 1, 2)))]);
        }
        $tips[] = __('storefront.search.tip_spelling');
        $sku = $this->page->exampleSku($locale);
        if ($sku !== null) {
            $tips[] = __('storefront.search.tip_sku', ['sku' => $sku]);
        }

        return $tips;
    }

    /**
     * The website's main contact action, the no-results page's button: the phone bottom bar's own cell
     * ("Yêu cầu báo giá"), else the bar theme's own button, else the first main-menu item marked as the
     * call to action. Null when the site has none (then the page shows no such box).
     *
     * @param  array<string, mixed>  $chrome
     * @return array{href: string, label: string}|null
     */
    private static function mainAction(array $chrome): ?array
    {
        $floating = is_array($chrome['floatingContact'] ?? null) ? $chrome['floatingContact'] : [];
        $bar = is_array($floating['phone_bar'] ?? null) ? $floating['phone_bar'] : [];
        $cta = is_array($bar['cta'] ?? null) ? $bar['cta'] : null;
        if ($cta !== null && is_string($cta['href'] ?? null) && is_string($cta['label'] ?? null)) {
            return ['href' => $cta['href'], 'label' => $cta['label']];
        }
        foreach (is_array($floating['buttons'] ?? null) ? $floating['buttons'] : [] as $button) {
            if (is_array($button) && ($button['key'] ?? null) === 'cta' && is_string($button['href'] ?? null) && is_string($button['label'] ?? null) && $button['label'] !== '') {
                return ['href' => $button['href'], 'label' => $button['label']];
            }
        }
        foreach (is_array($chrome['menu'] ?? null) ? $chrome['menu'] : [] as $item) {
            if (is_array($item) && ! empty($item['cta']) && is_string($item['url'] ?? null) && is_string($item['label'] ?? null)
                && trim($item['label']) !== '' && SafeLink::href($item['url']) !== null) {
                return ['href' => $item['url'], 'label' => $item['label']];
            }
        }

        return null;
    }

    /** @param array<string, mixed> $chrome */
    private function busy(array $chrome, int $retryAfter): Response
    {
        $extra = $chrome;
        $extra['hreflang'] = [];
        $extra['robots'] = 'noindex';
        $extra['notice'] = [
            'icon' => 'pause',
            'code' => null,
            'heading' => __('storefront.search.busy_heading'),
            'text' => __('storefront.search.busy_text'),
            'actions' => [['href' => is_string($chrome['search'] ?? null) ? $chrome['search'] : PathBuilder::SEARCH_PATH, 'icon' => null, 'kind' => null, 'label' => __('storefront.search.busy_back')]],
        ];
        $response = response($this->renderer->renderNotice(__('storefront.search.busy_heading'), $extra), 429);
        $response->headers->set('Retry-After', (string) max(1, $retryAfter));

        return $this->private($response);
    }

    private function private(Response $response): Response
    {
        $response->headers->set('Cache-Control', StorefrontSession::PRIVATE);

        return $response;
    }
}
