@props([
    'color' => 'primary',
    'variant' => 'solid',
    'size' => 'md',
    'type' => 'button',
    'href' => null,
    'icon' => false,
])
@php
    // Mirrors Velzon .btn / .btn-soft-* / .btn-outline-* / .btn-ghost-*.
    // Deviation: Velzon solid hover uses the per-color *-text-emphasis shade; we use
    // hover:brightness-95 (uniform darken) to avoid per-color shade tokens — same effect.
    // The brand navy as TEXT, BORDER or RING is `accent` (the dark scheme lightens it — navy on
    // the dark surface reads 1.8:1); as a FILL under white text it stays `admin-primary`.
    // Semantic colors the same way: white text sits on the `*-solid` fill, the hue as text or
    // border is `*-text`; warning keeps its fill and takes dark text (white on it reads 1.8:1).
    // Keyboard focus is the shared focus ring (app.css :focus-visible), not a per-color ring.
    // Heights come from the control-size tokens (app.css, docs/notes/design-tokens.md §2.5), which
    // grow with the user's text size: md is a main button (40 → 44), sm a button on a list row or
    // inline (36 → 44). The touch area stays 44 through hit-area. A long label wraps instead of
    // running out of the button; `icon` makes a square button for a lone icon with an aria-label.
    $base = 'hit-area inline-flex items-center justify-center gap-2 rounded font-medium leading-tight text-center transition-colors disabled:opacity-60 disabled:pointer-events-none';

    $sizes = $icon ? [
        'sm' => 'size-control-row shrink-0 text-caption',
        'md' => 'size-control shrink-0 text-body',
        'lg' => 'size-hit shrink-0 text-lead',
    ] : [
        'sm' => 'min-h-control-row px-2.5 py-1 text-caption',
        'md' => 'min-h-control px-3.5 py-1.5 text-body',
        'lg' => 'min-h-hit px-5 py-2 text-lead',
    ];

    $solid = [
        'primary' => 'bg-admin-primary text-white shadow-sm hover:brightness-95',
        'secondary' => 'bg-admin-secondary-solid text-white shadow-sm hover:brightness-95',
        'success' => 'bg-admin-success-solid text-white shadow-sm hover:brightness-95',
        'info' => 'bg-admin-info-solid text-white shadow-sm hover:brightness-95',
        'warning' => 'bg-admin-warning text-admin-dark shadow-sm hover:brightness-95',
        'danger' => 'bg-admin-danger-solid text-white shadow-sm hover:brightness-95',
        'dark' => 'bg-admin-dark text-white shadow-sm hover:brightness-95',
        'light' => 'bg-admin-light text-admin-dark shadow-sm hover:brightness-95',
    ];

    $soft = [
        'primary' => 'bg-admin-primary/10 text-accent hover:bg-admin-primary hover:text-white',
        'secondary' => 'bg-admin-secondary/10 text-admin-secondary-text hover:bg-admin-secondary-solid hover:text-white',
        'success' => 'bg-admin-success/10 text-admin-success-text hover:bg-admin-success-solid hover:text-white',
        'info' => 'bg-admin-info/10 text-admin-info-text hover:bg-admin-info-solid hover:text-white',
        'warning' => 'bg-admin-warning/10 text-admin-warning-text hover:bg-admin-warning hover:text-admin-dark',
        'danger' => 'bg-admin-danger/10 text-admin-danger-text hover:bg-admin-danger-solid hover:text-white',
    ];

    $outline = [
        'primary' => 'border border-accent text-accent hover:bg-admin-primary hover:text-white',
        'secondary' => 'border border-admin-secondary-text text-admin-secondary-text hover:bg-admin-secondary-solid hover:text-white',
        'success' => 'border border-admin-success-text text-admin-success-text hover:bg-admin-success-solid hover:text-white',
        'danger' => 'border border-admin-danger-text text-admin-danger-text hover:bg-admin-danger-solid hover:text-white',
    ];

    $ghost = [
        'primary' => 'text-accent hover:bg-admin-primary/10',
        'danger' => 'text-admin-danger-text hover:bg-admin-danger/10',
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
