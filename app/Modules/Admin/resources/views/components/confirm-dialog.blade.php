@props([
    'model' => null,
    'state' => null,
    'title' => '',
    'icon' => 'ri-information',
    'danger' => false,
    'ok' => '',
    'confirm',
    'cancel',
    'cancelLabel' => null,
])
@php
    // A question before a change (design 21 .tm-dlg.sm, role=alertdialog): an icon box, the
    // question as its title, one line per consequence (the slot: <x-bs-admin::confirm-line>), then
    // [Hủy] and the change. Hủy takes the focus first, so Enter never makes the change by
    // accident; Escape and the backdrop cancel. A change that cannot be undone is `danger` (the
    // red button with the bin) and says so in its lines. `model` is the host's open flag
    // (entangled); `confirm` and `cancel` are the host's public methods. Below 600px it is a sheet
    // from the bottom with its buttons stacked, the change on top. The `alt` slot holds a second
    // way through (buttons between Hủy and the change). `cancel-label` renames Hủy where the
    // question itself is about cancelling something ("Giữ lời mời").
    //
    // A screen that keeps its state in the browser passes `state` instead of `model`: an Alpine
    // expression for its question object { title, ok, danger } — the dialog is open while it is set
    // and draws its title, its change button and its danger look from it; `confirm` and `cancel`
    // are then Alpine calls ("askOk()"). Focus goes back to where it was when the dialog closes.
    $alpine = $state !== null;
    $seed = substr(md5((string) ($model ?? $state)), 0, 8);
    $tid = 'confirm-title-'.$seed;
    $did = 'confirm-lines-'.$seed;
    $cancelCall = $alpine ? $cancel : '$wire.'.$cancel.'()';
    $data = $alpine ? '{ get open() { return !!('.$state.'); } }' : "{ open: \$wire.entangle('".$model."').live }";
@endphp
<div x-data="{{ $data }}"
     x-effect="document.body.style.overflow = open ? 'hidden' : ''"
     @keydown.escape.window="open && {{ $cancelCall }}">
    <div x-show="open" x-cloak class="fixed inset-0 z-90 flex items-end justify-center min-[600px]:items-center min-[600px]:p-4">
        <div x-show="open" x-transition.opacity @click="{{ $cancelCall }}" class="absolute inset-0 bg-gray-900/45"></div>

        <div x-show="open" x-transition x-trap="open"
             role="alertdialog" aria-modal="true" aria-labelledby="{{ $tid }}" aria-describedby="{{ $did }}"
             class="relative flex max-h-[92dvh] w-full flex-col rounded-t-xl bg-surface shadow-lg min-[600px]:max-h-[calc(100dvh-2rem)] min-[600px]:max-w-120 min-[600px]:rounded-md">
            <div class="flex min-h-0 flex-1 flex-col gap-2.5 overflow-auto px-5.5 pt-5 pb-4">
                <div class="flex items-center gap-3">
                    @if ($alpine)
                        <span class="inline-flex size-9 shrink-0 items-center justify-center rounded-lg"
                              x-bind:class="({{ $state }})?.danger ? 'bg-danger-soft text-danger-text' : 'bg-primary-soft text-primary-text'">
                            <x-bs-admin::icon :name="$icon" class="size-[min(1.5rem,calc(1.125rem+var(--user-text-boost)))]" />
                        </span>
                        <h2 id="{{ $tid }}" class="m-0 text-[calc(1rem+var(--user-text-boost))] font-semibold leading-snug text-text-strong [overflow-wrap:anywhere]" x-text="({{ $state }})?.title"></h2>
                    @else
                        <span @class([
                            'inline-flex size-9 shrink-0 items-center justify-center rounded-lg',
                            'bg-danger-soft text-danger-text' => $danger,
                            'bg-primary-soft text-primary-text' => ! $danger,
                        ])>
                            <x-bs-admin::icon :name="$icon" class="size-[min(1.5rem,calc(1.125rem+var(--user-text-boost)))]" />
                        </span>
                        <h2 id="{{ $tid }}" class="m-0 text-[calc(1rem+var(--user-text-boost))] font-semibold leading-snug text-text-strong [overflow-wrap:anywhere]">{{ $title }}</h2>
                    @endif
                </div>
                <div id="{{ $did }}" class="flex flex-col gap-1.5 text-body leading-relaxed">{{ $slot }}</div>
            </div>
            <div class="flex flex-wrap justify-end gap-2 border-t border-border bg-surface-muted px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] max-[599.98px]:flex-col-reverse max-[599.98px]:*:w-full">
                @if ($alpine)
                    <x-bs-admin::button variant="outline" color="secondary" autofocus x-on:click="{{ $cancel }}">{{ $cancelLabel ?? __('admin.cancel') }}</x-bs-admin::button>
                    {{ $alt ?? '' }}
                    {{-- two buttons, so a danger confirm keeps the danger colours for hover and press too --}}
                    <x-bs-admin::button x-show="!({{ $state }})?.danger" x-on:click="{{ $confirm }}">
                        <span x-text="({{ $state }})?.ok || @js(__('admin.confirm'))">{{ __('admin.confirm') }}</span>
                    </x-bs-admin::button>
                    <x-bs-admin::button color="danger" x-show="({{ $state }})?.danger" x-cloak x-on:click="{{ $confirm }}">
                        <x-bs-admin::icon name="ri-delete-bin" class="size-[calc(1rem+var(--user-text-boost))]" />
                        <span x-text="({{ $state }})?.ok || @js(__('admin.confirm'))">{{ __('admin.confirm') }}</span>
                    </x-bs-admin::button>
                @else
                    <x-bs-admin::button variant="outline" color="secondary" autofocus wire:click="{{ $cancel }}">{{ $cancelLabel ?? __('admin.cancel') }}</x-bs-admin::button>
                    {{ $alt ?? '' }}
                    <x-bs-admin::button :color="$danger ? 'danger' : 'primary'" wire:click="{{ $confirm }}">
                        @if ($danger)<x-bs-admin::icon name="ri-delete-bin" class="size-[calc(1rem+var(--user-text-boost))]" />@endif
                        {{-- a host fills its labels only when it opens the dialog: closed, the button still has a name --}}
                        <span>{{ $ok !== '' ? $ok : __('admin.confirm') }}</span>
                    </x-bs-admin::button>
                @endif
            </div>
        </div>
    </div>
</div>
