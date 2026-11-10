@if (\App\Modules\Theme\Components\SectionProps::choice($props, 'variant', \App\Modules\Theme\Components\SectionProps::CONTACT_VARIANTS) === 'cards')
{{-- The card look (opt-in): one card per contact way, then the map. Card titles are h3, so a
     block without its own heading prints a visually hidden h2 above them. The address card may add
     a small line of working hours under the address. --}}
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
                <a class="sf-contact-card__value" href="{{ \App\Modules\Theme\Components\ButtonLink::tel($props['phone']) ?? 'tel:'.preg_replace('/[^+\d]/', '', $props['phone']) }}">{{ $props['phone'] }}</a>
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
@if (\App\Modules\Theme\Components\SectionProps::text($props, 'hours') !== '')
                <p class="sf-contact-card__note">{{ $props['hours'] }}</p>
@endif
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
        {{-- The number shows as the site wrote it ("0915 66 83 83"); the link dials its digits only. tel: is
             built INSIDE {{ }} so the drift sentinel still sees $props['phone'] (type:url) — a
             "tel:{{ … }}" prefix outside the braces would evade it. --}}
        <a class="contact__phone" href="{{ \App\Modules\Theme\Components\ButtonLink::tel($props['phone']) ?? 'tel:'.preg_replace('/[^+\d]/', '', $props['phone']) }}">{{ $props['phone'] }}</a>
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
