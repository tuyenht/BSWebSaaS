<?php

use App\Modules\Rbac\Context\TeamContext;

/*
 * The shared auth views (login + 2FA/email-OTP challenges, via the auth layout) are reused on
 * BOTH planes. Their links must resolve to the CURRENT plane's routes via AdminPlane::route() —
 * they used to hardcode `admin.*`, which 404'd / pointed cross-host on the platform console
 * (no platform.locale route + URL generated from APP_URL). Regression guard for that fix.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

it('renders the platform login with platform-scoped auth links (no /admin/*)', function (): void {
    $res = $this->get('http://platform.bswebsaas.test/login')->assertOk();

    // Language switcher resolves to platform.locale on this host…
    $res->assertSee('platform.bswebsaas.test/locale?set=')
        // …and NO tenant /admin/* auth link leaks onto the platform console.
        ->assertDontSee('/admin/locale')
        ->assertDontSee('/admin/login');
});

it('switches the UI locale on the platform plane', function (): void {
    $this->get('http://platform.bswebsaas.test/locale?set=ja')
        ->assertRedirect()
        ->assertSessionHas('locale', 'ja');
});

it('ignores an unknown locale code on the platform plane', function (): void {
    $this->get('http://platform.bswebsaas.test/locale?set=zz')
        ->assertRedirect()
        ->assertSessionMissing('locale');
});

it('keeps the tenant admin login switcher on the admin routes as a full-reload link (regression)', function (): void {
    $this->get('http://a.test/admin/login')
        ->assertOk()
        ->assertSee('/admin/locale?set=')
        // The login switcher must NOT be wire:navigate: this page co-hosts the Turnstile widget, and a
        // SPA morph would blank its iframe. The in-app topbar switcher keeps wire:navigate (no Turnstile).
        ->assertDontSee('wire:navigate');
});
