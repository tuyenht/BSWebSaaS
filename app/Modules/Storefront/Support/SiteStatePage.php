<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Support;

use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Theme\Rendering\PageRenderer;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The page a visitor gets on a site that is not open (SiteState), inside the site's own context:
 * the site's header, footer, tokens and language around one short message, in the same layout as
 * the 404 page (theme::layouts.not-found). It reads nothing of the site's content, only the chrome
 * every page reads. Kept out of search engines, with no canonical, share tags or alternates.
 */
final readonly class SiteStatePage
{
    public function __construct(
        private SiteLocale $locale,
        private PageChrome $chrome,
        private PageRenderer $renderer,
    ) {}

    public function render(Request $request, SiteState $state): Response
    {
        $default = $this->locale->default();
        $locale = $this->localeOf($request, $default);
        // Storefront routes carry no SetLocale: the words follow the language of the address.
        app()->setLocale($locale);

        $extra = $this->chrome->for($locale, $default, strtolower('/'.ltrim($request->path(), '/')), []);
        $extra['robots'] = 'noindex';

        $email = data_get($extra, 'brand.email');
        $email = is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;

        $action = match (true) {
            $state->offersReload() => ['href' => self::here($request), 'label' => __('storefront.site_state.reload'), 'icon' => 'refresh'],
            $email !== null => ['href' => 'mailto:'.$email, 'label' => __('storefront.site_state.email'), 'icon' => 'email'],
            default => null,
        };

        $extra['notice'] = [
            'icon' => $state->icon(),
            'code' => null,
            'heading' => $state->heading(),
            'text' => $state->text($email !== null),
            'action' => $action,
        ];

        $headers = $state->retryAfter() === null ? [] : ['Retry-After' => (string) $state->retryAfter()];

        return response($this->renderer->renderNotice($state->heading(), $extra), $state->httpStatus(), $headers);
    }

    /**
     * The address the visitor opened, as a path on this host (query dropped) for the "reload"
     * link. Built from the trimmed path, so `//other.host/x` becomes `/other.host/x` and never a
     * link off the site.
     */
    public static function here(Request $request): string
    {
        return '/'.ltrim($request->path(), '/');
    }

    /**
     * The language of the address: a public, non-default language's prefix selects it, anything
     * else is the site's default (the same reading StorefrontController makes, without redirects).
     */
    private function localeOf(Request $request, string $default): string
    {
        $first = strtolower(explode('/', trim($request->path(), '/'))[0]);

        return $first !== $default && preg_match('/^[a-z]{2}(-[a-z0-9]{2,8})?$/', $first) === 1 && $this->locale->isPublic($first)
            ? $first
            : $default;
    }
}
