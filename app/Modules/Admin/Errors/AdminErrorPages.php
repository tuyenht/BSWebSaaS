<?php

declare(strict_types=1);

namespace App\Modules\Admin\Errors;

use App\Modules\Admin\Support\AdminPlane;
use App\Modules\Admin\Support\Locales;
use App\Modules\Admin\Theme\AdminBrandColor;
use App\Modules\Admin\Theme\AdminChromeResolver;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Support\RoleLabel;
use App\Modules\Shared\Support\Cast;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\SiteLocale;
use App\Modules\Tenancy\Exceptions\ModuleNotEnabled;
use App\Modules\Tenancy\Exceptions\SiteNotActive;
use App\Modules\Tenancy\Registry\ModuleCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use PDOException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The admin's own error pages (design board 25 "Trang lỗi quản trị"): what happened, why, whose
 * side it is on, and one button to go on — in the member's admin language. Drawn for requests to
 * the tenant admin (/admin…), the platform console host and Livewire updates; any other request
 * (the storefront, JSON callers) keeps its own handling (null).
 *
 * Inside the admin frame (menu + top bar kept) when the member is signed in and passed the
 * membership check of this request; otherwise a page of its own with the platform logo, "Về trang
 * chủ" and "Liên hệ hỗ trợ". Never shows an exception's text, a stack, a class name or a record id:
 * only fixed sentences, the member's own role, the roles that can do the job, and — for a fault of
 * ours — a short random reference also written to the log.
 *
 * Tenancy: inside a request whose site is resolved, the page reads only what the admin frame reads
 * already (menu, roles of this site), in the request's own tenant transaction. With no site (the
 * platform host, maintenance before any middleware, a 419 before the site is resolved) it reads no
 * tenant table: everything comes from config and the session.
 */
final class AdminErrorPages
{
    /** The states a page exists for; any other status keeps Laravel's own handling. */
    private const array STATUSES = [403, 404, 419, 429, 500, 503];

    public function render(Throwable $e, Request $request): ?Response
    {
        if (! self::isAdminRequest($request)
            || $e instanceof ValidationException
            || $e instanceof HttpResponseException) {
            return null;
        }
        $livewire = $request->hasHeader('X-Livewire');
        if ((! $livewire && $request->wantsJson()) || ($e instanceof AuthenticationException && ! $livewire)) {
            return null; // a page whose sign-in ended goes to the right login (bootstrap/app.php)
        }

        // A Livewire update whose sign-in ended (signed out from another device, or the password
        // changed elsewhere): "Phiên đã hết hạn", which the page opens on a 419 to sign in again.
        $status = $e instanceof AuthenticationException ? 419 : self::status($e);
        if (! in_array($status, self::STATUSES, true) || ($status === 500 && (bool) config('app.debug'))) {
            return null;
        }

        try {
            $this->applyLocale($request);
            $page = match (true) {
                $e instanceof SiteNotActive => $this->siteClosed($e->siteStatus),
                $e instanceof ModuleNotEnabled => $this->locked($e->module),
                $status === 419 => $this->expired($request),
                $status === 429 => $this->rate($e),
                $status === 503 => $this->maintenance(),
                $status === 403 => $this->noPermission($request),
                $status === 404 => $this->notFound($request),
                default => $this->systemError($e),
            };

            return $this->respond($page, $status, $request, $e, $livewire);
        } catch (Throwable $drawFailed) {
            // A page that cannot be drawn must not hide the first fault: Laravel's own page instead.
            report($drawFailed);

            return null;
        }
    }

    /** The tenant admin (/admin…), the platform console host, or a Livewire update (admin only). */
    public static function isAdminRequest(Request $request): bool
    {
        if ($request->getHost() === config('tenancy.platform_host')) {
            return true;
        }
        $prefix = Cast::string(config('admin.prefix', 'admin'));

        return $request->is($prefix, $prefix.'/*') || ($request->is('livewire/update') && $request->hasHeader('X-Livewire'));
    }

    private static function status(Throwable $e): int
    {
        return match (true) {
            $e instanceof TokenMismatchException => 419,
            $e instanceof AuthorizationException => $e->hasStatus() ? (int) $e->status() : 403,
            $e instanceof ModelNotFoundException, $e instanceof RecordsNotFoundException => 404,
            $e instanceof HttpExceptionInterface => $e->getStatusCode(),
            default => 500,
        };
    }

    /**
     * The admin language of this request, as SetLocale picks it (that middleware may not have run
     * yet when the fault happened): the member's own switch, else the site's default language.
     * The session is read only when this request started one (not during maintenance).
     */
    private function applyLocale(Request $request): void
    {
        $locale = $request->hasSession() ? $request->session()->get('locale') : null;
        if (! is_string($locale) || ! in_array($locale, Locales::codes(), true)) {
            $locale = app(CurrentSite::class)->hasId() ? app(SiteLocale::class)->default() : null;
        }
        if (is_string($locale) && in_array($locale, Locales::codes(), true)) {
            app()->setLocale($locale);
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private static function page(string $key, string $title, string $line, array $extra = []): array
    {
        return array_merge([
            'key' => $key, 'tone' => '', 'icon' => 'ri-error-warning', 'num' => null, 'title' => $title, 'line' => $line,
            'groups' => [], 'primary' => null, 'secondary' => [], 'why' => null, 'code' => null, 'support' => null,
            'retryAfter' => null, 'inShell' => false,
        ], $extra);
    }

    /**
     * @return array{label: string, icon: string, href: string|null, action: string|null}
     */
    private static function action(string $label, string $icon, ?string $href = null, ?string $action = null): array
    {
        return ['label' => $label, 'icon' => $icon, 'href' => $href, 'action' => $action];
    }

    /**
     * The support box: every configured channel. With a phone, the main button calls it and the
     * box says "Hoặc nhắn…" (the number is then in the box too, never cut on a narrow phone).
     *
     * @return array{title: string, channels: list<array{icon: string, label: string, href: string}>}|null
     */
    private static function supportBox(string $title): ?array
    {
        $links = SupportChannels::links();

        return $links === [] ? null : ['title' => $title, 'channels' => $links];
    }

    /**
     * "Gọi hỗ trợ" as the main button (when a phone is configured) + the box of every channel.
     *
     * @return array<string, mixed>
     */
    private static function callSupport(): array
    {
        $tel = SupportChannels::all()['tel'];

        return [
            'primary' => $tel !== '' ? self::action(__('errors.call_support'), 'ri-phone', $tel) : null,
            'support' => self::supportBox($tel !== '' ? __('errors.support_or') : __('errors.support_box')),
        ];
    }

    /** @return array<string, mixed> */
    private function siteClosed(string $siteStatus): array
    {
        return match ($siteStatus) {
            'provisioning' => self::page('provisioning', __('errors.provisioning.title'), __('errors.provisioning.line'), [
                'icon' => 'ri-hammer',
                'primary' => self::action(__('errors.reload'), 'ri-refresh', null, 'reload'),
                'support' => self::supportBox(__('errors.support_box')),
            ]),
            'archived' => self::page('archived', __('errors.archived.title'), __('errors.archived.line'), [
                'tone' => 'muted', 'icon' => 'ri-archive', ...self::callSupport(),
            ]),
            default => self::page('suspended', __('errors.suspended.title'), __('errors.suspended.line'), [
                'tone' => 'muted', 'icon' => 'ri-pause-circle', ...self::callSupport(),
            ]),
        };
    }

    /** @return array<string, mixed> */
    private function locked(string $module): array
    {
        // The module's name as the catalog labels it (vi/en/ja; other languages read the English one).
        $name = null;
        foreach (ModuleCatalog::all() as $entry) {
            if ($entry['key'] === $module) {
                $name = $entry['label'][app()->getLocale()] ?? $entry['label']['en'];
            }
        }

        return self::page('lock', __('errors.lock.title', ['module' => $name ?? __('errors.lock.this_item')]), __('errors.lock.line'), [
            'tone' => 'muted', 'icon' => 'ri-lock', 'inShell' => true, ...self::callSupport(),
        ]);
    }

    /** @return array<string, mixed> */
    private function expired(Request $request): array
    {
        // Back to the page the member was on once they sign in again (this host's admin only).
        $referer = (string) $request->headers->get('referer');
        $query = (string) parse_url($referer, PHP_URL_QUERY);
        $path = (string) parse_url($referer, PHP_URL_PATH).($query !== '' ? '?'.$query : '');
        $back = self::safeReturnPath($request, $path, (string) parse_url($referer, PHP_URL_HOST));
        if ($back !== null && $request->hasSession()) {
            $request->session()->put('url.intended', $request->getSchemeAndHttpHost().$back);
        }

        return self::expiredPage();
    }

    /** @return array<string, mixed> */
    public static function expiredPage(): array
    {
        return self::page('expired', __('errors.expired.title'), __('errors.expired.line'), [
            'icon' => 'ri-time',
            'primary' => self::action(__('errors.expired.login'), 'ri-login-box', AdminPlane::route('login')),
        ]);
    }

    /**
     * A path on this host's own admin plane to come back to, with its query string (a list's
     * filter, the Thùng rác tab), or null (anything else: another host, a scheme, a
     * protocol-relative or odd path, a backslash or control character, the sign-in pages).
     */
    public static function safeReturnPath(Request $request, string $path, string $host = ''): ?string
    {
        if ($host !== '' && $host !== $request->getHost()) {
            return null;
        }
        $cut = strpos($path, '?');
        $query = $cut === false ? null : substr($path, $cut + 1);
        $path = $cut === false ? $path : substr($path, 0, $cut);
        if ($path === '' || ! str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\')
            || preg_match('#^/[A-Za-z0-9/_\-.~%]*$#', $path) !== 1) {
            return null;
        }
        // The query only ever follows this host's own path, so it cannot change where the browser
        // goes: printable characters only, no backslash, no fragment.
        if ($query !== null && preg_match('#^[A-Za-z0-9/_\-.~%=&+,:;@!$\'()*\[\]?]*$#', $query) !== 1) {
            return null;
        }
        $prefix = '/'.trim(Cast::string(config('admin.prefix', 'admin')), '/');
        if (! AdminPlane::isPlatform() && $path !== $prefix && ! str_starts_with($path, $prefix.'/')) {
            return null;
        }
        if (preg_match('#/(login|logout|session-expired|forgot-password|reset-password|two-factor|otp)(/|$)#', $path) === 1) {
            return null;
        }

        return $query === null || $query === '' ? $path : $path.'?'.$query;
    }

    /** @return array<string, mixed> */
    private function rate(Throwable $e): array
    {
        $headers = $e instanceof HttpExceptionInterface ? $e->getHeaders() : [];
        $retry = $headers['Retry-After'] ?? null;
        $seconds = is_numeric($retry) ? max(1, (int) $retry) : 30;

        return self::page('rate', __('errors.rate.title'), __('errors.rate.wait', ['seconds' => $seconds]), [
            'tone' => 'warn', 'icon' => 'ri-timer', 'inShell' => true, 'retryAfter' => $seconds,
            'primary' => self::action(__('errors.retry'), 'ri-refresh', null, 'retry'),
            'why' => __('errors.rate.why'),
        ]);
    }

    /** @return array<string, mixed> */
    private function maintenance(): array
    {
        $end = MaintenanceWindow::expectedEnd();
        $line = $end !== null
            ? __('errors.maint.line', ['time' => $end->format('H:i d/m/Y')])
            : __('errors.maint.line_plain');

        return self::page('maint', __('errors.maint.title'), $line, [
            'icon' => 'ri-tools',
            'primary' => self::action(__('errors.reload'), 'ri-refresh', null, 'reload'),
        ]);
    }

    /** @return array<string, mixed> */
    private function systemError(Throwable $e): array
    {
        // A logged fault already carries its reference (bootstrap/app.php adds it before the log
        // line). One that was not logged (an abort(500)), even after another fault of the same
        // request was, gets a reference and a log line of its own: support must find this one.
        $ref = ErrorReference::of($e);
        if ($ref === null) {
            $ref = ErrorReference::assign($e);
            Log::warning('Admin error page shown', ['status' => 500, 'exception' => $e::class, 'error_ref' => $ref]);
        }

        return self::page('e500', __('errors.e500.title'), __('errors.e500.line'), [
            'tone' => 'danger', 'icon' => 'ri-error-warning', 'num' => '500', 'inShell' => ! self::isDatabaseFault($e),
            'primary' => self::action(__('errors.retry'), 'ri-refresh', null, 'reload'),
            'code' => $ref,
            'support' => self::supportBox(__('errors.support')),
        ]);
    }

    private static function isDatabaseFault(Throwable $e): bool
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof QueryException || $cause instanceof PDOException) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function noPermission(Request $request): array
    {
        $user = Auth::user();
        $member = $user instanceof User && $request->attributes->get('admin.member') === true;
        $role = $member ? RoleLabel::topOf($user) : null;
        $screen = self::screenTitle($request);
        $permission = self::routePermission($request);

        return self::page('noperm', __('errors.noperm.title', ['screen' => $screen ?? __('errors.this_item')]),
            $role !== null ? __('errors.noperm.line', ['role' => $role]) : __('errors.noperm.line_plain'), [
                'icon' => 'ri-shield-keyhole', 'inShell' => true,
                'groups' => $member && $permission !== null ? $this->rolesHolding($permission) : [],
                'primary' => $member ? self::action(__('errors.back_dashboard'), 'ri-dashboard', self::home()) : null,
            ]);
    }

    /** @return array<string, mixed> */
    private function notFound(Request $request): array
    {
        $member = Auth::user() instanceof User && $request->attributes->get('admin.member') === true;
        $list = $member ? self::listOf($request) : null;
        $extra = ['icon' => 'ri-file-search', 'num' => '404', 'inShell' => true];

        if ($list === null) {
            $extra['primary'] = self::action(__('errors.back_dashboard'), 'ri-dashboard', $member ? self::home() : AdminPlane::route('login'));
        } elseif ($list['trash'] !== null) {
            $extra['primary'] = self::action(__('errors.notfound.trash'), 'ri-delete-bin-2', $list['trash']);
            $extra['secondary'] = [self::action(__('errors.notfound.list'), 'ri-list-check', $list['url'])];
        } else {
            $extra['primary'] = self::action(__('errors.notfound.list'), 'ri-list-check', $list['url']);
        }

        return self::page('notfound', __('errors.notfound.title'), __('errors.notfound.line'), $extra);
    }

    /** The plane's first page: Tổng quan on a site, the customer list on the console. */
    public static function home(): string
    {
        return AdminPlane::isPlatform() ? route('platform.tenants.index') : route('admin.dashboard');
    }

    /**
     * The list a missing record belonged to, and its Thùng rác tab when the member may open it.
     *
     * @return array{url: string, trash: string|null}|null
     */
    private static function listOf(Request $request): ?array
    {
        $name = (string) $request->route()?->getName();

        return match (true) {
            str_starts_with($name, 'admin.entries.') => [
                'url' => route('admin.entries.index'),
                'trash' => Gate::any(['entries.delete.own', 'entries.delete.any']) ? route('admin.entries.index', ['tab' => 'trash']) : null,
            ],
            str_starts_with($name, 'admin.catalog.') => [
                'url' => route('admin.catalog.index'),
                'trash' => Gate::allows('catalog.manage') ? route('admin.catalog.index', ['tab' => 'trash']) : null,
            ],
            str_starts_with($name, 'admin.forms.') => ['url' => route('admin.forms.index'), 'trash' => null],
            str_starts_with($name, 'platform.tenants.') => ['url' => route('platform.tenants.index'), 'trash' => null],
            str_starts_with($name, 'platform.sites.') => ['url' => route('platform.sites.index'), 'trash' => null],
            default => null,
        };
    }

    /** The screen the member tried to open, as the menu names it; null when it is not a known screen. */
    private static function screenTitle(Request $request): ?string
    {
        $name = (string) $request->route()?->getName();
        $titles = [
            'admin.users.' => 'admin.members.title',
            'admin.settings.domains' => 'admin.domains_title',
            'admin.settings.' => 'admin.settings',
            'admin.entries.' => 'errors.screen.content',
            'admin.catalog.' => 'admin.catalog',
            'admin.taxonomies.' => 'admin.categories',
            'admin.menus.' => 'admin.menu_builder',
            'admin.forms.' => 'leads.title',
            'admin.media.' => 'media.nav_label',
            'platform.tenants.' => 'admin.platform_tenants',
            'platform.sites.' => 'admin.platform_sites',
        ];
        foreach ($titles as $prefix => $key) {
            if ($name === $prefix || str_starts_with($name, $prefix)) {
                return __($key);
            }
        }

        return null;
    }

    /** The permission the route's own `can:` gate asked for, when it names one plainly. */
    private static function routePermission(Request $request): ?string
    {
        foreach ($request->route()?->gatherMiddleware() ?? [] as $middleware) {
            if (is_string($middleware) && preg_match('/^can:([a-z0-9_.]+)$/', $middleware, $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * This site's roles that hold $permission (the owner always can), as people read them.
     *
     * @return list<string>
     */
    private function rolesHolding(string $permission): array
    {
        $site = app(CurrentSite::class);
        if (! $site->hasId()) {
            return [];
        }
        $roles = Role::query()->where('site_id', $site->id())
            ->where(fn ($q) => $q->where('name', 'owner')->orWhereHas('permissions', fn ($p) => $p->where('name', $permission)))
            ->select(['id', 'name', 'is_system', 'level'])->orderByDesc('level')->limit(20)->get();

        return array_values($roles->map(fn (Role $role): string => RoleLabel::for($role))->all());
    }

    /**
     * @param  array<string, mixed>  $page
     */
    private function respond(array $page, int $status, Request $request, Throwable $e, bool $livewire): Response
    {
        $headers = ['Cache-Control' => 'no-store, private'];
        $retry = $e instanceof HttpExceptionInterface ? ($e->getHeaders()['Retry-After'] ?? null) : null;
        if (is_int($retry) || is_string($retry)) {
            $headers['Retry-After'] = (string) $retry;
        }

        $inShell = $page['inShell'] === true && ! $livewire && Auth::user() instanceof User
            && $request->attributes->get('admin.member') === true;
        if ($inShell) {
            try {
                $layout = AdminPlane::isPlatform() ? 'admin::layouts.platform' : 'admin::layouts.app';

                return response()->view('admin::errors.shell', ['page' => $page, 'layout' => $layout], $status, $headers);
            } catch (Throwable) {
                // the frame could not be drawn (its own reads failed): the page on its own instead
            }
        }

        return response()->view('admin::errors.page', [
            'page' => $page,
            'chrome' => $this->chrome($status, $e),
            'brandTokens' => AdminBrandColor::cssVariables(config('admin.brand.color')),
        ], $status, $headers);
    }

    /**
     * The member's light/dark choice for a page on its own — skipped when the database may be the
     * fault or is not to be touched (maintenance), and never allowed to fail the page.
     *
     * @return array<string, string>
     */
    private function chrome(int $status, Throwable $e): array
    {
        // No site resolved on a tenant host (a 419 before the site is known): no read at all.
        if ($status === 503 || self::isDatabaseFault($e) || (! AdminPlane::isPlatform() && ! app(CurrentSite::class)->hasId())) {
            return [];
        }
        try {
            $user = Auth::user();
            $attributes = app(AdminChromeResolver::class)->attributes($user instanceof User ? $user : null);

            return array_filter($attributes, fn (string $attr): bool => $attr === 'data-bs-theme', ARRAY_FILTER_USE_KEY);
        } catch (Throwable) {
            return [];
        }
    }
}
