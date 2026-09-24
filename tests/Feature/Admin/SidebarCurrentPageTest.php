<?php

use App\Modules\Identity\Models\User;
use App\Modules\Provisioning\Actions\ProvisionSite;
use App\Modules\Provisioning\Data\ProvisionSiteData;
use Illuminate\Support\Facades\DB;

/*
 * The sidebar says which link is the page being viewed — to assistive technology (aria-current)
 * and by shape as well as color: resting items are white/.85, too close to the current item's white
 * to tell them apart by color alone (WCAG 1.4.1).
 */

beforeEach(function (): void {
    DB::connection('pgsql_admin')->statement(
        'truncate tenant_probes, site_profile_translations, site_profiles, site_settings,
         platform_settings, site_locales, site_modules, content_sections, domains, sites, tenants,
         tenant_users, users restart identity cascade'
    );
    truncateRbac();

    app(ProvisionSite::class)->handle(new ProvisionSiteData(
        tenantName: 'Demo', siteName: 'Demo', hostname: 'demo.test',
        ownerEmail: 'owner@demo.test', ownerName: 'Demo Owner',
        ownerPassword: 'a-strong-password', locales: ['vi'], modules: ['cms', 'media'],
    ));
});

function sidebarOf(string $html): string
{
    preg_match('/<aside\b.*?<\/aside>/s', $html, $sidebar);

    return $sidebar[0] ?? '';
}

function sidebarOwner(): User
{
    return User::query()->where('email', 'owner@demo.test')->firstOrFail();
}

it('marks the current page link, and only it, as the current page', function (): void {
    $sidebar = sidebarOf((string) $this->actingAs(sidebarOwner())->get('http://demo.test/admin')->assertOk()->getContent());

    expect(substr_count($sidebar, 'aria-current="page"'))->toBe(1)
        ->and($sidebar)->toMatch('/<a href="http:\/\/demo\.test\/admin"\s+wire:navigate\s+aria-label="[^"]*"\s+aria-current="page"/');
});

it('marks a page inside a group on its own link, and the group row by shape only', function (): void {
    $sidebar = sidebarOf((string) $this->actingAs(sidebarOwner())->get('http://demo.test/admin/settings/security')->assertOk()->getContent());

    expect(substr_count($sidebar, 'aria-current="page"'))->toBe(1)
        ->and($sidebar)->toMatch('/<a href="[^"]*\/admin\/settings\/security"\s+wire:navigate\s+aria-current="page"/')
        // The group row is a disclosure button, not a page: it shows the current-row look (so the
        // collapsed rail still shows where you are) without claiming aria-current.
        ->and($sidebar)->not->toMatch('/<button[^>]*aria-current/')
        ->and(substr_count($sidebar, 'bg-sidebar-active shadow-[inset_3px_0_0_var(--color-sidebar-fg-active)]'))->toBe(2);
});
