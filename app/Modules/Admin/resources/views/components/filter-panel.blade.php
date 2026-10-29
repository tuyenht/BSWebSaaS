@props([
    'sections' => [],
    'active' => 0,
    'results' => null,
    'clear' => null,
    'label' => null,
])
@php
    // The filters of a record list (canvas [data-lf] / .lf-* / .ql-pop, K69 + K46), two surfaces from one list of sections:
    // - From 1024px: one light button per section (icon + name + ▾; tinted pill, outlined in the accent colour while it
    //   filters or is open), each opening that section's own small panel.
    // - Below 1024px: one "Lọc" button (with how many conditions are on) opening the Filter sheet from the bottom of the
    //   screen: "Bộ lọc" + ✕, every section, [Bỏ lọc] [Xem N …]. Status groups come first when the list has them (they
    //   live here below 900px), "Hiện trên thẻ" last. The sheet has two columns when the screen is at least 29 body-text
    //   widths wide: on the left one button per section saying in words what it is set to ("Ảnh, Tài liệu PDF"; nothing
    //   chosen → "Tất cả", dimmed); a tap scrolls the right column to that section. One column: each section's heading
    //   carries its value (": Bản nháp", " · 2 đã chọn").
    //
    // $sections: list<array{key: string, label: string, model: string, multiple?: bool, icon?: string|null,
    //            value?: string|list<string>|null, default?: string|null,
    //            options: list<array{value: string, label: string, count?: int|null}>}>
    //            value is the host property's current value, so every re-render says what is chosen; a one-choice section
    //            filters when its value differs from default (its first option unless given).
    // $active: how many conditions are on (shown on the Lọc button and in its name).
    // $results: the footer button text, e.g. "Xem 12 dòng" — filters apply as they change, so it only closes the sheet.
    // $clear: the host's public method that resets every filter (shown when $active > 0).
    //
    // Keyboard: opening focuses the first option; Esc or a click outside closes and focus returns to the button; Tab
    // stays inside the sheet.
    $label ??= __('admin.list.filter');
    $name = $active > 0 ? __('admin.list.filter_active', ['n' => $active]) : $label;
    $uid = \Illuminate\Support\Str::random(8);
    $panelId = 'lf-'.$uid;
    $fmt = fn (int $n): string => \Illuminate\Support\Number::format($n, locale: app()->getLocale());
    $view = [];
    foreach ($sections as $section) {
        $multiple = $section['multiple'] ?? false;
        $value = $section['value'] ?? null;
        $default = (string) ($section['default'] ?? ($multiple ? '' : ($section['options'][0]['value'] ?? '')));
        $chosen = array_map('strval', is_array($value) ? $value : ($value === null || $value === '' ? [] : [$value]));
        if (! $multiple && $chosen === [] && $default !== '') {
            $chosen = [$default];
        }
        $labels = array_values(array_map(
            fn (array $o): string => $o['label'],
            array_filter($section['options'], fn (array $o): bool => in_array((string) $o['value'], $chosen, true)),
        ));
        $view[] = $section + [
            'multiple' => $multiple,
            'on' => $multiple ? $labels !== [] : $chosen[0] !== $default,
            'all' => $multiple && $labels === [],
            'summary' => match (true) {
                ! $multiple => $labels[0] ?? '',
                $labels === [] => __('admin.list.all'),
                count($labels) <= 4 => implode(', ', $labels),
                default => __('admin.list.and_more', ['list' => implode(', ', array_slice($labels, 0, 3)), 'n' => $fmt(count($labels) - 3)]),
            },
            'suffix' => match (true) {
                ! $multiple => $labels !== [] ? ': '.$labels[0] : '',
                $labels === [] => '',
                default => ' · '.__('admin.list.n_selected', ['n' => $fmt(count($labels))]),
            },
        ];
    }
    $pill = 'hit-area group inline-flex min-h-control items-center gap-1.5 rounded-full border px-3.5 text-body font-medium transition-colors pointer-coarse:min-h-hit aria-expanded:border-accent aria-expanded:bg-admin-primary/12 aria-expanded:text-accent';
    $pillState = fn (bool $on): string => $on ? 'border-accent bg-admin-primary/12 text-accent' : 'border-transparent bg-admin-primary/7 text-text hover:bg-admin-primary/12';
    $pillIcon = fn (bool $on): string => 'size-4 shrink-0 group-aria-expanded:text-accent '.($on ? 'text-accent' : 'text-body-muted');
@endphp
<div {{ $attributes->merge(['class' => 'flex min-w-0 shrink-0 flex-wrap items-center gap-2']) }}>
    @foreach ($view as $section)
        <div class="relative hidden shrink-0 min-[1024px]:block"
             x-data="{
                 open: false,
                 show() { this.open = true; this.whenShown(() => { this.place(); (this.$refs.panel.querySelector('input:checked') || this.$refs.panel.querySelector('input'))?.focus(); }); },
                 hide(back = true) { if (! this.open) return; this.open = false; if (back) this.$refs.button.focus(); },
                 // x-show reveals the panel a moment after open turns true, so wait until it is on screen
                 whenShown(fn, tries = 20) { this.$refs.panel.getClientRects().length || ! tries ? fn() : setTimeout(() => this.whenShown(fn, tries - 1), 16); },
                 place() { const p = this.$refs.panel; p.style.insetInlineStart = ''; const over = p.getBoundingClientRect().right - (window.innerWidth - 8); if (over > 0) p.style.insetInlineStart = -over + 'px'; },
             }"
             @keydown.escape.prevent.stop="hide()"
             @click.outside="hide(false)"
             @focusout="if ($event.relatedTarget && ! $root.contains($event.relatedTarget)) hide(false)">
            <button type="button" x-ref="button" data-fsec="{{ $section['key'] }}" data-act="{{ $section['on'] ? '1' : '0' }}"
                    @click="open ? hide() : show()"
                    aria-haspopup="true" :aria-expanded="open ? 'true' : 'false'" aria-controls="lfs-{{ $uid }}-{{ $section['key'] }}"
                    class="{{ $pill }} {{ $pillState($section['on']) }}">
                @if ($section['icon'] ?? null)
                    <x-bs-admin::icon :name="$section['icon']" class="{{ $pillIcon($section['on']) }}" />
                @endif
                <span>{{ $section['label'] }}</span>
                <x-bs-admin::icon name="chevron-down" class="{{ $pillIcon($section['on']) }}" />
            </button>
            <div x-show="open" x-cloak x-ref="panel" id="lfs-{{ $uid }}-{{ $section['key'] }}" role="group" aria-label="{{ $section['label'] }}"
                 class="absolute start-0 top-full z-60 mt-1.5 flex max-h-[min(27.5rem,70vh)] w-max min-w-60 max-w-[min(20rem,calc(100vw-2rem))] flex-col rounded-md border border-border bg-surface py-1 shadow-lg">
                <p class="m-0 flex flex-wrap items-baseline gap-x-2 px-4 pb-1 pt-2.5 text-small font-semibold text-heading">
                    <span>{{ $section['label'] }}</span>
                    <span class="font-normal text-body-muted">{{ __($section['multiple'] ? 'admin.list.choose_many' : 'admin.list.choose_one') }}</span>
                </p>
                <div class="min-h-0 overflow-y-auto overscroll-contain">
                    <x-bs-admin::filter-options :section="$section" :name="'lfs-'.$uid.'-'.$section['key']" />
                </div>
            </div>
        </div>
    @endforeach

    <div class="relative shrink-0 min-[1024px]:hidden"
         x-data="{
             open: false, cols: 1, cur: 0, pin: null, prog: 0,
             // two columns once the screen is at least 29 body-text widths wide; decided on opening and on resize only
             decide() { const fs = parseFloat(getComputedStyle(this.$refs.panel).fontSize) || 14; this.cols = window.innerWidth / fs >= 29 ? 2 : 1; },
             show() { this.decide(); this.open = true; this.whenShown(() => { this.mark(); this.focusables().find((el) => this.$refs.scroll.contains(el))?.focus(); }); },
             hide(back = true) { if (! this.open) return; this.open = false; if (back) this.$refs.button.focus(); },
             // x-show reveals the sheet a moment after open turns true, so wait until it is on screen
             whenShown(fn, tries = 20) { this.$refs.panel.getClientRects().length || ! tries ? fn() : setTimeout(() => this.whenShown(fn, tries - 1), 16); },
             heads() { return [...this.$refs.scroll.querySelectorAll('legend[data-sec]')]; },
             go(i) { const h = this.heads()[i], s = this.$refs.scroll; if (! h) return; this.prog = Date.now(); this.pin = i; s.scrollTop = Math.max(0, h.getBoundingClientRect().top - s.getBoundingClientRect().top + s.scrollTop - 4); h.focus({ preventScroll: true }); this.cur = i; },
             // the current section is the last whose heading has passed the top of the right column; a section reached by a
             // tap stays current until the user scrolls, since the last ones cannot scroll up to the top
             mark() { if (Date.now() - this.prog > 250) this.pin = null; if (this.pin !== null) { this.cur = this.pin; return; } const top = this.$refs.scroll.getBoundingClientRect().top + 12; let c = 0; this.heads().forEach((h, i) => { if (h.getBoundingClientRect().top <= top) c = i; }); this.cur = c; },
             focusables() { return [...this.$refs.panel.querySelectorAll('input:not([disabled]),button:not([disabled])')].filter((el) => el.getClientRects().length && ! (el.type === 'radio' && ! el.checked && this.$refs.panel.querySelector(`input[type=radio][name='${el.name}']:checked`))); },
             trap(e) { const f = this.focusables(); if (! f.length) return; const first = f[0], last = f[f.length - 1]; if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); } else if (! e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); } },
         }"
         @keydown.escape.prevent.stop="hide()"
         @resize.window="open && decide()">
        <button type="button" x-ref="button"
                @click="open ? hide() : show()"
                :aria-expanded="open ? 'true' : 'false'" aria-controls="{{ $panelId }}"
                aria-label="{{ $name }}" title="{{ $name }}"
                class="{{ $pill }} {{ $pillState($active > 0) }}">
            <x-bs-admin::icon name="ri-filter-3" class="{{ $pillIcon($active > 0) }}" />
            <span data-lf-text>{{ $label }}</span>
            @if ($active > 0)
                <span class="inline-flex min-w-5 items-center justify-center rounded-full bg-admin-primary px-1.5 text-caption font-bold leading-5 text-white">{{ $active }}</span>
            @endif
        </button>

        <div x-show="open" x-cloak @click="hide()" class="fixed inset-0 z-60 bg-[rgb(15_18_30/0.45)]" aria-hidden="true"></div>
        <div x-show="open" x-cloak x-ref="panel" id="{{ $panelId }}"
             role="dialog" aria-modal="true" aria-labelledby="{{ $panelId }}-t"
             @keydown.tab="trap($event)"
             class="fixed inset-x-0 bottom-0 z-60 flex max-h-[75dvh] flex-col rounded-t-xl border-t border-border bg-surface text-body shadow-lg">
            <div class="flex shrink-0 items-center gap-2 border-b border-border py-1 pe-1 ps-3.5">
                <p id="{{ $panelId }}-t" class="m-0 min-w-0 flex-1 text-[calc(1rem+var(--user-text-boost))] font-semibold leading-snug text-heading">{{ __('admin.list.filter_panel_title') }}</p>
                <button type="button" @click="hide()"
                        aria-label="{{ __('admin.list.close_filter_panel') }}" title="{{ __('admin.list.close_filter_panel') }}"
                        class="inline-flex size-hit shrink-0 items-center justify-center rounded text-body-muted hover:bg-surface-hover hover:text-heading">
                    <x-bs-admin::icon name="ri-close" class="size-5" />
                </button>
            </div>
            <div class="flex min-h-0 flex-1">
                <nav x-show="cols === 2" aria-label="{{ __('admin.list.filter_sections') }}"
                     class="flex w-[min(16em,36%)] shrink-0 flex-col gap-0.5 overflow-y-auto overflow-x-hidden overscroll-contain border-e border-border bg-surface-muted p-2">
                    @foreach ($view as $i => $section)
                        <button type="button" @click="go({{ $i }})" :aria-current="cur === {{ $i }} ? 'true' : null"
                                aria-label="{{ __('admin.list.go_to_section', ['name' => $section['label']]) }}{{ $section['summary'] !== '' ? ': '.$section['summary'] : '' }}"
                                class="group flex min-h-hit w-full flex-col items-start justify-center gap-0.5 rounded px-3 py-2 text-start transition-colors hover:bg-surface-hover aria-[current=true]:bg-admin-primary/10 aria-[current=true]:font-semibold aria-[current=true]:text-accent">
                            <span class="min-w-0 max-w-full [overflow-wrap:anywhere]">{{ $section['label'] }}</span>
                            @if ($section['summary'] !== '')
                                <span @if ($section['all']) data-all @endif @class([
                                    'min-w-0 max-w-full text-small font-normal [overflow-wrap:anywhere] group-aria-[current=true]:text-accent',
                                    'text-body-muted' => $section['all'],
                                    'text-text' => ! $section['all'],
                                ])>{{ $section['summary'] }}</span>
                            @endif
                        </button>
                    @endforeach
                </nav>
                <div x-ref="scroll" @scroll.passive="mark()" class="min-h-0 min-w-0 flex-1 overflow-y-auto overscroll-contain py-1">
                    @foreach ($view as $section)
                        {{-- the divider sits on a wrapper: a fieldset's own top border is cut by its legend --}}
                        <div @class(['border-t border-border' => ! $loop->first])>
                        <fieldset class="m-0 min-w-0 border-0 px-0 pb-2 pt-1">
                            <legend data-sec tabindex="-1" class="flex flex-wrap items-baseline gap-x-2 px-4 pb-1 pt-3 text-small font-semibold text-heading outline-none">
                                <span>{{ $section['label'] }}@if ($section['suffix'] !== '')<span x-show="cols === 1" class="font-normal">{{ $section['suffix'] }}</span>@endif</span>
                                <span class="font-normal text-body-muted">{{ __($section['multiple'] ? 'admin.list.choose_many' : 'admin.list.choose_one') }}</span>
                            </legend>
                            <x-bs-admin::filter-options :section="$section" :name="$panelId.'-'.$section['key']" />
                        </fieldset>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="flex shrink-0 flex-wrap justify-end gap-3 border-t border-border px-3 pt-2 pb-[calc(0.5rem+env(safe-area-inset-bottom))]">
                @if ($clear && $active > 0)
                    <x-bs-admin::button variant="outline" color="secondary" class="min-w-0 flex-1" wire:click="{{ $clear }}">{{ __('admin.list.clear_filters') }}</x-bs-admin::button>
                @endif
                <x-bs-admin::button class="min-w-0 flex-1" @click="hide()">{{ $results ?? __('admin.list.close_filters') }}</x-bs-admin::button>
            </div>
        </div>
    </div>
</div>
