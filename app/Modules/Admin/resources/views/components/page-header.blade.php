@props([
    'title' => '',
    'subtitle' => null,
])
@php
    // Standard admin page header: h1 (mirrors the dashboard/gallery title style) + optional
    // subtitle, an optional breadcrumbs slot above the title, and an optional actions slot
    // pinned to the right.
@endphp
<div {{ $attributes->merge(['class' => 'mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between']) }}>
    <div>
        @isset($breadcrumbs)
            <nav aria-label="{{ __('admin.breadcrumb') }}" class="mb-1 text-xs text-body-faint">
                {{ $breadcrumbs }}
            </nav>
        @endisset
        <h1 class="text-xl font-semibold text-heading">{{ $title }}</h1>
        @if ($subtitle)
            <p class="mt-1 text-sm text-body-faint">{{ $subtitle }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
    @endisset
</div>
