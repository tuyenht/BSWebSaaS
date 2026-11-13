{{--
    The platform console's bell (design 01 "Thông báo", the platform plane): the same panel as a
    website's bell (admin::livewire.notification-bell, the .ntf-* part and the bsBell Alpine part),
    with the board's "Chờ backend" note in its head. The console has no notification store yet, so
    the panel shows the empty state: no badge, no list, no request to a server.
--}}
<div class="relative" x-ignore data-bs-lazy="bsBell" x-data="bsBell"
     x-on:keydown.escape="close(true)" x-on:focusout="leave($event)" x-on:click.outside="close(false)">
    <button type="button" x-ref="bell" data-bs-lazy-retry x-on:click="toggle()"
            aria-haspopup="dialog" aria-controls="bs-bell-panel" aria-expanded="false" x-bind:aria-expanded="open ? 'true' : 'false'"
            aria-label="{{ __('admin.bell.aria_none') }}" title="{{ __('admin.notifications') }}"
            class="relative grid size-control place-items-center rounded text-topbar-fg any-pointer-coarse:size-hit transition-colors hover:bg-topbar-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-topbar-fg">
        <x-bs-admin::icon name="ri-notification-3" class="size-5" />
    </button>

    <div id="bs-bell-panel" x-ref="panel" x-show="open" x-cloak role="dialog" aria-label="{{ __('admin.notifications') }}" tabindex="-1" class="ntf-pop"
         x-trap.inert.noscroll.noautofocus.noreturn="open && narrow" x-bind:aria-modal="open && narrow ? 'true' : null">
        <div class="ntf-h">
            <span class="ntf-ht">{{ __('admin.notifications') }}</span>
            <x-bs-admin::button icon variant="ghost" color="secondary" size="sm" x-on:click="close(true)"
                                aria-label="{{ __('admin.bell.close') }}" title="{{ __('admin.bell.close_tip') }}">
                <x-bs-admin::icon name="ri-close" class="size-5" />
            </x-bs-admin::button>
        </div>
        <div class="ntf-list">
            <div class="ntf-empty">
                <x-bs-admin::icon name="ri-notification-off" class="size-7" />
                <span>{{ __('admin.notifications_empty') }}</span>
            </div>
        </div>
    </div>
</div>
