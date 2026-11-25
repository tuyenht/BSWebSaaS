<?php

use App\Models\User;
use App\Modules\Rbac\Support\PlatformAccess;

/*
 * The console host's front page. A visitor sees what the app is (the Google/Facebook sign-in app
 * reviews need a public home page that explains it); a signed-in member goes on to the console.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
});

it('shows a visitor the public intro instead of the sign-in page', function (): void {
    $brand = (string) config('admin.brand.name');

    $this->get('http://'.config('tenancy.platform_host').'/')
        ->assertOk()
        ->assertSee(__('intro.title', ['brand' => $brand]))
        ->assertSee(__('intro.signin_heading'))
        ->assertSee(route('platform.login'))
        ->assertSee((string) config('admin.brand.privacy_url'))
        ->assertSee((string) config('admin.brand.terms_url'))
        ->assertDontSee('noindex');

    expect(session('url.intended'))->toBeNull();
});

it('leaves out a policy link whose address is not configured', function (): void {
    $host = 'http://'.config('tenancy.platform_host').'/';

    config(['admin.brand.terms_url' => '']);
    $this->get($host)->assertOk()
        ->assertSee('class="intro-links"', false)
        ->assertDontSee('>'.__('intro.terms').'</a>', false);

    config(['admin.brand.privacy_url' => '']);
    $this->get($host)->assertOk()->assertDontSee('class="intro-links"', false);
});

it('sends a signed-in operator on to the console', function (): void {
    $operator = User::factory()->create();
    app(PlatformAccess::class)->onPlatform(fn () => $operator->assignRole('super_admin'));

    $this->actingAs($operator)->get('http://'.config('tenancy.platform_host').'/')
        ->assertRedirect(route('platform.tenants.index'));
});
