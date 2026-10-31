@props([
    'status' => 'draft',
    'button' => false,
])
@php
    // The one record-status pill of every list row (design .ql-st): a light tint of its own ink,
    // no visible border, 28 high at the default text size and growing with it, always lower than the row's
    // buttons. A screen picks only the status; tone, icon, height and padding live here so no
    // screen can make one look like an action button. `button` makes it the status menu's trigger:
    // a caret, a darker tint while hovered or open, and the shared 44 touch area around it.
    // The transparent border keeps the design's 9px inset and shows the outline in forced colours.
    // A label wraps only when the pill alone is wider than its cell (names are never clipped).
    // Unknown statuses read as plain text with no icon.
    $tones = [
        'published' => ['[--c:var(--color-success-text)]', 'ri-global'],
        'draft' => ['[--c:var(--color-text)]', 'ri-draft'],
        'scheduled' => ['[--c:color-mix(in_oklab,var(--color-secondary-text)_80%,var(--color-text-strong))]', 'ri-calendar-schedule'],
        'pending' => ['[--c:var(--color-warning-text)]', 'ri-inbox-unarchive'],
        'private' => ['[--c:var(--color-primary-text)]', 'ri-lock-2'],
        'archived' => ['[--c:var(--color-text-muted)]', 'ri-archive'],
    ];
    [$tone, $icon] = $tones[$status] ?? ['[--c:var(--color-text)]', null];

    $base = 'inline-flex min-h-7 max-w-full shrink-0 items-center gap-1.5 rounded-full border border-transparent px-2 py-0.5 text-small leading-[1.4] font-semibold text-(color:--c) bg-[color-mix(in_srgb,var(--c)_11%,var(--color-surface))]';
    $glyph = 'size-[calc(0.875rem+var(--user-text-boost))] shrink-0';
@endphp
@if ($button)
    <button type="button" data-s="{{ $status }}" {{ $attributes->merge(['class' => "hit-area $base $tone cursor-pointer transition-colors hover:bg-[color-mix(in_srgb,var(--c)_18%,var(--color-surface))] aria-expanded:bg-[color-mix(in_srgb,var(--c)_18%,var(--color-surface))]"]) }}>
        @if ($icon)<x-bs-admin::icon :name="$icon" :class="$glyph" />@endif
        <span>{{ $slot }}</span>
        <x-bs-admin::icon name="ri-arrow-down-s" :class="$glyph.' opacity-80'" />
    </button>
@else
    <span data-s="{{ $status }}" {{ $attributes->merge(['class' => "$base $tone"]) }}>
        @if ($icon)<x-bs-admin::icon :name="$icon" :class="$glyph" />@endif
        <span>{{ $slot }}</span>
    </span>
@endif
