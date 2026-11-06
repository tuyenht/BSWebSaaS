{{--
    Team: a centred column per member — a round photo (the first letter of the name on a tinted disc
    when there is none), name and role; two across on a phone, three or four (variant) on a wide
    screen. A member needs a name. The name is a heading one level under the section's (or the
    section's own level when it has no heading).
--}}
@php
    $heading = \App\Modules\Theme\Components\SectionProps::text($props, 'heading');
    $level = $heading !== '' ? 'h3' : 'h2';
    $variant = \App\Modules\Theme\Components\SectionProps::choice($props, 'variant', \App\Modules\Theme\Components\SectionProps::TEAM_VARIANTS);
    $members = array_values(array_filter(
        \App\Modules\Theme\Components\SectionProps::items($props, 'members'),
        static fn (array $item): bool => \App\Modules\Theme\Components\SectionProps::text($item, 'name') !== '',
    ));
@endphp
@php
    $sfDisplay = \App\Modules\Theme\Components\ListDisplay::plan('team', $props, count($members), PHP_INT_MAX);
    $members = $sfDisplay !== null ? array_slice($members, 0, $sfDisplay['shown']) : $members;
@endphp
@if ($members !== [])
@component('theme::partials.sf-band', ['bg' => \App\Modules\Theme\Components\SectionProps::choice($props, 'bg', \App\Modules\Theme\Components\SectionProps::BG), 'class' => 'sf-team'])
@include('theme::partials.sf-section-head', ['heading' => $heading])
@if ($sfDisplay !== null)
@include('theme::partials.sf-rail-open', ['display' => $sfDisplay, 'label' => $heading])
@endif
<ul class="{{ $sfDisplay['class'] ?? 'sf-team__list sf-team__list--'.$variant.' sf-team__list--n'.min(count($members), $variant === 'grid-4' ? 4 : 3) }}">
@foreach ($members as $item)
@php($role = \App\Modules\Theme\Components\SectionProps::text($item, 'role'))
<li class="sf-member">
@if (\App\Modules\Shared\Support\SafeLink::href($item['photo'] ?? null) !== null)
@include('theme::partials.sf-img', ['src' => $item['photo'], 'srcset' => ($srcsets ?? [])[$item['photo']] ?? null, 'sizes' => '84px', 'w' => 168, 'h' => 168, 'class' => 'sf-member__photo'])
@else
<span class="sf-member__photo sf-member__photo--initial" aria-hidden="true">{{ \App\Modules\Theme\Components\SectionProps::initial($item['name']) }}</span>
@endif
<{{ $level }} class="sf-member__name">{{ $item['name'] }}</{{ $level }}>
@if ($role !== '')
<p class="sf-member__role">{{ $role }}</p>
@endif
</li>
@endforeach
</ul>
@if ($sfDisplay !== null)
@include('theme::partials.sf-rail-close', ['display' => $sfDisplay])
@endif
@endcomponent
@endif
