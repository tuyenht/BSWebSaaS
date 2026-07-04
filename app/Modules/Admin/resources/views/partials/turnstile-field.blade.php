{{--
    Cloudflare Turnstile bot challenge for the login form. DORMANT unless BOTH keys are set
    (isConfigured) — no widget, no script, and the server check is gated on the same signal, so a
    half-set config can neither show an unverified widget nor lock login out. It ships inert and is
    enabled by config once verified in a real browser.

    Implicit render (same proven pattern as the storefront lead form): api.js auto-renders the
    .cf-turnstile element and reads its data-* callbacks. The login page is always a fresh full load
    (its locale switcher does NOT use wire:navigate), so there is no SPA-morph re-render to manage.

    wire:ignore keeps Livewire from morphing the iframe when the component re-renders (e.g. an error).
    The solved token is pushed into the component via the turnstile-token event; login() verifies it
    server-side. Turnstile tokens are single-use, so after any rejected attempt login() dispatches
    bs-turnstile-reset to issue a fresh token — else a corrected retry is refused as a duplicate.
--}}
@if (\App\Modules\Admin\Support\LoginTurnstile::active())
    <div wire:ignore class="auth-turnstile">
        <div class="cf-turnstile" data-sitekey="{{ \App\Modules\Admin\Support\Cast::string(config('services.turnstile.site_key')) }}"
             data-size="flexible" data-callback="bsTurnstileOk" data-error-callback="bsTurnstileClear"
             data-expired-callback="bsTurnstileClear" data-timeout-callback="bsTurnstileClear"></div>
    </div>
    @once
        <script>
            window.bsTurnstileOk = function (token) { window.Livewire && window.Livewire.dispatch('turnstile-token', { token: token }); };
            window.bsTurnstileClear = function () { window.Livewire && window.Livewire.dispatch('turnstile-token', { token: '' }); };

            // Cloudflare's widget iframe carries a hard min-width:300px inside a CLOSED shadow root, so
            // external CSS can't shrink it — on a form narrower than 300px it overflows the field. Scale
            // the (styleable) .cf-turnstile wrapper to (host width / 300) so the widget fits the field
            // exactly. Measure the HOST, never the scaled element, to avoid a ResizeObserver feedback loop.
            window.bsFitTurnstile = function () {
                document.querySelectorAll('.cf-turnstile').forEach(function (el) {
                    var host = el.parentElement;
                    if (!host || !host.clientWidth) return;
                    var scale = Math.min(1, host.clientWidth / 300);
                    el.style.transformOrigin = 'top left';
                    el.style.transform = scale < 1 ? 'scale(' + scale + ')' : 'none';
                });
            };
            document.querySelectorAll('.auth-turnstile').forEach(function (host) {
                new ResizeObserver(window.bsFitTurnstile).observe(host);
            });
            window.addEventListener('resize', window.bsFitTurnstile);
            window.bsFitTurnstile();

            document.addEventListener('livewire:init', function () {
                window.Livewire.on('bs-turnstile-reset', function () {
                    // The server already cleared turnstileToken; just re-challenge so the widget's own
                    // callback delivers a fresh single-use token.
                    var el = document.querySelector('.cf-turnstile');
                    if (window.turnstile && el) { window.turnstile.reset(el); }
                });
            });
        </script>
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endonce
@endif
