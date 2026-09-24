@props([
    'color' => 'primary',
    'variant' => 'solid',
    'size' => 'md',
    'type' => 'button',
    'href' => null,
])
@php
    // Mirrors Velzon .btn / .btn-soft-* / .btn-outline-* / .btn-ghost-*.
    // Deviation: Velzon solid hover uses the per-color *-text-emphasis shade; we use
    // hover:brightness-95 (uniform darken) to avoid per-color shade tokens — same effect.
    // The brand navy as TEXT, BORDER or RING is `accent` (the dark scheme lightens it — navy on
    // the dark surface reads 1.8:1); as a FILL under white text it stays `admin-primary`.
    $base = 'inline-flex items-center justify-center gap-2 rounded font-medium leading-none whitespace-nowrap transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-1 disabled:opacity-60 disabled:pointer-events-none';

    $sizes = [
        'sm' => 'px-2.5 py-1.5 text-xs',
        'md' => 'px-3.5 py-2 text-sm',
        'lg' => 'px-5 py-2.5 text-base',
    ];

    $solid = [
        'primary' => 'bg-admin-primary text-white shadow-sm hover:brightness-95 focus-visible:ring-accent',
        'secondary' => 'bg-admin-secondary text-white shadow-sm hover:brightness-95 focus-visible:ring-admin-secondary',
        'success' => 'bg-admin-success text-white shadow-sm hover:brightness-95 focus-visible:ring-admin-success',
        'info' => 'bg-admin-info text-white shadow-sm hover:brightness-95 focus-visible:ring-admin-info',
        'warning' => 'bg-admin-warning text-white shadow-sm hover:brightness-95 focus-visible:ring-admin-warning',
        'danger' => 'bg-admin-danger text-white shadow-sm hover:brightness-95 focus-visible:ring-admin-danger',
        'dark' => 'bg-admin-dark text-white shadow-sm hover:brightness-95 focus-visible:ring-admin-dark',
        'light' => 'bg-admin-light text-admin-dark shadow-sm hover:brightness-95 focus-visible:ring-gray-300',
    ];

    $soft = [
        'primary' => 'bg-admin-primary/10 text-accent hover:bg-admin-primary hover:text-white focus-visible:ring-accent',
        'secondary' => 'bg-admin-secondary/10 text-admin-secondary hover:bg-admin-secondary hover:text-white focus-visible:ring-admin-secondary',
        'success' => 'bg-admin-success/10 text-admin-success hover:bg-admin-success hover:text-white focus-visible:ring-admin-success',
        'info' => 'bg-admin-info/10 text-admin-info hover:bg-admin-info hover:text-white focus-visible:ring-admin-info',
        'warning' => 'bg-admin-warning/10 text-admin-warning hover:bg-admin-warning hover:text-white focus-visible:ring-admin-warning',
        'danger' => 'bg-admin-danger/10 text-admin-danger hover:bg-admin-danger hover:text-white focus-visible:ring-admin-danger',
    ];

    $outline = [
        'primary' => 'border border-accent text-accent hover:bg-admin-primary hover:text-white focus-visible:ring-accent',
        'secondary' => 'border border-admin-secondary text-admin-secondary hover:bg-admin-secondary hover:text-white focus-visible:ring-admin-secondary',
        'success' => 'border border-admin-success text-admin-success hover:bg-admin-success hover:text-white focus-visible:ring-admin-success',
        'danger' => 'border border-admin-danger text-admin-danger hover:bg-admin-danger hover:text-white focus-visible:ring-admin-danger',
    ];

    $ghost = [
        'primary' => 'text-accent hover:bg-admin-primary/10 focus-visible:ring-accent',
        'danger' => 'text-admin-danger hover:bg-admin-danger/10 focus-visible:ring-admin-danger',
    ];

    $tone = match ($variant) {
        'soft' => $soft[$color] ?? $soft['primary'],
        'outline' => $outline[$color] ?? $outline['primary'],
        'ghost' => $ghost[$color] ?? $ghost['primary'],
        default => $solid[$color] ?? $solid['primary'],
    };

    $classes = trim($base.' '.($sizes[$size] ?? $sizes['md']).' '.$tone);
@endphp
@if ($href)
    <a href="{{ $href }}" wire:navigate {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>{{ $slot }}</button>
@endif
