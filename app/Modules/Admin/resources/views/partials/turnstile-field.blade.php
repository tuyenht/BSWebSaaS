{{--
    Cloudflare Turnstile bot challenge for the auth forms. DORMANT until a site key is set
    (TURNSTILE_SITE_KEY) — no widget, no script, and TurnstileVerifier skips server-side too — so it
    ships inert and is enabled by config once verified in a browser.

    The solved token is pushed into the Livewire component via a global event (turnstile-token); the
    component's #[On('turnstile-token')] setter stores it and login() verifies it server-side.

    wire:ignore keeps Livewire from morphing the iframe on component updates. The api.js auto-render
    fires only once at load, so after a wire:navigate page swap (e.g. the locale switcher) the fresh
    .cf-turnstile is blank — bsRenderTurnstile re-draws any unrendered widget on livewire:navigated.
--}}
@php $turnstileSiteKey = \App\Modules\Admin\Support\Cast::string(config('services.turnstile.site_key')); @endphp
@if ($turnstileSiteKey !== '')
    <div wire:ignore class="auth-turnstile">
        <div class="cf-turnstile" data-sitekey="{{ $turnstileSiteKey }}"
             data-callback="bsTurnstileOk" data-expired-callback="bsTurnstileClear"
             data-error-callback="bsTurnstileClear" data-timeout-callback="bsTurnstileClear"></div>
    </div>
    @once
        <script>
            window.bsTurnstileOk = function (token) { window.Livewire && window.Livewire.dispatch('turnstile-token', { token: token }); };
            window.bsTurnstileClear = function () { window.Livewire && window.Livewire.dispatch('turnstile-token', { token: '' }); };
            window.bsRenderTurnstile = function () {
                if (!window.turnstile) return;
                document.querySelectorAll('.cf-turnstile:not([data-bs-rendered])').forEach(function (el) {
                    window.turnstile.render(el);
                    el.setAttribute('data-bs-rendered', '1');
                });
            };
            if (!window.bsTurnstileBound) {
                window.bsTurnstileBound = true;
                document.addEventListener('livewire:navigated', window.bsRenderTurnstile);
            }
        </script>
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js?onload=bsRenderTurnstile&render=explicit" async defer></script>
    @endonce
@endif
