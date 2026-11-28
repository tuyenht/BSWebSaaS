@props([
    'tabs' => [],
    'active' => null,
    'label',
    'panel',
    // the tabs' id prefix: tab "x" is "{ids}-x" (default "{panel}-tab")
    'ids' => null,
    // what a tab's click does: `action` names the host Livewire method that takes the tab's key,
    // otherwise `model` names the host property it sets
    'action' => null,
    'model' => null,
])
@php
    // A row of tabs, each with its count (canvas .dli-tabs / .dli-tab .n, «Thông báo» c-thong-bao),
    // drawn by the server: a click calls the host's `action` with the tab's key (or sets `model`). They
    // are ARIA tabs over one panel: the host wraps the tab's content in role="tabpanel" with id $panel,
    // labelled by the chosen tab. ← → Home End move between the tabs and open the one reached. Any
    // number of tabs: the row never wraps, it scrolls sideways with no scroll bar drawn (a focused tab
    // scrolls into view), as the board's. Names never break: a long one is the row's reason to scroll.
    // The row's bottom line is an inner shadow, under the chosen tab's 2px line: a negative margin would
    // make the scrolling row scroll up and down by a pixel.
    //
    // $tabs: list<array{key: string, label: string, count?: int|string|null}>; a string count is shown as given ("99+")
    $ids ??= $panel.'-tab';
    $num = fn (int|string|null $n): ?string => is_int($n) ? \Illuminate\Support\Number::format($n, locale: app()->getLocale()) : $n;
    $click = fn (string $key): string => $action !== null
        ? $action.'('.\Illuminate\Support\Js::from($key).')'
        : '$set('.\Illuminate\Support\Js::from($model).', '.\Illuminate\Support\Js::from($key).')';
@endphp
<div role="tablist" aria-label="{{ $label }}"
     x-on:keydown="if (! ['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes($event.key)) return; const t = [...$el.querySelectorAll('[role=tab]')], i = t.indexOf(document.activeElement); if (i < 0) return; const k = $event.key, n = k === 'Home' ? 0 : k === 'End' ? t.length - 1 : (i + (k === 'ArrowRight' ? 1 : -1) + t.length) % t.length; $event.preventDefault(); t[n].focus(); t[n].click()"
     {{ $attributes->merge(['class' => 'flex min-w-0 items-end gap-0.5 overflow-x-auto shadow-[inset_0_-1px_0_var(--color-border)] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden']) }}>
    @foreach ($tabs as $tab)
        @php $on = $tab['key'] === $active; @endphp
        <button type="button" role="tab" id="{{ $ids }}-{{ $tab['key'] }}" aria-controls="{{ $panel }}" title="{{ $tab['label'] }}"
                aria-selected="{{ $on ? 'true' : 'false' }}" tabindex="{{ $on ? '0' : '-1' }}"
                wire:key="{{ $ids }}-{{ $tab['key'] }}" wire:click="{{ $click($tab['key']) }}"
                @class([
                    'inline-flex min-h-control shrink-0 items-center gap-1.5 whitespace-nowrap border-b-2 px-2.5 text-body transition-colors any-pointer-coarse:min-h-hit @max-[37.4375rem]:gap-1 @max-[37.4375rem]:px-2',
                    'border-transparent font-medium text-text-muted hover:text-text-strong' => ! $on,
                    'border-primary-text font-semibold text-primary-text' => $on,
                ])>
            <span>{{ $tab['label'] }}</span>
            @if (($n = $num($tab['count'] ?? null)) !== null)
                <x-bs-admin::tab-count :on="$on">{{ $n }}</x-bs-admin::tab-count>
            @endif
        </button>
    @endforeach
</div>
