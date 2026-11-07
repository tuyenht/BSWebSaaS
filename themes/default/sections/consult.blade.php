{{--
    The consult box of a side column (a block slot only): a small line, a heading, a line of text and
    one button, then the hotline and the email. Those two come from the site's contact settings
    (`$sfSlot['contact']`, SlotContext); a line the settings leave empty is hidden.
--}}
@php
    $sfButton = \App\Modules\Theme\Components\ButtonLink::of($props, 'button_url');
    $sfLines = is_array($sfSlot['contact'] ?? null) ? $sfSlot['contact'] : [];
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
