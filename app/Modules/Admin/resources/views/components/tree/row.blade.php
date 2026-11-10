@aware([
    'move' => null,
])
@props([
    'row',
    'name' => '',
    'locked' => null,
    'state' => null,
    'dragOff' => null,
])
@php
    // One record of the shared tree: `row` is one entry of TreeRows::build(), `name` the record's
    // plain name (the drop labels and the toggle say it). The default slot holds the name
    // (<x-bs-admin::tree.name>) and anything drawn beside it; `actions` is the cell of row buttons;
    // `below` is a full-width part under the row (an inline editor), outside the keyboard moves.
    // `sub` is the record's second line (its path, counts, languages): under the name, and on a
    // narrow tree (below 600px) across the whole row under the name and the actions, as a card.
    // `locked` is the reason the record cannot be moved or take children now (someone is editing
    // it…): the row shows it beside the name, the grip greys out and a drop on it is refused.
    // `state` draws a drag state in place, for the gallery: dragging · drop-in · drop-bad.
    // The Alpine expressions read the key from the row itself, so every row shares them (Alpine
    // compiles each distinct expression once — a key inside it would cost one per row).
    //
    // A tree the browser draws (<x-bs-admin::tree local>, inside <template x-for="r in …">) gives
    // `row` as the loop variable's name instead: an object with the same fields as a TreeRows entry
    // — k (key), parent, lv (level), kids (count), pos, size, guides [{d, k}] and name — so the
    // row looks and behaves the same; `drag-off` is an expression that stops dragging the row
    // (while its editor is open, or while the list is filtered). A server-drawn row takes `drag-off`
    // as true when it is never dragged nor moved by the keys, yet still takes children (a fixed
    // first-level record): its grip greys out with `drag-off` as its tip when that is text.
    $frozen = $move === null;
    $client = is_string($row);
    if ($client) {
        $r = $row;
        $collapseTpl = __('admin.tree.collapse');
        $expandTpl = __('admin.tree.expand');
    } else {
        $key = $row['key'];
        $hasKids = $row['kids'] > 0;
        $drop = ['drop-in' => 'in', 'drop-bad' => 'bad'][$state] ?? null;
        $fixed = $dragOff === true || (is_string($dragOff) && $dragOff !== '');
        $collapse = __('admin.tree.collapse', ['name' => $name]);
        $expand = __('admin.tree.expand', ['name' => $name]);
    }
@endphp
@if ($client)
<div role="row" data-tree-row x-bind:data-k="{{ $r }}.k" x-bind:data-parent="{{ $r }}.parent || ''" x-bind:data-lv="{{ $r }}.lv" x-bind:data-name="{{ $r }}.name"
    x-bind:aria-level="{{ $r }}.lv" x-bind:aria-posinset="{{ $r }}.pos" x-bind:aria-setsize="{{ $r }}.size"
    x-bind:data-kids="{{ $r }}.kids ? '1' : null" x-bind:aria-expanded="{{ $r }}.kids ? String(isOpen({{ $r }}.k)) : null"
    x-bind:draggable="{{ $dragOff ? '('.$dragOff.') ? \'false\' : \'true\'' : "'true'" }}" x-bind:hidden="hid($el)"
    {{ $attributes->merge(['class' => 'tree-r']) }}>
    <span class="tree-g" aria-hidden="true" x-show="{{ $r }}.guides.length"><template x-for="g in {{ $r }}.guides" :key="g.d + g.k"><span class="tree-gl" x-bind:data-d="g.d" x-bind:data-k="g.k"></span></template></span>
    <span class="tree-grip" aria-hidden="true" title="{{ __('admin.tree.grip_tip') }}"><x-bs-admin::icon name="ri-draggable" class="tree-grip-i" /></span>
    <div role="gridcell" class="tree-c tree-c-main">
        <template x-if="{{ $r }}.kids">
            <button type="button" class="tree-tg" x-bind:data-open="@js($collapseTpl).replace(':name', {{ $r }}.name)" x-bind:data-shut="@js($expandTpl).replace(':name', {{ $r }}.name)"
                x-bind:aria-label="tgLabel($el)" x-bind:title="tgLabel($el)" x-on:click="tg($el)"><x-bs-admin::icon name="ri-arrow-down-s" class="tree-tg-i" /></button>
        </template>
        <template x-if="!{{ $r }}.kids"><span class="tree-tg0" aria-hidden="true"></span></template>
        <div class="tree-main">
            {{ $slot }}
            @isset($sub)<div class="tree-sub">{{ $sub }}</div>@endisset
        </div>
    </div>
    @isset($actions)
        <div role="gridcell" class="tree-c tree-c-act">{{ $actions }}</div>
    @endisset
    @isset($below)
        <div role="gridcell" class="tree-c tree-c-x" data-tree-x>{{ $below }}</div>
    @endisset
</div>
@else
<div role="row" data-tree-row data-k="{{ $key }}" data-parent="{{ $row['parent'] }}" data-lv="{{ $row['level'] }}" data-name="{{ $name }}"
    aria-level="{{ $row['level'] }}" aria-posinset="{{ $row['pos'] }}" aria-setsize="{{ $row['size'] }}"
    @if ($hasKids) data-kids="1" aria-expanded="true" @unless ($frozen) x-bind:aria-expanded="String(isOpen($el.dataset.k))" @endunless @endif
    @if ($locked !== null) data-lock="{{ $locked }}" @endif
    @if ($state === 'dragging') data-drag="1" @endif
    @if ($drop) data-drop="{{ $drop }}" @endif
    @if ($fixed) data-fixed="1" @endif
    @unless ($frozen) draggable="{{ $locked === null && ! $fixed ? 'true' : 'false' }}" x-bind:hidden="hid($el)" @endunless
    {{ $attributes->merge(['class' => 'tree-r']) }}>
    @if ($row['guides'] !== [])
        <span class="tree-g" aria-hidden="true">@foreach ($row['guides'] as $g)<span class="tree-gl" data-d="{{ $g['d'] }}" data-k="{{ $g['k'] }}"></span>@endforeach</span>
    @endif
    <span class="tree-grip" aria-hidden="true" @if ($locked !== null || $fixed) data-off="1" @endif title="{{ $locked ?? (is_string($dragOff) && $fixed ? $dragOff : __('admin.tree.grip_tip')) }}"><x-bs-admin::icon name="ri-draggable" class="tree-grip-i" /></span>
    <div role="gridcell" class="tree-c tree-c-main">
        @if ($hasKids && ! $frozen)
            <button type="button" class="tree-tg" aria-label="{{ $collapse }}" title="{{ $collapse }}" data-open="{{ $collapse }}" data-shut="{{ $expand }}"
                x-bind:aria-label="tgLabel($el)" x-bind:title="tgLabel($el)" x-on:click="tg($el)"><x-bs-admin::icon name="ri-arrow-down-s" class="tree-tg-i" /></button>
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
            @isset($sub)<div class="tree-sub">{{ $sub }}</div>@endisset
        </div>
    </div>
    @isset($actions)
        <div role="gridcell" class="tree-c tree-c-act">{{ $actions }}</div>
    @endisset
    @isset($below)
        <div role="gridcell" class="tree-c tree-c-x" data-tree-x>{{ $below }}</div>
    @endisset
</div>
@endif
