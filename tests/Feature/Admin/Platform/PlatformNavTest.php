<?php

use App\Modules\Admin\Navigation\AdminNav;
use App\Modules\Admin\Navigation\NavItem;
use App\Modules\Admin\Navigation\NavSection;
use App\Modules\Admin\Navigation\PlatformNav;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\CurrentSite;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/*
 * Operator console nav (platform plane). Data-driven by platform permissions (PlatformNav): a
 * link shows only when its route EXISTS and the operator may see it. Full matrix now that S0–S4
 * exist: super_admin sees every section; support sees the view-scoped ones (tenants/sites/audit)
 * but NOT the super-only Settings; billing (tenants.view only) sees just Tenants. A tenant user
 * is forbidden; a guest is sent to the platform login.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app(PlatformAccess::class)->forgetMemo();
});

function navAs(string $role): User
{
    $user = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $user->assignRole($role));
    app(PlatformAccess::class)->forgetMemo();

    return $user->fresh();
}

it('shows super_admin every console section', function (): void {
    $host = (string) config('tenancy.platform_host');

    $this->actingAs(navAs('super_admin'))->get("http://{$host}/tenants")
        ->assertOk()
        ->assertSee(route('platform.tenants.index'))
        ->assertSee(route('platform.sites.index'))
        ->assertSee(route('platform.audit.index'))
        ->assertSee(route('platform.settings.email')); // super-only
});

it('shows support tenants+sites+audit but not the super-only Settings', function (): void {
    $host = (string) config('tenancy.platform_host');

    $this->actingAs(navAs('support'))->get("http://{$host}/tenants")
        ->assertOk()
        ->assertSee(route('platform.tenants.index'))
        ->assertSee(route('platform.sites.index'))
        ->assertSee(route('platform.audit.index'))
        ->assertDontSee(route('platform.settings.email'));
});

it('shows billing only the Tenants section', function (): void {
    $host = (string) config('tenancy.platform_host');

    $this->actingAs(navAs('billing'))->get("http://{$host}/tenants")
        ->assertOk()
        ->assertSee(route('platform.tenants.index'))
        ->assertDontSee(route('platform.sites.index'))
        ->assertDontSee(route('platform.audit.index'))
        ->assertDontSee(route('platform.settings.email'));
});

it('forbids a tenant user (no platform role) on the platform host', function (): void {
    $host = (string) config('tenancy.platform_host');
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'admin', ['users.manage', 'entries.view']));

    $this->actingAs($user)->get("http://{$host}/tenants")->assertForbidden();
});

it('redirects a guest on the platform host to the platform login', function (): void {
    $host = (string) config('tenancy.platform_host');

    $this->get("http://{$host}/tenants")->assertRedirect(route('platform.login'));
});

it('groups the console nav under headings with an icon per link, in the design order', function (): void {
    $sections = app(PlatformNav::class)->sections(navAs('super_admin'), 'platform.tenants.index');

    expect(array_map(fn (NavSection $s): string => $s->key, $sections))->toBe(['customers', 'operations'])
        ->and(array_map(fn (NavSection $s): ?string => $s->label, $sections))
        ->toBe([__('admin.platform_nav_customers'), __('admin.platform_nav_operations')]);

    $links = array_merge(...array_map(fn (NavSection $s): array => $s->items, $sections));
    expect(array_map(fn (NavItem $i): string => $i->key, $links))->toBe(['tenants', 'sites', 'audit', 'settings'])
        ->and(array_map(fn (NavItem $i): ?string => $i->icon, $links))
        ->toBe(['ri-building-2', 'ri-window', 'ri-file-shield-2', 'ri-settings-3'])
        ->and(array_map(fn (NavItem $i): ?string => $i->url, $links))
        ->toBe([route('platform.tenants.index'), route('platform.sites.index'), route('platform.audit.index'), route('platform.settings.email')]);
});

it('marks only the current page link active, its detail and sibling pages included', function (string $route, string $active): void {
    $sections = app(PlatformNav::class)->sections(navAs('super_admin'), $route);
    $links = array_merge(...array_map(fn (NavSection $s): array => $s->items, $sections));

    expect(array_values(array_map(fn (NavItem $i): string => $i->key, array_filter($links, fn (NavItem $i): bool => $i->active))))
        ->toBe([$active]);
})->with([
    'customer list' => ['platform.tenants.index', 'tenants'],
    'customer detail' => ['platform.tenants.show', 'tenants'],
    'website detail' => ['platform.sites.show', 'sites'],
    'audit log' => ['platform.audit.index', 'audit'],
    'security settings' => ['platform.settings.security', 'settings'],
]);

it('drops a heading whose links the operator may not see', function (): void {
    $sections = app(PlatformNav::class)->sections(navAs('billing'), null);

    expect($sections)->toHaveCount(1)
        ->and($sections[0]->key)->toBe('customers')
        ->and(array_map(fn (NavItem $i): string => $i->key, $sections[0]->items))->toBe(['tenants']);
});

it('returns no sections for a guest', function (): void {
    expect(app(PlatformNav::class)->sections(null, 'platform.tenants.index'))->toBe([]);
});

it('renders the sidebar with headings, the current page marked, and no link without a screen yet', function (): void {
    $host = (string) config('tenancy.platform_host');

    $html = $this->actingAs(navAs('super_admin'))->get("http://{$host}/audit")
        ->assertOk()
        ->assertSee('id="platform-sidebar"', false)
        ->assertSee(__('admin.platform_nav_customers'))
        ->assertSee(__('admin.platform_nav_operations'))
        ->assertSee(__('admin.platform_settings'))
        ->getContent();

    // aria-current sits on the audit link only.
    expect(substr_count($html, 'aria-current="page"'))->toBe(1)
        ->and($html)->toMatch('~<a href="'.preg_quote(route('platform.audit.index'), '~').'"[^>]*aria-current="page"~');

    // Screens the design still waits on have no route, so nothing points at them.
    foreach (['/plans', '/site-types', '/staff', '/impersonate'] as $path) {
        expect($html)->not->toContain('href="http://'.$host.$path);
    }
});

it('renders the console shell without touching any tenant context', function (): void {
    $host = (string) config('tenancy.platform_host');
    $operator = navAs('support');
    $queries = [];
    DB::listen(function (QueryExecuted $q) use (&$queries): void {
        $queries[] = $q->sql;
    });

    $this->actingAs($operator)->get("http://{$host}/tenants")
        ->assertOk()
        ->assertSee('id="platform-sidebar"', false);

    expect(app()->resolved(AdminNav::class))->toBeFalse()   // the tenant menu builder never ran
        ->and(app(CurrentSite::class)->hasId())->toBeFalse() // no site was entered
        ->and(collect($queries)->filter(fn (string $sql): bool => str_contains($sql, 'app.current_site_id')
            || str_contains($sql, 'content_sections') || str_contains($sql, 'site_modules'))->all())->toBe([]);
});
