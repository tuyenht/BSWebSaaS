{{--
    Floating contact (`storefront.floating_contact`, worked out by FloatingContact::widget(); null =
    nothing printed). Every channel is a plain link printed here, so it works before any script.
    Layouts: combo = round buttons from 768px and the bottom bar below it; vertical = round buttons at
    every width (on a phone with many channels folded into one dark "Liên hệ" button that opens them);
    horizontal = the bottom bar on phones only. The bar holds FloatingContact::barSlots(): its "Thêm"
    cell opens a small sheet above the bar ("Kênh liên hệ khác"), "Mua hàng" always last; a narrow phone
    drops channel cells from the end (sf-fc-bar__item--r4/r5, min. three cells) and the sheet then lists
    them — a bar of four or five cells without "Thêm" gains one there (--m4/--m5), so no channel is lost.
    The dial and the sheet open with the browser's popover (Esc, a tap outside and focus return are the
    browser's); a browser without popover shows the dial's buttons in place and the sheet's channels as
    more cells of a bar that scrolls sideways.
    On a computer the call button's label shows the number and a "Chép số" button (sf-fc-copy.js, its
    address in data-fc-js only when that button is printed; fetched once the page has loaded on a
    computer, or on the first touch of the widget). With click tracking on, each link names its channel,
    place and kind of link (never the number or address) for the `bs:contact-click` event (storefront.js).
    Invisible copies in the page flow after the footer keep the room the buttons and the bar take.
    A channel with its own icon picture (a PNG or WebP of the site's media library) prints it as an <img>
    covering the disc, in the round buttons, the bar, the sheet and the dial alike.
    The admin preview prints this same partial; only there a channel not filled in yet carries
    data-fc-pending (FloatingContact::widget(…, pending: true)), styled by the admin page alone.
--}}
@if (! empty($floatingContact))
@php
    $fc = $floatingContact;
    $fcFloat = $fc['layout'] !== 'horizontal';
    $fcBar = $fc['layout'] !== 'vertical';
    $fcWord = static fn (array $b): string => $b['label']
        ?? (array_key_exists($b['key'], \App\Modules\Theme\Chrome\FloatingContact::CHANNELS) ? __('storefront.fc_'.$b['key']) : __('storefront.fc_contact'));
    $fcEv = static fn (string $channel, string $at, string $link): string => $fc['track']
        ? ' data-fc="'.e($channel).'" data-fc-at="'.e($at).'" data-fc-lt="'.e($link).'"' : '';
    $fcRel = static fn (string $href): string => str_starts_with($href, 'http') ? ' rel="noopener"' : '';
    $fcVars = static fn (array $b): string => '--fc-fill:'.$b['fill'].';--fc-tint:'.$b['tint'];
    // A channel's own icon picture (ContactIconImages) fills the disc instead of the glyph; it says nothing
    // the link's label does not (alt=""), and its size is printed so the disc never shifts.
    $fcGlyph = static fn (array $b, string $cls): string => ($b['img'] ?? null) !== null
        ? '<img class="sf-fc__img" src="'.e($b['img']['src']).'" alt="" width="'.(int) $b['img']['w'].'" height="'.(int) $b['img']['h'].'" loading="lazy" decoding="async">'
        : ($b['zalo']
        ? '<svg class="'.$cls.'" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 2.5C6.2 2.5 1.5 6.3 1.5 11c0 2.6 1.4 4.9 3.7 6.5l-.9 3.4a.5.5 0 0 0 .7.6l3.9-2.1c1 .3 2 .4 3.1.4 5.8 0 10.5-3.8 10.5-8.5S17.8 2.5 12 2.5z"/><path class="sf-fc__z" d="M7.7 6.6h8.6v1.9l-5.8 5.9h5.8v1.9H7.7v-1.9l5.8-5.9H7.7z"/></svg>'
        : '<svg class="'.$cls.'" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="'.e($b['icon']).'"/></svg>');
    $fcIcon = static fn (string $code, string $cls): string => '<svg class="'.$cls.'" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="'.e(\App\Modules\Theme\Chrome\ContactIcons::solid($code) ?? '').'"/></svg>';
    $fcOffsets = '--fc-ox:'.$fc['offset_x'].'px;--fc-oy:'.$fc['offset_y'].'px';
    // The bar fits five cells from 22.5rem, four from 18rem, three below. "Thêm" and "Mua hàng" stay on
    // any phone, so a channel cell's place counts them first ("Thêm" too where the bar has none: a narrow
    // phone adds it), never past the bar's own cell count: places 4 and 5 are the channels a narrow phone
    // lets go (r4 below 18rem, r5 below 22.5rem), and the extra "Thêm" (m4/m5) shows exactly there.
    $fcBuy = $fc['buy'] !== null ? 1 : 0;
    $fcCells = count($fc['bar']) + ($fc['more'] ? 1 : 0) + $fcBuy;
    $fcRank = static fn (int $i): int => min($i + 2 + $fcBuy, $fcCells);
    $fcSpare = ! $fc['more'] && $fcCells >= 4 ? ' sf-fc-bar__item--m'.$fcCells : '';
    $fcSheet = $fc['more'] || $fcSpare !== '';
    $fcCopy = $fcFloat && array_filter($fc['buttons'], static fn (array $b): bool => $b['tel'] !== null && $b['key'] === 'phone') !== [];
@endphp
@if ($fcFloat)
<div class="sf-fc-ghost sf-fc-ghost--side{{ $fc['position'] === 'bottom' ? ' sf-fc-ghost--row' : '' }}{{ $fc['layout'] === 'combo' ? ' sf-fc-ghost--wide' : '' }}{{ $fc['dial'] ? ' sf-fc-ghost--dial' : '' }}" aria-hidden="true" style="{{ $fcOffsets }};--fc-n:{{ count(array_filter($fc['buttons'], static fn (array $b): bool => $b['key'] !== 'sms')) }}"><div class="sf-fc__list sf-fc__list--{{ $fc['position'] }}">@foreach ($fc['buttons'] as $b)<span class="sf-fc__btn{{ $b['key'] === 'sms' ? ' sf-fc__item--sms' : '' }}"></span>@endforeach @if ($fc['dial'])<span class="sf-fc__btn sf-fc-ghost__dial"></span>@endif</div></div>
@endif
@if ($fcBar)
<div class="sf-fc-bar sf-fc-bar--ghost" aria-hidden="true"><ul class="sf-fc-bar__list">@foreach ($fc['bar'] as $b)<li class="sf-fc-bar__item{{ $fcRank($loop->index) > 3 ? ' sf-fc-bar__item--r'.$fcRank($loop->index) : '' }}"><span class="sf-fc-bar__link"><span class="sf-fc-bar__dot"></span><span class="sf-fc-bar__label">{{ $fcWord($b) }}</span></span></li>@endforeach @if ($fcSheet)<li class="sf-fc-bar__item sf-fc-bar__item--more{{ $fcSpare }}"><span class="sf-fc-bar__link"><span class="sf-fc-bar__dot"></span><span class="sf-fc-bar__label">{{ __('storefront.fc_more') }}</span></span></li>@endif @if ($fc['buy'] !== null)<li class="sf-fc-bar__item"><span class="sf-fc-bar__link"><span class="sf-fc-bar__dot"></span><span class="sf-fc-bar__label">{{ __('storefront.fc_buy') }}</span></span></li>@endif</ul></div>
@endif
<div class="sf-fc sf-fc--{{ $fc['layout'] }}" data-fc-root{!! $fcCopy ? ' data-fc-js="'.e(\Illuminate\Support\Facades\Vite::asset('resources/js/sf-fc-copy.js')).'"' : '' !!}>
@if ($fcFloat)
    <nav class="sf-fc__float sf-fc__float--{{ $fc['position'] }}{{ $fc['dial'] ? ' sf-fc__float--dial' : '' }}" aria-label="{{ __('storefront.fc_nav') }}" style="{{ $fcOffsets }}">
        <ul class="sf-fc__list sf-fc__list--{{ $fc['position'] }}" role="list"@if ($fc['dial']) id="sf-fc-dial" popover @endif>
            @foreach ($fc['buttons'] as $b)
                <li class="sf-fc__item{{ $b['key'] === 'sms' ? ' sf-fc__item--sms' : '' }}{{ $b['tel'] !== null && $b['key'] === 'phone' ? ' sf-fc__item--call' : '' }}{{ $b['pulse'] ? ' sf-fc__item--pulse' : '' }}" style="{{ $fcVars($b) }}"{!! empty($b['pending']) ? '' : ' data-fc-pending' !!}>
                    <a class="sf-fc__btn" href="{{ $b['href'] }}" aria-label="{{ $fcWord($b) }}"{!! $fcRel($b['href']) !!}{!! $fcEv($b['key'], $fc['dial'] ? 'dial' : 'floating', $b['link']) !!}>{!! $fcGlyph($b, 'sf-fc__icon') !!}<span class="sf-fc__tip" aria-hidden="true">{{ $fcWord($b) }}</span></a>
                    @if ($b['tel'] !== null && $b['key'] === 'phone')
                        <span class="sf-fc__label"><span class="sf-fc__label-in"><span class="sf-fc__label-t">{{ $fcWord($b) }} · <span class="sf-fc__num">{{ $b['tel'] }}</span></span><button type="button" class="sf-fc__copy" data-fc-copy data-done="{{ __('storefront.fc_copied') }}">{!! $fcIcon('ri-file-copy-line', 'sf-fc__copy-i') !!}<span class="sf-fc__copy-t">{{ __('storefront.fc_copy') }}</span></button><span class="sf-fc__sr" role="status"></span></span></span>
                    @endif
                </li>
            @endforeach
        </ul>
        @if ($fc['dial'])
            <button type="button" class="sf-fc__dial" popovertarget="sf-fc-dial"{!! $fcEv('dial', 'dial', 'none') !!}>{!! $fcIcon('ri-customer-service-2-fill', 'sf-fc__icon sf-fc__dial-open') !!}{!! $fcIcon('ri-close-line', 'sf-fc__icon sf-fc__dial-shut') !!}<span class="sf-fc__sr sf-fc__dial-open">{{ __('storefront.fc_dial', ['n' => count($fc['buttons'])]) }}</span><span class="sf-fc__sr sf-fc__dial-shut">{{ __('storefront.fc_close') }}</span></button>
        @endif
    </nav>
@endif
@if ($fcBar)
    <nav class="sf-fc-bar sf-fc-bar--{{ $fc['tone'] }}" aria-label="{{ __('storefront.fc_nav') }}">
        @if ($fcSheet)
            <div class="sf-fc-sheet" id="sf-fc-more" popover>
                <p class="sf-fc-sheet__h">{{ __('storefront.fc_others') }}</p>
                <ul class="sf-fc-sheet__list" role="list">
                    @foreach ($fc['bar'] as $b)
                        @if ($fcRank($loop->index) > 3)
                            <li class="sf-fc-sheet__item sf-fc-sheet__item--r{{ $fcRank($loop->index) }}"{!! empty($b['pending']) ? '' : ' data-fc-pending' !!}><a class="sf-fc-sheet__row" href="{{ $b['href'] }}"{!! $fcRel($b['href']) !!}{!! $fcEv($b['key'], 'sheet', $b['link']) !!}><span class="sf-fc-bar__dot" style="{{ $fcVars($b) }}">{!! $fcGlyph($b, 'sf-fc-bar__icon') !!}</span><span class="sf-fc-sheet__l">{{ $fcWord($b) }}</span>{!! $fcIcon('ri-arrow-right-s-line', 'sf-fc-sheet__go') !!}</a></li>
                        @endif
                    @endforeach
                    @foreach ($fc['sheet'] as $b)
                        <li class="sf-fc-sheet__item"{!! empty($b['pending']) ? '' : ' data-fc-pending' !!}><a class="sf-fc-sheet__row" href="{{ $b['href'] }}"{!! $fcRel($b['href']) !!}{!! $fcEv($b['key'], 'sheet', $b['link']) !!}><span class="sf-fc-bar__dot" style="{{ $fcVars($b) }}">{!! $fcGlyph($b, 'sf-fc-bar__icon') !!}</span><span class="sf-fc-sheet__l">{{ $fcWord($b) }}</span>{!! $fcIcon('ri-arrow-right-s-line', 'sf-fc-sheet__go') !!}</a></li>
                    @endforeach
                </ul>
            </div>
        @endif
        <ul class="sf-fc-bar__list" role="list">
            @foreach ($fc['bar'] as $b)
                <li class="sf-fc-bar__item{{ $fcRank($loop->index) > 3 ? ' sf-fc-bar__item--r'.$fcRank($loop->index) : '' }}"{!! empty($b['pending']) ? '' : ' data-fc-pending' !!}><a class="sf-fc-bar__link" href="{{ $b['href'] }}"{!! $fcRel($b['href']) !!}{!! $fcEv($b['key'], 'bar', $b['link']) !!}><span class="sf-fc-bar__dot" style="{{ $fcVars($b) }}">{!! $fcGlyph($b, 'sf-fc-bar__icon') !!}</span><span class="sf-fc-bar__label">{{ $fcWord($b) }}</span></a></li>
            @endforeach
            @if ($fcSheet)
                <li class="sf-fc-bar__item sf-fc-bar__item--more{{ $fcSpare }}"><button type="button" class="sf-fc-bar__link" popovertarget="sf-fc-more"><span class="sf-fc-bar__dot" style="--fc-fill:{{ \App\Modules\Theme\Chrome\FloatingContact::MORE_COLOR }};--fc-tint:{{ \App\Modules\Theme\Chrome\FloatingContact::MORE_COLOR }}">{!! $fcIcon('ri-more-2-fill', 'sf-fc-bar__icon') !!}</span><span class="sf-fc-bar__label">{{ __('storefront.fc_more') }}</span></button></li>
            @endif
            @if ($fc['buy'] !== null)
                <li class="sf-fc-bar__item"><a class="sf-fc-bar__link" href="{{ $fc['buy']['href'] }}"{!! $fcEv('buy', 'bar', str_starts_with($fc['buy']['href'], '/') ? 'page' : 'web') !!}><span class="sf-fc-bar__dot" style="--fc-fill:{{ $fc['buy']['fill'] }};--fc-tint:{{ $fc['buy']['fill'] }};color:{{ $fc['buy']['ink'] }}">{!! $fcIcon('ri-shopping-bag-fill', 'sf-fc-bar__icon') !!}</span><span class="sf-fc-bar__label">{{ __('storefront.fc_buy') }}</span></a></li>
            @endif
        </ul>
    </nav>
@endif
</div>
@endif
