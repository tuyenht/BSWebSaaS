{{--
    Pricing: 2–4 plans side by side (stacked on a phone). The price is whole dong printed the
    Vietnamese way; empty (or 0) says "Liên hệ". Features are one per line. The button needs a label
    and a safe link. A plan needs a name; fewer than two plans render nothing.
--}}
@php
    $heading = \App\Modules\Theme\Components\SectionProps::text($props, 'heading');
    $level = $heading !== '' ? 'h3' : 'h2';
    $plans = array_slice(array_values(array_filter(
        \App\Modules\Theme\Components\SectionProps::items($props, 'plans', 4),
        static fn (array $item): bool => \App\Modules\Theme\Components\SectionProps::text($item, 'name') !== '',
    )), 0, 4);
@endphp
@if (count($plans) >= 2)
@component('theme::partials.sf-band', ['bg' => \App\Modules\Theme\Components\SectionProps::choice($props, 'bg', \App\Modules\Theme\Components\SectionProps::BG), 'class' => 'sf-pricing'])
@include('theme::partials.sf-section-head', ['heading' => $heading])
<ul class="sf-plans sf-plans--{{ count($plans) }}">
@foreach ($plans as $item)
@php
    $price = \App\Modules\Theme\Components\SectionProps::price($item['price'] ?? null);
    $unit = \App\Modules\Theme\Components\SectionProps::text($item, 'unit');
    $features = \App\Modules\Theme\Components\SectionProps::lines($item['features'] ?? null);
    $href = \App\Modules\Shared\Support\SafeLink::href($item['cta_url'] ?? null);
    $label = \App\Modules\Theme\Components\SectionProps::text($item, 'cta_label');
@endphp
<li class="sf-plan">
<{{ $level }} class="sf-plan__name">{{ $item['name'] }}</{{ $level }}>
<p class="sf-plan__price"><span class="sf-plan__amount">{{ $price ?? __('storefront.pricing_contact') }}</span>@if ($price !== null && $unit !== '') <span class="sf-plan__unit">{{ $unit }}</span>@endif</p>
@if ($features !== [])
<ul class="sf-plan__features">
@foreach ($features as $feature)
<li>{{ $feature }}</li>
@endforeach
</ul>
@endif
@if ($href !== null && $label !== '')
<a class="sf-view-all__link sf-plan__cta" href="{{ $href }}">{{ $label }}</a>
@endif
</li>
@endforeach
</ul>
@endcomponent
@endif
