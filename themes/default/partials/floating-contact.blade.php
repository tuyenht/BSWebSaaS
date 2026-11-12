{{--
    Floating contact buttons (`storefront.floating_contact`, normalized by FloatingContact; null =
    nothing printed). Plain links only — no script. "vertical": round buttons in a corner or along
    the bottom on every screen; "horizontal": a bar along the bottom on phones (every channel in the
    admin's order; place N from the 4th shows where its container is N x 4.5rem wide, sf-fc-bar__item--r4..r11,
    and --rx from the 12th never shows) and the same channels as round buttons from 768px (sf-fc--wide).
    An invisible copy after the footer (same list classes, so the same size and wrapping) keeps exactly the
    room they take, so the end of the page — footer links included — always scrolls clear of them:
    for the bar below 768px and for the round buttons from 768px (sf-fc-ghost--wide), never both; in
    the vertical theme for the bottom row at every width and for a side stack wherever it can cover the
    footer's text. Labels wrap at spaces, so a fixed padding could not match. The bar ends with "Mua hàng"
    when the site has a product list; it always takes one of the first three places, so a narrow phone drops
    channels from the end and keeps it. Each link
    carries its name (aria-label), which also holds the word printed on the button (SMS), and the
    hover label repeats the name for the eye. Colours come from the normalizer (white marks at 3:1,
    words at 4.5:1; the brand fill uses the brand's own readable ink).
    A round-button site may add a phone bar (`phone_bar`): below 768px the round buttons give way to a
    dark bar of up to three equal cells (quick channels, then the site's own cell such as a quote
    request, drawn with the design's send-a-file glyph) and above it the round buttons stay; no band is
    reserved under the footer at the wider widths, and the invisible copy under it is dark, like the footer, so the page ends on the bar.
--}}
@if (! empty($floatingContact))
@php
    $fc = $floatingContact;
    $fcWord = static fn (array $b): string => $b['label']
        ?? (array_key_exists($b['key'], \App\Modules\Theme\Chrome\FloatingContact::CHANNELS) ? __('storefront.fc_'.$b['key']) : __('storefront.fc_contact'));
    $fcName = static fn (array $b): string => $b['text'] !== null && ! str_contains(mb_strtolower($fcWord($b)), mb_strtolower($b['text']))
        ? $fcWord($b).' '.$b['text'] : $fcWord($b);
    $fcOffsets = '--fc-ox:'.$fc['offset_x'].'px;--fc-oy:'.$fc['offset_y'].'px';
    // Place of a bar channel in the order buttons drop out on narrow phones: "Mua hàng" stays third.
    // From the 12th place on a cell never shows (--rx): cells past the 11th (792px at 4.5rem) never fit under 768px.
    $fcRank = static fn (int $i): string => ($r = $i + 1 + ($fc['buy'] !== null && $i >= 2 ? 1 : 0)) > 3 ? ' sf-fc-bar__item--'.($r > 11 ? 'rx' : 'r'.$r) : '';
    $fcBarMode = $fc['theme'] === 'horizontal';
@endphp
@php($fcPb = $fc['phone_bar'])
@if ($fcBarMode || $fcPb === null)
<div class="sf-fc-ghost sf-fc-ghost--{{ $fc['position'] === 'bottom' ? 'row' : 'side' }}{{ $fcBarMode ? ' sf-fc-ghost--wide' : '' }}" aria-hidden="true" style="{{ $fcOffsets }};--fc-n:{{ count($fc['buttons']) }}"><div class="sf-fc__list sf-fc__list--{{ $fc['position'] }}">@foreach ($fc['buttons'] as $b)<span class="sf-fc__btn"></span>@endforeach</div></div>
@endif
<nav class="sf-fc sf-fc--{{ $fc['position'] }}{{ $fcBarMode ? ' sf-fc--wide' : ($fcPb !== null ? ' sf-fc--md' : '') }}" aria-label="{{ __('storefront.fc_nav') }}" style="{{ $fcOffsets }}">
    <ul class="sf-fc__list sf-fc__list--{{ $fc['position'] }}" role="list">
        @foreach ($fc['buttons'] as $b)
            <li><a class="sf-fc__btn{{ $b['pulse'] ? ' sf-fc__btn--pulse' : '' }}" href="{{ $b['href'] }}" aria-label="{{ $fcName($b) }}" style="--fc-fill:{{ $b['fill'] }};--fc-ink:{{ $b['ink'] }}">
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
@if (! $fcBarMode && $fcPb !== null)
<div class="sf-fc-bar sf-fc-bar--board sf-fc-bar--ghost" aria-hidden="true"><ul class="sf-fc-bar__list" role="list">@foreach ($fcPb['bar'] as $b)<li class="sf-fc-bar__item"><span class="sf-fc-bar__link"><span class="sf-fc-bar__dot"></span><span class="sf-fc-bar__label">{{ $fcWord($b) }}</span></span></li>@endforeach @if ($fcPb['cta'] !== null)<li class="sf-fc-bar__item"><span class="sf-fc-bar__link"><span class="sf-fc-bar__dot"></span><span class="sf-fc-bar__label">{{ $fcPb['cta']['label'] }}</span></span></li>@endif</ul></div>
<nav class="sf-fc-bar sf-fc-bar--board" aria-label="{{ __('storefront.fc_nav') }}">
    <ul class="sf-fc-bar__list" role="list">
        @foreach ($fcPb['bar'] as $b)
            <li class="sf-fc-bar__item"><a class="sf-fc-bar__link" href="{{ $b['href'] }}" @if (str_starts_with($b['href'], 'http')) rel="noopener" @endif style="--fc-fill:{{ $b['fill'] }};--fc-ink:{{ $b['ink'] }}">
                <span class="sf-fc-bar__dot{{ $b['text'] !== null ? ' sf-fc-bar__dot--chip' : '' }}" aria-hidden="true">
                    @if ($b['text'] !== null)
                        <span class="sf-fc-bar__word">{{ $b['text'] }}</span>
                    @else
                        <svg class="sf-fc-bar__icon" viewBox="0 0 24 24" focusable="false"><path d="{{ $b['icon'] }}"/></svg>
                    @endif
                </span>
                <span class="sf-fc-bar__label">{{ $fcWord($b) }}</span>
            </a></li>
        @endforeach
        @if ($fcPb['cta'] !== null)
            <li class="sf-fc-bar__item sf-fc-bar__item--cta"><a class="sf-fc-bar__link" href="{{ $fcPb['cta']['href'] }}"><span class="sf-fc-bar__dot" aria-hidden="true">@include('theme::partials.sf-icon', ['name' => 'upload', 'size' => 22])</span><span class="sf-fc-bar__label">{{ $fcPb['cta']['label'] }}</span></a></li>
        @endif
    </ul>
</nav>
@endif
@if ($fcBarMode)
<div class="sf-fc-bar sf-fc-bar--ghost" aria-hidden="true"><ul class="sf-fc-bar__list" role="list">@foreach ($fc['bar'] as $b)<li class="sf-fc-bar__item{{ $fcRank($loop->index) }}"><span class="sf-fc-bar__link"><span class="sf-fc-bar__dot">@if ($b['text'] !== null)<span class="sf-fc-bar__word">{{ $b['text'] }}</span>@endif</span><span class="sf-fc-bar__label">{{ $fcWord($b) }}</span></span></li>@endforeach @if ($fc['buy'] !== null)<li class="sf-fc-bar__item"><span class="sf-fc-bar__link"><span class="sf-fc-bar__dot"></span><span class="sf-fc-bar__label">{{ __('storefront.fc_buy') }}</span></span></li>@endif</ul></div>
<nav class="sf-fc-bar" aria-label="{{ __('storefront.fc_nav') }}">
    <ul class="sf-fc-bar__list" role="list">
        @foreach ($fc['bar'] as $b)
            <li class="sf-fc-bar__item{{ $fcRank($loop->index) }}"><a class="sf-fc-bar__link" href="{{ $b['href'] }}" style="--fc-fill:{{ $b['fill'] }};--fc-ink:{{ $b['ink'] }}">
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
