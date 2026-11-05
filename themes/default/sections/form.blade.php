{{--
    Lead-capture form block (Forms\Components\FormComponent). SSR Blade; the only client JS is the
    Cloudflare Turnstile widget, loaded async/defer ONLY when a site_key is configured (prod) — so
    dev/test render no third-party JS. Anti-spam: hidden honeypot (off-screen, a11y-hidden) +
    Turnstile + server-side rate-limit. POSTs same-origin to /forms/{key} (form-action 'self').
    Optional: a `description` line under the heading, and a `map_embed` (host-checked against
    cms.map_hosts like the contact block) that puts the form in a card beside the map.

    States, all server-side: after a send the form gives way to a thank-you card with a link back to
    an empty form; after a failed send one alert sits above the button (tied to it when the button is
    what takes the focus), each bad field carries its own
    message, what was typed comes back, and the first bad field (else the button) takes the focus.
    Every id is prefixed with the form's own id, so two forms on a page never clash and only the
    form that was sent shows the outcome. Text and phone fields pair up two per row from sm; a field without a partner takes the full row.
--}}
@php
    $sfMap = \App\Modules\Shared\Support\SafeLink::href($props['map_embed'] ?? null, config('cms.map_hosts'));
    $sfFormKey = basename((string) $action);
    // The same form may sit twice on a page: the later copies get a numbered id (per request, so
    // nothing leaks between requests under Octane) and only the first one shows the send's outcome.
    $sfNth = (int) request()->attributes->get('lead_form_seen.'.$sfFormKey, 0);
    request()->attributes->set('lead_form_seen.'.$sfFormKey, $sfNth + 1);
    // Ids: the key reduced to [a-z0-9_], then "--n" for a copy and "__field" for a field, so no two
    // forms or fields can meet on one id whatever their keys hold.
    $sfFormId = 'lf-'.preg_replace('/[^a-z0-9]+/', '_', strtolower($sfFormKey)).($sfNth > 0 ? '--'.($sfNth + 1) : '');
    // A send's outcome (errors, typed values, thank-you) belongs to the form it came from only. A
    // failed send that names no form (a page loaded before the hidden field existed) goes to the
    // page's first form, so what the visitor typed is never lost.
    $sfFirstOnPage = ! request()->attributes->has('lead_form_first');
    request()->attributes->set('lead_form_first', true);
    $sfMine = $sfNth === 0 && $sfFormKey !== ''
        && (old('lead_form') === $sfFormKey || (old('lead_form') === null && $errors->any() && $sfFirstOnPage));
    $sfErr = $sfMine ? $errors : new \Illuminate\Support\MessageBag();
    $sfOld = fn (string $name) => $sfMine ? old($name) : null;
    $sfSent = $sfNth === 0 && session('form_success_key') === $sfFormKey ? session('form_success') : null;
    $sfFirstBad = null;
    foreach ($fields as $sfField) {
        if ($sfErr->has($sfField['key'])) {
            $sfFirstBad = $sfField['key'];
            break;
        }
    }
    $sfAlert = $sfErr->first('form') ?: ($sfFirstBad !== null ? $sfErr->first($sfFirstBad) : $sfErr->first());
    $sfKeys = array_column($fields, 'key');
    // The "phone or email" rule (StoreLeadRequest) is told once, under the later of the two fields.
    $sfOneOfHint = in_array('email', $sfKeys, true) && in_array('phone', $sfKeys, true)
        ? $sfKeys[max(array_search('email', $sfKeys, true), array_search('phone', $sfKeys, true))]
        : null;
    // When both fail on that rule, its message shows once (under the hint) and both fields point to it.
    $sfErrorAt = [];
    foreach ($sfKeys as $sfKey) {
        if ($sfErr->has($sfKey)) {
            $sfErrorAt[$sfKey] = $sfFormId.'__'.$sfKey.'-error';
        }
    }
    $sfQuiet = null;
    if ($sfOneOfHint !== null && isset($sfErrorAt['email'], $sfErrorAt['phone']) && $sfErr->first('email') === $sfErr->first('phone')) {
        $sfQuiet = $sfOneOfHint === 'email' ? 'phone' : 'email';
        $sfErrorAt[$sfQuiet] = $sfErrorAt[$sfOneOfHint];
    }
    $sfPolicy = in_array('consent', $sfKeys, true) && ! $sfSent
        ? app(\App\Modules\Storefront\Support\PrivacyPolicyLink::class)->url((string) ($locale ?? ''))
        : null;
    // Two short fields share a row; one with no short neighbour to pair with spans the row.
    $sfHalf = [];
    $sfPending = null;
    foreach ($fields as $sfIndex => $sfField) {
        if (in_array($sfField['type'], ['text', 'email', 'tel'], true)) {
            if ($sfPending === null) {
                $sfPending = $sfIndex;
            } else {
                $sfHalf[$sfPending] = $sfHalf[$sfIndex] = true;
                $sfPending = null;
            }
        } else {
            $sfPending = null;
        }
    }
    [$sfNoteBefore, $sfNoteAfter] = array_pad(explode('__MARK__', __('forms.required_note', ['mark' => '__MARK__']), 2), 2, '');
@endphp
@if (! empty($action))
@if ($sfMap !== null)
    <section class="lead-form lead-form--map" id="{{ $sfFormId }}">
        <div class="lead-form__main">
@else
    <section class="lead-form" id="{{ $sfFormId }}">
@endif
        @if (! empty($heading))
            <h2 class="lead-form__heading">{{ $heading }}</h2>
        @endif
@if (! empty($props['description']))
        <p class="lead-form__description">{{ $props['description'] }}</p>
@endif

        @if ($sfSent)
            <div class="lead-form__sent" role="status" tabindex="-1" autofocus>
                <span class="lead-form__sent-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 13l4 4L19 7"/></svg></span>
                <p class="lead-form__sent-title">{{ __('forms.success_title') }}</p>
                <p class="lead-form__sent-text">{{ $sfSent }}</p>
                <a class="lead-form__again" href="{{ request()->getPathInfo() }}#{{ $sfFormId }}">{{ __('forms.send_another') }}</a>
            </div>
        @else
        <form class="lead-form__form" method="POST" action="{{ $action }}" novalidate>
            {{-- The language the visitor is reading, so errors and the thank-you come back in it
                 (the POST URL carries no locale prefix). The server accepts only a public locale. --}}
            <input type="hidden" name="locale" value="{{ $locale ?? '' }}">
            {{-- Names this form, so a failed send comes back to it and not to another form on the page. --}}
            <input type="hidden" name="lead_form" value="{{ $sfFormKey }}">
            {{-- Honeypot: a real field name bots tend to fill, hidden from humans + the a11y tree.
                 A non-empty value flags the submission as spam server-side (SubmitForm). --}}
            <div aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden">
                <label>Website
                    <input type="text" name="company_website" tabindex="-1" autocomplete="off" value="">
                </label>
            </div>

            @foreach ($fields as $sfIndex => $field)
                @php
                    $sfId = $sfFormId.'__'.$field['key'];
                    $sfBad = isset($sfErrorAt[$field['key']]);
                    $sfHint = $sfOneOfHint === $field['key'];
                    $sfDescribed = trim(($sfHint ? $sfId.'-hint ' : '').($sfErrorAt[$field['key']] ?? ''));
                @endphp
                @if ($field['type'] === 'checkbox')
                    {{-- The box precedes its sentence and the whole label is clickable (large tap target). --}}
                    <div class="lead-form__field lead-form__field--checkbox">
                        <label class="lead-form__check" for="{{ $sfId }}">
                            <input id="{{ $sfId }}" type="checkbox" name="{{ $field['key'] }}" value="1"
                                   @checked($sfOld($field['key'])) @if ($field['is_required']) required @endif
                                   @if ($sfBad) aria-invalid="true" aria-describedby="{{ $sfDescribed }}" @endif
                                   @if ($sfFirstBad === $field['key']) autofocus @endif>
                            <span>{{ $field['label'] }}@if ($field['key'] === 'consent' && $sfPolicy !== null) <a class="lead-form__policy" href="{{ $sfPolicy }}" target="_blank" rel="noopener">{{ __('forms.privacy_link') }}<span class="sr-only"> {{ __('forms.opens_in_new_tab') }}</span></a>@endif @if ($field['is_required'])<span class="lead-form__required" aria-hidden="true">*</span>@endif</span>
                        </label>
                        @if ($sfBad)
                            <p class="lead-form__field-error" id="{{ $sfId }}-error">{{ $sfErr->first($field['key']) }}</p>
                        @endif
                    </div>
                    @continue
                @endif
                @php
                    $inputType = in_array($field['type'], ['email', 'tel', 'text'], true) ? $field['type'] : 'text';
                    $sfAuto = match (true) {
                        $inputType === 'email' => 'email',
                        $inputType === 'tel' => 'tel',
                        $field['key'] === 'name' => 'name',
                        default => null,
                    };
                @endphp
                <div class="lead-form__field{{ isset($sfHalf[$sfIndex]) ? ' lead-form__field--half' : '' }}">
                    <label for="{{ $sfId }}">
                        {{ $field['label'] }}@if ($field['is_required']) <span class="lead-form__required" aria-hidden="true">*</span>@endif
                    </label>
                    @if ($field['type'] === 'textarea')
                        <textarea id="{{ $sfId }}" name="{{ $field['key'] }}" rows="4"
                                  @if ($field['is_required']) required @endif
                                  @if ($sfBad) aria-invalid="true" @endif
                                  @if ($sfDescribed !== '') aria-describedby="{{ $sfDescribed }}" @endif
                                  @if ($sfFirstBad === $field['key']) autofocus @endif>{{ $sfOld($field['key']) }}</textarea>
                    @else
                        <input id="{{ $sfId }}" type="{{ $inputType }}" name="{{ $field['key'] }}"
                               value="{{ $sfOld($field['key']) }}" @if ($field['is_required']) required @endif
                               @if ($sfAuto !== null) autocomplete="{{ $sfAuto }}" @endif
                               @if ($sfBad) aria-invalid="true" @endif
                               @if ($sfDescribed !== '') aria-describedby="{{ $sfDescribed }}" @endif
                               @if ($sfFirstBad === $field['key']) autofocus @endif>
                    @endif
                    @if ($sfHint)
                        <p class="lead-form__hint" id="{{ $sfId }}-hint">{{ __('forms.hint_contact_one_of') }}</p>
                    @endif
                    @if ($sfBad && $sfQuiet !== $field['key'])
                        <p class="lead-form__field-error" id="{{ $sfId }}-error">{{ $sfErr->first($field['key']) }}</p>
                    @endif
                </div>
            @endforeach

            @if ($sfAlert !== '')
                {{-- The one summary of a failed send; it leads to the first field to fix. --}}
                <p class="lead-form__alert" id="{{ $sfFormId }}-alert" role="alert">@if ($sfFirstBad !== null)<a href="#{{ $sfFormId }}__{{ $sfFirstBad }}">{{ $sfAlert }}</a>@else{{ $sfAlert }}@endif</p>
            @endif

            @if (! empty($turnstileSiteKey))
                <div class="cf-turnstile lead-form__wide" data-sitekey="{{ $turnstileSiteKey }}"></div>
                <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
            @endif

            <div class="lead-form__actions">
                <button type="submit" class="lead-form__submit" @if ($sfAlert !== '' && $sfFirstBad === null) aria-describedby="{{ $sfFormId }}-alert" autofocus @endif>{{ __('forms.submit_button') }}</button>
                <p class="lead-form__note">{{ $sfNoteBefore }}<span class="lead-form__required">*</span>{{ $sfNoteAfter }}</p>
            </div>
        </form>
        @endif
@if ($sfMap !== null)
        </div>
        <iframe class="lead-form__map" src="{{ $sfMap }}" title="{{ __('storefront.map') }}" width="600" height="340" loading="lazy" referrerpolicy="no-referrer-when-downgrade" sandbox="allow-scripts allow-same-origin allow-popups"></iframe>
@endif
    </section>
@endif
