@php
    // The head is the shared centered one (heading, decoration, optional description).
    // A block with the display group (a new one) draws a rail; a block saved before it keeps the plain list.
    // A partner with a logo or a name is shown: the name stands in for a logo not uploaded yet.
    $sfShown = static fn (mixed $logo): bool => (is_string($src = ((array) $logo)['image_url'] ?? null) && $src !== '')
        || \App\Modules\Theme\Components\SectionProps::text((array) $logo, 'name') !== '';
    $sfLogos = array_values(array_filter((array) ($props['logos'] ?? []), $sfShown));
    $sfDisplay = null;
    if (\App\Modules\Theme\Components\ListDisplay::applies($props)) {
        $sfDisplay = \App\Modules\Theme\Components\ListDisplay::plan('partners', $props, count($sfLogos), PHP_INT_MAX);
        $sfLogos = array_slice($sfLogos, 0, $sfDisplay['shown'] ?? count($sfLogos));
    }
@endphp
<section class="partners">
@include('theme::partials.sf-section-head', [
    'heading' => \App\Modules\Theme\Components\SectionProps::text($props, 'heading'),
    'description' => \App\Modules\Theme\Components\SectionProps::text($props, 'description'),
])
@if ($sfDisplay !== null)
@include('theme::partials.sf-rail-open', ['display' => $sfDisplay, 'label' => \App\Modules\Theme\Components\SectionProps::text($props, 'heading', __('storefront.list_rail'))])
@endif
    <ul class="{{ $sfDisplay['class'] ?? 'partners__list' }}"@if ($sfDisplay['scrolls'] ?? false) tabindex="0"@endif>
        @foreach ($sfLogos as $item)
            @php($item = (array) $item)
            {{-- A logo is drawn at its own proportions (the library's width/height reserve its space, no
                 CLS) and sized by them, so a square and a long logo weigh the same; a partner with no logo
                 shows its name in the same frame. Linked only when it has BOTH a url and a name — the
                 name is the link's accessible text, so a linked logo is never name-less. --}}
            <li class="partners__item">
                @if (($link = \App\Modules\Theme\Components\ButtonLink::of($item, 'url')) !== null && \App\Modules\Theme\Components\SectionProps::text($item, 'name') !== '')
                    <a class="partners__link" href="{{ $link['href'] }}"@if ($link['blank']) target="_blank" rel="noopener"@elseif ($link['download']) download=""@endif>
                        @include('theme::partials.partners-logo', ['item' => $item, 'dims' => $dims ?? []])
                    @if ($link['blank'])<span class="sr-only"> {{ __('storefront.new_tab') }}</span>@endif</a>
                @else
                    @include('theme::partials.partners-logo', ['item' => $item, 'dims' => $dims ?? []])
                @endif
            </li>
        @endforeach
    </ul>
@if ($sfDisplay !== null)
@include('theme::partials.sf-rail-close', ['display' => $sfDisplay])
@endif
@if (\App\Modules\Theme\Components\SectionProps::text($props, 'note') !== '')
    <p class="partners__note">{{ \App\Modules\Theme\Components\SectionProps::text($props, 'note') }}</p>
@endif
</section>
