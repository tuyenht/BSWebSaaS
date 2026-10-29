@props([
    'sections' => [],
    'active' => 0,
    'results' => null,
    'clear' => null,
    'label' => null,
])
@php
    // The list Filter panel (canvas [data-lf] / .lf-*, K69): one "Lọc" button that looks pressable
    // (tinted fill + border + icon + text, never bare text) and opens every filter section of the
    // list. Status groups come first when the list has them (they live here below 900px, K69),
    // "Hiện trên thẻ" last. Each section is a fieldset of native radios (one choice) or
    // checkboxes (several), bound live to the host Livewire property, each option with its count.
    //
    // $sections: list<array{key: string, label: string, model: string, multiple?: bool,
    //            options: list<array{value: string, label: string, count?: int|null}>}>
    // $active: how many conditions are on (shown on the button and in its name).
    // $results: the footer button text, e.g. "Xem 12 dòng" — the filters apply as they change,
    //           so this button only closes the panel and takes the user to the list.
    // $clear: the host's public method that resets every filter (shown when $active > 0).
    //
    // Keyboard: Enter/Space opens and focuses the first option, Tab stays inside the panel,
    // Esc or a click outside closes it and focus returns to the button. Below 600px the panel is a
    // sheet from the bottom of the screen; from 600px it hangs under the button, never wider than
    // the screen, and scrolls inside itself when tall.
    $label ??= __('admin.list.filter');
    $name = $active > 0 ? __('admin.list.filter_active', ['n' => $active]) : $label;
    $panelId = 'lf-'.\Illuminate\Support\Str::random(8);
@endphp
<div
    x-data="{
        open: false,
        // x-show reveals the panel a moment after open turns true, so wait until it is on screen before focusing
        show() { this.open = true; this.whenShown(() => this.focusables()[0]?.focus()); },
        whenShown(fn, tries = 20) { this.$refs.panel.getClientRects().length || ! tries ? fn() : setTimeout(() => this.whenShown(fn, tries - 1), 16); },
        hide(back = true) { if (! this.open) return; this.open = false; if (back) this.$refs.button.focus(); },
        focusables() { return [...this.$refs.panel.querySelectorAll('input:not([disabled]),button:not([disabled])')].filter((el) => el.offsetParent !== null && ! (el.type === 'radio' && ! el.checked && this.$refs.panel.querySelector(`input[type=radio][name='${el.name}']:checked`))); },
        trap(e) { const f = this.focusables(); if (! f.length) return; const first = f[0], last = f[f.length - 1]; if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); } else if (! e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); } },
    }"
    @keydown.escape.prevent.stop="hide()"
    @click.outside="hide(false)"
    {{ $attributes->merge(['class' => 'relative shrink-0']) }}
>
    <button type="button" x-ref="button"
            @click="open ? hide() : show()"
            :aria-expanded="open ? 'true' : 'false'" aria-controls="{{ $panelId }}"
            aria-label="{{ $name }}" title="{{ $name }}"
            @class([
                'hit-area inline-flex min-h-control items-center gap-1.5 rounded border px-3 text-body font-medium transition-colors pointer-coarse:min-h-hit',
                'border-accent bg-admin-primary/10 text-accent' => $active > 0,
                'border-border-input bg-admin-primary/5 text-text hover:bg-admin-primary/10' => $active === 0,
            ])>
        <x-bs-admin::icon name="ri-filter-3" class="size-4 shrink-0" />
        <span data-lf-text>{{ $label }}</span>
        @if ($active > 0)
            <span class="inline-flex min-w-5 items-center justify-center rounded-full bg-admin-primary px-1.5 text-caption font-bold leading-5 text-white">{{ $active }}</span>
        @endif
    </button>

    <div x-show="open" x-cloak x-ref="panel" id="{{ $panelId }}"
         role="dialog" aria-label="{{ $label }}"
         @keydown.tab="trap($event)"
         class="fixed inset-x-0 bottom-0 z-60 flex max-h-[85dvh] flex-col rounded-t-lg border border-border bg-surface shadow-lg sm:absolute sm:inset-x-auto sm:end-0 sm:bottom-auto sm:top-full sm:mt-1.5 sm:max-h-[min(27.5rem,70vh)] sm:w-max sm:min-w-60 sm:max-w-[min(21.25rem,calc(100vw-1.5rem))] sm:rounded-md">
        <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain py-1">
            @foreach ($sections as $section)
                <fieldset @class(['min-w-0 border-0 px-0 pb-2 pt-1', 'border-t border-border' => ! $loop->first])>
                    <legend class="px-4 pb-1 pt-3 text-small font-semibold text-heading">{{ $section['label'] }}</legend>
                    @foreach ($section['options'] as $option)
                        <label class="flex min-h-hit cursor-pointer items-center gap-2.5 px-4 py-1.5 text-body text-text hover:bg-surface-hover has-checked:font-bold pointer-coarse:min-h-12">
                            <input type="{{ ($section['multiple'] ?? false) ? 'checkbox' : 'radio' }}"
                                   name="{{ $panelId }}-{{ $section['key'] }}"
                                   value="{{ $option['value'] }}"
                                   wire:model.live="{{ $section['model'] }}"
                                   class="size-4.5 shrink-0 accent-accent">
                            <span class="min-w-0 flex-1 [overflow-wrap:anywhere]">{{ $option['label'] }}</span>
                            @if (isset($option['count']))
                                <span class="shrink-0 text-small font-normal text-body-muted">{{ \Illuminate\Support\Number::format($option['count'], locale: app()->getLocale()) }}</span>
                            @endif
                        </label>
                    @endforeach
                </fieldset>
            @endforeach
        </div>
        <div class="flex flex-wrap justify-end gap-3 border-t border-border px-3 py-2">
            @if ($clear && $active > 0)
                <x-bs-admin::button variant="outline" color="secondary" class="min-w-0 flex-1" wire:click="{{ $clear }}">{{ __('admin.list.clear_filters') }}</x-bs-admin::button>
            @endif
            <x-bs-admin::button class="min-w-0 flex-1" @click="hide()">{{ $results ?? __('admin.list.close_filters') }}</x-bs-admin::button>
        </div>
    </div>
</div>
