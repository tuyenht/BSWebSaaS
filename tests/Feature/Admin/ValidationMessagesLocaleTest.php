<?php

use App\Modules\Admin\Livewire\Login;
use App\Modules\Admin\Livewire\Settings;
use App\Modules\Admin\Livewire\Users\Index as UsersIndex;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Livewire\Livewire;

/*
 * Laravel's built-in validation messages ("The email field is required.") only translate when a
 * lang/<locale>/validation.php file exists for the active locale — and this repo deliberately
 * has none (lang/*.json only; lang:check enforces equal keys across them). Without a matching
 * "validation.<rule>" line in the JSON files, every screen that validates without its own custom
 * messages() leaked raw English to every non-English admin, vi included — the language most of
 * this product's admins actually read. These tests pin the fix at the locale boundary: they fail
 * on the old code (no validation.* keys) and pass once the JSON lines exist.
 */

beforeEach(function (): void {
    truncateRbac();
    seedTenancy();
    $this->artisan('rbac:sync')->assertSuccessful();
    app(TeamContext::class)->forget();
    app()->setLocale('vi');
});

it('shows the Vietnamese required message on an empty login, not the English default', function (): void {
    $component = Livewire::test(Login::class)
        ->set('email', '')
        ->set('password', '')
        ->call('login');

    $emailMessage = $component->errors()->first('email');
    $passwordMessage = $component->errors()->first('password');

    expect($emailMessage)->toBe('Email không được để trống.')
        ->and($emailMessage)->not->toContain('field is required');

    expect($passwordMessage)->toBe('Mật khẩu không được để trống.')
        ->and($passwordMessage)->not->toContain('field is required');
});

it('shows the Vietnamese max-length message on the own-profile form, not the English default', function (): void {
    $user = User::factory()->create();
    app(Tenancy::class)->enter(1, fn () => grantRole($user, 'editor', ['entries.view']));

    $component = Livewire::actingAs($user->fresh())
        ->test(Settings::class)
        ->set('profileName', str_repeat('a', 130))
        ->call('saveProfile');

    $message = $component->errors()->first('profileName');

    expect($message)->toBe('Họ và tên dài tối đa 120 ký tự.')
        ->and($message)->not->toContain('must not be greater than');
});

it('shows the Vietnamese email-format message on an invalid member invite, not the English default', function (): void {
    $admin = User::factory()->create();

    $message = app(Tenancy::class)->enter(1, function () use ($admin): string {
        grantRole($admin, 'admin', ['users.manage', 'users.invite', 'entries.view']);

        $component = Livewire::actingAs($admin->fresh())
            ->test(UsersIndex::class)
            ->set('inviteEmail', 'not-an-email')
            ->set('inviteRole', 'viewer')
            ->call('addMember');

        return $component->errors()->first('inviteEmail');
    });

    expect($message)->toBe('Email chưa đúng dạng email.')
        ->and($message)->not->toContain('must be a valid email address');
});
