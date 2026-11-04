@props([
    'label',
    'move' => null,
    'maxDepth' => null,
    'noun' => null,
])
@php
    // The shared admin tree (stylesheet resources/css/admin-tree.css, behaviour resources/js/admin-tree.js):
    // a treegrid of <x-bs-admin::tree.row> drawn by the server from App\Modules\Admin\Support\TreeRows,
    // with guide lines (├ └ │) in the indent, 32px per level, 24 when the tree is narrower than 600.
    // `move` names the screen's Livewire method that moves one record — (key, parent key or null,
    // position among the parent's other children) → null when done, or the reason it refuses —
    // and turns on dragging and the keyboard moves. Without `move` the tree is a still picture
    // (the gallery's states). `max-depth` greys a drop that would nest deeper; `noun` names a child
    // ("mục con", "chuyên mục con"…) in the drop labels. The touch path for the moves is
    // <x-bs-admin::tree.move-menu> in each row's ⋮ menu.
    $frozen = $move === null;
    $noun ??= __('admin.tree.noun');
    $config = [
        'move' => $move,
        'maxDepth' => $maxDepth,
        'texts' => [
            'drag' => [
                'start' => __('admin.tree.drag_start', ['noun' => $noun]),
                'child' => __('admin.tree.will_child', ['noun' => $noun]),
                'before' => __('admin.tree.will_before'),
                'after' => __('admin.tree.will_after'),
                'self' => __('admin.tree.no_self', ['noun' => $noun]),
            ],
            'tooDeep' => __('admin.tree.too_deep'),
            'tooDeepWhy' => __('admin.tree.too_deep_why'),
            'tooDeepKids' => __('admin.tree.too_deep_kids'),
            'lockedTarget' => __('admin.tree.locked_target'),
            'lockedSelf' => __('admin.tree.locked_self'),
            'kept' => __('admin.tree.kept'),
            'movedChild' => __('admin.tree.moved_child', ['noun' => $noun]),
            'movedBefore' => __('admin.tree.moved_before'),
            'movedAfter' => __('admin.tree.moved_after'),
            'movedUp' => __('admin.tree.moved_up'),
            'movedDown' => __('admin.tree.moved_down'),
            'movedIn' => __('admin.tree.moved_in'),
            'movedOut' => __('admin.tree.moved_out'),
            'atTop' => __('admin.tree.at_top'),
            'atBottom' => __('admin.tree.at_bottom'),
            'noAbove' => __('admin.tree.no_above'),
            'atOuter' => __('admin.tree.at_outer'),
            'undone' => __('admin.tree.undone'),
            'redone' => __('admin.tree.redone'),
            'saving' => __('admin.tree.saving'),
            'wait' => __('admin.tree.wait'),
            'dragCancelled' => __('admin.tree.drag_cancelled'),
            'offline' => __('admin.tree.offline'),
        ],
    ];
@endphp
<div {{ $attributes->merge(['class' => 'tree-w']) }}
    @unless ($frozen) x-data="bsTree(@js($config))" x-on:keydown="keydown($event)" @endunless>
    <div role="treegrid" aria-label="{{ $label }}" class="tree"
        @unless ($frozen) x-ref="grid" aria-busy="false" x-bind:aria-busy="busy ? 'true' : 'false'" x-on:focusin="focused($event)"
            x-on:dragstart="dragStart($event)" x-on:dragover="dragOver($event)" x-on:drop.prevent="dragDone()" x-on:dragend="dragCancel()" @endunless>
        {{ $slot }}
        @unless ($frozen)
            <x-bs-admin::tree.placeholder x-ref="ph" hidden />
        @endunless
    </div>
    @unless ($frozen)
        {{-- what a drag will do and done moves are read politely; only a refused move interrupts --}}
        <div class="tree-sr" role="status" aria-live="polite" x-ref="live"></div>
        <div class="tree-sr" aria-live="assertive" aria-atomic="true" x-ref="alert"></div>
        <div class="tree-msg" x-show="msg" x-cloak x-bind:data-bad="msgBad ? '1' : '0'"
            x-on:mouseenter="msgHold()" x-on:mouseleave="msgRun()" x-on:focusin="msgHold()" x-on:focusout="msgRun()">
            <x-bs-admin::icon name="ri-checkbox-circle" class="tree-msg-i tree-msg-ok" />
            <x-bs-admin::icon name="ri-error-warning" class="tree-msg-i tree-msg-no" />
            <span class="tree-msg-t" x-text="msg"></span>
            <x-bs-admin::button size="sm" variant="ghost" color="primary" x-show="msgUndo" x-on:click="msgClose(); undo()">
                <x-bs-admin::icon name="ri-arrow-go-back" class="size-4" /> {{ __('admin.tree.undo') }}
            </x-bs-admin::button>
            <x-bs-admin::button size="sm" variant="ghost" color="secondary" x-show="msgBad" x-on:click="msgClose()">
                <x-bs-admin::icon name="ri-close" class="size-4" /> {{ __('admin.tree.close') }}
            </x-bs-admin::button>
        </div>
    @endunless
</div>
