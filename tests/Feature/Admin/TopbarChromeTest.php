<?php

use App\Modules\Admin\Navigation\PlatformNav;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\Tenancy;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;
use Spatie\Permission\PermissionRegistrar;

/*
 * Velzon-parity admin topbar chrome (V-A2 branding): the shared tools partial
 * (language · fullscreen · light/dark · notifications) + the user area (avatar +
 * name + primary-role line) with an account menu wired to REAL routes only. The
 * platform console reuses the tools but carries no tenant account links.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

it('shows the user name, primary-role label, and a real-flag language switcher', function (): void {
    $user = User::factory()->create(['name' => 'Topbar Tester']);
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'editor', ['entries.view']));

    $this->actingAs($user->fresh())->get('http://a.test/admin/content')
        ->assertOk()
        ->assertSee('Topbar Tester', false)          // user area shows the name
        ->assertSee('Editor', false)                 // Str::headline('editor') — primary-role label
        ->assertSee('langOpen', false)               // language switcher rendered
        ->assertSee('images/admin/flags/', false);   // a vendored flag image, not an emoji
});

it('wires the user menu to real routes and keeps notifications an honest placeholder', function (): void {
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'editor', ['entries.view']));

    $this->actingAs($user->fresh())->get('http://a.test/admin/content')
        ->assertOk()
        ->assertSee(route('admin.settings.security', absolute: false), false) // "My Account" → own-account security (no profile route exists)
        ->assertSee(route('admin.logout', absolute: false), false)            // sign-out posts to the real logout route
        ->assertSee('notifOpen', false)                                       // notifications button present
        ->assertDontSee('topbar-badge', false)                                // NO Velzon fake unread-count badge
        ->assertSee("adminTheme.set('scheme'", false)                         // light/dark toggle shares the Customizer store
        ->assertSee('requestFullscreen', false);                              // fullscreen toggle present
});

it('renders the sidebar logo at the reference sizes (48px full, 22px collapsed mark)', function (): void {
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'editor', ['entries.view']));

    $this->actingAs($user->fresh())->get('http://a.test/admin/content')
        ->assertOk()
        ->assertSee('brand-logo--light h-12', false)   // full logo = 48px
        ->assertSee('brand-logo--sm h-[22px]', false); // collapsed rail mark = 22px
});

it('gives the platform console the shared tools but omits tenant account links', function (): void {
    $host = (string) config('tenancy.platform_host');
    $operator = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $operator->assignRole('super_admin'));
    app(PlatformAccess::class)->forgetMemo();

    $this->actingAs($operator->fresh())->get("http://{$host}/tenants")
        ->assertOk()
        ->assertSee('langOpen', false)                                            // shared topbar tools present
        ->assertSee("adminTheme.set('scheme'", false)
        ->assertSee('Super Admin', false)                                         // operator's primary-role label
        ->assertSee(route('platform.logout', absolute: false), false)            // platform sign-out
        ->assertDontSee(route('admin.settings.security', absolute: false), false); // no tenant account link on the operator plane
});

/** The page as an HTML5 document, so a test can check the shell's structure and attributes. */
function platformShellDom(string $html): HTMLDocument
{
    return HTMLDocument::createFromString($html, LIBXML_NOERROR);
}

/** One element the test needs; fails the test when it is missing. */
function platformShellOne(HTMLDocument $dom, string $selector): Element
{
    $element = $dom->querySelector($selector);
    expect($element)->toBeInstanceOf(Element::class, "missing: {$selector}");
    assert($element instanceof Element);

    return $element;
}

/** @return list<string> */
function platformShellClasses(Element $element): array
{
    return array_values(array_filter(explode(' ', (string) $element->getAttribute('class'))));
}

it('gives the platform console a menu button that drives the drawer, the laid-over menu and the collapse', function (): void {
    $host = (string) config('tenancy.platform_host');
    $operator = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $operator->assignRole('super_admin'));
    app(PlatformAccess::class)->forgetMemo();

    $dom = platformShellDom((string) $this->actingAs($operator->fresh())->get("http://{$host}/tenants")->assertOk()->getContent());

    // One menu button, inside the topbar, pointing at the sidebar. It stays at every width (no
    // xl:hidden): from 1280px it collapses the sidebar to the rail. Its name is fixed; the title
    // and aria-expanded follow the state (drawer/laid-over menu open, or the sidebar size from 1280px).
    expect($dom->querySelectorAll('[aria-controls="platform-sidebar"]'))->toHaveCount(1);
    $button = platformShellOne($dom, 'header > button[aria-controls="platform-sidebar"]');
    expect($button->getAttribute('type'))->toBe('button')
        ->and($button->getAttribute('aria-label'))->toBe(__('admin.menu'))
        ->and($button->getAttribute('aria-expanded'))->toBe('false')
        ->and($button->getAttribute('@click'))->toBe('toggle()')
        ->and($button->getAttribute(':aria-expanded'))->toBe("expanded ? 'true' : 'false'")
        ->and((string) $button->getAttribute(':title'))->toContain(__('admin.collapse_menu'))->toContain(__('admin.expand_menu'))
        ->and(platformShellClasses($button))->not->toContain('xl:hidden');

    // The shell's state lives on its wrapper: Esc closes; the toggle saves the shared sidebar size.
    $shell = platformShellOne($dom, 'div.platform-shell');
    expect($shell->getAttribute('@keydown.escape.window'))->toBe('close()')
        ->and($shell->getAttribute(':data-nav-open'))->toBe("open ? '' : null")
        ->and((string) $shell->getAttribute('x-data'))->toContain("\$store.adminTheme.set('sidebar_size'");

    // The open drawer / laid-over menu is a dialog: Tab stays inside and the page does not scroll,
    // but the page is never hidden from screen readers — the drawer has no close button of its own,
    // so the menu button must stay reachable. The closed phone drawer is inert.
    $aside = platformShellOne($dom, 'aside#platform-sidebar');
    expect($aside->getAttribute('x-trap.noscroll.noautofocus'))->toBe('open && ! wide')
        ->and($aside->hasAttribute('x-trap.inert.noscroll.noautofocus'))->toBeFalse()
        ->and($aside->hasAttribute(':aria-modal'))->toBeFalse()
        ->and($aside->getAttribute(':role'))->toBe("open && ! wide ? 'dialog' : null")
        ->and((string) $aside->getAttribute(':aria-label'))->toContain(__('admin.menu'))
        ->and($aside->getAttribute('x-effect'))->toBe('$el.inert = mobile && ! open')
        ->and($aside->querySelector('nav[aria-label] a[href]'))->toBeInstanceOf(Element::class);

    // The backdrop closes the menu and fades through the layout's own class (none for reduced motion).
    $backdrop = platformShellOne($dom, 'div.platform-shell > div[aria-hidden="true"]');
    expect($backdrop->getAttribute('@click'))->toBe('close()')
        ->and($backdrop->getAttribute('x-transition:enter'))->toBe('platform-fade')
        ->and($backdrop->getAttribute('x-transition:leave'))->toBe('platform-fade');

    // With a sidebar, the topbar logo is the phone/tablet way home; the sidebar carries it from 1024px.
    $home = platformShellOne($dom, 'header > a[href="'.route('platform.dashboard').'"]');
    expect(platformShellClasses($home))->toContain('lg:hidden')
        ->and($aside->querySelector('a[href="'.route('platform.dashboard').'"]'))->toBeInstanceOf(Element::class);
});

it('keeps the logo and a way home in the topbar at every width when the operator may see no console screen', function (): void {
    $host = (string) config('tenancy.platform_host');
    $operator = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(function () use ($operator): void {
        $operator->assignRole('billing');
        $operator->roles()->firstOrFail()->revokePermissionTo('platform.tenants.view');
    });
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    app(PlatformAccess::class)->forgetMemo();
    $operator = $operator->fresh();

    // Still an operator (the console lets them in), but no nav link is theirs to see.
    expect(app(PlatformAccess::class)->isOperator($operator))->toBeTrue()
        ->and(app(PlatformNav::class)->sections($operator, null))->toBe([]);

    // No console screen opens for this operator, so the layout is rendered on a probe route that
    // sits behind the console's own middleware.
    Route::domain($host)->middleware(['web', 'auth', 'platform'])
        ->get('/__shell-probe', fn () => view('admin::layouts.platform', ['slot' => new HtmlString('<p>probe</p>')]));

    $dom = platformShellDom((string) $this->actingAs($operator)->get("http://{$host}/__shell-probe")->assertOk()->getContent());

    expect($dom->querySelector('aside#platform-sidebar'))->toBeNull()
        ->and($dom->querySelector('[aria-controls="platform-sidebar"]'))->toBeNull()
        ->and($dom->querySelector('div.platform-shell'))->toBeNull(); // no rail padding on the content

    $home = platformShellOne($dom, 'header > a[href="'.route('platform.dashboard').'"]');
    expect(platformShellClasses($home))->not->toContain('lg:hidden')
        ->and($home->firstElementChild)->toBeInstanceOf(Element::class); // the logo (or the initials chip)
});
