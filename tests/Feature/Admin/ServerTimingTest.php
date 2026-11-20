<?php

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;

/*
 * A signed-in admin's browser is told how long the server took and how much of it was SQL
 * (Server-Timing, read in DevTools), so a slow page change can be split into server and network.
 * A guest never gets the header.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

it('tells a signed-in admin the server and SQL time of the page', function (): void {
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'editor', ['entries.view']));

    $timing = (string) $this->actingAs($user->fresh())->get('http://a.test/admin/content')
        ->assertOk()
        ->headers->get('Server-Timing');

    expect($timing)->toMatch('/^app;dur=\d+\.\d;desc="server", db;dur=\d+\.\d;desc="sql"$/');

    preg_match_all('/dur=(\d+\.\d)/', $timing, $m);
    expect((float) $m[1][1])->toBeGreaterThan(0.0)                     // the page's own SQL is counted
        ->toBeLessThanOrEqual((float) $m[1][0]);                       // and is part of the page time
});

it('gives a guest no timings', function (): void {
    $this->get('http://a.test/admin/content')
        ->assertRedirect()
        ->assertHeaderMissing('Server-Timing');
});
