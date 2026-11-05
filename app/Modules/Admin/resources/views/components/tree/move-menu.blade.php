@aware([
    'locked' => null,
])
@props([
    'row',
])
@php
    // The moves of one tree record as ⋮ menu items — the touch path, the same on every screen
    // (the keyboard has Alt+arrows, the mouse has dragging): put it in the `menu` slot of the row's
    // <x-bs-admin::list.actions>. An item that cannot be used where the record stands (first in its
    // group, nothing above to go into, already at the outer level…) stays, greyed, with the reason
    // under it and as its title; a locked record greys all four and says why once. Each item shows
    // its shortcut. A move that only the browser can refuse (too deep) is refused when picked. The
    // items read the record's key from their row, so every row shares the same expressions.
    // In a tree the browser draws (`row` is the loop variable's name) the tree itself works out,
    // from the rows as drawn now, which items cannot be used and shows their reasons the same way.
    if (is_string($row)) {
        $row = ['pos' => 2, 'size' => 3, 'level' => 2];
        $client = true;
    }
    $client ??= false;
    $first = $row['pos'] <= 1;
    $items = [
        ['go' => 'moveUp', 'icon' => 'ri-arrow-up', 'label' => __('admin.tree.move_up'), 'hint' => 'Alt ↑', 'keys' => 'Alt+ArrowUp', 'why' => $first ? __('admin.tree.why_top') : null],
        ['go' => 'moveDown', 'icon' => 'ri-arrow-down', 'label' => __('admin.tree.move_down'), 'hint' => 'Alt ↓', 'keys' => 'Alt+ArrowDown', 'why' => $row['pos'] >= $row['size'] ? __('admin.tree.why_bottom') : null],
        ['go' => 'indent', 'icon' => 'ri-indent-increase', 'label' => __('admin.tree.move_in'), 'hint' => 'Alt →', 'keys' => 'Alt+ArrowRight', 'why' => $first ? __('admin.tree.why_no_above') : null],
        ['go' => 'outdent', 'icon' => 'ri-indent-decrease', 'label' => __('admin.tree.move_out'), 'hint' => 'Alt ←', 'keys' => 'Alt+ArrowLeft', 'why' => $row['level'] <= 1 ? __('admin.tree.why_outer') : null],
    ];
@endphp
@foreach ($items as $item)
    @if ($client)
        <x-bs-admin::list.menu-item :icon="$item['icon']" :hint="$item['hint']" aria-keyshortcuts="{{ $item['keys'] }}"
            x-bind:aria-disabled="blocked('{{ $item['go'] }}', $el) ? 'true' : null" x-on:click="go('{{ $item['go'] }}', $el)">{{ $item['label'] }}</x-bs-admin::list.menu-item>
        <span class="list-mwhy" x-show="blocked('{{ $item['go'] }}', $el)">{{ ['moveUp' => __('admin.tree.why_top'), 'moveDown' => __('admin.tree.why_bottom'), 'indent' => __('admin.tree.why_no_above'), 'outdent' => __('admin.tree.why_outer')][$item['go']] }}</span>
        @continue
    @endif
    <x-bs-admin::list.menu-item :icon="$item['icon']" :hint="$item['hint']" aria-keyshortcuts="{{ $item['keys'] }}"
        :why="$locked === null ? $item['why'] : null" :off="$locked !== null" :title="$locked ?? $item['why']"
        x-on:click="go('{{ $item['go'] }}', $el)">{{ $item['label'] }}</x-bs-admin::list.menu-item>
@endforeach
@if ($locked !== null)
    <span class="list-mwhy">{{ $locked }}</span>
@endif
