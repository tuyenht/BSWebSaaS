{{--
    Team: a card per member — photo, name and role. A member needs a name. The name is a heading one
    level under the section's (or the section's own level when it has no heading).
--}}
@php
    $heading = \App\Modules\Theme\Components\SectionProps::text($props, 'heading');
    $level = $heading !== '' ? 'h3' : 'h2';
    $members = array_values(array_filter(
        \App\Modules\Theme\Components\SectionProps::items($props, 'members'),
        static fn (array $item): bool => \App\Modules\Theme\Components\SectionProps::text($item, 'name') !== '',
    ));
@endphp
@if ($members !== [])
@component('theme::partials.sf-band', ['bg' => \App\Modules\Theme\Components\SectionProps::choice($props, 'bg', \App\Modules\Theme\Components\SectionProps::BG), 'class' => 'sf-team'])
@include('theme::partials.sf-section-head', ['heading' => $heading])
<ul class="sf-team__list">
@foreach ($members as $item)
@php($role = \App\Modules\Theme\Components\SectionProps::text($item, 'role'))
<li class="sf-member">
@include('theme::partials.sf-img', ['src' => $item['photo'] ?? null, 'srcset' => ($srcsets ?? [])[$item['photo'] ?? ''] ?? null, 'sizes' => '(min-width: 64rem) 20rem, (min-width: 40rem) 33vw, 100vw', 'w' => 400, 'h' => 400, 'class' => 'sf-member__photo'])
<{{ $level }} class="sf-member__name">{{ $item['name'] }}</{{ $level }}>
@if ($role !== '')
<p class="sf-member__role">{{ $role }}</p>
@endif
</li>
@endforeach
</ul>
@endcomponent
@endif
