@aware([
    'move' => null,
])
@props([
    'row',
    'name',
    'locked' => null,
    'state' => null,
])
@php
    // One record of the shared tree: `row` is one entry of TreeRows::build(), `name` the record's
    // plain name (the drop labels and the toggle say it). The default slot holds the name
    // (<x-bs-admin::tree.name>) and anything drawn beside it; `actions` is the cell of row buttons.
    // `locked` is the reason the record cannot be moved or take children now (someone is editing
    // it…): the row shows it beside the name, the grip greys out and a drop on it is refused.
    // `state` draws a drag state in place, for the gallery: dragging · drop-in · drop-bad.
    $frozen = $move === null;
    $key = $row['key'];
    $hasKids = $row['kids'] > 0;
    $drop = ['drop-in' => 'in', 'drop-bad' => 'bad'][$state] ?? null;
    $collapse = __('admin.tree.collapse', ['name' => $name]);
    $expand = __('admin.tree.expand', ['name' => $name]);
@endphp
<div role="row" data-tree-row data-k="{{ $key }}" data-parent="{{ $row['parent'] }}" data-lv="{{ $row['level'] }}" data-name="{{ $name }}"
    aria-level="{{ $row['level'] }}" aria-posinset="{{ $row['pos'] }}" aria-setsize="{{ $row['size'] }}"
    @if ($hasKids) data-kids="1" aria-expanded="true" @unless ($frozen) x-bind:aria-expanded="String(isOpen(@js($key)))" @endunless @endif
    @if ($locked !== null) data-lock="{{ $locked }}" @endif
    @if ($state === 'dragging') data-drag="1" @endif
    @if ($drop) data-drop="{{ $drop }}" @endif
    @unless ($frozen) draggable="{{ $locked === null ? 'true' : 'false' }}" x-bind:hidden="hid($el)" @endunless
    {{ $attributes->merge(['class' => 'tree-r']) }}>
    @if ($row['guides'] !== [])
        <span class="tree-g" aria-hidden="true">@foreach ($row['guides'] as $g)<span class="tree-gl" data-d="{{ $g['d'] }}" data-k="{{ $g['k'] }}"></span>@endforeach</span>
    @endif
    <span class="tree-grip" aria-hidden="true" @if ($locked !== null) data-off="1" @endif title="{{ $locked ?? __('admin.tree.grip_tip') }}"><x-bs-admin::icon name="ri-draggable" class="tree-grip-i" /></span>
    <div role="gridcell" class="tree-c tree-c-main">
        @if ($hasKids && ! $frozen)
            <button type="button" class="tree-tg" aria-label="{{ $collapse }}" title="{{ $collapse }}"
                x-bind:aria-label="isOpen(@js($key)) ? @js($collapse) : @js($expand)" x-bind:title="isOpen(@js($key)) ? @js($collapse) : @js($expand)"
                x-on:click="toggle(@js($key))"><x-bs-admin::icon name="ri-arrow-down-s" class="tree-tg-i" /></button>
        @elseif ($hasKids)
            <span class="tree-tg" aria-hidden="true"><x-bs-admin::icon name="ri-arrow-down-s" class="tree-tg-i" /></span>
        @else
            <span class="tree-tg0" aria-hidden="true"></span>
        @endif
        <div class="tree-main">
            {{ $slot }}
            @if ($locked !== null)
                <span class="tree-lk"><x-bs-admin::icon name="ri-lock" class="tree-lk-i" /><span>{{ $locked }}</span></span>
            @endif
        </div>
    </div>
    @isset($actions)
        <div role="gridcell" class="tree-c tree-c-act">{{ $actions }}</div>
    @endisset
</div>
