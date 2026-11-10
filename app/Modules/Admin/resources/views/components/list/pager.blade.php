@props([
    'older' => null,
    'newer' => null,
    'sizes' => [10, 20, 50],
    'model' => 'per',
    'label',
    'perLabel',
    'prev',
    'next',
])
@php
    // The footer of a list read page by page from a cursor (newest first): "Dòng/trang" and
    // [‹ Trước] [Sau ›]. $older / $newer are the ulids the host's older() / newer() take, null when
    // there is no page that way (the button is then disabled). $model is the host's page-size
    // property. The row wraps; the buttons use the shared small control size.
@endphp
<div {{ $attributes->merge(['class' => 'flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-t border-border px-4 py-2.5']) }}>
    <label class="flex items-center gap-2 text-small text-text">
        {{ $perLabel }}
        <select wire:model.live="{{ $model }}" class="field rounded border border-border-input bg-surface px-2 text-text-strong">
            @foreach ($sizes as $n)
                <option value="{{ $n }}">{{ $n }}</option>
            @endforeach
        </select>
    </label>
    <nav class="flex gap-2" aria-label="{{ $label }}">
        <x-bs-admin::button size="sm" variant="outline" color="secondary" :disabled="$newer === null" wire:click="newer({{ \Illuminate\Support\Js::from((string) $newer) }})">
            <x-bs-admin::icon name="chevron-left" class="size-4" />{{ $prev }}
        </x-bs-admin::button>
        <x-bs-admin::button size="sm" variant="outline" color="secondary" :disabled="$older === null" wire:click="older({{ \Illuminate\Support\Js::from((string) $older) }})">
            {{ $next }}<x-bs-admin::icon name="chevron-right" class="size-4" />
        </x-bs-admin::button>
    </nav>
</div>
