<?php

use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Support\PlatformAccess;

/*
 * P0.3 — genesis: the first Bảo Sơn operator is created as a platform super_admin
 * via a console command, with the password typed at a secret prompt (never an arg).
 */

beforeEach(fn () => truncateRbac());

it('creates the first operator as a platform super_admin', function (): void {
    $this->artisan('rbac:bootstrap-super-admin', ['--email' => 'ops@bsweb.vn', '--name' => 'Ops'])
        ->expectsQuestion('Password', 'change-me-strong')
        ->expectsQuestion('Confirm password', 'change-me-strong')
        ->assertSuccessful();

    $user = User::query()->firstWhere('email', 'ops@bsweb.vn');

    expect($user)->not->toBeNull()
        ->and(app(PlatformAccess::class)->isSuperAdmin($user->fresh()))->toBeTrue();
});

it('rejects a password mismatch', function (): void {
    $this->artisan('rbac:bootstrap-super-admin', ['--email' => 'ops2@bsweb.vn', '--name' => 'Ops'])
        ->expectsQuestion('Password', 'one-password')
        ->expectsQuestion('Confirm password', 'different')
        ->assertFailed();

    expect(User::query()->where('email', 'ops2@bsweb.vn')->exists())->toBeFalse();
});
