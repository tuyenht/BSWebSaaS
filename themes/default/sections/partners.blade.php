@php
    // A block with the display group (a new one) draws a rail; a block saved before it keeps the plain list.
    $sfLogos = array_values((array) ($props['logos'] ?? []));
    $sfDisplay = null;
    if (\App\Modules\Theme\Components\ListDisplay::applies($props)) {
        $sfLogos = array_values(array_filter($sfLogos, static fn (mixed $logo): bool => ! empty(((array) $logo)['image_url'])));
        $sfDisplay = \App\Modules\Theme\Components\ListDisplay::plan('partners', $props, count($sfLogos), PHP_INT_MAX);
        $sfLogos = array_slice($sfLogos, 0, $sfDisplay['shown'] ?? count($sfLogos));
    }
@endphp
<section class="partners">
    @if (! empty($props['heading']))
        <h2 class="partners__heading">{{ $props['heading'] }}</h2>
    @endif
@if ($sfDisplay !== null)
@include('theme::partials.sf-rail-open', ['display' => $sfDisplay, 'label' => (string) ($props['heading'] ?? '')])
@endif
    <ul class="{{ $sfDisplay['class'] ?? 'partners__list' }}"@if ($sfDisplay['scrolls'] ?? false) tabindex="0" aria-label="{{ is_string($props['heading'] ?? null) && $props['heading'] !== '' ? $props['heading'] : __('storefront.list_rail') }}"@endif>
        @foreach ($sfLogos as $item)
            @php($item = (array) $item)
            @continue(empty($item['image_url']))
            {{-- Logo = pre-optimized image (Option C R2); width/height reserve space (no CLS).
                 Item skipped if it has no image. Linked only when it has BOTH a url and a name —
                 the name is the link's accessible text (alt), so a linked logo is never name-less. --}}
            <li class="partners__item">
                @if (($link = \App\Modules\Theme\Components\ButtonLink::of($item, 'url')) !== null && ! empty($item['name']))
                    <a class="partners__link" href="{{ $link['href'] }}"@if ($link['blank']) target="_blank" rel="noopener"@elseif ($link['download']) download=""@endif>
                        <img class="partners__logo" src="{{ $item['image_url'] }}" alt="{{ $item['name'] ?? '' }}" width="160" height="80" loading="lazy">
                    @if ($link['blank'])<span class="sr-only"> {{ __('storefront.new_tab') }}</span>@endif</a>
                @else
                    <img class="partners__logo" src="{{ $item['image_url'] }}" alt="{{ $item['name'] ?? '' }}" width="160" height="80" loading="lazy">
                @endif
            </li>
        @endforeach
    </ul>
@if ($sfDisplay !== null)
@include('theme::partials.sf-rail-close', ['display' => $sfDisplay])
@endif
</section>
