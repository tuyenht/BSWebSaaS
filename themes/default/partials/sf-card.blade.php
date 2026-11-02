{{--
    One card, the same in a block and on a list page. `kind`: icon, product, product-overlay,
    project or news. `card` is server-built (title, url = a stored route path, image + srcset,
    icon = a glyph key, excerpt, date + datetime, label); no id ever reaches the markup.
    The title link is the card's one link and is stretched over the whole card; "view details" /
    "read more" are visual text only (aria-hidden). Titles and labels wrap, never clip; only an
    excerpt may be cut at four lines. `level` is h3 inside a block and h2 on a list page.
    `priority` (high / eager / lazy) and `sizes` go to the photo.
--}}
@php
    $sfKind = in_array($kind ?? 'news', ['icon', 'product', 'product-overlay', 'project', 'news'], true) ? $kind : 'news';
    $sfUrl = \App\Modules\Shared\Support\SafeLink::href($card['url'] ?? null);
    $sfLevel = ($level ?? 'h3') === 'h2' ? 'h2' : 'h3';
    $sfTitle = (string) ($card['title'] ?? '');
    $sfExcerpt = (string) ($card['excerpt'] ?? '');
    $sfLabel = (string) ($card['label'] ?? '');
    $sfFrame = in_array($sfKind, ['product', 'product-overlay'], true) ? '4x3' : '8x5';
    $sfSizes = $sizes ?? '(min-width:1360px) 408px, (min-width:1024px) calc((100vw - 8.5rem)/3), (min-width:768px) calc((100vw - 5rem)/2), calc(100vw - 2rem)';
@endphp
<article class="sf-card sf-card--{{ $sfKind }}">
@if ($sfKind === 'icon')
@if (($card['icon'] ?? '') !== '')
    @include('theme::partials.icon', ['name' => $card['icon'], 'stroke' => 1.5, 'wrap' => 'sf-card__icon'])
@endif
    <{{ $sfLevel }} class="sf-card__title">@if ($sfUrl !== null)<a class="sf-card__link" href="{{ $sfUrl }}">{{ $sfTitle }}</a>@else{{ $sfTitle }}@endif</{{ $sfLevel }}>
@if ($sfExcerpt !== '')
    <p class="sf-card__text">{{ $sfExcerpt }}</p>
@endif
@if ($sfUrl !== null)
    <span class="sf-card__more" aria-hidden="true">{{ $linkLabel ?? __('storefront.view_detail') }} <span class="sf-card__arrow">→</span></span>
@endif
@elseif ($sfKind === 'product-overlay')
    <div class="sf-card__frame sf-card__frame--{{ $sfFrame }}">
        @include('theme::partials.sf-img', ['src' => $card['image'] ?? null, 'srcset' => $card['srcset'] ?? null, 'sizes' => $sfSizes, 'w' => 960, 'h' => 720, 'priority' => $priority ?? 'lazy', 'class' => 'sf-card__img'])
        <span class="sf-card__shade" aria-hidden="true"></span>
        <span class="sf-card__hover" aria-hidden="true"><span class="sf-card__eye">@include('theme::partials.icon', ['name' => 'eye', 'stroke' => 2])</span></span>
        <div class="sf-card__caption">
@if ($sfLabel !== '')
            <p class="sf-card__label">{{ $sfLabel }}</p>
@endif
            <{{ $sfLevel }} class="sf-card__title">@if ($sfUrl !== null)<a class="sf-card__link" href="{{ $sfUrl }}">{{ $sfTitle }}</a>@else{{ $sfTitle }}@endif</{{ $sfLevel }}>
        </div>
    </div>
@else
    <div class="sf-card__frame sf-card__frame--{{ $sfFrame }}">
        @include('theme::partials.sf-img', ['src' => $card['image'] ?? null, 'srcset' => $card['srcset'] ?? null, 'sizes' => $sfSizes, 'w' => 960, 'h' => $sfFrame === '4x3' ? 720 : 600, 'priority' => $priority ?? 'lazy', 'class' => 'sf-card__img'])
@if ($sfKind === 'project')
        <span class="sf-card__shade" aria-hidden="true"></span>
@endif
    </div>
    <div class="sf-card__body">
@if ($sfKind === 'news' && ($sfLabel !== '' || ($card['date'] ?? '') !== ''))
        <div class="sf-card__meta">
@if ($sfLabel !== '')
            <span class="sf-card__chip">{{ $sfLabel }}</span>
@endif
@if (($card['date'] ?? '') !== '')
            <time class="sf-card__date" datetime="{{ $card['datetime'] ?? '' }}">{{ $card['date'] }}</time>
@endif
        </div>
@elseif ($sfKind === 'product' && $sfLabel !== '')
        <p class="sf-card__label">{{ $sfLabel }}</p>
@endif
        <{{ $sfLevel }} class="sf-card__title">@if ($sfUrl !== null)<a class="sf-card__link" href="{{ $sfUrl }}">{{ $sfTitle }}</a>@else{{ $sfTitle }}@endif</{{ $sfLevel }}>
@if ($sfKind !== 'product' && $sfExcerpt !== '')
        <p class="sf-card__text">{{ $sfExcerpt }}</p>
@endif
@if ($sfKind === 'news' && $sfUrl !== null)
        <span class="sf-card__more" aria-hidden="true">{{ __('storefront.read_more') }} <span class="sf-card__arrow">→</span></span>
@endif
    </div>
@endif
</article>
