@props([
    'color' => 'info',
    'icon' => null,
    'dismissible' => false,
])
@php
    // Mirrors Velzon soft .alert (bg-*-subtle + text-*). Reuses the same subtle token map as
    // the badge so colours stay consistent. Dismiss is a per-instance x-data toggle (Alpine
    // is Livewire-bundled) — transient, so a wire:navigate morph resetting it (re-showing) is
    // acceptable for a page-level alert.
    $subtle = [
        'primary' => 'bg-admin-primary/10 text-accent',
        'success' => 'bg-admin-success/10 text-admin-success-text',
        'info' => 'bg-admin-info/10 text-admin-info-text',
        'warning' => 'bg-admin-warning/10 text-admin-warning-text',
        'danger' => 'bg-admin-danger/10 text-admin-danger-text',
    ];
    $tone = $subtle[$color] ?? $subtle['info'];
@endphp
<div role="alert"
     @if ($dismissible) x-data="{ show: true }" x-show="show" @endif
     {{ $attributes->merge(['class' => 'flex items-start gap-2.5 rounded px-3.5 py-3 text-sm '.$tone]) }}>
    @if ($icon)
        <x-admin::icon :name="$icon" class="mt-0.5 size-5 shrink-0" />
    @endif
    <div class="flex-1">{{ $slot }}</div>
    @if ($dismissible)
        <button type="button" @click="show = false" aria-label="{{ __('admin.dismiss') }}"
                class="-m-1 shrink-0 rounded p-1 transition-colors hover:bg-current/10">
            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" /></svg>
        </button>
    @endif
</div>
