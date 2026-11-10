{{--
    Features: a band (`bg`: none, surface, tint, dark) with the shared section head and a grid sized by how
    many items there are: two fill two columns, three fill three, a multiple of four fills rows of four on
    a wide screen (two a row below it), so none of these counts leaves a card alone; any other count takes
    three a row.

    - top-rule (opt-in): cards with an accent rule; on a phone each is a compact row (glyph left, text
      right) one a row, from a tablet up a centered card with the rule on top.
    - default: plain cards; an item with a glyph shows it in a dark tile beside its text. A multiple of
      four puts two cards a row on a phone too, and there the tile sits above the text.

    On a dark band both looks drop the card: the glyph sits beside a light title. `icon` is a key of the
    icon partial; an unknown key draws nothing.
--}}
@php
    $sfTopRule = \App\Modules\Theme\Components\SectionProps::choice($props, 'variant', \App\Modules\Theme\Components\SectionProps::FEATURES_VARIANTS) === 'top-rule';
    $sfItems = array_map(static fn (mixed $sfItem): array => (array) $sfItem, array_values((array) ($props['items'] ?? [])));
    $sfCount = count($sfItems);
    $sfGrid = match (true) {
        $sfCount <= 2 => 'sf-grid--2',
        $sfCount === 3 => 'sf-grid--3',
        $sfCount % 4 === 0 => $sfTopRule ? 'sf-grid--4' : 'sf-grid--4p',
        default => 'sf-grid--3s',
    };
@endphp
@component('theme::partials.sf-band', ['bg' => \App\Modules\Theme\Components\SectionProps::choice($props, 'bg', \App\Modules\Theme\Components\SectionProps::BG), 'class' => $sfTopRule ? 'sf-features' : 'sf-features sf-features--cards'])
@include('theme::partials.sf-section-head', [
    'heading' => \App\Modules\Theme\Components\SectionProps::text($props, 'heading'),
    'description' => \App\Modules\Theme\Components\SectionProps::text($props, 'description'),
])
<ul class="sf-grid {{ $sfGrid }}">
@foreach ($sfItems as $item)
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
