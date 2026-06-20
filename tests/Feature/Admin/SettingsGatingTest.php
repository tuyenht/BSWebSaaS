<?php

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;

/*
 * Site-wide settings (Email / Social) are restricted to roles carrying `settings.manage`
 * (owner/admin) via the route gate; personal account settings (security/2FA) are open to
 * any site member. The Livewire components also re-check `settings.manage` in boot() because
 * the /livewire/update endpoint bypasses route middleware.
 */

beforeEach(function () {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
});

it('lets a member with settings.manage open the email settings', function () {
    $admin = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($admin, 'admin', ['settings.manage', 'entries.view']));

    $this->actingAs($admin)->get('http://a.test/admin/settings/email')->assertOk();
});

it('lets a member with settings.manage open the social settings', function () {
    $admin = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($admin, 'admin', ['settings.manage', 'entries.view']));

    $this->actingAs($admin)->get('http://a.test/admin/settings/social')->assertOk();
});

it('forbids a member without settings.manage from the email settings', function () {
    $author = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($author, 'author', ['entries.view']));

    $this->actingAs($author)->get('http://a.test/admin/settings/email')->assertForbidden();
});

it('forbids a member without settings.manage from the social settings', function () {
    $author = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($author, 'author', ['entries.view']));

    $this->actingAs($author)->get('http://a.test/admin/settings/social')->assertForbidden();
});

it('lets any member open their personal security settings (no settings.manage needed)', function () {
    $author = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($author, 'author', ['entries.view']));

    $this->actingAs($author)->get('http://a.test/admin/settings/security')->assertOk();
});
