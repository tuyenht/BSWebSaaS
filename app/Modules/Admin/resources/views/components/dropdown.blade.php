@props([
    'align' => 'start',
])
@php
    // Mirrors the topbar user-menu (per-instance Alpine x-data + @click.outside). $trigger is
    // the toggle; the default slot is the menu panel (consumer writes role="menuitem" links).
    $menuAlign = $align === 'end' ? 'end-0' : 'start-0';
@endphp
<div x-data="{ open: false }" @keydown.escape="open = false" {{ $attributes->merge(['class' => 'relative inline-block']) }}>
    <div @click="open = ! open" aria-haspopup="true" :aria-expanded="open ? 'true' : 'false'" class="inline-flex cursor-pointer">
        {{ $trigger }}
    </div>
    {{-- A disclosure popover of links, not an ARIA menu (which would owe roving arrow-key nav).
         Each row the consumer writes is a menu row: the shared height, centred, at the user's text size. --}}
    <div x-show="open" @click.outside="open = false" x-transition x-cloak
         class="absolute {{ $menuAlign }} z-30 mt-1 min-w-44 rounded-md border border-border bg-surface py-1 text-body shadow-card *:flex *:min-h-menu-row *:items-center any-pointer-coarse:*:min-h-menu-row-touch">
        {{ $slot }}
    </div>
</div>
