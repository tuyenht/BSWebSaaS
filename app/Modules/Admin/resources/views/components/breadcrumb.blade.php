@props([
    // [['label' => string, 'url' => ?string], …]; the last entry is the current page.
    'items' => [],
])
@php
    // The page's trail above its title: ⌂ (the dashboard) › section › page. A middle step with a
    // URL is a link, one without is plain text; the last step is the current page. The links keep
    // a 24px line and get a 48px hit area through a transparent extension (.hit-area).
    $items = array_values($items);
    $last = count($items) - 1;
    $glyph = 'size-[min(1.5rem,calc(0.8125rem+var(--user-text-boost)))] shrink-0';
@endphp
<nav aria-label="{{ __('admin.breadcrumb') }}" {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-1.5 text-caption text-text-muted']) }}>
    <a href="{{ \App\Modules\Admin\Support\AdminPlane::route('dashboard') }}" wire:navigate
       aria-label="{{ __('admin.dashboard') }}" title="{{ __('admin.dashboard') }}"
       class="hit-area -mx-1 inline-flex min-h-6 items-center rounded px-1 transition-colors hover:text-primary-text">
        <x-bs-admin::icon name="ri-home-4" class="size-[min(1.5rem,calc(0.875rem+var(--user-text-boost)))] shrink-0" />
    </a>
    @foreach ($items as $i => $item)
        <x-bs-admin::icon name="ri-arrow-right-s" :class="$glyph" />
        @if ($i === $last)
            <span class="font-medium text-text" aria-current="page">{{ $item['label'] }}</span>
        @elseif (! empty($item['url']))
            <a href="{{ $item['url'] }}" wire:navigate class="hit-area inline-flex min-h-6 items-center underline underline-offset-2 transition-colors hover:text-primary-text">{{ $item['label'] }}</a>
        @else
            <span>{{ $item['label'] }}</span>
        @endif
    @endforeach
</nav>
