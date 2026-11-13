{{--
    The top bar's bell (design 01 "Thông báo", .ntf-*): the unread badge on every page, the panel
    under the bell (a full screen below 600px). Alpine part bsBell (resources/js/admin-bell.js,
    started by the shared lazy loader): opens and closes, moves focus into the panel and back to the
    bell (Esc, "Đóng", a click outside, Tab past the end), asks for the list the first time and says
    politely when it has arrived, and inserts the shared error alert with "Thử lại" when a request is
    lost (inserted, so a screen reader reads it out). Below 600px the panel covers the screen and is
    modal: the focus stays inside and the page behind holds still (the shared x-trap, as the modal);
    wider, it stays a non-modal popover. Until the part has loaded, a click on the bell after a lost
    download tries again (data-bs-lazy-retry).
--}}
<div class="relative" x-ignore wire:ignore.self data-bs-lazy="bsBell" x-data="bsBell" data-loaded="{{ __('admin.bell.loaded') }}"
     x-on:keydown.escape="close(true)" x-on:focusout="leave($event)" x-on:click.outside="close(false)">
    <button type="button" x-ref="bell" data-bs-lazy-retry x-on:click="toggle()"
            aria-haspopup="dialog" aria-controls="bs-bell-panel" aria-expanded="false" x-bind:aria-expanded="open ? 'true' : 'false'"
            aria-label="{{ $unread > 0 ? __('admin.bell.aria', ['count' => $badge]) : __('admin.bell.aria_none') }}" title="{{ __('admin.notifications') }}"
            class="relative grid size-control place-items-center rounded text-topbar-fg any-pointer-coarse:size-hit transition-colors hover:bg-topbar-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-topbar-fg">
        <x-bs-admin::icon name="ri-notification-3" class="size-5" />
        @if ($unread > 0)
            <span class="ntf-badge" aria-hidden="true">{{ $badge }}</span>
        @endif
    </button>

    <div id="bs-bell-panel" x-ref="panel" x-show="open" x-cloak role="dialog" aria-label="{{ __('admin.notifications') }}" tabindex="-1" class="ntf-pop"
         x-trap.inert.noscroll.noautofocus.noreturn="open && narrow" x-bind:aria-modal="open && narrow ? 'true' : null">
        <div class="ntf-h">
            <span class="ntf-ht">{{ __('admin.notifications') }}</span>
            @if ($unread > 0)
                <x-bs-admin::badge soft pill>{{ __('admin.bell.unread', ['count' => $badge]) }}</x-bs-admin::badge>
            @endif
            <x-bs-admin::button icon variant="ghost" color="secondary" size="sm" x-on:click="close(true)"
                                aria-label="{{ __('admin.bell.close') }}" title="{{ __('admin.bell.close_tip') }}">
                <x-bs-admin::icon name="ri-close" class="size-5" />
            </x-bs-admin::button>
        </div>

        @if ($loaded && $unread > 0)
            <div class="ntf-tools">
                <x-bs-admin::button variant="ghost" size="sm" wire:click="readAll"
                                    :aria-disabled="$supporting ? 'true' : null"
                                    title="{{ $supporting ? __('admin.bell.read_all_support') : __('admin.bell.read_all') }}">
                    <x-bs-admin::icon name="ri-check-double" class="size-4" /><span>{{ __('admin.bell.read_all') }}</span>
                </x-bs-admin::button>
            </div>
        @endif

        <div class="ntf-list">
            <p class="sr-only" role="status" wire:ignore x-text="said"></p>
            <template x-if="failed">
                <x-bs-admin::alert color="danger" icon="ri-wifi-off" class="m-4">
                    <span class="flex flex-wrap items-center gap-x-3 gap-y-2">
                        <span class="min-w-0 flex-[1_1_200px]" x-text="failed === 'load' ? @js(__('admin.bell.error_load')) : @js(__('admin.bell.error_save'))"></span>
                        <x-bs-admin::button variant="outline" color="danger" size="sm" x-on:click="again()">
                            <x-bs-admin::icon name="ri-refresh" class="size-4" /><span>{{ __('admin.bell.retry') }}</span>
                        </x-bs-admin::button>
                    </span>
                </x-bs-admin::alert>
            </template>

            @if (! $loaded)
                <div x-show="failed !== 'load'" aria-busy="true">
                    <span class="sr-only">{{ __('admin.bell.loading') }}</span>
                    @foreach (['78%', '64%', '86%'] as $width)
                        <div class="ntf-sk"><span class="ntf-skb ntf-skr"></span><span class="ntf-skl"><span class="ntf-skb" style="width: {{ $width }}"></span><span class="ntf-skb ntf-sks"></span></span></div>
                    @endforeach
                </div>
            @elseif ($days === [])
                <div class="ntf-empty">
                    <x-bs-admin::icon name="ri-notification-off" class="size-7" />
                    <span>{{ __('admin.notifications_empty') }}</span>
                </div>
            @else
                @foreach ($days as $day)
                    <div class="ntf-day">{{ $day['label'] }}</div>
                    @foreach ($day['items'] as $item)
                        @php($tag = $item['url'] !== null ? 'a' : 'button')
                        <{{ $tag }} wire:key="ntf-{{ $item['ulid'] }}" class="ntf-it" data-unread="{{ $item['unread'] ? '1' : '0' }}"
                            @if ($item['url'] !== null) href="{{ $item['url'] }}" x-on:click="follow($event, '{{ $item['ulid'] }}')" @else type="button" wire:click="open('{{ $item['ulid'] }}')" @endif>
                            <span class="ntf-ic" data-t="{{ $item['tone'] }}"><x-bs-admin::icon :name="$item['icon']" class="size-[1.0625em]" /></span>
                            <span class="ntf-m">
                                <span class="ntf-tx">{{ $item['text'] }}</span>
                                <span class="ntf-meta">
                                    @if ($item['unread'])
                                        <span class="ntf-new">{{ __('admin.bell.new') }}</span>
                                    @endif
                                    <span>{{ $item['ago'] }}</span>
                                    @if ($item['act'] !== null)
                                        <span class="ntf-act">{{ $item['act'] }}<x-bs-admin::icon name="ri-arrow-right-s" class="size-4" /></span>
                                    @endif
                                </span>
                            </span>
                        </{{ $tag }}>
                    @endforeach
                @endforeach
            @endif
        </div>
        <div class="ntf-f">
            <a href="{{ route('admin.notifications') }}" wire:navigate x-on:click="close(false)"
               class="hit-area inline-flex min-h-control w-full items-center justify-center gap-1.5 rounded border border-border-input bg-surface px-3.5 py-1.5 text-body font-medium leading-tight text-text transition-colors hover:bg-surface-hover hover:text-text-strong">{{ __('admin.bell.see_all') }}</a>
        </div>
    </div>
</div>
