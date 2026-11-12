{{--
    Branches: the site's branches and factories (Settings, one source with the footer and the contact
    map) as cards — a photo with the area on it, the name, the role and the address. A branch without a
    photo draws its card without that part. The name is a heading one level under the section's (or the
    section's own level when it has no heading). Three across on a wide screen, a swipe row on a phone
    (the shared display group); nothing prints when the site has no branch.
--}}
@php
    $sfHeading = \App\Modules\Theme\Components\SectionProps::text($props, 'heading');
    $sfLevel = $sfHeading !== '' ? 'h3' : 'h2';
    $sfCards = array_values($branches ?? []);
    $sfDisplay = \App\Modules\Theme\Components\ListDisplay::plan('branches', $props, count($sfCards), PHP_INT_MAX);
    $sfCards = $sfDisplay !== null ? array_slice($sfCards, 0, $sfDisplay['shown']) : $sfCards;
@endphp
@if ($sfCards !== [])
@component('theme::partials.sf-band', ['bg' => \App\Modules\Theme\Components\SectionProps::choice($props, 'bg', \App\Modules\Theme\Components\SectionProps::BG), 'class' => 'sf-branches'])
@include('theme::partials.sf-section-head', ['heading' => $sfHeading, 'description' => \App\Modules\Theme\Components\SectionProps::text($props, 'description')])
@if ($sfDisplay !== null)
@include('theme::partials.sf-rail-open', ['display' => $sfDisplay, 'label' => $sfHeading !== '' ? $sfHeading : __('storefront.list_rail')])
@endif
<ul class="{{ $sfDisplay['class'] ?? 'sf-branches__list' }}"@if ($sfDisplay['scrolls'] ?? false) tabindex="0"@endif>
@foreach ($sfCards as $sfCard)
<li>
<article class="sf-branch">
@if ($sfCard['image'] !== null)
<div class="sf-branch__photo">
<img class="sf-branch__img" src="{{ $sfCard['image'] }}"@if ($sfCard['srcset'] !== null) srcset="{{ $sfCard['srcset'] }}" sizes="(min-width: 1024px) 24rem, 85vw"@endif alt="{{ $sfCard['alt'] }}" width="{{ $sfCard['width'] }}" height="{{ $sfCard['height'] }}" loading="lazy" decoding="async" fetchpriority="low">
@if ($sfCard['area'] !== null)
<span class="sf-branch__chip">{{ $sfCard['area'] }}</span>
@endif
</div>
@endif
<div class="sf-branch__body">
<{{ $sfLevel }} class="sf-branch__name">{{ $sfCard['full_name'] }}</{{ $sfLevel }}>
@if ($sfCard['role'] !== null)
<p class="sf-branch__role">{{ $sfCard['role'] }}</p>
@endif
@if ($sfCard['image'] === null && $sfCard['area'] !== null)
<p class="sf-branch__area">{{ $sfCard['area'] }}</p>
@endif
@if ($sfCard['address'] !== null)
<p class="sf-branch__addr">{{ $sfCard['address'] }}</p>
@endif
</div>
</article>
</li>
@endforeach
</ul>
@if ($sfDisplay !== null)
@include('theme::partials.sf-rail-close', ['display' => $sfDisplay])
@endif
@endcomponent
@endif
