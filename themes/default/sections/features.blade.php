{{--
    Features: a band (`bg`: none, surface, tint, dark) with the shared section head and a grid sized by how
    many items there are: two fill two columns, three fill three, a multiple of four fills rows of four on
    a wide screen (two a row below it), so none of these counts leaves a card alone; any other count takes
    three a row.

    - top-rule (opt-in): cards with an accent rule; on a phone each is a compact row (glyph left, text
      right) one a row, from a tablet up a centered card with the rule on top.
    - icon-head (opt-in): a white card per item, the glyph in a round brand tile on the same line as a large
      title, the text under it, left aligned; two across on a wide screen.
    - default: plain cards; an item with a glyph shows it in a dark tile beside its text. A multiple of
      four puts two cards a row on a phone too, and there the tile sits above the text.

    On a dark band both looks drop the card: the glyph sits beside a light title. `icon` is a key of the
    icon partial; an unknown key draws nothing.
--}}
@php
    $sfVariant = \App\Modules\Theme\Components\SectionProps::choice($props, 'variant', \App\Modules\Theme\Components\SectionProps::FEATURES_VARIANTS);
    $sfTopRule = $sfVariant === 'top-rule';
    $sfIconHead = $sfVariant === 'icon-head';
    $sfItems = array_map(static fn (mixed $sfItem): array => (array) $sfItem, array_values((array) ($props['items'] ?? [])));
    $sfCount = count($sfItems);
    $sfGrid = match (true) {
        $sfCount <= 2 => 'sf-grid--2',
        $sfCount === 3 => 'sf-grid--3',
        $sfCount % 4 === 0 => $sfTopRule ? 'sf-grid--4' : 'sf-grid--4p',
        default => 'sf-grid--3s',
    };
@endphp
@component('theme::partials.sf-band', ['bg' => \App\Modules\Theme\Components\SectionProps::choice($props, 'bg', \App\Modules\Theme\Components\SectionProps::BG), 'class' => $sfIconHead ? 'sf-features sf-features--head' : ($sfTopRule ? 'sf-features' : 'sf-features sf-features--cards')])
@include('theme::partials.sf-section-head', [
    'heading' => \App\Modules\Theme\Components\SectionProps::text($props, 'heading'),
    'description' => \App\Modules\Theme\Components\SectionProps::text($props, 'description'),
])
<ul class="sf-grid {{ $sfGrid }}">
@foreach ($sfItems as $item)
@if ($sfIconHead)
<li class="sf-hcard">
    <div class="sf-hcard__head">
@if (is_string($item['icon'] ?? null) && $item['icon'] !== '')
        @include('theme::partials.icon', ['name' => $item['icon'], 'stroke' => 2, 'wrap' => 'sf-hcard__icon'])
@endif
        <h3 class="sf-hcard__title">{{ $item['title'] ?? '' }}</h3>
    </div>
@if (! empty($item['text']))
    <p class="sf-hcard__text">{{ $item['text'] }}</p>
@endif
</li>
@continue
@endif
<li class="{{ $sfTopRule ? 'sf-feature' : 'sf-fcard' }}">
@if (is_string($item['icon'] ?? null) && $item['icon'] !== '')
    @include('theme::partials.icon', ['name' => $item['icon'], 'stroke' => 1.5, 'wrap' => $sfTopRule ? 'sf-feature__icon' : 'sf-fcard__icon'])
@endif
    <div class="{{ $sfTopRule ? 'sf-feature__body' : 'sf-fcard__body' }}">
        <h3 class="{{ $sfTopRule ? 'sf-feature__title' : 'sf-fcard__title' }}">{{ $item['title'] ?? '' }}</h3>
@if (! empty($item['text']))
        <p class="{{ $sfTopRule ? 'sf-feature__text' : 'sf-fcard__text' }}">{{ $item['text'] }}</p>
@endif
    </div>
</li>
@endforeach
</ul>
@endcomponent
