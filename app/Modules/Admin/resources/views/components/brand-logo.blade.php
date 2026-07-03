@props(['context' => 'sidebar'])
@php
    // Real logo when configured (ADR-12); falls back to an initials chip so a fresh
    // white-label install (no logo files, no env override) never shows a broken <img>.
    $logo = config('admin.brand.logo');
    $hasLogo = filled($logo['dark'] ?? null) || filled($logo['light'] ?? null);
    $brandName = config('admin.brand.name');
    $brandInitials = collect(explode(' ', (string) $brandName))
        ->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))
        ->take(2)
        ->implode('');
@endphp
@if ($hasLogo)
    @if ($context === 'topbar')
        {{-- Platform topbar: light bg → dark (blue) logo, single image. --}}
        <img src="{{ asset($logo['dark']) }}" alt="{{ $brandName }}" class="brand-logo brand-logo--static h-7 w-auto">
    @else
        {{-- Sidebar: CSS picks the variant by [data-sidebar] / [data-sidebar-size] (see admin-chrome.css). --}}
        <img src="{{ asset($logo['light']) }}" alt="{{ $brandName }}" class="brand-logo brand-logo--light h-12 w-auto">
        <img src="{{ asset($logo['dark']) }}" alt="{{ $brandName }}" class="brand-logo brand-logo--dark h-12 w-auto">
        <img src="{{ asset($logo['sm']) }}" alt="{{ $brandName }}" class="brand-logo brand-logo--sm h-[22px] w-auto shrink-0">
    @endif
@else
    {{-- White-label fallback: initials chip + name (mirrors the old shell). --}}
    <span class="grid size-9 shrink-0 place-items-center rounded bg-sidebar-hover text-sm font-bold">{{ $brandInitials }}</span>
    @if ($context !== 'topbar')
        <span class="sidebar-label text-base font-semibold tracking-tight text-sidebar-brand">{{ $brandName }}</span>
    @endif
@endif
