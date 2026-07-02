@props([
    'text' => '',
    'position' => 'top',
])
@php
    // Shows on hover AND focus (keyboard-accessible), hides on leave/blur/Escape. The bubble is
    // linked to the trigger via aria-describedby so screen readers announce it.
    $pos = $position === 'bottom' ? 'top-full mt-1' : 'bottom-full mb-1';
    // Str::random (not uniqid) so two tooltips rendered in the same microsecond can't collide.
    $tid = 'tt-'.\Illuminate\Support\Str::random(6);
@endphp
<span x-data="{ show: false }" @keydown.escape="show = false"
      {{ $attributes->merge(['class' => 'relative inline-flex']) }}>
    {{-- focusin/focusout (bubbling) so a focusable child trigger shows the tooltip without the
         wrapper adding a second tab stop; put the tooltip on a focusable element for keyboard. --}}
    <span @mouseenter="show = true" @mouseleave="show = false" @focusin="show = true" @focusout="show = false"
          aria-describedby="{{ $tid }}" class="inline-flex">
        {{ $slot }}
    </span>
    <span x-show="show" x-transition x-cloak id="{{ $tid }}" role="tooltip"
          class="absolute {{ $pos }} left-1/2 z-40 -translate-x-1/2 whitespace-nowrap rounded bg-admin-dark px-2 py-1 text-xs text-white shadow-card">
        {{ $text }}
    </span>
</span>
