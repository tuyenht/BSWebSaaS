@props([
    'tone' => 'wait',
    'icon' => 'ri-loader-4',
    'spin' => false,
])
@php
    // A status block for work done off the page (design: the shared .jb, admin-components.css):
    // a round icon tinted by the tone, the words in the middle read out politely when they change
    // (role="status"), and the buttons on the right — under the words once the frame is 640px or
    // narrower (40rem at the default text size). The tone always comes with the icon and the words (the slot names the state), never
    // colour alone; `spin` turns the icon while work runs (not when motion is reduced).
    // Tones: wait (running) and nochk (not checked) are neutral, done green, info blue, warn amber.
    $ring = [
        'done' => 'bg-[color-mix(in_srgb,var(--color-admin-success)_12%,var(--color-surface))] text-admin-success-text',
        'info' => 'bg-[color-mix(in_srgb,var(--color-admin-info)_12%,var(--color-surface))] text-admin-info-text',
        'warn' => 'bg-[color-mix(in_srgb,var(--color-admin-warning)_18%,var(--color-surface))] text-admin-warning-text',
    ][$tone] ?? 'bg-surface-hover text-text';
@endphp
<div {{ $attributes->class('relative grid grid-cols-[2.25rem_minmax(0,1fr)] items-start gap-x-3 gap-y-2.5 overflow-hidden rounded-md border border-border px-3.5 pt-3.5 pb-4 @min-[40.0625rem]:grid-cols-[2.25rem_minmax(0,1fr)_auto] @min-[40.0625rem]:items-center') }}>
    <span class="flex size-9 items-center justify-center rounded-full {{ $ring }}" data-tone="{{ $tone }}">
        <x-bs-admin::icon :name="$icon" :class="'size-[calc(1.125rem+var(--user-text-boost))]'.($spin ? ' animate-spin motion-reduce:animate-none' : '')" />
    </span>
    <div class="min-w-0" role="status" aria-live="polite">{{ $slot }}</div>
    @isset($actions)
        <div class="col-start-2 flex flex-wrap gap-2 @min-[40.0625rem]:col-start-auto">{{ $actions }}</div>
    @endisset
</div>
