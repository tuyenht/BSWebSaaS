{{--
    Floating contact buttons (`storefront.floating_contact`, normalized by FloatingContact; null =
    nothing printed). Plain links only — no script. "vertical": round buttons in a corner or along
    the bottom on every screen, with no room kept for them at the end of the page; "horizontal": a
    bar along the bottom on phones only, with an invisible copy after the footer that keeps exactly
    its room (labels may wrap, so a fixed padding could not match), so the end of the page is never
    hidden under it. The bar ends with "Mua hàng" when the site has a product list. Each link
    carries its name (aria-label), which also holds the word printed on the button (SMS), and the
    hover label repeats the name for the eye. Colours come from the normalizer (white marks at 3:1,
    words at 4.5:1; the brand fill uses the brand's own readable ink).
--}}
@if (! empty($floatingContact))
@php
    $fc = $floatingContact;
    $fcWord = static fn (array $b): string => $b['label']
        ?? (array_key_exists($b['key'], \App\Modules\Theme\Chrome\FloatingContact::CHANNELS) ? __('storefront.fc_'.$b['key']) : __('storefront.fc_contact'));
    $fcName = static fn (array $b): string => $b['text'] !== null && ! str_contains(mb_strtolower($fcWord($b)), mb_strtolower($b['text']))
        ? $fcWord($b).' '.$b['text'] : $fcWord($b);
    $fcOffsets = '--fc-ox:'.$fc['offset_x'].'px;--fc-oy:'.$fc['offset_y'].'px';
@endphp
@if ($fc['theme'] === 'vertical')
<nav class="sf-fc sf-fc--{{ $fc['position'] }}" aria-label="{{ __('storefront.fc_nav') }}" style="{{ $fcOffsets }}">
    <ul class="sf-fc__list sf-fc__list--{{ $fc['position'] }}" role="list">
        @foreach ($fc['buttons'] as $b)
            <li><a class="sf-fc__btn{{ $b['pulse'] ? ' sf-fc__btn--pulse' : '' }}" href="{{ $b['href'] }}" aria-label="{{ $fcName($b) }}" style="--fc-fill:{{ $b['fill'] }}">
                @if ($b['text'] !== null)
                    <span class="sf-fc__word" aria-hidden="true">{{ $b['text'] }}</span>
                @else
                    <svg class="sf-fc__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="{{ $b['icon'] }}"/></svg>
                @endif
                <span class="sf-fc__tip" aria-hidden="true">{{ $fcWord($b) }}</span>
            </a></li>
        @endforeach
    </ul>
</nav>
@else
<div class="sf-fc-bar sf-fc-bar--ghost" aria-hidden="true"><ul class="sf-fc-bar__list" role="list">@foreach ($fc['bar'] as $b)<li class="sf-fc-bar__item"><span class="sf-fc-bar__link"><span class="sf-fc-bar__dot">@if ($b['text'] !== null)<span class="sf-fc-bar__word">{{ $b['text'] }}</span>@endif</span><span class="sf-fc-bar__label">{{ $fcWord($b) }}</span></span></li>@endforeach @if ($fc['buy'] !== null)<li class="sf-fc-bar__item"><span class="sf-fc-bar__link"><span class="sf-fc-bar__dot"></span><span class="sf-fc-bar__label">{{ __('storefront.fc_buy') }}</span></span></li>@endif</ul></div>
<nav class="sf-fc-bar" aria-label="{{ __('storefront.fc_nav') }}">
    <ul class="sf-fc-bar__list" role="list">
        @foreach ($fc['bar'] as $b)
            <li class="sf-fc-bar__item"><a class="sf-fc-bar__link" href="{{ $b['href'] }}" style="--fc-fill:{{ $b['fill'] }}">
                <span class="sf-fc-bar__dot" aria-hidden="true">
                    @if ($b['text'] !== null)
                        <span class="sf-fc-bar__word">{{ $b['text'] }}</span>
                    @else
                        <svg class="sf-fc-bar__icon" viewBox="0 0 24 24" focusable="false"><path d="{{ $b['icon'] }}"/></svg>
                    @endif
                </span>
                <span class="sf-fc-bar__label">{{ $fcWord($b) }}</span>
            </a></li>
        @endforeach
        @if ($fc['buy'] !== null)
            <li class="sf-fc-bar__item"><a class="sf-fc-bar__link" href="{{ $fc['buy']['href'] }}" style="--fc-fill:{{ $fc['buy']['fill'] }};--fc-ink:{{ $fc['buy']['ink'] }}">
                <span class="sf-fc-bar__dot" aria-hidden="true"><svg class="sf-fc-bar__icon" viewBox="0 0 24 24" focusable="false"><path d="{{ $fc['buy']['icon'] }}"/></svg></span>
                <span class="sf-fc-bar__label">{{ __('storefront.fc_buy') }}</span>
            </a></li>
        @endif
    </ul>
</nav>
@endif
@endif
