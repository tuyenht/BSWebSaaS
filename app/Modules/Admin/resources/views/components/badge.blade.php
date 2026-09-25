@props([
    'color' => 'primary',
    'soft' => false,
    'pill' => false,
])
@php
    // Mirrors Velzon .badge / soft badge (bg-*-subtle + text-*). line-height ~0.95, .75em.
    // White text sits on the `*-solid` fill; soft text is `*-text` (readable in both schemes);
    // warning keeps its fill and takes dark text.
    $base = 'inline-flex items-center gap-1 px-2 py-1 text-xs font-medium leading-none';
    $shape = $pill ? 'rounded-full' : 'rounded';

    $solid = [
        'primary' => 'bg-admin-primary text-white',
        'secondary' => 'bg-admin-secondary-solid text-white',
        'success' => 'bg-admin-success-solid text-white',
        'info' => 'bg-admin-info-solid text-white',
        'warning' => 'bg-admin-warning text-admin-dark',
        'danger' => 'bg-admin-danger-solid text-white',
        'dark' => 'bg-admin-dark text-white',
    ];

    $subtle = [
        'primary' => 'bg-admin-primary/10 text-accent',
        'secondary' => 'bg-admin-secondary/10 text-admin-secondary-text',
        'success' => 'bg-admin-success/10 text-admin-success-text',
        'info' => 'bg-admin-info/10 text-admin-info-text',
        'warning' => 'bg-admin-warning/10 text-admin-warning-text',
        'danger' => 'bg-admin-danger/10 text-admin-danger-text',
        'dark' => 'bg-admin-dark/10 text-admin-dark-text',
    ];

    $tone = $soft ? ($subtle[$color] ?? $subtle['primary']) : ($solid[$color] ?? $solid['primary']);
@endphp
<span {{ $attributes->merge(['class' => trim($base.' '.$shape.' '.$tone)]) }}>{{ $slot }}</span>
