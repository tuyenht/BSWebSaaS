{{--
    Cloudflare Turnstile bot challenge for the login form. DORMANT unless BOTH keys are set
    (isConfigured) — no widget, no script, and the server check is gated on the same signal, so a
    half-set config can neither show an unverified widget nor lock login out. It ships inert and is
    enabled by config once verified in a real browser.

    Implicit render (same proven pattern as the storefront lead form): api.js auto-renders the
    .cf-turnstile element and reads its data-* callbacks. The login page is always a fresh full load
    (its locale switcher does NOT use wire:navigate), so there is no SPA-morph re-render to manage.

    wire:ignore keeps Livewire from morphing the iframe when the component re-renders (e.g. an error).
    The token goes to the component when the form is sent: read from the widget, written into a hidden
    wire:model field outside wire:ignore (a send before the check answers waits for it); login()
    verifies it server-side. Turnstile tokens are single-use, so after any rejected attempt login() dispatches
    bs-turnstile-reset to issue a fresh token — else a corrected retry is refused as a duplicate.

    Looks: light theme (the sign-in card is light only) and the page's language (checked 20/11/2026:
    Cloudflare draws vi, en, ja and zh as they are; a code it lacks falls back to English with a console
    warning, never an error). The widget keeps the language it was drawn in when the in-place language
    switch re-renders the form (redrawing it would throw away a solved check); the next load follows.
    The frame around it is drawn by auth.css, not by Cloudflare (see .auth-turnstile there).
--}}
@if (\App\Modules\Admin\Support\LoginTurnstile::active())
    <input type="hidden" wire:model="turnstileToken" data-bs-turnstile-token>
    <div wire:ignore class="auth-turnstile">
        <div class="cf-turnstile" data-sitekey="{{ \App\Modules\Shared\Support\Cast::string(config('services.turnstile.site_key')) }}"
             data-size="flexible" data-theme="light" data-language="{{ app()->getLocale() }}" data-callback="bsTurnstileOk" data-error-callback="bsTurnstileClear"
             data-expired-callback="bsTurnstileClear" data-timeout-callback="bsTurnstileClear"
             data-before-interactive-callback="bsTurnstileAsk" data-after-interactive-callback="bsTurnstileAsked"></div>
    </div>
    @once
        <script>
            // The check's latest answer, kept in case Cloudflare's own hidden field is filled after the
            // callback runs. Sends waiting for the check (below) go on as soon as it answers.
            var bsTurnstileLast = '';
            var bsTurnstileWaiting = [];
            window.bsTurnstileOk = function (token) {
                bsTurnstileLast = token;
                bsTurnstileAsking = false;
                bsTurnstileWaiting.splice(0).forEach(function (go) { go(); });
            };
            window.bsTurnstileClear = function () {
                bsTurnstileLast = '';
                bsTurnstileWaiting.forEach(function (go) { go.resume(); });
            };
            // When Cloudflare asks the person to tick its box, a send already waiting stops its 15 s clock
            // and its sending look (a refusal would reset the box mid-tick) and goes on by itself after the
            // tick. A press while the box is asking is sent at once, as before this wait existed: the server
            // answers that the check is not done.
            var bsTurnstileAsking = false;
            window.bsTurnstileAsk = function () {
                bsTurnstileAsking = true;
                bsTurnstileWaiting.forEach(function (go) { go.pause(); });
            };
            // The box stopped asking: a token follows (and sends), or it failed, and then the paused sends
            // take up their busy look and 15 s clock again instead of waiting for ever.
            window.bsTurnstileAsked = function () {
                bsTurnstileAsking = false;
                bsTurnstileWaiting.forEach(function (go) { go.resume(); });
            };

            // Cloudflare's widget iframe carries a hard min-width:300px inside a CLOSED shadow root, so
            // external CSS can't shrink it — on a form narrower than 300px it overflows the field. Scale
            // the (styleable) .cf-turnstile wrapper to (host width / 300) so the widget fits the field
            // exactly. Measure the HOST, never the scaled element, to avoid a ResizeObserver feedback loop.
            // The host draws the frame and .cf-turnstile overhangs its inside by 1px per side (auth.css),
            // so the widget spans the host's inner width + 2. A transform leaves the layout height
            // unscaled, so a negative bottom margin pulls the host up to the scaled widget, keeping
            // Cloudflare's own (scaled) bottom border hidden under ours.
            window.bsFitTurnstile = function () {
                document.querySelectorAll('.auth-turnstile > .cf-turnstile').forEach(function (el) {
                    var host = el.parentElement;
                    if (!host.clientWidth) return;
                    var scale = Math.min(1, (host.clientWidth + 2) / 300);
                    var transform = scale < 1 ? 'scale(' + scale + ')' : 'none';
                    var margin = scale < 1 ? -(el.offsetHeight * (1 - scale) + scale) + 'px' : '';
                    // Write only on a change: each write resizes the observed host and calls this again.
                    if (el.style.transform !== transform) { el.style.transformOrigin = 'top left'; el.style.transform = transform; }
                    if (el.style.marginBottom !== margin) { el.style.marginBottom = margin; }
                });
            };
            document.querySelectorAll('.auth-turnstile').forEach(function (host) {
                // Fit on the next frame, not inside the observer's delivery: a size change made there
                // makes Safari report "ResizeObserver loop completed with undelivered notifications".
                var fit = new ResizeObserver(function () { requestAnimationFrame(window.bsFitTurnstile); });
                fit.observe(host);
                // The widget's height arrives after the script draws it: refit then too.
                fit.observe(host.querySelector('.cf-turnstile'));
            });
            window.addEventListener('resize', window.bsFitTurnstile);
            window.bsFitTurnstile();

            // Every send reads the token from the widget, before Livewire sees the send (a capture listener
            // on document runs before the form's own wire:submit listeners), and hands it over through the
            // hidden wire:model field, so it goes out with the login call. No token yet («Đăng nhập» pressed
            // before the check answered): the send waits, the button shows its sending state, and it goes
            // on as soon as the check answers. If it never answers within 15 s, the form is sent anyway and
            // the server refuses it with its message.
            var bsTurnstileToken = function (box) {
                var input = box.querySelector('input[name="cf-turnstile-response"]');
                return (input && input.value) || bsTurnstileLast;
            };
            document.addEventListener('submit', function (e) {
                var form = e.target;
                var box = form.querySelector && form.querySelector('.auth-turnstile > .cf-turnstile');
                var field = box && form.querySelector('[data-bs-turnstile-token]');
                if (!field || !window.Livewire) { return; } // no Livewire yet: its wire:submit is not bound either
                var token = bsTurnstileToken(box);
                // The page has loaded but Cloudflare's script has not (blocked or failed): no answer will
                // come, so send now and let the server say so, instead of a 15 s wait on every press.
                var noScript = document.readyState === 'complete' && !window.turnstile;
                if (token || form.bsTurnstileGiveUp || noScript || bsTurnstileAsking) {
                    if (!token && bsTurnstileAsking && form.bsTurnstileWaiting) {
                        // This press replaces the send that was waiting for the tick.
                        bsTurnstileWaiting.splice(0).forEach(function (go) { go.pause(); });
                        form.bsTurnstileWaiting = false;
                    }
                    field.value = token;
                    field.dispatchEvent(new Event('input', { bubbles: true }));
                    return;
                }
                e.preventDefault();
                e.stopPropagation();
                if (form.bsTurnstileWaiting) { return; }
                form.bsTurnstileWaiting = true;
                var button = e.submitter || form.querySelector('button[type="submit"]');
                var timer;
                var idle = function () {
                    clearTimeout(timer);
                    if (button) { button.disabled = false; button.removeAttribute('aria-busy'); }
                };
                var busy = function () {
                    clearTimeout(timer);
                    if (button) { button.disabled = true; button.setAttribute('aria-busy', 'true'); }
                    timer = setTimeout(go, 15000);
                };
                var go = function () {
                    var i = bsTurnstileWaiting.indexOf(go);
                    if (i >= 0) { bsTurnstileWaiting.splice(i, 1); }
                    idle();
                    form.bsTurnstileWaiting = false;
                    // A later task: a form ignores requestSubmit() while one of its submit events runs. The
                    // give-up mark lives only for this send (requestSubmit fires its submit event at once, or
                    // none when the browser's own field check stops it).
                    setTimeout(function () {
                        form.bsTurnstileGiveUp = !bsTurnstileToken(box);
                        form.requestSubmit(button && button.form === form ? button : undefined);
                        form.bsTurnstileGiveUp = false;
                    });
                };
                go.pause = idle;
                go.resume = busy;
                bsTurnstileWaiting.push(go);
                busy();
            }, true);

            document.addEventListener('livewire:init', function () {
                window.Livewire.on('bs-turnstile-reset', function () {
                    // The server already cleared turnstileToken; drop the spent token here too (a quick retry
                    // must wait for the new one, never resend it) and re-challenge for a fresh single-use token.
                    var el = document.querySelector('.cf-turnstile');
                    var input = el && el.querySelector('input[name="cf-turnstile-response"]');
                    if (input) { input.value = ''; }
                    bsTurnstileLast = '';
                    bsTurnstileAsking = false; // the new check asks again if it needs a tick
                    if (window.turnstile && el) { window.turnstile.reset(el); }
                });
            });
        </script>
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endonce
@endif
