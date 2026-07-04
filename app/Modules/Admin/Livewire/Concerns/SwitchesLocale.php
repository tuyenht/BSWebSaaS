<?php

declare(strict_types=1);

namespace App\Modules\Admin\Livewire\Concerns;

use App\Modules\Admin\Support\Locales;
use Livewire\Attributes\On;

/**
 * Lets an auth Livewire component switch the UI language in place. The switcher in the auth layout
 * dispatches `auth-locale`; the component persists the choice to the session and re-renders its
 * labels in the new locale WITHOUT a full navigation — so the Cloudflare Turnstile widget (which is
 * wire:ignore'd) survives, where a wire:navigate morph would blank its iframe. SetLocale middleware
 * applies the stored locale on any later request (including a real reload).
 */
trait SwitchesLocale
{
    #[On('auth-locale')]
    public function switchLocale(string $code): void
    {
        if (in_array($code, Locales::codes(), true)) {
            session()->put('locale', $code);
            app()->setLocale($code);
        }
    }
}
