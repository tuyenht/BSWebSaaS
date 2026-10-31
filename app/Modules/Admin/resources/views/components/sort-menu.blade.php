@props([
    'options' => [],
    'active',
    'lit' => false,
    'set',
])
@php
    // The Sort button of a record list's tools row (canvas .ql-sort, K69): "⇅ Sắp xếp" opens a menu headed
    // "Sắp xếp theo" with one menuitemradio per order, the one in force checked. The button is tinted while
    // the list is not in its default order ($lit), so a changed order is never hidden; the order's name is
    // in the button's accessible name and tooltip, so it still reads when the tools row has to drop the
    // word "Sắp xếp" ([data-sort-text]) and keep only the icon.
    //
    // $options: list<array{key: string, label: string, icon?: string|null}>. $active: the key in force.
    // $set: the host's public method a choice calls with the key, e.g. "setSort" — a fixed name, never input.
    //
    // Opens with Enter, Space or ↓ (focus goes to the order in force); ↑ ↓ move through it, Home / End jump to
    // the first / last; Esc or Tab closes it and focus returns to the button. One item at a time is in the
    // Tab order (roving tabindex): the order in force, or the first when none is.
    $current = collect($options)->firstWhere('key', $active)['label'] ?? '';
    $anyOn = in_array($active, array_column($options, 'key'), true);
    $name = __('admin.list.sort_named', ['name' => $current]);
    $menuId = 'sm-'.substr(md5($set.'|'.implode(',', array_column($options, 'key'))), 0, 8);
    $pill = 'hit-area group inline-flex min-h-control shrink-0 items-center justify-center gap-1.5 rounded-full border px-3.5 text-body font-medium transition-colors pointer-coarse:min-h-hit aria-expanded:border-primary-text aria-expanded:bg-primary/12 aria-expanded:text-primary-text';
    $state = $lit ? 'border-primary-text bg-primary/12 text-primary-text' : 'border-transparent bg-primary/7 text-text hover:bg-primary/12';
@endphp
<div {{ $attributes->merge(['class' => 'relative shrink-0']) }}
     x-data="{
         open: false,
         items() { return [...this.$refs.menu.querySelectorAll('[role=menuitemradio]')]; },
         show() {
             this.open = true;
             this.whenShown(() => { this.place(); this.go(this.$refs.menu.querySelector('[aria-checked=true]') || this.items()[0]); });
         },
         // the item given focus is the only one in the Tab order
         go(el) { if (! el) return; this.items().forEach((i) => { i.tabIndex = i === el ? 0 : -1; }); el.focus(); },
         whenShown(fn, tries = 20) { this.$refs.menu.getClientRects().length || ! tries ? fn() : setTimeout(() => this.whenShown(fn, tries - 1), 16); },
         // the menu hangs from the button's end edge and never leaves the screen
         place() { const m = this.$refs.menu; m.style.insetInlineEnd = ''; const over = 8 - m.getBoundingClientRect().left; if (over > 0) m.style.insetInlineEnd = -over + 'px'; },
         hide(back = true) { if (! this.open) return; this.open = false; if (back) this.$refs.button.focus(); },
         step(d) { const f = this.items(), i = f.indexOf(document.activeElement); this.go(f[(i + d + f.length) % f.length]); },
         edge(last) { const f = this.items(); this.go(last ? f[f.length - 1] : f[0]); },
     }"
     @keydown.escape.prevent.stop="hide()"
     @click.outside="hide(false)"
     @focusout="if ($event.relatedTarget && ! $root.contains($event.relatedTarget)) hide(false)">
    <button type="button" x-ref="button" data-sort="{{ $active }}" data-act="{{ $lit ? '1' : '0' }}"
            aria-haspopup="menu" aria-controls="{{ $menuId }}" :aria-expanded="open ? 'true' : 'false'"
            aria-label="{{ $name }}" title="{{ $name }}"
            @click="open ? hide() : show()" @keydown.down.prevent="show()"
            class="{{ $pill }} {{ $state }}">
        <x-bs-admin::icon name="ri-arrow-up-down" @class(['size-4 shrink-0 group-aria-expanded:text-primary-text', 'text-primary-text' => $lit, 'text-text-muted' => ! $lit]) />
        <span data-sort-text>{{ __('admin.list.sort') }}</span>
    </button>
    <div x-show="open" x-cloak x-ref="menu" id="{{ $menuId }}" role="menu" aria-label="{{ __('admin.list.sort_by') }}"
         @keydown.down.prevent="step(1)" @keydown.up.prevent="step(-1)" @keydown.home.prevent="edge(false)" @keydown.end.prevent="edge(true)"
         @keydown.tab.prevent="hide()"
         class="absolute end-0 top-full z-60 mt-1.5 w-max min-w-60 max-w-[min(20rem,calc(100vw-2rem))] rounded-md border border-border bg-surface p-1 shadow-lg">
        <p aria-hidden="true" class="m-0 px-2.5 pb-1 pt-1.5 text-small font-semibold text-text-strong">{{ __('admin.list.sort_by') }}</p>
        @foreach ($options as $option)
            @php $on = $option['key'] === $active; @endphp
            <button type="button" role="menuitemradio" aria-checked="{{ $on ? 'true' : 'false' }}" tabindex="{{ $on || (! $anyOn && $loop->first) ? '0' : '-1' }}"
                    wire:click="{{ $set }}({{ \Illuminate\Support\Js::from($option['key']) }})" @click="hide()"
                    @class([
                        'flex min-h-menu-row w-full items-center gap-2.5 rounded px-2.5 py-1.5 text-start text-body [overflow-wrap:anywhere] hover:bg-surface-hover pointer-coarse:min-h-menu-row-touch',
                        'font-bold text-primary-text' => $on,
                        'text-text' => ! $on,
                    ])>
                @if ($option['icon'] ?? null)
                    <x-bs-admin::icon :name="$option['icon']" class="size-4 shrink-0 text-text-muted" />
                @endif
                <span class="min-w-0 flex-1">{{ $option['label'] }}</span>
                @if ($on)
                    <x-bs-admin::icon name="ri-check" class="size-4 shrink-0" />
                @endif
            </button>
        @endforeach
    </div>
</div>
