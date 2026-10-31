@props([
    'section',
    'name',
])
@php
    // The options of one Filter section, shared by the section's own panel and the Filter sheet: native radios (one
    // choice) or checkboxes (several) bound live to the host property, each with its count. $name keeps the radio
    // groups of the two surfaces apart, so checking one never unchecks the other's copy.
    //
    // $section: array{key: string, model: string, multiple?: bool, options: list<array{value: string, label: string, count?: int|null, divider?: bool}>,
    //           search?: array{model: string, label: string, placeholder?: string}, note?: string,
    //           range?: array{from: string, to: string, from_label: string, to_label: string}}
    // search: a field above the options that narrows them (a long list such as websites); its host property is
    //         bound with a short debounce and the host returns the matching options.
    // range: two date fields below the options ("Từ ngày" · "Đến ngày"), each bound to its own host property.
    // note: one plain sentence above the options saying what the section goes by ("Theo ngày đăng — …").
    // divider: a thin rule above the option, set off from the ones before it (Thùng rác after the live statuses); the
    //          rule sits inside the option's own row, so no gap opens between two tap targets.
@endphp
@if ($section['note'] ?? null)
    <p class="m-0 px-4 pb-1 text-small text-text-muted [overflow-wrap:anywhere]">{{ $section['note'] }}</p>
@endif
@if ($section['search'] ?? null)
    <div class="px-4 pb-1 pt-1">
        <x-bs-admin::search :label="$section['search']['label']" :placeholder="$section['search']['placeholder'] ?? null"
                            wire:model.live.debounce.300ms="{{ $section['search']['model'] }}" />
    </div>
@endif
@foreach ($section['options'] as $option)
    <label @class([
        'flex min-h-hit cursor-pointer items-center gap-2.5 px-4 py-1.5 text-body text-text hover:bg-surface-hover has-checked:font-bold pointer-coarse:min-h-12',
        'border-t border-border' => $option['divider'] ?? false,
    ])>
        <input type="{{ ($section['multiple'] ?? false) ? 'checkbox' : 'radio' }}"
               name="{{ $name }}"
               value="{{ $option['value'] }}"
               wire:model.live="{{ $section['model'] }}"
               class="size-4.5 shrink-0 accent-primary-text">
        <span class="min-w-0 flex-1 [overflow-wrap:anywhere]">{{ $option['label'] }}</span>
        @if (isset($option['count']))
            <span class="shrink-0 text-small font-normal text-text-muted">{{ \Illuminate\Support\Number::format($option['count'], locale: app()->getLocale()) }}</span>
        @endif
    </label>
@endforeach
@if ($section['range'] ?? null)
    <div class="grid grid-cols-2 gap-2 px-4 pb-2 pt-1">
        @foreach (['from', 'to'] as $end)
            <label class="flex min-w-0 flex-col gap-1 text-small text-text-muted">
                {{ $section['range'][$end.'_label'] }}
                <input type="date" wire:model.live="{{ $section['range'][$end] }}"
                       class="min-h-control w-full min-w-0 rounded border border-border-input bg-surface px-2 text-body text-text pointer-coarse:min-h-hit">
            </label>
        @endforeach
    </div>
@endif
