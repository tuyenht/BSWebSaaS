@props([
    'group' => null,
    'summary' => null,
    'chips' => [],
    'clear' => null,
])
@php
    // The active-filter status line of a record list (canvas .lf-flm, K69): "Tạm ngưng · 1 website
    // · Bỏ lọc", then one chip per other condition, each with its own ✕. Shown only when a group
    // other than "all" is chosen or a condition is on; it is a live region, so a screen reader hears
    // the new state after each change; only the group and summary text are
    // inside it, never the buttons. Long text wraps, never clips.
    //
    // $group: the chosen group's label, or null for "all". $summary: e.g. "1 website".
    // $chips: list<array{label: string, remove: string}> — remove is the host's Livewire call.
    // $clear: the host's public method that resets the group and every condition.
    // remove and $clear are printed into wire:click as they are: build them in PHP from fixed method
    // names and Js::from() arguments, never from user input.
@endphp
@if ($group !== null || $chips !== [])
    <p {{ $attributes->merge(['class' => 'm-0 flex min-h-control flex-wrap items-center gap-x-1.5 gap-y-1 px-3 pb-1 text-body text-text [overflow-wrap:anywhere] pointer-coarse:min-h-hit']) }}>
        @if ($group !== null || $summary !== null)
            <span role="status" class="inline-flex min-w-0 flex-wrap items-center gap-x-1.5 gap-y-1">
                @if ($group !== null)
                    <strong class="min-w-0 font-semibold text-text-strong">{{ $group }}</strong>
                @endif
                @if ($summary !== null)
                    @if ($group !== null)<span aria-hidden="true">·</span>@endif
                    <span class="min-w-0">{{ $summary }}</span>
                @endif
            </span>
        @endif
        @if ($clear)
            @if ($group !== null || $summary !== null)<span aria-hidden="true">·</span>@endif
            <button type="button" wire:click="{{ $clear }}"
                    class="inline-flex min-h-control items-center px-1 text-primary-text underline pointer-coarse:min-h-hit">{{ __('admin.list.clear_filters') }}</button>
        @endif
        @foreach ($chips as $chip)
            <span class="inline-flex min-h-8 min-w-0 max-w-full items-center gap-1 rounded-full border border-border-input bg-surface ps-2.5 text-small">
                <span class="min-w-0">{{ $chip['label'] }}</span>
                <button type="button" wire:click="{{ $chip['remove'] }}"
                        aria-label="{{ __('admin.list.remove_filter', ['name' => $chip['label']]) }}" title="{{ __('admin.list.remove_filter', ['name' => $chip['label']]) }}"
                        class="hit-area inline-flex size-8 shrink-0 items-center justify-center rounded-full text-text-muted hover:text-text-strong">
                    <x-bs-admin::icon name="ri-close" class="size-3.5" />
                </button>
            </span>
        @endforeach
    </p>
@endif
