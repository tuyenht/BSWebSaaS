{{--
    Cloudflare Turnstile bot challenge for the login form. DORMANT unless BOTH keys are set
    (isConfigured) — no widget, no script, and the server check is gated on the same signal, so a
    half-set config can neither show an unverified widget nor lock login out. It ships inert and is
    enabled by config once verified in a real browser.

    Implicit render (same proven pattern as the storefront lead form): api.js auto-renders the
    .cf-turnstile element and reads its data-* callbacks. The login page is always a fresh full load
    (its locale switcher does NOT use wire:navigate), so there is no SPA-morph re-render to manage.

    wire:ignore keeps Livewire from morphing the iframe when the component re-renders (e.g. an error).
    The solved token is pushed into the component via the turnstile-token event, and read again from
    the widget on every send (a send before the check answers waits for it); login() verifies it
    server-side. Turnstile tokens are single-use, so after any rejected attempt login() dispatches
    bs-turnstile-reset to issue a fresh token — else a corrected retry is refused as a duplicate.

    Looks: light theme (the sign-in card is light only) and the page's language (checked 20/11/2026:
    Cloudflare draws vi, en, ja and zh as they are; a code it lacks falls back to English with a console
    warning, never an error). The widget keeps the language it was drawn in when the in-place language
    switch re-renders the form (redrawing it would throw away a solved check); the next load follows.
    The frame around it is drawn by auth.css, not by Cloudflare (see .auth-turnstile there).
--}}
@if (\App\Modules\Admin\Support\LoginTurnstile::active())
    <div wire:ignore class="auth-turnstile">
        <div class="cf-turnstile" data-sitekey="{{ \App\Modules\Shared\Support\Cast::string(config('services.turnstile.site_key')) }}"
             data-size="flexible" data-theme="light" data-language="{{ app()->getLocale() }}" data-callback="bsTurnstileOk" data-error-callback="bsTurnstileClear"
             data-expired-callback="bsTurnstileClear" data-timeout-callback="bsTurnstileClear"></div>
    </div>
    @once
        <script>
            // Sends waiting for the check (below) go on as soon as a token arrives.
            var bsTurnstileWaiting = [];
            window.bsTurnstileOk = function (token) {
                window.Livewire && window.Livewire.dispatch('turnstile-token', { token: token });
                bsTurnstileWaiting.splice(0).forEach(function (go) { go(); });
            };
            window.bsTurnstileClear = function () { window.Livewire && window.Livewire.dispatch('turnstile-token', { token: '' }); };

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

            // The token reaches the form through the event above, which is lost when the check answers
            // before Livewire is ready, and missing when «Đăng nhập» is pressed before the check answers.
            // So every send reads the token from the widget itself, before Livewire sees the send (capture
            // phase on document runs before the form's own wire:submit listeners). No token yet: the send
            // waits (the button shows its sending state) and goes on when the check answers. If it never
            // answers within 15 s, the form is sent anyway and the server refuses it with its message.
            var bsTurnstileToken = function (box) {
                var input = box.querySelector('input[name="cf-turnstile-response"]');
                return input ? input.value : '';
            };
            document.addEventListener('submit', function (e) {
                var form = e.target;
                var box = form.querySelector && form.querySelector('.auth-turnstile > .cf-turnstile');
                var root = box && form.closest('[wire\\:id]');
                var wire = root && window.Livewire && window.Livewire.find(root.getAttribute('wire:id'));
                if (!wire) { return; }
                var token = bsTurnstileToken(box);
                if (token || form.bsTurnstileGiveUp) {
                    form.bsTurnstileGiveUp = false;
                    wire.turnstileToken = token; // sent with this action, no request of its own
                    return;
                }
                e.preventDefault();
                e.stopPropagation();
                if (form.bsTurnstileWaiting) { return; }
                form.bsTurnstileWaiting = true;
                var button = e.submitter || form.querySelector('button[type="submit"]');
                if (button) { button.disabled = true; button.setAttribute('aria-busy', 'true'); }
                var timer;
                var go = function () {
                    clearTimeout(timer);
                    var i = bsTurnstileWaiting.indexOf(go);
                    if (i >= 0) { bsTurnstileWaiting.splice(i, 1); }
                    if (button) { button.disabled = false; button.removeAttribute('aria-busy'); }
                    form.bsTurnstileWaiting = false;
                    form.bsTurnstileGiveUp = !bsTurnstileToken(box);
                    // A later task: a form ignores requestSubmit() while one of its submit events runs.
                    setTimeout(function () { form.requestSubmit(button && button.form === form ? button : undefined); });
                };
                timer = setTimeout(go, 15000);
                bsTurnstileWaiting.push(go);
            }, true);

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
