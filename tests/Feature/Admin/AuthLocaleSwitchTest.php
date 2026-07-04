<?php

declare(strict_types=1);

use App\Modules\Admin\Livewire\Login;
use Livewire\Livewire;

// The login page can't use wire:navigate (it would blank the wire:ignore'd Turnstile iframe), so the
// language switcher dispatches an `auth-locale` event that re-renders the component in place instead.
it('switches the UI locale in place from the auth-locale event', function () {
    Livewire::test(Login::class)
        ->dispatch('auth-locale', code: 'en')
        ->assertOk();

    // Persisted (a later request / real reload keeps it via SetLocale) and applied to this render.
    expect(session('locale'))->toBe('en')
        ->and(app()->getLocale())->toBe('en');
});

it('ignores an unknown locale code so the session value cannot be poisoned', function () {
    Livewire::test(Login::class)
        ->dispatch('auth-locale', code: 'zz');

    expect(session('locale'))->toBeNull();
});
