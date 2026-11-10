@aware([
    'move' => null,
])
@props([
    'href' => null,
])
@php
    // The name of a tree record: a link to its editor, or a button the screen wires (x-on:click,
    // wire:click). It is where the keyboard moves start (Alt+arrows) and where the arrow keys go
    // between rows. It wraps onto as many lines as it needs — never clipped. In a still tree it is
    // plain text.
@endphp
@if ($href)
    <a href="{{ $href }}" wire:navigate data-tree-name {{ $attributes->merge(['class' => 'tree-nm']) }}>{{ $slot }}</a>
@elseif ($move === null)
    <span {{ $attributes->merge(['class' => 'tree-nm']) }}>{{ $slot }}</span>
@else
    <button type="button" data-tree-name {{ $attributes->merge(['class' => 'tree-nm']) }}>{{ $slot }}</button>
@endif
