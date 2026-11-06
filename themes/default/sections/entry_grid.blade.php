@if (in_array($variant ?? 'list', ['icon-cards', 'project-cards', 'news-cards', 'overlay'], true))
{{-- A card look (opt-in through `variant`, SectionProps): the shared band, head, card and
     "view all" partials. The default look below is the original markup, untouched. --}}
@php
    $sfKind = ['icon-cards' => 'icon', 'project-cards' => 'project', 'news-cards' => 'news', 'overlay' => 'product-overlay'][$variant];
    $sfGrid = ['icon' => 'sf-grid--4', 'project' => 'sf-grid--4s', 'news' => 'sf-grid--4s', 'product-overlay' => 'sf-grid--3s'][$sfKind];
    $sfSizes = $sfKind === 'product-overlay'
        ? '(min-width:1360px) 408px, (min-width:1024px) calc((100vw - 8.5rem)/3), (min-width:640px) calc((100vw - 5rem)/2), calc(100vw - 2rem)'
        : '(min-width:1360px) 298px, (min-width:1024px) calc((100vw - 10.5rem)/4), (min-width:640px) calc((100vw - 5rem)/2), calc(100vw - 2rem)';
    $sfBg = \App\Modules\Theme\Components\SectionProps::choice($props, 'bg', \App\Modules\Theme\Components\SectionProps::BG);
@endphp
@component('theme::partials.sf-band', ['bg' => $sfBg, 'class' => 'sf-cards sf-cards--'.$sfKind])
@include('theme::partials.sf-section-head', [
    'heading' => \App\Modules\Theme\Components\SectionProps::text($props, 'heading'),
    'description' => \App\Modules\Theme\Components\SectionProps::text($props, 'description'),
])
@php($sfDisplay = $display ?? null)
@if (count($items) > 0)
@if ($sfDisplay !== null)
@include('theme::partials.sf-rail-open', ['display' => $sfDisplay, 'label' => \App\Modules\Theme\Components\SectionProps::text($props, 'heading')])
@endif
<ul class="{{ $sfDisplay['class'] ?? 'sf-grid '.$sfGrid }}"@if ($sfDisplay['scrolls'] ?? false) tabindex="0" aria-label="{{ is_string($props['heading'] ?? null) && $props['heading'] !== '' ? $props['heading'] : __('storefront.list_rail') }}"@endif>
@foreach ($items as $card)
<li>@include('theme::partials.sf-card', [
    'card' => $card,
    'kind' => $sfKind,
    'level' => 'h3',
    'sizes' => $sfSizes,
    'linkLabel' => \App\Modules\Theme\Components\SectionProps::text($props, 'link_label', __('storefront.view_detail')),
])</li>
@endforeach
</ul>
@if ($sfDisplay !== null)
@include('theme::partials.sf-rail-close', ['display' => $sfDisplay])
@endif
@else
<p class="sf-empty">{{ __('storefront.no_content') }}</p>
@endif
@include('theme::partials.sf-view-all', ['label' => \App\Modules\Theme\Components\SectionProps::text($props, 'view_all_label'), 'url' => $props['view_all_url'] ?? null, 'show' => $sfDisplay['view_all'] ?? true])
@endcomponent
@else
<section class="entry-grid">
    @if (! empty($props['heading']))
        <h2 class="entry-grid__heading">{{ $props['heading'] }}</h2>
    @endif

    {{-- $items are SERVER-resolved cards (EntryGridComponent::data), not author Page-JSON props —
         hence a data var ($card), like latest_posts' $post. url = a stored route path; image = a
         site meta field. Neither is author-injected Page-JSON, so they are not type:url props. --}}
@php($sfDisplay = $display ?? null)
    @if (count($items) > 0)
@if ($sfDisplay !== null)
@include('theme::partials.sf-rail-open', ['display' => $sfDisplay, 'label' => (string) ($props['heading'] ?? '')])
@endif
        <ul class="{{ $sfDisplay['class'] ?? 'entry-grid__list' }}"@if ($sfDisplay['scrolls'] ?? false) tabindex="0" aria-label="{{ is_string($props['heading'] ?? null) && $props['heading'] !== '' ? $props['heading'] : __('storefront.list_rail') }}"@endif>
            @foreach ($items as $card)
                <li class="entry-grid__card">
                    @if (! empty($card['image']))
                        {{-- Featured image is smart: a path/URL value renders as <img> (CLS-safe via
                             width/height; lazy), otherwise it is a curated glyph key (e.g. a service
                             icon). Same image_url concept across post-type content. --}}
                        @if (\Illuminate\Support\Str::contains((string) $card['image'], ['/', '.', ':']))
                            <img class="entry-grid__image" src="{{ $card['image'] }}" alt="{{ $card['title'] ?? '' }}" width="480" height="320" loading="lazy">
                        @else
                            @include('theme::partials.icon', ['name' => $card['image']])
                        @endif
                    @endif
                    <h3 class="entry-grid__title">
                        @if (! empty($card['url']))
                            <a href="{{ $card['url'] }}">{{ $card['title'] ?? '' }}</a>
                        @else
                            {{ $card['title'] ?? '' }}
                        @endif
                    </h3>
                    @if (! empty($card['sector']))
                        <span class="entry-grid__sector">{{ $card['sector'] }}</span>
                    @endif
                    @if (! empty($card['short_outcome']))
                        <p class="entry-grid__outcome">{{ $card['short_outcome'] }}</p>
                    @endif
                </li>
            @endforeach
        </ul>
@if ($sfDisplay !== null)
@include('theme::partials.sf-rail-close', ['display' => $sfDisplay])
@endif
    @else
        <p class="entry-grid__empty">{{ __('storefront.no_content') }}</p>
    @endif
@if ($sfDisplay !== null)
{{-- "View all" only for a block that carries the display group; one saved before it never showed it. --}}
@include('theme::partials.sf-view-all', ['label' => \App\Modules\Theme\Components\SectionProps::text($props, 'view_all_label'), 'url' => $props['view_all_url'] ?? null, 'show' => $sfDisplay['view_all']])
@endif
</section>
@endif
