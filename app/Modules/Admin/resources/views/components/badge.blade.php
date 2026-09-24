@props([
    'color' => 'primary',
    'soft' => false,
    'pill' => false,
])
@php
    // Mirrors Velzon .badge / soft badge (bg-*-subtle + text-*). line-height ~0.95, .75em.
    $base = 'inline-flex items-center gap-1 px-2 py-1 text-xs font-medium leading-none';
    $shape = $pill ? 'rounded-full' : 'rounded';

    $solid = [
        'primary' => 'bg-admin-primary text-white',
        'secondary' => 'bg-admin-secondary text-white',
        'success' => 'bg-admin-success text-white',
        'info' => 'bg-admin-info text-white',
        'warning' => 'bg-admin-warning text-white',
        'danger' => 'bg-admin-danger text-white',
        'dark' => 'bg-admin-dark text-white',
    ];

    $subtle = [
        'primary' => 'bg-admin-primary/10 text-accent',
        'secondary' => 'bg-admin-secondary/10 text-admin-secondary',
        'success' => 'bg-admin-success/10 text-admin-success',
        'info' => 'bg-admin-info/10 text-admin-info',
        'warning' => 'bg-admin-warning/10 text-admin-warning',
        'danger' => 'bg-admin-danger/10 text-admin-danger',
        'dark' => 'bg-admin-dark/10 text-admin-dark',
    ];

    $tone = $soft ? ($subtle[$color] ?? $subtle['primary']) : ($solid[$color] ?? $solid['primary']);
@endphp
<span {{ $attributes->merge(['class' => trim($base.' '.$shape.' '.$tone)]) }}>{{ $slot }}</span>
