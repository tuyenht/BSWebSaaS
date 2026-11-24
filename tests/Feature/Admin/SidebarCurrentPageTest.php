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
        tenantName: 'Demo', siteName: 'Demo', hostname: 'demo.platform.test',
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
    $sidebar = sidebarOf((string) $this->actingAs(sidebarOwner())->get('http://demo.platform.test/admin')->assertOk()->getContent());

    expect(substr_count($sidebar, 'aria-current="page"'))->toBe(1)
        ->and($sidebar)->toMatch('/<a href="http:\/\/demo\.platform\.test\/admin"\s+wire:navigate\s+aria-label="[^"]*"\s+aria-current="page"/');
});

it('marks a page inside a group on its own link, and the group row by shape only', function (): void {
    $sidebar = sidebarOf((string) $this->actingAs(sidebarOwner())->get('http://demo.platform.test/admin/content?section=posts')->assertOk()->getContent());

    expect(substr_count($sidebar, 'aria-current="page"'))->toBe(1)
        ->and($sidebar)->toMatch('/<a href="[^"]*\/admin\/content\?section=posts"\s+wire:navigate\s+aria-current="page"/')
        // The group row is a disclosure button, not a page: it never claims aria-current.
        ->and($sidebar)->not->toMatch('/<button[^>]*aria-current/');
});

it('marks only the child row while its group is open, and the group row once the group closes', function (): void {
    $sidebar = sidebarOf((string) $this->actingAs(sidebarOwner())->get('http://demo.platform.test/admin/content?section=posts')->assertOk()->getContent());
    $mark = 'bg-sidebar-active shadow-[inset_3px_0_0_var(--color-sidebar-fg-active)]';

    preg_match('/<button\b[^>]*\bis-trail\b[^>]*>/s', $sidebar, $group);
    preg_match('/<a href="[^"]*\/admin\/content\?section=posts"[^>]*>/s', $sidebar, $child);

    expect($group)->not->toBeEmpty()
        // First paint = the group open: the group row is bold and bright, without the bar and fill…
        ->and($group[0])->toMatch('/\sclass="[^"]*\bfont-semibold\b[^"]*\btext-sidebar-fg-active\b[^"]*"/')
        ->and($group[0])->not->toMatch('/\sclass="[^"]*bg-sidebar-active[^"]*"/')
        // …which takes them back only when another group is opened (Alpine), and on the collapsed rail (.is-trail).
        ->and($group[0])->toContain(':class="(openGroup === ')
        ->and($group[0])->toContain("? 'text-sidebar-fg-active' : 'text-sidebar-fg-active ".$mark."')")
        // The bar and fill sit on the current page's own link.
        ->and($child[0] ?? '')->toContain($mark)
        ->and((string) file_get_contents(resource_path('css/admin-chrome.css')))->toContain('.admin-sidebar .is-trail {');
});
