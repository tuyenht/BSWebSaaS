<?php

use App\Modules\Admin\Livewire\Catalog\Editor as CatalogEditor;
use App\Modules\Admin\Livewire\Login;
use App\Modules\Admin\Livewire\ResetPassword;
use App\Modules\Admin\Livewire\Settings;
use App\Modules\Admin\Livewire\Users\Index as UsersIndex;
use App\Modules\Identity\Models\User;
use App\Modules\Rbac\Context\TeamContext;
use App\Modules\Tenancy\Context\Tenancy;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
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

it('shows the Vietnamese required message on an empty login, naming the field by its visible label', function (): void {
    $component = Livewire::test(Login::class)
        ->set('email', '')
        ->set('password', '')
        ->call('login');

    $emailMessage = $component->errors()->first('email');
    $passwordMessage = $component->errors()->first('password');

    expect($emailMessage)->toBe('Tài khoản không được để trống.')
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

it('capitalises a field name only where it opens the sentence', function (): void {
    $errors = Validator::make(
        ['password' => 'a', 'password_confirmation' => 'b', 'phone' => ''],
        ['password' => 'confirmed', 'phone' => 'required'],
    )->errors();

    expect($errors->first('password'))->toBe('Hai lần nhập mật khẩu chưa khớp nhau.')
        ->and($errors->first('phone'))->toBe('Số điện thoại không được để trống.');
});

it('names the product field by the product label, not the shared person-name label', function (string $locale, string $expected): void {
    $editor = User::factory()->create();
    app()->setLocale($locale);

    $message = app(Tenancy::class)->enter(1, function () use ($editor): string {
        grantRole($editor, 'cat-manager', ['catalog.view', 'catalog.manage']);

        return Livewire::actingAs($editor->fresh())
            ->test(CatalogEditor::class)
            ->set('name', '')
            ->call('save')
            ->errors()->first('name');
    });

    expect($message)->toBe($expected);
})->with([
    'vi' => ['vi', 'Tên sản phẩm không được để trống.'],
    'zh' => ['zh', '产品名称不能为空。'],
]);

it('names the reset-password email field by its visible label', function (): void {
    $message = Livewire::test(ResetPassword::class)
        ->set('token', 'any')->set('email', 'not-an-email')
        ->set('password', 'a-fresh-secure-pass')->set('password_confirmation', 'a-fresh-secure-pass')
        ->call('resetPassword')
        ->errors()->first('email');

    expect($message)->toBe('Tài khoản chưa đúng dạng email.');
});

it('keeps the "and N more errors" tail of a summarised message in the site language', function (): void {
    $validator = Validator::make(['phone' => '', 'email' => ''], ['phone' => 'required', 'email' => 'required']);

    expect((new ValidationException($validator))->getMessage())
        ->toBe('Số điện thoại không được để trống. (và 1 lỗi khác)');
});
