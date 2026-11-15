<?php

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Dom\HTMLDocument;

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

it('lets the settings section strip shrink to a phone screen instead of widening the page', function (): void {
    $admin = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($admin, 'admin', ['settings.manage', 'entries.view']));

    // a grid item keeps its content's width unless told it may shrink; the strip of section
    // links then pushed the whole page sideways on a 390px phone
    $this->actingAs($admin)->get('http://a.test/admin/settings/email')
        ->assertOk()
        ->assertSee('<nav class="min-w-0 ', false);
});

it('leads a settings section with the trail home › settings › section from 768px', function (): void {
    $admin = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($admin, 'admin', ['settings.manage', 'entries.view']));

    $html = (string) $this->actingAs($admin)->withSession(['locale' => 'vi'])->get('http://a.test/admin/settings/email')->assertOk()->getContent();
    $dom = HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $nav = $dom->querySelector('[data-set-shell] > nav[aria-label="'.__('admin.breadcrumb').'"]');

    expect($nav)->not->toBeNull()
        ->and(explode(' ', (string) $nav->getAttribute('class')))->toContain('max-md:hidden')
        ->and($nav->querySelector('a[aria-label="'.__('admin.dashboard').'"]')?->getAttribute('href'))->toBe(route('admin.dashboard'))
        ->and(trim((string) $nav->querySelector('a.underline')?->textContent))->toBe('Cài đặt')
        ->and(trim((string) $nav->querySelector('[aria-current="page"]')?->textContent))->toBe(__('admin.email_settings'));
});
