{{--
    Frequently asked questions: native <details>/<summary>, so it opens and closes with no script
    and works from the keyboard. The first question starts open. One column (capped and centred), or two from a tablet. A question needs its text.
--}}
@php
    $heading = \App\Modules\Theme\Components\SectionProps::text($props, 'heading');
    $columns = \App\Modules\Theme\Components\SectionProps::choice($props, 'columns', \App\Modules\Theme\Components\SectionProps::FAQ_COLUMNS);
    $questions = array_values(array_filter(
        \App\Modules\Theme\Components\SectionProps::items($props, 'items'),
        static fn (array $item): bool => \App\Modules\Theme\Components\SectionProps::text($item, 'q') !== '',
    ));
@endphp
@if ($questions !== [])
@component('theme::partials.sf-band', ['bg' => \App\Modules\Theme\Components\SectionProps::choice($props, 'bg', \App\Modules\Theme\Components\SectionProps::BG), 'class' => 'sf-faq'])
@include('theme::partials.sf-section-head', ['heading' => $heading])
<div class="sf-faq__list sf-faq__list--{{ $columns }}">
@foreach ($questions as $index => $item)
<details class="sf-faq__item"{{ $index === 0 ? ' open' : '' }}>
<summary class="sf-faq__q"><span class="sf-faq__q-text">{{ $item['q'] }}</span><span class="sf-faq__chevron" aria-hidden="true">@include('theme::partials.sf-icon', ['name' => 'chevron', 'size' => 20])</span></summary>
@if (\App\Modules\Theme\Components\SectionProps::text($item, 'a') !== '')
<p class="sf-faq__a">{{ $item['a'] }}</p>
@endif
</details>
@endforeach
</div>
@endcomponent
@endif
