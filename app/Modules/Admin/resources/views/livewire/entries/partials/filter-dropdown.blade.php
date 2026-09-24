@props(['group', 'label', 'options', 'activeCount', 'icon' => null, 'heading' => null])
{{-- A multi-select facet dropdown (design §3a). Options carry their live count; OR within the
     group, AND across groups is enforced server-side. Open/close is client-side (Alpine). The
     optional `heading` renders as an uppercase label above the option list (design line 61). --}}
<div x-data="{ open: false }" @click.outside="open = false" class="relative">
    <button type="button" @click="open = !open"
        @class([
            'inline-flex items-center gap-2 rounded border px-3 py-2 text-sm font-medium transition-colors',
            'border-admin-primary bg-admin-primary/5 text-admin-primary' => $activeCount > 0,
            'border-border-strong bg-surface text-body-text hover:border-admin-primary' => $activeCount === 0,
        ])>
        @if ($icon)
            <x-admin::icon :name="$icon" class="size-4" />
        @endif
        {{ $label }}
        @if ($activeCount > 0)
            <span class="rounded bg-admin-primary px-1.5 text-xs font-semibold leading-5 text-white">{{ $activeCount }}</span>
        @endif
        <x-admin::icon name="chevron-down" class="size-3.5 transition-transform" ::class="open && 'rotate-180'" />
    </button>
    <div x-show="open" x-cloak x-transition
        class="absolute left-0 z-30 mt-1 max-h-72 w-60 overflow-auto rounded-lg border border-border bg-surface p-1.5 shadow-lg">
        @if ($heading)
            <p class="px-2 pb-1 pt-1.5 text-xs font-semibold uppercase tracking-wide text-placeholder">{{ $heading }}</p>
        @endif
        @forelse ($options as $opt)
            <button type="button" wire:click="toggleFilterValue('{{ $group }}', '{{ $opt['value'] }}')"
                class="flex w-full items-center gap-2.5 rounded px-2 py-1.5 text-left text-sm hover:bg-surface-hover">
                <span @class([
                    'flex size-4 shrink-0 items-center justify-center rounded border',
                    'border-admin-primary bg-admin-primary text-white' => $opt['active'],
                    'border-border-strong' => ! $opt['active'],
                ])>
                    @if ($opt['active'])<x-admin::icon name="ri-check" class="size-3" />@endif
                </span>
                <span @class(['flex-1 truncate', 'font-medium text-admin-primary' => $opt['active'], 'text-body-text' => ! $opt['active']])>{{ $opt['label'] }}</span>
                <span class="text-xs text-placeholder">{{ $opt['count'] }}</span>
            </button>
        @empty
            <p class="px-2 py-3 text-center text-xs text-placeholder">{{ __('entries.filter_none') }}</p>
        @endforelse
        @if (count($options) > 0)
            <div class="mt-1 flex items-center justify-between border-t border-border px-1 pt-1.5">
                <button type="button" wire:click="resetFilterGroup('{{ $group }}')" class="rounded px-1.5 py-1 text-xs text-body-muted hover:text-body-text">{{ __('entries.filter_reset') }}</button>
                <button type="button" @click="open = false" class="rounded px-1.5 py-1 text-xs font-medium text-admin-primary">{{ __('entries.filter_done') }}</button>
            </div>
        @endif
    </div>
</div>
