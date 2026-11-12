{{--
    Features: a band (`bg`: none, surface, tint, dark) with the shared section head and a grid sized by how
    many items there are: two fill two columns, three fill three, a multiple of four fills rows of four on
    a wide screen (two a row below it), so none of these counts leaves a card alone; any other count takes
    three a row.

    - top-rule (opt-in): cards with an accent rule; on a phone each is a compact row (glyph left, text
      right) one a row, from a tablet up a centered card with the rule on top.
    - icon-head (opt-in): a white card per item, the glyph in a round brand tile on the same line as a large
      title, the text under it, left aligned; two across on a wide screen.
    - icon-cards (opt-in): each item is the shared icon card (theme::partials.sf-card, kind icon): a large
      brand glyph over a centered title and text. With the display group (`layout`) the list is a rail, as a
      list block's: a grid on a wide screen, a swipe row on a phone.
    - default: plain cards; an item with a glyph shows it in a dark tile beside its text. A multiple of
      four puts two cards a row on a phone too, and there the tile sits above the text.

    An item may carry a link (`url`, a button link: ButtonLink::of). With one, its title is the card's one
    link, stretched over the whole card inside the card's own layer, and the card ends with "Xem chi tiết →"
    (visual text only). Without one the card draws exactly as it did before items had links.

    On a dark band the top-rule and default looks drop the card: the glyph sits beside a light title.
    `icon` is a key of the icon partial; an unknown key draws nothing.
--}}
@php
    $sfVariant = \App\Modules\Theme\Components\SectionProps::choice($props, 'variant', \App\Modules\Theme\Components\SectionProps::FEATURES_VARIANTS);
    $sfTopRule = $sfVariant === 'top-rule';
    $sfIconHead = $sfVariant === 'icon-head';
    $sfIconCards = $sfVariant === 'icon-cards';
    $sfItems = array_map(static fn (mixed $sfItem): array => (array) $sfItem, array_values((array) ($props['items'] ?? [])));
    $sfCount = count($sfItems);
    $sfGrid = match (true) {
        $sfCount <= 2 => 'sf-grid--2',
        $sfCount === 3 => 'sf-grid--3',
        $sfCount % 4 === 0 => $sfTopRule || $sfIconCards ? 'sf-grid--4' : 'sf-grid--4p',
        default => 'sf-grid--3s',
    };
    $sfDisplay = $sfIconCards ? \App\Modules\Theme\Components\ListDisplay::plan('icon', $props, $sfCount, PHP_INT_MAX) : null;
    if ($sfDisplay !== null) {
        $sfItems = array_slice($sfItems, 0, $sfDisplay['shown']);
    }
    $sfMore = __('storefront.view_detail');
    $sfBandClass = match (true) {
        $sfIconCards => 'sf-features sf-cards sf-cards--icon',
        $sfIconHead => 'sf-features sf-features--head',
        $sfTopRule => 'sf-features',
        default => 'sf-features sf-features--cards',
    };
@endphp
@component('theme::partials.sf-band', ['bg' => \App\Modules\Theme\Components\SectionProps::choice($props, 'bg', \App\Modules\Theme\Components\SectionProps::BG), 'class' => $sfBandClass])
@include('theme::partials.sf-section-head', [
    'heading' => \App\Modules\Theme\Components\SectionProps::text($props, 'heading'),
    'description' => \App\Modules\Theme\Components\SectionProps::text($props, 'description'),
])
@if ($sfDisplay !== null)
@include('theme::partials.sf-rail-open', ['display' => $sfDisplay, 'label' => \App\Modules\Theme\Components\SectionProps::text($props, 'heading', __('storefront.list_rail'))])
@endif
<ul class="{{ $sfDisplay['class'] ?? 'sf-grid '.$sfGrid }}"@if ($sfDisplay['scrolls'] ?? false) tabindex="0"@endif>
@foreach ($sfItems as $item)
@php
    $sfLink = \App\Modules\Theme\Components\ButtonLink::of($item, 'url');
    $sfTitle = (string) (is_scalar($item['title'] ?? null) ? $item['title'] : '');
@endphp
@if ($sfIconCards)
<li>@include('theme::partials.sf-card', [
    'card' => ['title' => $sfTitle, 'icon' => is_string($item['icon'] ?? null) ? $item['icon'] : '', 'excerpt' => is_scalar($item['text'] ?? null) ? (string) $item['text'] : ''],
    'kind' => 'icon',
    'level' => 'h3',
    'link' => $sfLink,
])</li>
@continue
@endif
@php
    // The title, escaped once; with a link, the card's one link around it.
    $sfTitleHtml = new \Illuminate\Support\HtmlString($sfLink === null ? e($item['title'] ?? '') : '<a class="sf-card__link" href="'.e($sfLink['href']).'"'
        .($sfLink['blank'] ? ' target="_blank" rel="noopener"' : ($sfLink['download'] ? ' download=""' : '')).'>'
        .e($sfTitle).($sfLink['blank'] ? '<span class="sr-only"> '.e(__('storefront.new_tab')).'</span>' : '').'</a>');
@endphp
@if ($sfIconHead)
<li class="sf-hcard{{ $sfLink !== null ? ' sf-hcard--link' : '' }}">
    <div class="sf-hcard__head">
@if (is_string($item['icon'] ?? null) && $item['icon'] !== '')
        @include('theme::partials.icon', ['name' => $item['icon'], 'stroke' => 2, 'wrap' => 'sf-hcard__icon'])
@endif
        <h3 class="sf-hcard__title">{{ $sfTitleHtml }}</h3>
    </div>
@if ($sfLink !== null)
    <div class="sf-hcard__body">
@if (! empty($item['text']))
        <p class="sf-hcard__text">{{ $item['text'] }}</p>
@endif
        <span class="sf-card__more" aria-hidden="true">{{ $sfMore }} <span class="sf-card__arrow">→</span></span>
    </div>
@elseif (! empty($item['text']))
    <p class="sf-hcard__text">{{ $item['text'] }}</p>
@endif
</li>
@continue
@endif
<li class="{{ $sfTopRule ? 'sf-feature' : 'sf-fcard' }}{{ $sfLink !== null ? ($sfTopRule ? ' sf-feature--link' : ' sf-fcard--link') : '' }}">
@if (is_string($item['icon'] ?? null) && $item['icon'] !== '')
    @include('theme::partials.icon', ['name' => $item['icon'], 'stroke' => 1.5, 'wrap' => $sfTopRule ? 'sf-feature__icon' : 'sf-fcard__icon'])
@endif
    <div class="{{ $sfTopRule ? 'sf-feature__body' : 'sf-fcard__body' }}">
        <h3 class="{{ $sfTopRule ? 'sf-feature__title' : 'sf-fcard__title' }}">{{ $sfTitleHtml }}</h3>
@if (! empty($item['text']))
        <p class="{{ $sfTopRule ? 'sf-feature__text' : 'sf-fcard__text' }}">{{ $item['text'] }}</p>
@endif
@if ($sfLink !== null)
        <span class="sf-card__more" aria-hidden="true">{{ $sfMore }} <span class="sf-card__arrow">→</span></span>
@endif
    </div>
</li>
@endforeach
</ul>
@if ($sfDisplay !== null)
@include('theme::partials.sf-rail-close', ['display' => $sfDisplay])
@endif
@endcomponent
