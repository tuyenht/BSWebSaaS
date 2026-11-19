{{-- The product editor when it cannot show the form (design 09, its states, in the board's order): the
     website's plan has no catalog; the member may not see products (or add one); the product could
     not be read (the database busy: «Thử lại», nothing lost); the product is in the Trash («Khôi phục»
     for who may restore it). The trail and the screen's name stay above the card at every width — on a
     phone the admin's top bar is gone on this screen, and the trail is the way back. --}}
@php
    $cards = [
        'plan' => ['icon' => 'ri-lock-2', 'role' => 'status', 'title' => __('catalog.plan_title'), 'text' => __('catalog.plan_text')],
        'noperm' => ['icon' => 'ri-lock-2', 'role' => 'status',
            'title' => __($ulid === null ? 'catalog.ed.noperm_title_new' : 'catalog.ed.noperm_title'),
            'text' => $role !== null ? __('catalog.ed.noperm_text', ['role' => $role]) : __('catalog.ed.noperm_text_plain')],
        'error' => ['icon' => 'ri-wifi-off', 'role' => 'alert', 'title' => __('catalog.ed.error_title'), 'text' => __('catalog.ed.error_text')],
        'trash' => ['icon' => 'ri-delete-bin', 'role' => 'status', 'title' => __('catalog.ed.trash_title'),
            'text' => __($mayRestore ? 'catalog.ed.trash_text_manage' : 'catalog.ed.trash_text_view')],
    ];
    $card = $cards[$screen];
    $back = $screen === 'error' || $screen === 'trash' || ($screen === 'noperm' && $mayList);
@endphp
<div class="catalog-editor @container" data-catalog-editor>
    <div class="mx-auto flex max-w-[80rem] min-w-0 flex-col gap-4 max-[599.98px]:pt-4 [@media(max-height:500px)]:pt-4">
        <header class="flex flex-col gap-1">
            <x-bs-admin::breadcrumb :items="$crumbs" />
            <h1 class="m-0 min-w-0 text-[calc(1.25rem+var(--user-text-boost))] font-semibold leading-snug text-text-strong [overflow-wrap:anywhere]">{{ $title }}</h1>
        </header>

        <x-bs-admin::card flush :role="$card['role']" :data-state="$screen" class="min-w-0 p-6 text-center">
            <x-bs-admin::icon :name="$card['icon']" class="mx-auto size-8 text-text-muted" />
            <h2 class="m-0 mt-2 text-[calc(1.125rem+var(--user-text-boost))] font-semibold text-text-strong">{{ $card['title'] }}</h2>
            <p class="m-0 mx-auto mt-1 max-w-[40rem] text-body text-text-muted">{{ $card['text'] }}</p>
            <div class="mt-4 flex flex-wrap justify-center gap-2">
                @if ($screen === 'error')
                    <x-bs-admin::button color="primary" wire:click="retry" wire:loading.attr="disabled" wire:target="retry">
                        <x-bs-admin::icon name="ri-refresh" class="size-4" /> {{ __('catalog.error_retry') }}
                    </x-bs-admin::button>
                @elseif ($screen === 'trash' && $mayRestore)
                    <x-bs-admin::button color="primary" wire:click="restore" wire:loading.attr="disabled" wire:target="restore,undoLast">
                        <x-bs-admin::icon name="ri-arrow-go-back" class="size-4" /> {{ __('catalog.restore_action') }}
                    </x-bs-admin::button>
                @elseif ($screen === 'plan' && $supportMail !== '')
                    <x-bs-admin::button variant="outline" color="secondary" :href="'mailto:'.$supportMail">
                        <x-bs-admin::icon name="ri-customer-service-2" class="size-4" /> {{ __('catalog.plan_support') }}
                    </x-bs-admin::button>
                @endif
                @if ($back)
                    <x-bs-admin::button variant="outline" color="secondary" :href="route('admin.catalog.index')">
                        <x-bs-admin::icon name="ri-arrow-left" class="size-4" /> {{ __('catalog.ed.state_back') }}
                    </x-bs-admin::button>
                @endif
            </div>
        </x-bs-admin::card>
    </div>

    <x-bs-admin::toast :message="$toastMsg" :seq="$toastSeq" dismiss="closeToast" :undo="$undo !== null ? 'undoLast' : null" />
</div>
