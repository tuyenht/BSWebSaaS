@props([
    'section',
    'name',
])
@php
    // The options of one Filter section, shared by the section's own panel and the Filter sheet: native radios (one
    // choice) or checkboxes (several) bound live to the host property, each with its count. $name keeps the radio
    // groups of the two surfaces apart, so checking one never unchecks the other's copy.
    //
    // $section: array{key: string, model: string, multiple?: bool, options: list<array{value: string, label: string, count?: int|null}>}
@endphp
@foreach ($section['options'] as $option)
    <label class="flex min-h-hit cursor-pointer items-center gap-2.5 px-4 py-1.5 text-body text-text hover:bg-surface-hover has-checked:font-bold pointer-coarse:min-h-12">
        <input type="{{ ($section['multiple'] ?? false) ? 'checkbox' : 'radio' }}"
               name="{{ $name }}"
               value="{{ $option['value'] }}"
               wire:model.live="{{ $section['model'] }}"
               class="size-4.5 shrink-0 accent-accent">
        <span class="min-w-0 flex-1 [overflow-wrap:anywhere]">{{ $option['label'] }}</span>
        @if (isset($option['count']))
            <span class="shrink-0 text-small font-normal text-body-muted">{{ \Illuminate\Support\Number::format($option['count'], locale: app()->getLocale()) }}</span>
        @endif
    </label>
@endforeach
