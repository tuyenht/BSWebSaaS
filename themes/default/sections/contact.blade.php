@if (\App\Modules\Theme\Components\SectionProps::choice($props, 'variant', \App\Modules\Theme\Components\SectionProps::CONTACT_VARIANTS) === 'aside')
{{-- The consult box of a side column (opt-in): a small line, a heading, a line of text, one button, then the
     hotline and the email. In a block slot the hotline and the email come from the site's contact settings
     ($sfSlot['contact']); a line that is empty there is hidden. Elsewhere they are the block's own. --}}
@php
    $sfButton = \App\Modules\Theme\Components\ButtonLink::of($props, 'button_url');
    $sfOwn = [
        'phone' => (string) ($props['phone'] ?? ''),
        'call' => is_string($props['phone'] ?? null) ? \App\Modules\Theme\Components\ButtonLink::tel($props['phone']) : null,
        'email' => (string) ($props['email'] ?? ''),
        'mail' => \App\Modules\Shared\Support\SafeLink::mailto($props['email'] ?? null),
    ];
    $sfLines = is_array($sfSlot['contact'] ?? null) ? $sfSlot['contact'] : $sfOwn;
@endphp
<section class="slot-consult">
@if (! empty($props['eyebrow']))
    <p class="slot-consult__eyebrow">{{ $props['eyebrow'] }}</p>
@endif
@if (! empty($props['heading']))
    <h2 class="slot-consult__title">{{ $props['heading'] }}</h2>
@endif
@if (! empty($props['text']))
    <p class="slot-consult__text">{{ $props['text'] }}</p>
@endif
@if (! empty($props['button_label']) && $sfButton !== null)
    <a class="slot-consult__button" href="{{ $sfButton['href'] }}"@if ($sfButton['blank']) target="_blank" rel="noopener"@elseif ($sfButton['download']) download=""@endif>{{ $props['button_label'] }}@if ($sfButton['blank'])<span class="sr-only"> {{ __('storefront.new_tab') }}</span>@endif</a>
@endif
@if (($sfLines['call'] ?? null) !== null)
    <a class="slot-consult__call" href="{{ $sfLines['call'] }}">{{ __('storefront.hotline_number', ['phone' => $sfLines['phone']]) }}</a>
@endif
@if (($sfLines['mail'] ?? null) !== null)
    <p class="slot-consult__mail">{{ __('storefront.contact_email') }}: <a class="slot-consult__mail-link" href="{{ $sfLines['mail'] }}">{{ $sfLines['email'] }}</a></p>
@endif
</section>
@elseif (\App\Modules\Theme\Components\SectionProps::choice($props, 'variant', \App\Modules\Theme\Components\SectionProps::CONTACT_VARIANTS) === 'cards')
{{-- The card look (opt-in): one card per contact way, then the map. Card titles are h3, so a
     block without its own heading prints a visually hidden h2 above them. --}}
@php($sfMap = \App\Modules\Shared\Support\SafeLink::href($props['map_embed'] ?? null, config('cms.map_hosts')))
@php($sfZalo = is_string($props['zalo'] ?? null) ? \App\Modules\Theme\Components\ButtonLink::zalo($props['zalo']) : null)
<section class="contact contact--cards">
@if (! empty($props['heading']))
    <h2 class="contact__heading">{{ $props['heading'] }}</h2>
@else
    <h2 class="sr-only">{{ __('storefront.contact_info') }}</h2>
@endif
    <ul class="sf-contact-cards">
@if (! empty($props['phone']))
        <li class="sf-contact-card">
            <span class="sf-contact-card__icon">@include('theme::partials.sf-icon', ['name' => 'phone', 'size' => 24])</span>
            <div>
                <h3 class="sf-contact-card__title">{{ \App\Modules\Theme\Components\SectionProps::text($props, 'phone_label', __('storefront.contact_phone')) }}</h3>
                <a class="sf-contact-card__value" href="{{ 'tel:'.$props['phone'] }}">{{ $props['phone'] }}</a>
            </div>
        </li>
@endif@if ($sfZalo !== null)
        <li class="sf-contact-card">
            <span class="sf-contact-card__icon sf-contact-card__icon--zalo" aria-hidden="true">Zalo</span>
            <div>
                <h3 class="sf-contact-card__title">Zalo</h3>
                <a class="sf-contact-card__value" href="{{ $sfZalo }}" target="_blank" rel="noopener">{{ \App\Modules\Theme\Components\SectionProps::text($props, 'zalo_label', $props['zalo']) }}<span class="sr-only"> {{ __('storefront.new_tab') }}</span></a>
            </div>
        </li>
@endif
@if (! empty($props['email']))
        <li class="sf-contact-card">
            <span class="sf-contact-card__icon">@include('theme::partials.sf-icon', ['name' => 'email', 'size' => 24])</span>
            <div>
                <h3 class="sf-contact-card__title">{{ \App\Modules\Theme\Components\SectionProps::text($props, 'email_label', __('storefront.contact_email')) }}</h3>
                <a class="sf-contact-card__value" href="{{ 'mailto:'.$props['email'] }}">{{ $props['email'] }}</a>
            </div>
        </li>
@endif
@if (! empty($props['address']))
        <li class="sf-contact-card">
            <span class="sf-contact-card__icon">@include('theme::partials.sf-icon', ['name' => 'address', 'size' => 24])</span>
            <div>
                <h3 class="sf-contact-card__title">{{ \App\Modules\Theme\Components\SectionProps::text($props, 'address_label', __('storefront.contact_address')) }}</h3>
                <p class="sf-contact-card__text">{{ $props['address'] }}</p>
            </div>
        </li>
@endif
    </ul>
@if ($sfMap !== null)
    <iframe class="sf-map" src="{{ $sfMap }}" title="{{ ($props['heading'] ?? '') ?: __('storefront.map') }}" width="600" height="340" loading="lazy" referrerpolicy="no-referrer-when-downgrade" sandbox="allow-scripts allow-same-origin allow-popups"></iframe>
@endif
</section>
@else
<section class="contact">
    @if (! empty($props['heading']))
        <h2 class="contact__heading">{{ $props['heading'] }}</h2>
    @endif
    @if (! empty($props['address']))
        <p class="contact__address">{{ $props['address'] }}</p>
    @endif
    @if (! empty($props['phone']))
        {{-- tel: built as a concat INSIDE {{ }} so the drift sentinel still sees $props['phone']
             (type:url) — a "tel:{{ … }}" prefix outside the braces would evade it. --}}
        <a class="contact__phone" href="{{ 'tel:'.$props['phone'] }}">{{ $props['phone'] }}</a>
    @endif@if (is_string($props['zalo'] ?? null) && ($sfZalo = \App\Modules\Theme\Components\ButtonLink::zalo($props['zalo'])) !== null)
        <a class="contact__zalo" href="{{ $sfZalo }}" target="_blank" rel="noopener">{{ \App\Modules\Theme\Components\SectionProps::text($props, 'zalo_label', 'Zalo') }}<span class="sr-only"> {{ __('storefront.new_tab') }}</span></a>
    @endif
    @if (! empty($props['email']))
        <a class="contact__email" href="{{ 'mailto:'.$props['email'] }}">{{ $props['email'] }}</a>
    @endif
    @if (\App\Modules\Shared\Support\SafeLink::href($props['map_embed'] ?? null, config('cms.map_hosts')) !== null)
        {{-- Tenant's own map embed URL (scheme-checked at save, host-checked here against cms.map_hosts);
             lazy + fixed box reserves space (no CLS). --}}
        <iframe class="contact__map" src="{{ \App\Modules\Shared\Support\SafeLink::href($props['map_embed'], config('cms.map_hosts')) }}" title="{{ ($props['heading'] ?? '') ?: __('storefront.map') }}" width="600" height="450" loading="lazy" referrerpolicy="no-referrer-when-downgrade" sandbox="allow-scripts allow-same-origin allow-popups"></iframe>
    @endif
</section>
@endif
