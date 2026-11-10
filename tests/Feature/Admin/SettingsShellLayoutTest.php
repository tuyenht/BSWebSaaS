<?php

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;

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
