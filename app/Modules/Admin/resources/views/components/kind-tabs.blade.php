@props([
    'tabs' => [],
    'active' => null,
    'model',
    'label',
    'panel',
])
@php
    // Tabs that switch the KIND of record a screen shows (canvas .sx-tabs3: "Thành viên" / "Lời mời"),
    // drawn by the server: a click sets the host Livewire property $model. Unlike the status-group row
    // (group-row, aria-pressed filters of one list), these are ARIA tabs over one panel: the host wraps
    // its content in role="tabpanel" with id $panel, labelled by the chosen tab (id "{$panel}-tab-{key}").
    // ← → Home End move between tabs and open the one reached. At most three; below 600 they share
    // the row evenly and their names wrap, never cut.
    //
    // $tabs: list<array{key: string, label: string, count?: int|null, cap?: int}>; a count above its cap reads "{cap}+"
    $fmt = fn (?int $n, ?int $cap = null): ?string => $n === null ? null
        : ($cap !== null && $n > $cap ? \Illuminate\Support\Number::format($cap, locale: app()->getLocale()).'+' : \Illuminate\Support\Number::format($n, locale: app()->getLocale()));
    $setter = fn (string $key): string => '$set('.\Illuminate\Support\Js::from($model).', '.\Illuminate\Support\Js::from($key).')';
@endphp
<div role="tablist" aria-label="{{ $label }}"
     x-on:keydown="if (! ['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes($event.key)) return; const t = [...$el.querySelectorAll('[role=tab]')], i = Math.max(0, t.indexOf(document.activeElement)), k = $event.key, n = k === 'Home' ? 0 : k === 'End' ? t.length - 1 : (i + (k === 'ArrowRight' ? 1 : -1) + t.length) % t.length; $event.preventDefault(); t[n].focus(); t[n].click()"
     {{ $attributes->merge(['class' => 'flex gap-1 border-b border-border']) }}>
    @foreach ($tabs as $tab)
        @php $on = $tab['key'] === $active; @endphp
        <button type="button" role="tab" id="{{ $panel }}-tab-{{ $tab['key'] }}" aria-controls="{{ $panel }}"
                aria-selected="{{ $on ? 'true' : 'false' }}" tabindex="{{ $on ? '0' : '-1' }}"
                wire:click="{{ $setter($tab['key']) }}"
                @class([
                    '-mb-px inline-flex min-h-control min-w-0 items-center gap-1.5 border-b-2 px-3 text-body transition-colors any-pointer-coarse:min-h-hit max-[599.98px]:flex-[1_1_0] max-[599.98px]:justify-center max-[599.98px]:text-center',
                    'border-transparent font-medium text-text-muted hover:text-text-strong' => ! $on,
                    'border-primary-text font-semibold text-primary-text' => $on,
                ])>
            <span class="[overflow-wrap:anywhere]">{{ $tab['label'] }}</span>
            @if (($n = $fmt($tab['count'] ?? null, $tab['cap'] ?? null)) !== null)
                <span class="inline-flex min-w-5 shrink-0 items-center justify-center rounded-full bg-surface-hover px-1.5 text-caption font-semibold leading-normal text-text">{{ $n }}</span>
            @endif
        </button>
    @endforeach
</div>
