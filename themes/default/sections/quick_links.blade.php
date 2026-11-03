{{--
    Quick links: tiles that each lead to a page — a glyph from the storefront's own set, a label and
    a link. A tile needs both a label and a safe link; anything else is skipped. Variants: a 2x2 grid
    (two across everywhere), one row (up to four across on a wide screen) or wrapping pills.
--}}
@php
    $heading = \App\Modules\Theme\Components\SectionProps::text($props, 'heading');
    $variant = \App\Modules\Theme\Components\SectionProps::choice($props, 'variant', \App\Modules\Theme\Components\SectionProps::QUICK_LINK_VARIANTS);
    $tiles = [];
    foreach (\App\Modules\Theme\Components\SectionProps::items($props, 'items') as $item) {
        $href = \App\Modules\Shared\Support\SafeLink::href($item['url'] ?? null);
        $label = \App\Modules\Theme\Components\SectionProps::text($item, 'label');
        if ($href !== null && $label !== '') {
            $tiles[] = ['href' => $href, 'label' => $label, 'icon' => \App\Modules\Theme\Components\SectionProps::choice($item, 'icon', \App\Modules\Theme\Components\SectionProps::QUICK_LINK_ICONS)];
        }
    }
@endphp
@if ($tiles !== [])
@component('theme::partials.sf-band', ['bg' => \App\Modules\Theme\Components\SectionProps::choice($props, 'bg', \App\Modules\Theme\Components\SectionProps::BG), 'class' => 'sf-qlinks'])
@include('theme::partials.sf-section-head', ['heading' => $heading])
<ul class="sf-qlinks__list sf-qlinks__list--{{ $variant }} sf-qlinks__list--n{{ min(count($tiles), 4) }}">
@foreach ($tiles as $tile)
<li><a class="sf-qlinks__tile" href="{{ $tile['href'] }}"><span class="sf-qlinks__icon">@include('theme::partials.sf-icon', ['name' => $tile['icon'], 'size' => $variant === 'pill' ? 20 : 24])</span><span class="sf-qlinks__label">{{ $tile['label'] }}</span></a></li>
@endforeach
</ul>
@endcomponent
@endif
