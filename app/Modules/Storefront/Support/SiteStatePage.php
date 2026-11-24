<?php

declare(strict_types=1);

namespace App\Modules\Storefront\Support;

use App\Modules\Shared\Support\SafeLink;
use App\Modules\Storefront\Http\Middleware\StorefrontSession;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Theme\Rendering\PageRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The page a visitor gets on a site that is not open (SiteState), inside the site's own context:
 * the site's header, footer, tokens and language around one short message, in the same layout as
 * the 404 page (theme::layouts.not-found). It reads nothing of the site's content, only the chrome
 * every page reads. Kept out of search engines, with no canonical, share tags or alternates, and
 * out of every cache (`private, no-store`). The same page answers a website its owner has put under
 * maintenance (maintenance()), with the owner's words.
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
        [, $extra, $email] = $this->context($request);

        $action = match (true) {
            $state->offersReload() => self::reload($request),
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

        return $this->respond($state, $extra, $state->retryAfter());
    }

    /**
     * The maintenance page (board 26, «Đang bảo trì»): the owner's words in the language of the
     * address (in the default language an empty message leaves the heading alone; in another
     * language the platform's own sentence in that language stands in), the reopen time while it
     * is still ahead, «Tải lại trang» and the website's public phone and email. 503 with
     * Retry-After until the reopen time (an hour without one).
     *
     * `preview` is the admin's «Xem trang khách sẽ thấy» (MaintenancePreviewController): the same
     * page in the language asked for, drawn as for the home page, answered 200 with no Retry-After.
     *
     * @param  array{messages: array<string, string>, reopens_at: CarbonImmutable|null}  $maintenance
     */
    public function maintenance(Request $request, array $maintenance, string $zone, ?string $locale = null, bool $preview = false): Response
    {
        [$locale, $extra] = $this->context($request, $locale, $preview ? '/' : null);
        $state = SiteState::Maintenance;

        $message = $maintenance['messages'][$locale] ?? '';
        $text = $message !== '' ? $message : ($locale === $this->locale->default() ? '' : $state->text(false));

        $ahead = SiteMaintenance::ahead($maintenance['reopens_at']);
        $contacts = [];
        $phone = data_get($extra, 'brand.phone');
        $tel = SafeLink::tel($phone);
        if ($tel !== null && is_string($phone)) {
            $contacts[] = ['href' => $tel, 'label' => trim($phone), 'icon' => 'phone'];
        }
        $email = data_get($extra, 'brand.email');
        if (is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $contacts[] = ['href' => 'mailto:'.$email, 'label' => $email, 'icon' => 'email'];
        }

        $extra['notice'] = [
            'icon' => $state->icon(),
            'code' => null,
            'heading' => $state->heading(),
            'text' => $text,
            'when' => $ahead === null ? null : __('storefront.site_state.maintenance_when', ['time' => SiteMaintenance::format($ahead, $zone)]),
            'action' => self::reload($request),
            'contacts' => $contacts,
        ];

        return $preview
            ? $this->respond($state, $extra, null, Response::HTTP_OK)
            : $this->respond($state, $extra, SiteMaintenance::retryAfter($maintenance['reopens_at']));
    }

    /**
     * The words follow the language of the address (or the one asked for), and the page carries the
     * site's chrome, kept out of search engines.
     *
     * @return array{0: string, 1: array<string, mixed>, 2: string|null} the language, the chrome, the public email
     */
    private function context(Request $request, ?string $locale = null, ?string $path = null): array
    {
        $default = $this->locale->default();
        $locale ??= $this->localeOf($request, $default);
        // Storefront routes carry no SetLocale: the words follow the language of the address.
        app()->setLocale($locale);

        $extra = $this->chrome->for($locale, $default, $path ?? strtolower('/'.ltrim($request->path(), '/')), []);
        $extra['robots'] = 'noindex';

        $email = data_get($extra, 'brand.email');

        return [$locale, $extra, is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null];
    }

    /** @return array{href: string, label: string, icon: string} */
    private static function reload(Request $request): array
    {
        return ['href' => self::here($request), 'label' => __('storefront.site_state.reload'), 'icon' => 'refresh'];
    }

    /** @param array<string, mixed> $extra */
    private function respond(SiteState $state, array $extra, ?int $retryAfter, ?int $status = null): Response
    {
        // Answered before EdgeCacheHeaders runs, so it says itself that no cache may keep it: the
        // site may open again at any moment.
        $headers = ['Cache-Control' => StorefrontSession::PRIVATE];
        if ($retryAfter !== null) {
            $headers['Retry-After'] = (string) $retryAfter;
        }

        return response($this->renderer->renderNotice($state->heading(), $extra), $status ?? $state->httpStatus(), $headers);
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
