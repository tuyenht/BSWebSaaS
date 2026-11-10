@props([
    'groups' => [],
    'active' => null,
    'model',
    'label',
])
@php
    // The status-group row of a record list (canvas .sx-grp, K69), shown from 900px; below that the
    // groups live at the top of the Filter panel. Groups filter the same records, so they are
    // aria-pressed buttons, never ARIA tabs; the chosen one is underlined and bold, not only coloured.
    // One line that never wraps or scrolls sideways: groups that do not fit move into "Khác ▾",
    // last first, while the chosen group and Trash always stay in the row. "Khác" sits right after
    // the last group and Trash at the far end, at least 2rem apart; the menu never leaves the row.
    //
    // $groups: list<array{key: string, label: string, icon?: string|null, count?: int|string|null, trash?: bool}>
    // A count given as text ("hơn 10.000", for a list that stops counting) is shown as given.
    // $active: the chosen group's key. $model: the host Livewire property a click sets.
    // $label: the row's name, e.g. "Nhóm tệp".
    //
    // "Khác" opens with Enter, Space or ↓; ↑ ↓ move through it; Esc closes it and focus returns to it.
    $fmt = fn (int|string|null $n): ?string => is_int($n) ? \Illuminate\Support\Number::format($n, locale: app()->getLocale()) : $n;
    $setter = fn (string $key): string => '$set('.\Illuminate\Support\Js::from($model).', '.\Illuminate\Support\Js::from($key).')';
    // Stable across re-renders, so a morph never rewrites aria-controls under an open menu
    $menuId = 'gm-'.substr(md5($model), 0, 8);
@endphp
<nav aria-label="{{ $label }}"
    x-data="{
        over: [],
        open: false,
        left: 0,
        stopMorph: null,
        stopSize: null,
        observeGroups: null,
        fits() {
            const row = this.$refs.row, trash = row.querySelector(':scope > [data-trash]');
            if (row.scrollWidth > row.clientWidth) return false;
            if (! trash) return true;
            const before = [...row.children].filter((el) => el !== trash && ! el.hidden && el.offsetLeft < trash.offsetLeft);
            const end = Math.max(0, ...before.map((el) => el.offsetLeft + el.offsetWidth));
            return trash.offsetLeft - end >= 2 * parseFloat(getComputedStyle(document.documentElement).fontSize);
        },
        fit() {
            const row = this.$refs.row, more = this.$refs.more;
            const items = [...row.querySelectorAll(':scope > [data-g]')];
            items.forEach((el) => { el.hidden = false; });
            more.hidden = true;
            const over = [];
            if (! this.fits()) {
                more.hidden = false;
                const movable = items.filter((el) => el.dataset.pin !== '1').reverse();
                for (const el of movable) {
                    if (this.fits()) break;
                    el.hidden = true;
                    over.unshift(el.dataset.g);
                }
            }
            this.over = over;
            if (! over.length) { more.hidden = true; this.open = false; }
        },
        // Fit again when the row's width changes, and when a shown group's width changes while the row
        // keeps its box: a larger text size (on touch every group is already 44px tall, so the row
        // does not grow) or a web font that arrives late. A group fit() has just hidden reports a
        // zero size and is skipped, so a fit never triggers itself. Groups a re-render adds are
        // observed too (observeGroups() runs again after each morph).
        watchSize() {
            const row = this.$refs.row;
            const ro = new ResizeObserver((entries) => { if (entries.some((e) => e.target === row || ! e.target.hidden)) this.fit(); });
            ro.observe(row);
            this.observeGroups = () => row.querySelectorAll(':scope > [data-g]').forEach((el) => ro.observe(el));
            this.observeGroups();
            this.stopSize = () => ro.disconnect();
        },
        // A Livewire re-render morphs the row back to the server HTML (every group shown, Khác hidden): fit again after each one
        watchMorph() {
            try { this.stopMorph = this.$wire.interceptMessage(({ onSuccess }) => onSuccess(({ onMorphed }) => onMorphed(() => { this.observeGroups?.(); this.fit(); }))); } catch (e) {}
        },
        destroy() { this.stopMorph?.(); this.stopSize?.(); },
        items() { return [...this.$refs.menu.querySelectorAll('[role=menuitemradio]')].filter((el) => el.offsetParent !== null); },
        show(focusFirst = true) {
            this.left = this.$refs.more.offsetLeft;
            this.open = true;
            // x-show reveals the menu a moment after open turns true, so wait until it is on screen to measure and focus
            this.whenShown(() => {
                this.left = Math.max(0, Math.min(this.left, this.$root.clientWidth - this.$refs.menu.offsetWidth));
                if (focusFirst) this.items()[0]?.focus();
            });
        },
        whenShown(fn, tries = 20) { this.$refs.menu.getClientRects().length || ! tries ? fn() : setTimeout(() => this.whenShown(fn, tries - 1), 16); },
        hide(back = true) { if (! this.open) return; this.open = false; if (back) this.$refs.moreButton.focus(); },
        step(d) { const f = this.items(), i = f.indexOf(document.activeElement); f[(i + d + f.length) % f.length]?.focus(); },
    }"
    x-init="$nextTick(() => fit()); watchSize(); watchMorph()"
    @keydown.escape="hide()"
    @click.outside="hide(false)"
    {{ $attributes->merge(['class' => 'relative hidden min-w-0 border-b border-border min-[900px]:block']) }}
>
    <div x-ref="row" class="flex min-w-0 flex-nowrap items-end gap-1 overflow-hidden px-3 pt-1">
        @foreach ([...array_filter($groups, fn (array $g): bool => ! ($g['trash'] ?? false)), null, ...array_filter($groups, fn (array $g): bool => $g['trash'] ?? false)] as $group)
            @if ($group === null)
                <div x-ref="more" hidden class="relative shrink-0">
                    <button type="button" x-ref="moreButton"
                            aria-haspopup="menu" aria-controls="{{ $menuId }}" :aria-expanded="open ? 'true' : 'false'"
                            @click="open ? hide() : show()"
                            @keydown.down.prevent="show()"
                            class="-mb-px inline-flex min-h-control items-center gap-1 whitespace-nowrap border-b-2 border-transparent px-2.5 text-body font-medium text-text-muted transition-colors hover:text-text-strong any-pointer-coarse:min-h-hit">
                        <span>{{ __('admin.list.more_groups') }}</span>
                        <x-bs-admin::icon name="chevron-down" class="size-4 shrink-0" />
                    </button>
                </div>
                @continue
            @endif
            @php $on = $group['key'] === $active; @endphp
            <button type="button" data-g="{{ $group['key'] }}" data-pin="{{ $on || ($group['trash'] ?? false) ? '1' : '0' }}" @if ($group['trash'] ?? false) data-trash @endif
                    aria-pressed="{{ $on ? 'true' : 'false' }}"
                    wire:click="{{ $setter($group['key']) }}"
                    @class([
                        '-mb-px inline-flex min-h-control shrink-0 items-center gap-1.5 whitespace-nowrap border-b-2 px-2.5 text-body transition-colors any-pointer-coarse:min-h-hit',
                        'ms-auto' => $group['trash'] ?? false,
                        'border-transparent font-medium text-text-muted hover:text-text-strong' => ! $on,
                        'border-primary-text font-bold text-primary-text' => $on && ! ($group['trash'] ?? false),
                        'border-danger-text font-bold text-danger-text' => $on && ($group['trash'] ?? false),
                    ])>
                @if ($group['icon'] ?? null)
                    <x-bs-admin::icon :name="$group['icon']" class="size-4 shrink-0" />
                @endif
                <span>{{ $group['label'] }}</span>
                @if (($n = $fmt($group['count'] ?? null)) !== null)
                    <span @class([
                        'inline-flex min-w-5 items-center justify-center rounded-full px-1.5 text-caption font-semibold leading-normal',
                        'bg-surface-hover text-text' => ! $on,
                        'bg-primary/10 text-primary-text' => $on,
                    ])>{{ $n }}</span>
                @endif
            </button>
        @endforeach
    </div>

    <div x-show="open" x-cloak x-ref="menu" id="{{ $menuId }}" role="menu" aria-label="{{ __('admin.list.more_groups') }}"
         :style="{ insetInlineStart: left + 'px' }"
         @keydown.down.prevent="step(1)" @keydown.up.prevent="step(-1)"
         @focusout="$event.relatedTarget && ! $refs.menu.contains($event.relatedTarget) && $event.relatedTarget !== $refs.moreButton && hide(false)"
         class="absolute top-full z-60 mt-1 min-w-55 max-w-[min(20rem,calc(100vw-1.5rem))] rounded-md border border-border-input bg-surface p-1 shadow-lg">
        @foreach ($groups as $group)
            @php $on = $group['key'] === $active; @endphp
            <button type="button" role="menuitemradio" aria-checked="{{ $on ? 'true' : 'false' }}"
                    x-show="over.includes(@js($group['key']))"
                    wire:click="{{ $setter($group['key']) }}" @click="hide()"
                    @class([
                        'flex min-h-menu-row w-full items-center gap-2 rounded px-2.5 py-1.5 text-start text-body [overflow-wrap:anywhere] hover:bg-surface-hover any-pointer-coarse:min-h-menu-row-touch',
                        'font-bold text-primary-text' => $on,
                        'text-text' => ! $on,
                    ])>
                <span class="min-w-0 flex-1">{{ $group['label'] }}</span>
                @if (($n = $fmt($group['count'] ?? null)) !== null)
                    <span class="shrink-0 text-small text-text-muted">{{ $n }}</span>
                @endif
            </button>
        @endforeach
    </div>
</nav>
