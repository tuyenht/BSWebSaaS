{{--
    Lead-capture form block (Forms\Components\FormComponent). SSR Blade; the only client JS is the
    Cloudflare Turnstile widget, loaded async/defer ONLY when a site_key is configured (prod) — so
    dev/test render no third-party JS. Anti-spam: hidden honeypot (off-screen, a11y-hidden) +
    Turnstile + server-side rate-limit. POSTs same-origin to /forms/{key} (form-action 'self').
--}}
@if (! empty($action))
    <section class="lead-form">
        @if (! empty($heading))
            <h2 class="lead-form__heading">{{ $heading }}</h2>
        @endif

        @if (session('form_success'))
            <p class="lead-form__success" role="status">{{ session('form_success') }}</p>
        @endif

        @if ($errors->has('form'))
            <p class="lead-form__error" role="alert">{{ $errors->first('form') }}</p>
        @endif

        <form class="lead-form__form" method="POST" action="{{ $action }}">
            {{-- The language the visitor is reading, so errors and the thank-you come back in it
                 (the POST URL carries no locale prefix). The server accepts only a public locale. --}}
            <input type="hidden" name="locale" value="{{ $locale ?? '' }}">
            {{-- Honeypot: a real field name bots tend to fill, hidden from humans + the a11y tree.
                 A non-empty value flags the submission as spam server-side (SubmitForm). --}}
            <div aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden">
                <label>Website
                    <input type="text" name="company_website" tabindex="-1" autocomplete="off" value="">
                </label>
            </div>

            @foreach ($fields as $field)
                @if ($field['type'] === 'checkbox')
                    {{-- The box precedes its sentence and the whole label is clickable (large tap target). --}}
                    <div class="lead-form__field lead-form__field--checkbox">
                        <input id="lead-{{ $field['key'] }}" type="checkbox" name="{{ $field['key'] }}" value="1"
                               @checked(old($field['key'])) @if ($field['is_required']) required @endif>
                        <label for="lead-{{ $field['key'] }}">{{ $field['label'] }}@if ($field['is_required'])<span class="lead-form__required" aria-hidden="true">*</span>@endif</label>
                        @error($field['key'])
                            <span class="lead-form__field-error" role="alert">{{ $message }}</span>
                        @enderror
                    </div>
                    @continue
                @endif
                @php $inputType = in_array($field['type'], ['email', 'tel', 'text'], true) ? $field['type'] : 'text'; @endphp
                <div class="lead-form__field">
                    <label for="lead-{{ $field['key'] }}">
                        {{ $field['label'] }}@if ($field['is_required'])<span class="lead-form__required" aria-hidden="true">*</span>@endif
                    </label>
                    @if ($field['type'] === 'textarea')
                        <textarea id="lead-{{ $field['key'] }}" name="{{ $field['key'] }}" rows="5"
                                  @if ($field['is_required']) required @endif>{{ old($field['key']) }}</textarea>
                    @else
                        <input id="lead-{{ $field['key'] }}" type="{{ $inputType }}" name="{{ $field['key'] }}"
                               value="{{ old($field['key']) }}" @if ($field['is_required']) required @endif>
                    @endif
                    @error($field['key'])
                        <span class="lead-form__field-error" role="alert">{{ $message }}</span>
                    @enderror
                </div>
            @endforeach

            @if (! empty($turnstileSiteKey))
                <div class="cf-turnstile" data-sitekey="{{ $turnstileSiteKey }}"></div>
                <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
            @endif

            <button type="submit" class="lead-form__submit">{{ __('forms.submit_button') }}</button>
        </form>
    </section>
@endif
