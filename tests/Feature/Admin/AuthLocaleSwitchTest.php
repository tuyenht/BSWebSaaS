<?php

declare(strict_types=1);

use App\Modules\Admin\Livewire\Login;
use Livewire\Livewire;

// The login page can't use wire:navigate (it would blank the wire:ignore'd Turnstile iframe), so the
// language switcher dispatches an `auth-locale` event that re-renders the component in place instead.
it('switches the UI locale in place — the labels re-render in the new locale', function () {
    // Switch to a NON-default locale and assert a real translated label appears, proving the component
    // actually re-rendered in the new locale (not merely that the session value was stored).
    Livewire::test(Login::class)
        ->dispatch('auth-locale', code: 'vi')
        ->assertOk()
        ->assertSee('Tài khoản'); // vi label for auth.email_label

    // Persisted (a later request / real reload keeps it via SetLocale) and applied to this render.
    expect(session('locale'))->toBe('vi')
        ->and(app()->getLocale())->toBe('vi');
});

it('ignores an unknown locale code so the session value cannot be poisoned', function () {
    Livewire::test(Login::class)
        ->dispatch('auth-locale', code: 'zz');

    expect(session('locale'))->toBeNull();
});
