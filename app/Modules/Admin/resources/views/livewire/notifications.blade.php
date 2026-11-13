{{--
    "Thông báo" — design c-thong-bao, ported onto the admin tokens (Notifications.php; the platform
    console's empty copy: Platform/Notifications.php). The header with "Đánh dấu đã đọc tất cả", a
    card with the tabs (one per group, each with its count), the 90-day line, and the tab's list
    grouped by the site's day, eight more on "Tải thêm". A row opens where it leads (marked read);
    "Đã đọc" marks it read; the shared toast offers "Hoàn tác". The Alpine part bsNotifications
    (resources/js/admin-notifications.js, started by the shared lazy loader) moves between tabs with
    the arrow keys, opens a row's link on a click anywhere in the row, and inserts the shared error
    alert with "Thử lại" when a request is lost. The row column is the card's width (@container).
--}}
@php
    $btn = 'hit-area inline-flex min-h-control max-w-full items-center justify-center gap-1.5 rounded border border-border-input bg-surface px-3.5 py-1.5 text-body font-medium leading-tight text-text transition-colors hover:bg-surface-hover hover:text-text-strong';
    $btnSm = 'hit-area inline-flex min-h-control-row max-w-full items-center justify-center gap-1.5 rounded border border-border-input bg-surface px-2.5 py-1 text-caption font-medium leading-tight text-text transition-colors hover:bg-surface-hover hover:text-text-strong';
    $tones = [
        'info' => 'bg-info-soft text-info-text',
        'warn' => 'bg-warning-soft text-warning-text',
        'danger' => 'bg-danger-soft text-danger-text',
        'ok' => 'bg-success-soft text-success-text',
        'brand' => 'bg-primary-soft text-primary-text',
    ];
    $allOff = $supporting || $unread === 0;
    $totalLabel = $total >= $cap ? ($cap - 1).'+' : (string) $total;
@endphp
<div class="@container/tb flex flex-col gap-4">
    <div class="flex flex-wrap items-start gap-x-4 gap-y-3">
        <header class="min-w-0 flex-[1_1_20rem]">
            <h1 class="text-title font-semibold leading-[1.4] text-text-strong">{{ __('admin.notifications') }}</h1>
            <p class="mt-1 text-body text-text-muted [text-wrap:pretty]">{{ __('admin.ntf.desc') }}</p>
        </header>
        <div class="ml-auto flex max-w-full flex-wrap gap-2 @max-[32.4375rem]/tb:flex-[1_1_100%]">
            <x-bs-admin::button :wire:click="$platform ? null : 'readAll'" class="@max-[32.4375rem]/tb:w-full"
                                :aria-disabled="$allOff ? 'true' : null" :aria-describedby="$allOff ? 'tb-allwhy' : null">
                <x-bs-admin::icon name="ri-check-double" class="size-[calc(1rem+var(--user-text-boost))]" /><span>{{ __('admin.bell.read_all') }}</span>
            </x-bs-admin::button>
        </div>
    </div>

    @if ($supporting)
        <x-bs-admin::alert color="warning" icon="ri-user-shared">{{ __('admin.ntf.support') }}</x-bs-admin::alert>
    @endif

    <section class="overflow-hidden rounded-md border border-border bg-surface shadow-card" aria-label="{{ __('admin.ntf.list') }}"
             x-ignore wire:ignore.self data-bs-lazy="bsNotifications" x-data="bsNotifications">
        <div class="flex flex-wrap items-end gap-x-4 gap-y-2 border-b border-border px-4 pt-2">
            <div role="tablist" aria-label="{{ __('admin.ntf.tabs') }}" x-on:keydown="tabKey($event)"
                 class="-mb-px flex flex-[1_1_100%] overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                @foreach ($tabs as $t)
                    @php($on = $t['key'] === $tab)
                    <button type="button" role="tab" id="tb-tab-{{ $t['key'] }}" aria-controls="tb-panel" title="{{ $t['label'] }}"
                            aria-selected="{{ $on ? 'true' : 'false' }}" tabindex="{{ $on ? 0 : -1 }}"
                            wire:key="tb-tab-{{ $t['key'] }}" wire:click="show('{{ $t['key'] }}')"
                            class="group inline-flex min-h-control shrink-0 items-center gap-1.5 whitespace-nowrap border-b-2 border-transparent px-2.5 text-body font-medium text-text-muted transition-colors hover:text-text-strong aria-selected:border-accent aria-selected:font-semibold aria-selected:text-accent any-pointer-coarse:min-h-hit @max-[37.4375rem]/tb:gap-1 @max-[37.4375rem]/tb:px-2">
                        {{ $t['label'] }}<span class="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-surface-hover px-1.5 text-[calc(0.75rem+var(--user-text-boost))] font-semibold text-text group-aria-selected:bg-[color-mix(in_srgb,var(--color-accent)_12%,var(--color-surface))] group-aria-selected:text-accent">{{ $t['n'] }}</span>
                    </button>
                @endforeach
            </div>
        </div>
        <p class="flex items-center gap-1.5 px-4 py-2 text-small text-text-muted">
            <x-bs-admin::icon name="ri-time" class="size-[calc(1rem+var(--user-text-boost))] shrink-0" /><span>{{ __('admin.ntf.keep') }}</span>
        </p>

        <div id="tb-panel" role="tabpanel" aria-labelledby="tb-tab-{{ $tab }}">
            <template x-if="failed">
                <x-bs-admin::alert color="danger" icon="ri-wifi-off" class="m-4">
                    <span class="flex flex-wrap items-center gap-x-3 gap-y-2">
                        <span class="min-w-0 flex-[1_1_15rem]" x-text="failed === 'load' ? @js(__('admin.bell.error_load')) : @js(__('admin.bell.error_save'))"></span>
                        <button type="button" class="{{ $btn }}" x-on:click="again()">
                            <x-bs-admin::icon name="ri-refresh" class="size-[calc(1rem+var(--user-text-boost))]" /><span>{{ __('admin.bell.retry') }}</span>
                        </button>
                    </span>
                </x-bs-admin::alert>
            </template>

            <div wire:loading.flex wire:target="show" class="hidden flex-col" aria-busy="true">
                <span class="sr-only">{{ __('admin.bell.loading') }}</span>
                @foreach (['78%', '62%', '70%', '54%'] as $width)
                    <div class="grid grid-cols-[2.5rem_minmax(0,1fr)] gap-3 border-t border-border px-4 py-3">
                        <span class="size-10 animate-pulse rounded-full bg-surface-hover"></span>
                        <span class="flex flex-col gap-2"><span class="h-3.5 animate-pulse rounded bg-surface-hover" style="width: {{ $width }}"></span><span class="h-3 w-2/5 animate-pulse rounded bg-surface-hover"></span></span>
                    </div>
                @endforeach
            </div>

            <div wire:loading.remove wire:target="show">
                @if ($empty !== null)
                    <div class="m-4 flex flex-col items-center gap-2 rounded-md border border-dashed border-border-strong px-4 py-8 text-center">
                        <x-bs-admin::icon :name="$empty['icon']" class="size-[calc(2rem+var(--user-text-boost))] text-text-muted" />
                        <p class="text-[calc(1rem+var(--user-text-boost))] font-semibold leading-[1.4] text-text-strong [overflow-wrap:anywhere]">{{ $empty['title'] }}</p>
                        <p class="max-w-[52ch] text-body text-text-muted [text-wrap:pretty]">{{ $empty['desc'] }}</p>
                        @if ($empty['btn'] !== null)
                            @if ($empty['all'])
                                <button type="button" class="{{ $btn }}" wire:click="show('tat-ca')">{{ $empty['btn'] }}</button>
                            @else
                                <a href="{{ $empty['url'] }}" wire:navigate class="{{ $btn }}">{{ $empty['btn'] }}</a>
                            @endif
                        @endif
                    </div>
                @else
                    @foreach ($days as $day)
                        <section aria-label="{{ $day['label'] }}" wire:key="tb-day-{{ $loop->index }}-{{ $day['items'][0]['ulid'] }}">
                            <h2 class="border-t border-border bg-surface-muted px-4 py-2 text-small font-semibold text-text-muted">{{ $day['label'] }}</h2>
                            @foreach ($day['items'] as $item)
                                <div wire:key="tb-{{ $item['ulid'] }}" data-unread="{{ $item['unread'] ? '1' : '0' }}" x-on:click="row($event, '{{ $item['ulid'] }}')"
                                     @class([
                                         'grid cursor-pointer grid-cols-[2.5rem_minmax(0,1fr)_auto] items-start gap-3 border-t border-border px-4 py-3 transition-colors hover:bg-surface-hover',
                                         '@max-[37.4375rem]/tb:grid-cols-[2.25rem_minmax(0,1fr)] @max-[37.4375rem]/tb:gap-x-3 @max-[37.4375rem]/tb:gap-y-2 @max-[37.4375rem]/tb:p-3',
                                         'bg-[color-mix(in_srgb,var(--color-accent)_5%,var(--color-surface))]' => $item['unread'],
                                         'bg-surface' => ! $item['unread'],
                                     ])>
                                    <span class="inline-flex size-10 shrink-0 items-center justify-center rounded-full @max-[37.4375rem]/tb:size-9 {{ $tones[$item['tone']] ?? $tones['info'] }}" aria-hidden="true">
                                        <x-bs-admin::icon :name="$item['icon']" class="size-[calc(1.1875rem+var(--user-text-boost))]" />
                                    </span>
                                    <div class="min-w-0">
                                        <p @class(['text-text-strong [overflow-wrap:anywhere] [text-wrap:pretty]', 'font-semibold' => $item['unread']])>{{ $item['text'] }}</p>
                                        @if ($item['note'] !== '')
                                            <p class="mt-1.5 rounded bg-surface-muted px-2.5 py-1.5 text-small text-text [overflow-wrap:anywhere]">{{ __('admin.ntf.reason', ['note' => $item['note']]) }}</p>
                                        @endif
                                        <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-small text-text-muted">
                                            @if ($item['unread'])
                                                <span class="inline-flex items-center gap-1 font-semibold text-accent"><span class="size-2 rounded-full bg-accent" aria-hidden="true"></span>{{ __('admin.bell.new') }}</span>
                                            @endif
                                            <span>{{ $item['meta'] }}</span>
                                        </div>
                                        @if ($item['denied'])
                                            <p class="mt-1.5 flex items-start gap-1.5 text-small text-warning-text">
                                                <x-bs-admin::icon name="ri-lock" class="mt-0.5 size-[calc(1rem+var(--user-text-boost))] shrink-0" /><span>{{ __('admin.ntf.denied') }}</span>
                                            </p>
                                        @endif
                                    </div>
                                    <div data-acts class="flex flex-col items-end gap-1.5 @max-[37.4375rem]/tb:col-start-2 @max-[37.4375rem]/tb:flex-row @max-[37.4375rem]/tb:flex-wrap @max-[37.4375rem]/tb:items-center">
                                        @if ($item['url'] !== null)
                                            <a href="{{ $item['url'] }}" data-open x-on:click="follow($event, '{{ $item['ulid'] }}')"
                                               aria-label="{{ __('admin.ntf.act_aria', ['act' => $item['act'], 'text' => $item['text']]) }}"
                                               class="{{ $btnSm }} max-w-[13.75rem] @max-[37.4375rem]/tb:min-w-0 @max-[37.4375rem]/tb:max-w-none @max-[37.4375rem]/tb:flex-[1_1_auto]">
                                                <span class="min-w-0 truncate">{{ $item['act'] }}</span><x-bs-admin::icon name="ri-arrow-right-s" class="size-[calc(1rem+var(--user-text-boost))] shrink-0" />
                                            </a>
                                        @endif
                                        @if ($item['unread'] && ! $supporting)
                                            <x-bs-admin::button variant="ghost" size="sm" wire:click="read('{{ $item['ulid'] }}')"
                                                                aria-label="{{ __('admin.ntf.read_aria', ['text' => $item['text']]) }}">
                                                <x-bs-admin::icon name="ri-check" class="size-[calc(1rem+var(--user-text-boost))]" /><span>{{ __('admin.ntf.read') }}</span>
                                            </x-bs-admin::button>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </section>
                    @endforeach
                    <div class="flex flex-col items-center gap-1.5 border-t border-border px-4 pb-4 pt-3 text-center text-small text-text-muted">
                        @if ($more)
                            <button type="button" class="{{ $btn }}" wire:click="more" wire:loading.attr="aria-busy" wire:target="more">
                                <x-bs-admin::icon name="ri-arrow-down" class="size-[calc(1rem+var(--user-text-boost))]" /><span>{{ __('admin.ntf.more') }}</span>
                            </button>
                            <span>{{ __('admin.ntf.shown', ['shown' => $shown, 'total' => $totalLabel]) }}</span>
                        @else
                            <span>{{ __('admin.ntf.all_shown', ['count' => $shown]) }}</span>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </section>

    <span id="tb-allwhy" class="sr-only">{{ $supporting ? __('admin.bell.read_all_support') : ($unread === 0 ? __('admin.ntf.none_unread') : '') }}</span>

    @unless ($platform)
        <x-bs-admin::toast :message="$toast" :tone="$toastTone" dismiss="dismissToast" :undo="$toastUndo ? 'undo' : null" :seq="$toastSeq" />
    @endunless
</div>
