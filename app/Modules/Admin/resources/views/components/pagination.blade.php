@props([
    'paginator' => null,
    'current' => 1,
    'last' => 1,
])
@php
    // Presentational pager (Velzon .pagination). Accepts a Laravel paginator OR plain
    // current/last props (for the gallery + non-Eloquent lists). Renders nothing for a
    // single page. Windowed page numbers (±2) around the current page with ellipses.
    if ($paginator !== null) {
        $current = (int) $paginator->currentPage();
        $last = (int) $paginator->lastPage();
        $urlFor = fn (int $p): string => (string) $paginator->url($p);
    } else {
        $urlFor = fn (int $p): string => '#';
    }
    $current = max(1, (int) $current);
    $last = max(1, (int) $last);
    $from = max(1, $current - 2);
    $to = min($last, $current + 2);
    // pager-link (app.css) is the size and shape, shared with the posts list's keyset pager.
    $linkBase = 'pager-link';
    $navlink = $linkBase.' text-text-muted hover:bg-surface-hover';
    $disabled = $linkBase.' cursor-not-allowed text-icon-ghost';
@endphp
@if ($last > 1)
    <nav aria-label="{{ __('admin.pagination.nav') }}" {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-2']) }}>
        @if ($current > 1)
            <a href="{{ $urlFor($current - 1) }}" @if ($paginator) wire:navigate @endif
               aria-label="{{ __('admin.pagination.previous') }}" class="{{ $navlink }}">
                <x-bs-admin::icon name="chevron-left" class="size-4" />
            </a>
        @else
            <span aria-hidden="true" class="{{ $disabled }}"><x-bs-admin::icon name="chevron-left" class="size-4" /></span>
        @endif

        @if ($from > 1)
            <span class="px-1 text-placeholder">&hellip;</span>
        @endif
        @for ($p = $from; $p <= $to; $p++)
            @if ($p === $current)
                <span aria-current="page" class="{{ $linkBase }} bg-primary font-medium text-white">{{ $p }}</span>
            @else
                <a href="{{ $urlFor($p) }}" @if ($paginator) wire:navigate @endif class="{{ $navlink }}">{{ $p }}</a>
            @endif
        @endfor
        @if ($to < $last)
            <span class="px-1 text-placeholder">&hellip;</span>
        @endif

        @if ($current < $last)
            <a href="{{ $urlFor($current + 1) }}" @if ($paginator) wire:navigate @endif
               aria-label="{{ __('admin.pagination.next') }}" class="{{ $navlink }}">
                <x-bs-admin::icon name="chevron-right" class="size-4" />
            </a>
        @else
            <span aria-hidden="true" class="{{ $disabled }}"><x-bs-admin::icon name="chevron-right" class="size-4" /></span>
        @endif
    </nav>
@endif
