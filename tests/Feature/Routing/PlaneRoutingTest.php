<?php

use App\Modules\Admin\Livewire\EmailOtpChallenge;
use App\Modules\Admin\Livewire\Login;
use App\Modules\Admin\Livewire\TwoFactorChallenge;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Http\Middleware\EnsurePlatformAccess;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * P0.8 — two-plane routing skeleton. Three planes with distinct middleware stacks:
 *   • storefront — public, tenant context
 *   • admin      — tenant context + auth (on the tenant's own domain, /admin)
 *   • platform   — operator host, auth + platform role
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

// ── Storefront ────────────────────────────────────────────────────────────────
it('serves the storefront on a tenant host within its context', function (): void {
    seedHomepage(1, 'A home');
    seedHomepage(2, 'B home');

    expect($this->get('http://a.test/')->assertOk()->getContent())->toContain('A home');
    expect($this->get('http://b.test/')->assertOk()->getContent())->toContain('B home');
});

it('404s the storefront on an unknown host', function (): void {
    $this->get('http://nope.test/')->assertNotFound();
});

it('refuses a suspended site', function (): void {
    seedHomepage(1, 'A home');
    DB::connection('pgsql_admin')->table('sites')->where('id', 2)->update(['status' => 'suspended']);

    $this->get('http://a.test/')->assertOk();
    $this->get('http://b.test/')->assertForbidden();
});

// ── Tenant admin ────────────────────────────────────────────────────────────────
it('redirects an unauthenticated visitor on the admin plane to the tenant login', function (): void {
    $this->get('http://a.test/admin')->assertRedirect('http://a.test/admin/login');
});

it('serves the admin shell to a site member in tenant context', function (): void {
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'editor', ['entries.view']));

    $this->actingAs($user)->get('http://a.test/admin')
        ->assertOk()
        ->assertSee(__('admin.dashboard'));
});

// ── Platform ────────────────────────────────────────────────────────────────────
it('redirects an unauthenticated visitor on the platform plane to login', function (): void {
    $this->get('http://platform.bswebsaas.test/')->assertRedirect('http://platform.bswebsaas.test/login');
});

it('forbids a non-operator on the platform plane', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('http://platform.bswebsaas.test/')->assertForbidden();
});

it('refuses a non-operator at login on the platform plane, before any session', function (): void {
    // A valid, verified tenant user (no platform role) submitting correct credentials on the
    // operator host is refused at login. The gate runs before 2FA is staged, so no authenticated
    // session is ever established on the platform plane — not merely blocked downstream.
    $user = User::factory()->create(); // random verified email, factory password 'password'
    $this->app->instance('request', Request::create('http://platform.bswebsaas.test/login', 'GET'));

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'password')
        ->call('login')
        ->assertHasErrors('email');

    $this->assertGuest();
    expect(session('2fa.user_id'))->toBeNull();
});

it('lets a platform operator through login to the mandatory second factor', function (): void {
    // Positive control: the gate must not over-block. A real operator with correct credentials
    // still reaches the mandatory 2FA challenge on the platform plane.
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
    app(PlatformAccess::class)->onPlatform(fn () => $user->assignRole('super_admin'));
    $this->app->instance('request', Request::create('http://platform.bswebsaas.test/login', 'GET'));

    Livewire::test(Login::class)
        ->set('email', $user->email)
        ->set('password', 'password')
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect(route('platform.two-factor.challenge'));

    $this->assertGuest();
    expect(session('2fa.user_id'))->not->toBeNull();
});

it('guards every authenticated platform-host route with the platform middleware', function (): void {
    // Security invariant: any route on the operator host that requires auth must ALSO carry the
    // `platform` gate. Guest routes (login/forgot/reset/challenges) have no `auth` and are
    // excluded; the shared /livewire/update route is not on the platform domain. A new protected
    // platform route that forgot `platform` would be reachable by any authenticated tenant user.
    $platformHost = (string) config('tenancy.platform_host');

    $has = fn (Route $route, string $alias, string $class): bool => collect($route->gatherMiddleware())
        ->contains(fn (mixed $m): bool => $m === $alias || $m === $class || str_starts_with((string) $m, $alias.':'));

    $unguarded = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn (Route $route): bool => $route->getDomain() === $platformHost)
        ->filter(fn (Route $route): bool => $has($route, 'auth', Authenticate::class))
        ->reject(fn (Route $route): bool => $has($route, 'platform', EnsurePlatformAccess::class))
        ->map(fn (Route $route): string => $route->uri())
        ->values()
        ->all();

    expect($unguarded)->toBe([]);
});

it('throttles a valid non-operator on the platform plane exactly like a bad password (no oracle)', function (): void {
    // A valid tenant credential refused on the operator host must ACCUMULATE the per-email throttle
    // so it locks out at MAX_ATTEMPTS identically to a wrong password. Otherwise the throttle timing
    // is a credential-validity oracle ("never locked out" ⇒ valid non-operator account).
    $user = User::factory()->create();
    $this->app->instance('request', Request::create('http://platform.bswebsaas.test/login', 'GET'));
    $key = 'admin-login:'.sha1(Str::lower($user->email).'|'.(string) request()->ip());

    foreach (range(1, 5) as $ignored) {
        Livewire::test(Login::class)
            ->set('email', $user->email)->set('password', 'password')
            ->call('login')->assertHasErrors('email');
    }

    // Pre-fix this was false (clear() reset the counter every attempt); the gate must leave it tripped.
    expect(RateLimiter::tooManyAttempts($key, 5))->toBeTrue();
});

it('never authenticates a non-operator on the platform plane at the TOTP challenge', function (): void {
    // Defense in depth: even if a 2fa.user_id were somehow staged for a non-operator on the operator
    // host, the challenge must refuse to Auth::login them — the operator-only rule holds at the point
    // of session creation, not only at the login gate.
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
    $this->app->instance('request', Request::create('http://platform.bswebsaas.test/two-factor/challenge', 'GET'));
    session(['2fa.user_id' => $user->getKey()]);

    Livewire::test(TwoFactorChallenge::class)
        ->set('code', '000000')
        ->call('verify')
        ->assertRedirect('http://platform.bswebsaas.test/login');

    $this->assertGuest();
    expect(session('2fa.user_id'))->toBeNull();
});

it('never authenticates a non-operator on the platform plane at the email-OTP challenge', function (): void {
    $user = User::factory()->create();
    $this->app->instance('request', Request::create('http://platform.bswebsaas.test/otp/challenge', 'GET'));
    session(['2fa.user_id' => $user->getKey()]);

    Livewire::test(EmailOtpChallenge::class)
        ->set('code', '000000')
        ->call('verify')
        ->assertRedirect('http://platform.bswebsaas.test/login');

    $this->assertGuest();
    expect(session('2fa.user_id'))->toBeNull();
});

it('lands an authenticated platform operator on the Tenants console', function (): void {
    $user = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $user->assignRole('super_admin'));

    // 2FA is enforced at login (TOTP or email-OTP), not by a console middleware gate; the
    // platform root now lands the operator on the Tenants list (S0 — the operator console's
    // home), replacing the earlier System-Email redirect.
    $this->actingAs($user)->get('http://platform.bswebsaas.test/')
        ->assertRedirect(route('platform.tenants.index'));
});

it('serves the System Email console to a super_admin operator', function (): void {
    $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
    app(PlatformAccess::class)->onPlatform(fn () => $user->assignRole('super_admin'));

    $this->actingAs($user)->get(route('platform.settings.email'))
        ->assertOk()
        ->assertSee(__('admin.system_email'));
});
