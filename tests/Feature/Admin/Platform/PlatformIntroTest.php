<?php

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Rbac\Support\PlatformAccess;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Hash;

/*
 * The console host's front page. A visitor sees what the app is (the Google/Facebook sign-in app
 * reviews need a public home page that explains it); a signed-in member goes on to the console.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

it('shows a visitor the public intro instead of the sign-in page', function (): void {
    $brand = (string) config('admin.brand.name');

    $this->get('http://'.config('tenancy.platform_host').'/')
        ->assertOk()
        ->assertSee(__('intro.title', ['brand' => $brand]))
        ->assertSee(__('intro.signin_heading'))
        ->assertSee(route('platform.login'))
        ->assertSee((string) config('oauth.legal.privacy_url'))
        ->assertSee((string) config('oauth.legal.terms_url'))
        ->assertDontSee('noindex')
        // Unlike the sign-in pages it may be indexed: no header keeps it out of search either.
        ->assertHeaderMissing('X-Robots-Tag');

    expect(session('url.intended'))->toBeNull();
});

it('shows the intro to an operator who has passed the password but not the second step yet', function (): void {
    // Half-way through sign-in the session holds only the pending second-factor user; nobody is
    // signed in. «/» must treat that as a visitor, and the console itself must still ask to sign in.
    $operator = User::factory()->create(['two_factor_confirmed_at' => now()]);
    app(PlatformAccess::class)->onPlatform(fn () => $operator->assignRole('super_admin'));
    $host = 'http://'.config('tenancy.platform_host');

    $this->withSession(['2fa.user_id' => $operator->getKey()])->get($host.'/')
        ->assertOk()
        ->assertSee(__('intro.signin_heading'));
    $this->assertGuest();

    $this->withSession(['2fa.user_id' => $operator->getKey()])->get($host.'/tenants')
        ->assertRedirect(route('platform.login'));
    $this->assertGuest();
});

it('shows no console data to a session whose password has since changed', function (): void {
    // «/» itself runs no session check: it sends a signed-in member on to the console, whose
    // session check signs out a session holding the old password and sends it to sign in.
    $operator = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $operator->assignRole('super_admin'));
    $staleHash = $operator->getAuthPassword();
    $operator->forceFill(['password' => Hash::make('rotated-operator-pw-123')])->save();
    $tenant = Tenant::query()->firstOrFail();

    $response = $this->actingAs($operator->fresh())
        ->withSession(['password_hash_web' => $staleHash])
        ->followingRedirects()
        ->get('http://'.config('tenancy.platform_host').'/');

    $response->assertOk()
        ->assertDontSee(route('platform.tenants.show', $tenant->ulid))
        ->assertSee('wire:model="password"', false);
    $this->assertGuest();
});

it('leaves out a policy link whose address is not configured', function (): void {
    $host = 'http://'.config('tenancy.platform_host').'/';

    config(['oauth.legal.terms_url' => '']);
    $this->get($host)->assertOk()
        ->assertSee('class="intro-links"', false)
        ->assertDontSee('>'.__('intro.terms').'</a>', false);

    config(['oauth.legal.privacy_url' => '']);
    $this->get($host)->assertOk()->assertDontSee('class="intro-links"', false);
});

it('sends a signed-in operator on to the console', function (): void {
    $operator = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $operator->assignRole('super_admin'));

    $this->actingAs($operator)->get('http://'.config('tenancy.platform_host').'/')
        ->assertRedirect(route('platform.tenants.index'));
});

it('turns away a signed-in non-operator: the console answers forbidden, never the intro', function (): void {
    $member = User::factory()->create();

    $response = $this->actingAs($member)->followingRedirects()->get('http://'.config('tenancy.platform_host').'/');

    $response->assertForbidden()->assertDontSee(__('intro.signin_heading'));
});

it('leaves a website\'s own front page to the storefront', function (): void {
    seedHomepage(1, 'A home');

    $this->get('http://a.test/')
        ->assertOk()
        ->assertSee('A home')
        ->assertDontSee(__('intro.signin_heading'));
});
