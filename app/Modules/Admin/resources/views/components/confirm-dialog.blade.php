@props([
    'model',
    'title' => '',
    'icon' => 'ri-information',
    'danger' => false,
    'ok' => '',
    'confirm',
    'cancel',
])
@php
    // A question before a change (design 21 .tm-dlg.sm, role=alertdialog): an icon box, the
    // question as its title, one line per consequence (the slot: <x-bs-admin::confirm-line>), then
    // [Hủy] and the change. Hủy takes the focus first, so Enter never makes the change by
    // accident; Escape and the backdrop cancel. A change that cannot be undone is `danger` (the
    // red button with the bin) and says so in its lines. `model` is the host's open flag
    // (entangled); `confirm` and `cancel` are the host's public methods. Below 600px it is a sheet
    // from the bottom with its buttons stacked, the change on top.
    $tid = 'confirm-title-'.substr(md5($model), 0, 8);
    $did = 'confirm-lines-'.substr(md5($model), 0, 8);
@endphp
<div x-data="{ open: $wire.entangle('{{ $model }}').live }"
     x-effect="document.body.style.overflow = open ? 'hidden' : ''"
     @keydown.escape.window="open && $wire.{{ $cancel }}()">
    <div x-show="open" x-cloak class="fixed inset-0 z-90 flex items-end justify-center min-[600px]:items-center min-[600px]:p-4">
        <div x-show="open" x-transition.opacity @click="$wire.{{ $cancel }}()" class="absolute inset-0 bg-gray-900/45"></div>

        <div x-show="open" x-transition x-trap="open"
             role="alertdialog" aria-modal="true" aria-labelledby="{{ $tid }}" aria-describedby="{{ $did }}"
             class="relative flex max-h-[92dvh] w-full flex-col rounded-t-xl bg-surface shadow-lg min-[600px]:max-h-[calc(100dvh-2rem)] min-[600px]:max-w-120 min-[600px]:rounded-md">
            <div class="flex min-h-0 flex-1 flex-col gap-2.5 overflow-auto px-5.5 pt-5 pb-4">
                <div class="flex items-center gap-3">
                    <span @class([
                        'inline-flex size-9 shrink-0 items-center justify-center rounded-lg',
                        'bg-danger-soft text-danger-text' => $danger,
                        'bg-primary-soft text-primary-text' => ! $danger,
                    ])>
                        <x-bs-admin::icon :name="$icon" class="size-[min(1.5rem,calc(1.125rem+var(--user-text-boost)))]" />
                    </span>
                    <h2 id="{{ $tid }}" class="m-0 text-[calc(1rem+var(--user-text-boost))] font-semibold leading-snug text-text-strong [overflow-wrap:anywhere]">{{ $title }}</h2>
                </div>
                <div id="{{ $did }}" class="flex flex-col gap-1.5 text-body leading-relaxed">{{ $slot }}</div>
            </div>
            <div class="flex flex-wrap justify-end gap-2 border-t border-border bg-surface-muted px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] max-[599.98px]:flex-col-reverse max-[599.98px]:*:w-full">
                <x-bs-admin::button variant="outline" color="secondary" autofocus wire:click="{{ $cancel }}">{{ __('admin.cancel') }}</x-bs-admin::button>
                <x-bs-admin::button :color="$danger ? 'danger' : 'primary'" wire:click="{{ $confirm }}">
                    @if ($danger)<x-bs-admin::icon name="ri-delete-bin" class="size-[calc(1rem+var(--user-text-boost))]" />@endif
                    <span>{{ $ok }}</span>
                </x-bs-admin::button>
            </div>
        </div>
    </div>
</div>
