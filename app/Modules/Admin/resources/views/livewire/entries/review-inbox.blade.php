{{--
    "Hộp duyệt" — board 17, ported onto the admin tokens: a list of what is waiting on the left,
    the picked post on the right, and one set of approval buttons at the foot of the reader. On a wide
    screen the first post opens by itself; below the two-pane width the list shows first and a post
    opens over the whole screen, with «Quay lại» back to the list (board 17 `.sp-detail`).

    Content is rendered as TEXT, never as the stored HTML: a reviewer needs to read the post, and
    running a tenant's markup inside the admin shell would hand them the page.

    States (board 17 loadState): the list arrives already drawn with the page; while a tab, a search,
    a type filter or «Thử lại» reloads it, the skeleton stands in («Đang tải»); a queue that could
    not be read is «Chưa tải được hộp duyệt» with «Thử lại» («Lỗi tải»).
--}}
@php
    $reloads = 'setTab,search,sectionFilter,clearFilters,reloadInbox';
    $Js = \Illuminate\Support\Js::class;
@endphp
{{-- The keyboard (board 17): ↑ / ↓ walk the list, Enter runs the publishing move of the post being read through
     its own button, so its question always comes first. Only while the focus is on this screen or nowhere, never
     while a dialog, the guide, the bell or a menu is open (the reader covering a phone's screen excepted) or a
     question is on its way, nor in a field; Enter stays with a button, a link or another post's row that has the
     focus; with the move out of sight (a phone showing the list) Enter opens the marked post instead; a held Enter
     never repeats it; a key held with Ctrl, Alt or ⌘ stays the browser's; ↑ on the first post and ↓ on the last
     stay the page's scroll. An open dialog is found by its role (aria-modal is valid only on one). --}}
<div class="space-y-4" x-data="{
        inboxAsking: false,
        inboxSteps: 0,
        key(e) {
            if (e.defaultPrevented || e.isComposing || e.altKey || e.ctrlKey || e.metaKey || this.inboxAsking || ! ['ArrowDown', 'ArrowUp', 'Enter'].includes(e.key)) { return }
            if ([...document.querySelectorAll('[role=dialog], [role=alertdialog], [role=menu]')].some((d) => ! d.matches('[data-inbox-reader]') && d.getClientRects().length > 0)) { return }
            const t = e.target instanceof Element ? e.target : null;
            if (t && t !== document.body && t !== document.documentElement && ! $root.contains(t)) { return }
            if (t?.closest('input, textarea, select, [contenteditable]:not([contenteditable=false])')) { return }
            if (e.key !== 'Enter') {
                const rows = [...$root.querySelectorAll('[data-inbox-open]')];
                const at = rows.findIndex((r) => r.hasAttribute('data-current'));
                if (rows.length === 0 || (at === 0 && e.key === 'ArrowUp') || (at === rows.length - 1 && e.key === 'ArrowDown' && ! $root.querySelector('[data-inbox-more]'))) { return }
                e.preventDefault();
                this.inboxSteps++;
                Promise.resolve().then(() => $wire.step(e.key === 'ArrowDown' ? 1 : -1, true)).then(() => {
                    if (! $wire.detail || window.matchMedia('(min-width: 64rem)').matches) { $root.querySelector('[data-inbox-open][data-current]')?.scrollIntoView({ block: 'nearest' }) }
                }).catch(() => {}).finally(() => { this.inboxSteps-- });
                return;
            }
            if (e.repeat) { return }
            // a step on its way: the page still shows the post being left, so Enter waits for the new one
            if (this.inboxSteps > 0) { e.preventDefault(); return }
            const row = t?.closest('[data-inbox-open]');
            if (row ? ! row.hasAttribute('data-current') : t?.closest('button, a[href], summary, [role=button], [role=checkbox], [role=switch], [role=tab], [role=radio], [role=link], [role=menuitem], [role=option]')) { return }
            const go = $root.querySelector('[data-inbox-primary]');
            if (go?.disabled) { return }
            if (go && go.getClientRects().length > 0) { e.preventDefault(); go.click(); return }
            // a phone showing the list: Enter opens the post ↑ / ↓ marked, to be read before any move
            const mark = $wire.detail || window.matchMedia('(min-width: 64rem)').matches ? null : $root.querySelector('[data-inbox-open][data-current]');
            if (mark && mark.getClientRects().length > 0) { e.preventDefault(); mark.click(); }
        } }" x-on:keydown.window="key($event)">
    {{-- The header, as the admin shell draws it (board 17): the trail «⌂ › Hộp duyệt», the title row with the (?)
         guide and «Tải lại» at its end, then the one line on what the screen is for. --}}
    <header class="flex flex-col gap-1">
        <x-bs-admin::breadcrumb :items="[['label' => __('admin.inbox.title')]]" />
        <x-bs-admin::title-row :title="__('admin.inbox.title')" title-id="inbox-title">
            <x-slot:help>
                <x-bs-admin::guide :guide="$guide" />
            </x-slot:help>
            <x-bs-admin::button variant="outline" color="neutral" data-guide="reload" wire:click="reloadInbox" wire:loading.attr="disabled" wire:target="reloadInbox"
                                :title="__('admin.inbox.reload_tip')" :label="__('admin.inbox.reload')">
                <x-bs-admin::icon name="ri-refresh" class="size-[min(1.5rem,calc(1rem+var(--user-text-boost)))]" />
            </x-bs-admin::button>
        </x-bs-admin::title-row>
        <p class="m-0 text-small leading-relaxed text-text-muted [text-wrap:pretty]">{{ __('admin.inbox.subtitle') }}</p>
    </header>

    @error('inbox')
        @unless ($noteOpen)
            <x-bs-admin::alert color="danger" icon="ri-error-warning">{{ $message }}</x-bs-admin::alert>
        @endunless
    @enderror

    {{-- What the last «Duyệt đăng N bài» could not move, post by post: stays until closed. --}}
    @if ($bulkFailed !== [])
        <x-bs-admin::alert color="danger" icon="ri-error-warning" data-inbox-bulk-failed>
            <div class="flex items-start gap-2">
                <div class="min-w-0 flex-1 space-y-1.5">
                    <p class="font-semibold">{{ __('admin.inbox.bulk_failed', ['count' => count($bulkFailed)]) }}</p>
                    <ul class="list-disc space-y-1 ps-5">
                        @foreach ($bulkFailed as $failed)
                            <li class="[overflow-wrap:anywhere]"><span class="font-semibold">{{ $failed['title'] }}</span> — {{ $failed['why'] }}</li>
                        @endforeach
                    </ul>
                </div>
                <x-bs-admin::button variant="ghost" color="danger" icon wire:click="dismissBulk" :title="__('admin.close')" :aria-label="__('admin.close')" class="shrink-0">
                    <x-bs-admin::icon name="ri-close" class="size-5" />
                </x-bs-admin::button>
            </div>
        </x-bs-admin::alert>
    @endif

    {{-- Tabs --}}
    <div class="flex flex-wrap gap-2" data-inbox-tabs data-guide="tabs">
        @foreach ([['mine', 'ri-user-follow', $mineCount], ['queue', 'ri-inbox-archive', $queueCount]] as [$key, $icon, $count])
            <button type="button" wire:click="setTab('{{ $key }}')" aria-pressed="{{ $tab === $key ? 'true' : 'false' }}" @class([
                'hit-area inline-flex min-h-control items-center gap-2 rounded border px-3.5 py-1.5 text-small font-medium transition-colors',
                'border-primary-text bg-primary/5 font-semibold text-primary-text' => $tab === $key,
                'border-border-input bg-surface text-text hover:bg-surface-hover' => $tab !== $key,
            ])>
                <x-bs-admin::icon :name="$icon" class="size-[min(1.5rem,calc(1rem+var(--user-text-boost)))]" />
                {{ __('admin.inbox.tab_'.$key) }}
                @unless ($loadFailed)
                    <span @class([
                        'inline-flex min-w-5 items-center justify-center rounded-full px-1.5 text-caption font-bold',
                        'bg-primary text-on-primary' => $tab === $key,
                        'text-text' => $tab !== $key,
                    ])>{{ $count }}</span>
                @endunless
            </button>
        @endforeach
    </div>

    {{-- «Đang tải»: the two panes as grey bars while the list is read again --}}
    <div wire:loading.delay.grid wire:target="{{ $reloads }}" class="hidden gap-4 lg:grid-cols-[352px_1fr]" data-inbox-loading>
        <x-bs-admin::card>
            <x-slot:header><span class="text-sm font-semibold text-text-strong">{{ __('admin.inbox.tab_'.$tab) }}</span></x-slot:header>
            <div class="-mx-4 divide-y divide-border-soft" aria-hidden="true">
                @foreach (range(1, 4) as $skeleton)
                    <div class="flex flex-col gap-[7px] px-4 py-3.5">
                        <span class="h-3.5 w-[82%] animate-pulse rounded bg-surface-hover motion-reduce:animate-none"></span>
                        <span class="h-2.5 w-[54%] animate-pulse rounded bg-surface-hover motion-reduce:animate-none"></span>
                        <span class="h-2.5 w-[64%] animate-pulse rounded bg-surface-hover motion-reduce:animate-none"></span>
                    </div>
                @endforeach
            </div>
        </x-bs-admin::card>
        <x-bs-admin::card class="hidden lg:block">
            <div class="flex flex-col gap-3">
                <span class="h-7 w-[30%] animate-pulse rounded-full bg-surface-hover motion-reduce:animate-none" aria-hidden="true"></span>
                <span class="h-3.5 w-[92%] animate-pulse rounded bg-surface-hover motion-reduce:animate-none" aria-hidden="true"></span>
                <span class="h-3.5 w-[86%] animate-pulse rounded bg-surface-hover motion-reduce:animate-none" aria-hidden="true"></span>
                <span class="h-3.5 w-[74%] animate-pulse rounded bg-surface-hover motion-reduce:animate-none" aria-hidden="true"></span>
                <span class="text-[12px] text-text-muted">{{ __('admin.inbox.loading') }}</span>
            </div>
        </x-bs-admin::card>
    </div>

    @if ($loadFailed)
        {{-- «Lỗi tải» --}}
        <x-bs-admin::card class="border-danger-base/35" wire:loading.delay.remove wire:target="{{ $reloads }}" data-inbox-load-failed>
            <div class="flex flex-col items-start gap-3 p-1">
                <x-bs-admin::alert color="danger" icon="ri-error-warning" class="w-full">
                    <p class="font-semibold">{{ __('admin.inbox.err_load_title') }}</p>
                    <p class="mt-1 max-w-[560px] leading-relaxed">{{ __('admin.inbox.err_load_body') }}</p>
                </x-bs-admin::alert>
                <x-bs-admin::button color="primary" wire:click="reloadInbox" wire:loading.attr="disabled" wire:target="reloadInbox">
                    <x-bs-admin::icon name="ri-refresh" class="size-4" />{{ __('admin.inbox.retry') }}
                </x-bs-admin::button>
            </div>
        </x-bs-admin::card>
    @endif

    {{-- «Thay đổi của bài đang đăng» (board 17, under the load error, above the list): its own component, so
         neither a tab, a filter nor a failed queue read narrows or hides it --}}
    <livewire:admin.entries.review-changes wire:key="inbox-changes" />

    @unless ($loadFailed)
    <div class="grid grid-cols-[minmax(0,1fr)] items-start gap-4 @container lg:grid-cols-[352px_minmax(0,1fr)]" wire:loading.delay.remove wire:target="{{ $reloads }}">
        {{-- LEFT: the queue --}}
        <x-bs-admin::card class="{{ $reader !== null && $detail ? 'hidden lg:block' : '' }}">
            <x-slot:header>
                <div class="flex min-w-0 flex-1 items-center gap-2">
                    <span class="text-body font-semibold text-text-strong">{{ __('admin.inbox.list_'.$tab) }}</span>
                    <span class="ms-auto text-caption text-text-muted">{{ __('admin.inbox.showing', ['shown' => count($rows), 'total' => $total]) }}</span>
                </div>
            </x-slot:header>

            {{-- Ticked posts: «Đã chọn N bài» · «Duyệt đăng M bài» (asked first) · «Bỏ chọn», and what happens to the rest --}}
            @if ($bulk !== null)
                <x-bs-admin::list.bulk :label="__('admin.inbox.bulk_region')" :status="__('admin.inbox.picked', ['count' => $bulk['picked']])"
                                       clear="clearPicked" :clear-label="__('admin.inbox.bulk_clear')" :skip="$bulk['why']" class="min-[600px]:mx-0!" data-inbox-bulk>
                    @if ($bulk['ready'] > 0)
                        <x-bs-admin::button size="sm" color="primary" :title="$bulk['why']" wire:loading.attr="disabled" wire:target="approvePicked"
                                            x-on:click="inboxAsking = true; $bsConfirm({
                                                icon: 'publish',
                                                danger: false,
                                                focusCancel: true,
                                                title: {{ $Js::from(__('admin.inbox.bulk_title', ['count' => $bulk['ready']])) }},
                                                text: {{ $Js::from(__('admin.inbox.bulk_text', ['count' => $bulk['ready']])) }},
                                                confirm: {{ $Js::from(__('admin.inbox.bulk_ok', ['count' => $bulk['ready']])) }},
                                                cancel: {{ $Js::from(__('entries.wf_ap_cancel')) }},
                                            }).then(ok => ok && $wire.approvePicked()).finally(() => { inboxAsking = false })">
                            <x-bs-admin::icon name="ri-check-double" class="size-4" />{{ __('admin.inbox.bulk_ok', ['count' => $bulk['ready']]) }}
                        </x-bs-admin::button>
                    @else
                        <x-bs-admin::button size="sm" color="primary" disabled :title="$bulk['why']">
                            <x-bs-admin::icon name="ri-check-double" class="size-4" />{{ __('admin.inbox.bulk_none_btn') }}
                        </x-bs-admin::button>
                    @endif
                </x-bs-admin::list.bulk>
            @endif

            {{-- Search + type filter --}}
            <div class="mb-3 flex flex-col gap-2">
                <label data-guide="search" class="field flex items-center gap-2 rounded border border-border-input px-2.5 focus-within:border-primary-text focus-within:ring-[3px] focus-within:ring-primary/20">
                    <x-bs-admin::icon name="ri-search" class="size-4 shrink-0 text-text-muted" />
                    <input type="search" wire:model.live.debounce.300ms="search" class="w-full min-w-0 border-0 bg-transparent p-0 text-text"
                           placeholder="{{ __('admin.inbox.search_placeholder') }}" aria-label="{{ __('admin.inbox.search_label') }}">
                </label>
                @if (count($sections) > 1)
                    <select wire:model.live="sectionFilter" data-guide="type" class="field w-auto max-w-full self-start rounded border border-border-input bg-surface px-2.5 text-text focus:border-primary-text" aria-label="{{ __('admin.inbox.all_types') }}">
                        <option value="">{{ __('admin.inbox.all_types') }}</option>
                        @foreach ($sections as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                @endif
            </div>

            @if ($rows === [])
                <div class="flex flex-col items-center gap-2.5 px-4 py-7 text-center">
                    <x-bs-admin::icon :name="$search !== '' || $sectionFilter !== '' ? 'ri-search-eye' : 'ri-checkbox-circle'" class="size-6 text-placeholder" />
                    <p class="max-w-[280px] text-[13px] leading-relaxed text-body-muted">
                        {{ $search !== '' || $sectionFilter !== '' ? __('admin.inbox.no_match') : __('admin.inbox.empty_'.$tab) }}
                    </p>
                    @if ($search !== '' || $sectionFilter !== '')
                        <x-bs-admin::button color="light" size="sm" wire:click="clearFilters">{{ __('admin.inbox.clear_filters') }}</x-bs-admin::button>
                    @endif
                </div>
            @else
                <ul class="-mx-4 divide-y divide-border-soft" data-guide="rows">
                    @foreach ($rows as $row)
                        @php $isPicked = in_array($row['ulid'], $picked, true); @endphp
                        <li wire:key="inbox-{{ $row['ulid'] }}" @class([
                            'flex items-start',
                            'border-l-2 border-primary-text bg-primary/5' => $row['current'],
                            'border-l-2 border-transparent' => ! $row['current'],
                        ])>
                            <x-bs-admin::list.check :checked="$isPicked" :label="__('admin.inbox.pick_label', ['title' => $row['title']])"
                                                    :title="$isPicked ? __('admin.inbox.pick_off') : __('admin.inbox.pick_on')"
                                                    wire:click="togglePick({{ $Js::from($row['ulid']) }})" class="mt-1.5 ms-1" data-inbox-pick="{{ $row['ulid'] }}" />
                            <button type="button" wire:click="open(@js($row['ulid']))" data-inbox-open="{{ $row['ulid'] }}" @if ($row['current']) data-current @endif class="flex min-w-0 flex-1 gap-2.5 py-3 pe-4 ps-1 text-start transition-colors hover:bg-surface-hover">
                                <span class="flex min-w-0 flex-1 flex-col gap-1">
                                    {{-- A name is never cut with «…»: it wraps to as many lines as it needs. --}}
                                    <span class="text-small font-semibold leading-snug text-text-strong [overflow-wrap:anywhere]">{{ $row['title'] }}</span>
                                    <span class="flex flex-wrap items-center gap-x-1.5 gap-y-0.5 text-caption text-text-muted">
                                        <x-bs-admin::status-pill :status="$row['tone']" dot />
                                        <span class="font-semibold text-text">{{ $row['stage'] }}</span>
                                        @if ($row['section'] !== '')
                                            <span class="text-text-faint" aria-hidden="true">·</span><span>{{ $row['section'] }}</span>
                                        @endif
                                    </span>
                                    <span class="flex flex-wrap items-center gap-x-1.5 gap-y-0.5 text-caption text-text-muted">
                                        <span class="[overflow-wrap:anywhere]">{{ __('admin.inbox.by_sender', ['name' => $row['author']]) }}</span>
                                        <span class="text-text-faint" aria-hidden="true">·</span>
                                        @php $waitDays = $row['waited_at'] === null ? 0 : (int) floor(now()->diffInDays($row['waited_at'], true)); @endphp
                                        <span @class(['font-semibold text-warning-text' => $row['late']])>{{ match (true) {
                                            $row['late'] => __('admin.inbox.wait_late', ['n' => $waitDays]),
                                            $waitDays <= 0 => __('admin.inbox.wait_now'),
                                            default => __('admin.inbox.wait_days', ['n' => $waitDays]),
                                        } }}</span>
                                    </span>
                                </span>
                            </button>
                        </li>
                    @endforeach
                </ul>

                @if ($total > count($rows))
                    <div class="mt-3 flex justify-center">
                        <x-bs-admin::button color="light" size="sm" wire:click="loadMore" wire:loading.attr="disabled" data-inbox-more>
                            <x-bs-admin::icon name="ri-arrow-down" class="size-4" />{{ __('admin.inbox.load_more', ['count' => $total - count($rows)]) }}
                        </x-bs-admin::button>
                    </div>
                @endif
            @endif
        </x-bs-admin::card>

        {{-- RIGHT: the reader --}}
        @if ($reader === null)
            <x-bs-admin::card class="hidden lg:block">
                <div class="flex flex-col items-center gap-3 px-6 py-10 text-center">
                    <span class="flex size-13 items-center justify-center rounded-full bg-primary/10">
                        <x-bs-admin::icon name="ri-file-list-3" class="size-6 text-primary-text" />
                    </span>
                    {{-- Nothing to open: the list is empty (board 17), or the open post has no workflow any more --}}
                    <p class="text-lead font-semibold text-text-strong">{{ $rows === [] ? __('admin.inbox.empty_pick_title') : __('admin.inbox.pick_title') }}</p>
                    <p class="max-w-[420px] text-small leading-relaxed text-text-muted">{{ $rows === [] ? __('admin.inbox.empty_pick_body') : __('admin.inbox.pick_body') }}</p>
                </div>
            </x-bs-admin::card>
        @else
            @php
                $sentDays = $reader['sent_at'] === null ? 0 : (int) floor(now()->diffInDays($reader['sent_at'], true));
                $isLate = $reader['sent_at'] !== null && $reader['sent_at']->getTimestamp() < now()->subDays(3)->getTimestamp();
                $doneCount = count(array_filter($reader['steps'], fn (array $step): bool => $step['state'] === 'done'));
                $nextStep = collect($reader['steps'])->firstWhere('state', 'todo');
                $lastNote = $reader['trail'][0]['note'] ?? null;
                // a node of the road: a step behind is ticked, the others carry their step's own icon
                $stepIcons = fn (array $step): string => $step['state'] === 'done' ? 'ri-check' : $step['icon'];
                $seoMissing = blank($reader['seo_title']) || blank($reader['seo_description']);
                $seoFieldErrors = $errors->hasAny(['seo', 'seoTitle', 'seoDescription']);
                $bandIcons = ['empty' => 'ri-edit', 'short' => 'ri-arrow-up', 'near' => 'ri-arrow-right-up', 'good' => 'ri-check', 'long' => 'ri-error-warning', 'over' => 'ri-close-circle'];
            @endphp
            {{-- Below the two-pane width the reader is hidden until a post is opened, then covers the whole
                 screen (board 17 `.sp-detail`: no frame, the phone's safe areas kept clear). Covering, it is a
                 modal dialog: the shell behind it is inert and does not scroll, so Tab and a screen reader stay
                 in the post until «Quay lại». --}}
            <div class="contents" x-data="{ narrow: false }"
                 x-init="const q = window.matchMedia('(max-width: 63.999rem)'); narrow = q.matches; q.addEventListener('change', (e) => { narrow = e.matches })">
            <section aria-label="{{ __('admin.inbox.reading') }}" data-guide="reader" data-inbox-reader x-data="bsSeoFields({{ $Js::from($seoConfig) }})"
                     x-trap.inert.noscroll.noautofocus.noreturn="narrow && $wire.detail" x-bind:role="narrow && $wire.detail ? 'dialog' : null"
                     x-bind:aria-modal="narrow && $wire.detail ? 'true' : null" @class([
                'flex min-w-0 flex-col bg-surface lg:sticky lg:top-4 lg:max-h-[calc(100dvh-2rem)] lg:rounded-md lg:border lg:border-border lg:shadow-card',
                'max-lg:fixed max-lg:inset-0 max-lg:z-50 max-lg:pt-[env(safe-area-inset-top)] max-lg:ps-[env(safe-area-inset-left)] max-lg:pe-[env(safe-area-inset-right)]' => $detail,
                'max-lg:hidden' => ! $detail,
            ])>
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-border px-4 py-3 max-lg:min-h-14">
                    <x-bs-admin::button color="light" size="sm" class="shrink-0 lg:hidden" wire:click="closeReader" :title="__('admin.inbox.back_tip')" data-inbox-back>
                        <x-bs-admin::icon name="ri-arrow-left" class="size-4" />{{ __('admin.inbox.back') }}
                    </x-bs-admin::button>
                    {{-- A long title wraps rather than being cut with «…» (the design bar: nothing important is clipped). --}}
                    <h2 id="inbox-reader-title" tabindex="-1" class="m-0 min-w-0 flex-1 text-body font-semibold leading-snug text-text-strong [overflow-wrap:anywhere]">{{ $reader['title'] }}</h2>
                    @if ($reader['section'] !== '')
                        <span class="ms-auto inline-flex min-h-6 shrink-0 items-center rounded-full bg-surface-hover px-2.5 text-caption font-bold text-text-muted">{{ $reader['section'] }}</span>
                    @endif
                </div>

                <div class="flex min-h-0 flex-1 flex-col gap-3 overflow-auto p-4">
                    {{-- Who sent it, and how long it has waited --}}
                    <div class="flex flex-wrap items-center gap-2.5">
                        <span class="inline-flex size-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-caption font-bold text-primary-text">
                            {{ mb_strtoupper(mb_substr(\Illuminate\Support\Str::of($reader['author'])->trim()->afterLast(' '), 0, 1)) }}
                        </span>
                        <span class="flex min-w-0 flex-col">
                            <span class="text-small font-semibold text-text-strong [overflow-wrap:anywhere]">{{ $reader['author'] }}</span>
                            <span class="text-caption text-text-muted">{{ $sentDays <= 0 ? __('admin.inbox.wait_now') : __('admin.inbox.sent_days', ['n' => $sentDays]) }}</span>
                        </span>
                        @if ($isLate)
                            <span class="ms-auto inline-flex min-h-6 items-center gap-1 rounded-full bg-warning-base/20 px-2.5 text-caption font-bold text-warning-text">
                                <x-bs-admin::icon name="ri-alarm-warning" class="size-[min(1.5rem,calc(0.75rem+var(--user-text-boost)))] shrink-0" />{{ __('admin.inbox.wait_late', ['n' => $sentDays]) }}
                            </span>
                        @endif
                    </div>

                    {{-- The step the post is at, and the whole road: a rail from 600px, a bar below --}}
                    <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                        <span class="inline-flex items-center gap-1 text-small font-bold text-primary-text">
                            <x-bs-admin::icon :name="$reader['step_icon']" class="size-[min(1.5rem,calc(0.875rem+var(--user-text-boost)))] shrink-0" />{{ __('admin.wfb.step_of', ['n' => $reader['step_no'], 'total' => $reader['step_count'], 'label' => $reader['step_label']]) }}
                        </span>
                        <span class="inline-flex items-center gap-1 text-caption text-text-muted">
                            <x-bs-admin::icon name="ri-flow-chart" class="size-[min(1.5rem,calc(0.75rem+var(--user-text-boost)))] shrink-0" />{{ $reader['workflow'] }}
                        </span>
                    </div>
                    <div class="@container">
                        <ol class="m-0 hidden list-none p-0 @min-[37.5rem]:flex" data-inbox-track>
                            @foreach ($reader['steps'] as $step)
                                @php $cur = $step['state'] === 'current'; $done = $step['state'] === 'done'; @endphp
                                <li class="flex min-w-0 flex-1 flex-col items-center gap-1.5" @if ($cur) aria-current="step" @endif>
                                    <span class="flex w-full items-center" aria-hidden="true">
                                        <span @class(['h-0.5 flex-1', 'invisible' => $loop->first, 'bg-primary-text' => $done || $cur, 'bg-border-strong' => ! $done && ! $cur])></span>
                                        <span @class([
                                            'inline-flex size-[calc(1.625rem+var(--user-text-boost))] shrink-0 items-center justify-center rounded-full border-2',
                                            'border-primary bg-primary text-on-primary' => $done,
                                            'border-primary-text bg-surface text-primary-text shadow-[0_0_0_4px_color-mix(in_oklab,var(--color-primary-base)_14%,transparent)]' => $cur && ! $step['is_published'],
                                            'border-success-base bg-surface text-success-text shadow-[0_0_0_4px_color-mix(in_oklab,var(--color-success-base)_16%,transparent)]' => $cur && $step['is_published'],
                                            'border-success-base/45 bg-surface text-success-text' => ! $done && ! $cur && $step['is_published'],
                                            'border-border-input bg-surface text-text-muted' => ! $done && ! $cur && ! $step['is_published'],
                                        ])>
                                            <x-bs-admin::icon :name="$stepIcons($step)" class="size-[min(1.5rem,calc(0.8125rem+var(--user-text-boost)))]" />
                                        </span>
                                        <span @class(['h-0.5 flex-1', 'invisible' => $loop->last, 'bg-primary-text' => $done, 'bg-border-strong' => ! $done])></span>
                                    </span>
                                    <span @class([
                                        'px-1 text-center text-caption leading-snug [text-wrap:pretty]',
                                        'font-bold text-primary-text' => $cur && ! $step['is_published'],
                                        'font-bold text-success-text' => $cur && $step['is_published'],
                                        'text-text' => $done,
                                        'text-text-muted' => ! $done && ! $cur,
                                    ])>{{ $step['label'] }}</span>
                                </li>
                            @endforeach
                        </ol>
                        <div class="flex flex-col gap-1.5 @min-[37.5rem]:hidden" data-inbox-progress>
                            <span class="flex h-1.5 overflow-hidden rounded-full bg-track" aria-hidden="true">
                                <span class="block h-1.5 rounded-full bg-primary-text" style="width: {{ $reader['step_count'] > 1 ? round($doneCount / ($reader['step_count'] - 1) * 100) : 100 }}%"></span>
                            </span>
                            <span class="text-caption text-text-muted">{{ $nextStep !== null ? __('admin.inbox.next_step', ['label' => $nextStep['label']]) : __('admin.inbox.last_step') }}</span>
                        </div>
                    </div>

                    {{-- The last thing said when the post last moved --}}
                    @if ($lastNote !== null)
                        <p class="m-0 flex gap-2.5 rounded-r border-l-2 border-border-strong bg-surface-muted px-3 py-3 text-small leading-relaxed text-text">
                            <x-bs-admin::icon name="ri-chat-quote" class="size-[min(1.5rem,calc(1rem+var(--user-text-boost)))] shrink-0 text-text-muted" />
                            <span class="min-w-0 [overflow-wrap:anywhere]">{{ $lastNote }}</span>
                        </p>
                    @endif

                    {{-- What the post still lacks (board 17 `missLine`): approving stays possible --}}
                    @if ($reader['missing'] !== [])
                        <p class="m-0 flex gap-2.5 rounded-r border-l-2 border-warning-base bg-warning-base/15 px-3 py-3 text-small leading-relaxed text-warning-text" data-inbox-missing>
                            <x-bs-admin::icon name="ri-error-warning" class="size-[min(1.5rem,calc(1rem+var(--user-text-boost)))] shrink-0" />
                            <span class="min-w-0 [overflow-wrap:anywhere]">{{ __('admin.inbox.missing_line', ['items' => implode(', ', $reader['missing'])]) }}</span>
                        </p>
                    @endif

                    {{-- Cover --}}
                    <x-bs-admin::fold :title="__('admin.inbox.fold_cover')" :warn="$reader['cover'] === null ? __('admin.inbox.missing') : null">
                        @if ($reader['cover'] !== null)
                            <img src="{{ $reader['cover'] }}" alt="{{ __('entries.cover_alt') }}" class="max-h-64 w-auto max-w-full self-start rounded border border-border-soft" loading="lazy">
                        @else
                            <p class="m-0 flex items-start gap-2 rounded bg-warning-base/15 px-3 py-2.5 text-small text-warning-text">
                                <x-bs-admin::icon name="ri-image-add" class="mt-0.5 size-4 shrink-0" />{{ __('admin.inbox.no_cover') }}
                            </p>
                        @endif
                    </x-bs-admin::fold>

                    {{-- «Thông tin chính»: the details this kind of post carries besides its text --}}
                    @if ($reader['fields'] !== [])
                        <x-bs-admin::fold :title="__('admin.inbox.fold_fields')" :hint="$reader['section'] !== '' ? __('admin.inbox.fold_fields_hint', ['section' => $reader['section']]) : null" data-inbox-fields>
                            <div class="@container">
                            <dl class="m-0 grid grid-cols-1 gap-x-4 gap-y-3 @min-[35rem]:grid-cols-2">
                                @foreach ($reader['fields'] as $field)
                                    <div class="flex min-w-0 flex-col gap-0.5">
                                        <dt class="text-caption text-text-muted">{{ $field['label'] }}</dt>
                                        <dd class="m-0 text-small font-medium leading-relaxed text-text-strong [overflow-wrap:anywhere]">{{ $field['value'] }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                            </div>
                        </x-bs-admin::fold>
                    @endif

                    {{-- The short description (what the list's card shows), with its length meter --}}
                    <x-bs-admin::fold :title="__('admin.inbox.fold_excerpt')" :hint="__('admin.inbox.fold_excerpt_hint')" :warn="blank($reader['excerpt']) ? __('admin.inbox.not_entered') : null">
                        @if (filled($reader['excerpt']))
                            <p class="m-0 text-small leading-relaxed text-text [overflow-wrap:anywhere]">{{ $reader['excerpt'] }}</p>
                            <span class="seo-meter mt-0!" :data-b="excerptMeter.band" aria-hidden="true">
                                <span class="seo-meter__zone" :style="{ left: excerptMeter.lo + '%', width: excerptMeter.zw + '%' }"></span>
                                <span class="seo-meter__fill" :style="{ width: excerptMeter.pct + '%' }"></span>
                                <span class="seo-meter__tick" :style="{ left: excerptMeter.tick + '%' }"></span>
                            </span>
                            <span class="text-caption text-text-muted" x-text="excerptMeter.text"></span>
                        @else
                            <p class="m-0 rounded bg-warning-base/15 px-3 py-2.5 text-small text-warning-text">{{ __('admin.inbox.no_excerpt') }}</p>
                        @endif
                    </x-bs-admin::fold>

                    {{-- The post, as text --}}
                    <x-bs-admin::fold :title="__('admin.inbox.content')" :end="$reader['body'] !== [] ? __('admin.inbox.body_counts', ['words' => \Illuminate\Support\Number::format($reader['words'], locale: app()->getLocale()), 'paragraphs' => $reader['paragraphs']]) : null">
                        @forelse ($reader['body'] as $line)
                            <p @class([
                                'm-0 text-small leading-relaxed [overflow-wrap:anywhere]',
                                'font-semibold text-text-strong' => $line['k'] === 'h',
                                'text-text' => $line['k'] !== 'h',
                                'ps-4 before:-ms-3 before:me-1.5 before:content-[\'•\']' => $line['k'] === 'li',
                            ])>{{ $line['t'] }}</p>
                        @empty
                            <p class="m-0 rounded bg-warning-base/15 px-3 py-2.5 text-small text-warning-text">{{ __('admin.inbox.no_content') }}</p>
                        @endforelse
                    </x-bs-admin::fold>

                    {{-- What visitors and Google will see --}}
                    {{-- What visitors and Google will see: the result line, then the two Google fields with their
                         meters. Someone the edit policy lets in edits them here on a post not yet live; each box
                         saves when it is left. Folded at first (board 17), «cần xem» while one is empty. --}}
                    <x-bs-admin::fold :title="__('admin.inbox.seo_title')" :warn="$seoMissing ? __('admin.inbox.seo_warn') : null" :open="$seoFieldErrors" data-inbox-seo>
                        <div class="flex flex-col gap-1 rounded border border-border-soft p-3">
                            @if ($reader['path'] !== null)
                                <p class="m-0 text-caption text-success-text [overflow-wrap:anywhere]">{{ $reader['path'] }}</p>
                            @endif
                            @if (filled($reader['seo_title']))
                                <p class="m-0 text-body leading-snug text-secondary-text [overflow-wrap:anywhere]">{{ $reader['seo_title'] }}</p>
                            @else
                                <p class="m-0 text-small leading-relaxed text-warning-text">{{ __('admin.inbox.seo_no_title') }}</p>
                            @endif
                            @if (filled($reader['seo_description']))
                                <p class="m-0 text-small leading-relaxed text-text-muted [overflow-wrap:anywhere]">{{ $reader['seo_description'] }}</p>
                            @else
                                <p class="m-0 text-small leading-relaxed text-warning-text">{{ __('admin.inbox.seo_no_description') }}</p>
                            @endif
                        </div>

                        @error('seo') <x-bs-admin::field-error>{{ $message }}</x-bs-admin::field-error> @enderror
                        @foreach ([['seoTitle', 'title', 'seoTitle', 'admin.inbox.seo_field_title', 'titleMeter'], ['seoDescription', 'description', 'seoDesc', 'admin.inbox.seo_field_description', 'descriptionMeter']] as [$model, $part, $limit, $labelKey, $meter])
                            <div class="flex min-w-0 flex-col gap-1.5" wire:key="inbox-seo-{{ $part }}-{{ $reader['ulid'] }}">
                                <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1.5">
                                    @if ($reader['seo_edit'])
                                        <label for="inbox-seo-{{ $part }}" class="text-small font-semibold text-text-strong">{{ __($labelKey) }}</label>
                                    @else
                                        <span class="text-small font-semibold text-text-strong">{{ __($labelKey) }}</span>
                                    @endif
                                    <span id="inbox-seo-{{ $part }}-c" class="seo-count" :data-b="{{ $meter }}.band" data-b="empty">
                                        @foreach ($bandIcons as $band => $icon)
                                            <x-bs-admin::icon :name="$icon" x-show="{{ $meter }}.band === '{{ $band }}'" x-cloak />
                                        @endforeach
                                        <span class="tabular-nums" x-text="[...String($wire.$get('{{ $model }}') ?? '')].length + '/{{ $seoLimits[$limit]['display'] }}'"></span>
                                    </span>
                                </div>
                                @if ($reader['seo_edit'])
                                    @if ($part === 'title')
                                        <input type="text" id="inbox-seo-title" wire:model="seoTitle" x-on:change="$wire.saveSeo()" maxlength="{{ \App\Modules\Admin\Support\SeoFields::hardCap('seoTitle') }}"
                                               aria-describedby="inbox-seo-title-c inbox-seo-title-m @error('seoTitle') inbox-seo-title-e @enderror" @error('seoTitle') aria-invalid="true" @enderror
                                               class="field block w-full rounded-md border border-border-input bg-surface-muted px-3 text-text placeholder:text-placeholder transition-colors focus:border-primary-text aria-invalid:border-danger-text">
                                    @else
                                        <textarea id="inbox-seo-description" wire:model="seoDescription" x-on:change="$wire.saveSeo()" rows="2" maxlength="{{ \App\Modules\Admin\Support\SeoFields::hardCap('seoDesc') }}"
                                                  aria-describedby="inbox-seo-description-c inbox-seo-description-m @error('seoDescription') inbox-seo-description-e @enderror" @error('seoDescription') aria-invalid="true" @enderror
                                                  x-on:keydown.enter="if (! $event.isComposing && $event.keyCode !== 229) $event.preventDefault()"
                                                  class="field block w-full resize-y rounded-md border border-border-input bg-surface-muted px-3 py-2.5 text-text placeholder:text-placeholder transition-colors focus:border-primary-text aria-invalid:border-danger-text"></textarea>
                                    @endif
                                @endif
                                <span class="seo-meter mt-0!" :data-b="{{ $meter }}.band" aria-hidden="true">
                                    <span class="seo-meter__zone" :style="{ left: {{ $meter }}.lo + '%', width: {{ $meter }}.zw + '%' }"></span>
                                    <span class="seo-meter__fill" :style="{ width: {{ $meter }}.pct + '%' }"></span>
                                    <span class="seo-meter__tick" :style="{ left: {{ $meter }}.tick + '%' }"></span>
                                </span>
                                <span id="inbox-seo-{{ $part }}-m" class="text-caption text-text-muted" x-text="{{ $meter }}.text"></span>
                                @error($model) <x-bs-admin::field-error id="inbox-seo-{{ $part }}-e">{{ $message }}</x-bs-admin::field-error> @enderror
                            </div>
                        @endforeach
                        @if ($reader['seo_edit'])
                            <p class="m-0 flex flex-wrap items-center gap-x-2 gap-y-1 text-caption text-text-muted">
                                <span>{{ __('admin.inbox.seo_edit_hint') }}</span>
                                <x-bs-admin::saved-note wire:loading.remove wire:target="saveSeo">{{ $seoSaved ? __('admin.saved') : '' }}</x-bs-admin::saved-note>
                            </p>
                        @elseif ($reader['edit_url'] !== null)
                            <p class="m-0 text-caption leading-relaxed text-text-muted">{{ __('admin.inbox.seo_live') }}</p>
                        @endif
                    </x-bs-admin::fold>

                    @if ($reader['edit_url'] !== null)
                        <x-bs-admin::button variant="ghost" color="primary" class="self-start" :href="$reader['edit_url']">
                            <x-bs-admin::icon name="ri-external-link" class="size-4" />{{ __('admin.inbox.open_full') }}
                        </x-bs-admin::button>
                    @endif

                    {{-- Trail --}}
                    @if ($reader['trail'] !== [])
                        <x-bs-admin::fold :title="__('admin.wfb.trail_title')" :end="__('admin.inbox.trail_count', ['n' => $reader['trail_total']])" :open="false" data-inbox-trail>
                            <ol class="m-0 flex list-none flex-col gap-3 p-0">
                                @foreach ($reader['trail'] as $row)
                                    @php $who = $row['actor'] ?? __('admin.wfb.actor_gone'); @endphp
                                    <li class="flex gap-2.5" wire:key="inbox-log-{{ $row['id'] }}">
                                        <span class="inline-flex size-[calc(1.625rem+var(--user-text-boost))] shrink-0 items-center justify-center rounded-full bg-primary/10 text-caption font-bold text-primary-text" aria-hidden="true">
                                            {{ mb_strtoupper(mb_substr(\Illuminate\Support\Str::of($who)->trim()->afterLast(' '), 0, 1)) }}
                                        </span>
                                        <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                                            <span class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                                                <span class="text-small font-semibold text-text-strong [overflow-wrap:anywhere]">{{ $who }}</span>
                                                <span class="text-caption text-text">{{ mb_strtolower($row['transition']) }}</span>
                                                <span class="text-caption text-text-muted">{{ $row['at'] }}</span>
                                            </span>
                                            <span class="text-caption text-text-muted [overflow-wrap:anywhere]">{{ __('admin.inbox.trail_flow', ['from' => $row['from'], 'to' => $row['to']]) }}</span>
                                            @if ($row['note'] !== null)
                                                <span class="mt-1 block rounded-r border-l-2 border-border-strong bg-surface-muted px-3 py-2 text-caption leading-relaxed text-text [overflow-wrap:anywhere]">{{ $row['note'] }}</span>
                                            @endif
                                        </span>
                                    </li>
                                @endforeach
                            </ol>
                            @if ($reader['trail_more'] > 0)
                                <x-bs-admin::button variant="ghost" color="primary" size="sm" class="self-start" wire:click="moreTrail" wire:loading.attr="disabled" wire:target="moreTrail" data-inbox-trail-more>
                                    <x-bs-admin::icon name="ri-arrow-down" class="size-4" />{{ __('admin.inbox.trail_more', ['n' => $reader['trail_more']]) }}
                                </x-bs-admin::button>
                            @endif
                        </x-bs-admin::fold>
                    @endif
                </div>

                {{-- The only place approval actions live (design: foot of the reader), with the walk through the list --}}
                <div @class([
                    'flex flex-wrap items-center gap-2 border-t border-border bg-surface-muted px-4 py-3 lg:rounded-b-md max-[599.98px]:[&_[data-inbox-act]]:flex-[1_1_8.75rem]',
                    'max-lg:pb-[calc(0.75rem+env(safe-area-inset-bottom))]' => $detail,
                ])>
                    @if ($reader['actions'] === [])
                        <p class="m-0 text-small text-text-muted" data-guide="bar">{{ __('admin.inbox.no_actions') }}</p>
                    @else
                        <div class="contents" data-guide="bar">
                            @foreach ($reader['actions'] as $action)
                                @if ($action['note'])
                                    {{-- A move that demands a reason opens the reason dialog (with «Giao cho») --}}
                                    <x-bs-admin::button :color="$action['to_published'] ? 'primary' : 'danger'" :variant="$action['to_published'] ? 'solid' : 'outline'" wire:key="act-{{ $action['key'] }}" data-inbox-act="{{ $action['key'] }}" :data-inbox-primary="$action['primary']"
                                                     wire:click="askNote({{ $Js::from($action['key']) }})" wire:loading.attr="disabled">
                                        <x-bs-admin::icon :name="$action['icon']" class="size-4" />{{ $action['label'] }}
                                    </x-bs-admin::button>
                                @elseif ($action['first'])
                                    {{-- The first publication asks «Khi nào đăng?» first (board 17): now, or approved for a later time --}}
                                    <x-bs-admin::button color="primary" wire:key="act-{{ $action['key'] }}" wire:loading.attr="disabled" data-inbox-act="{{ $action['key'] }}" :data-inbox-primary="$action['primary']"
                                                     x-on:click="$dispatch('inbox-ap', {{ $Js::from(['key' => $action['key'], 'ulid' => $reader['ulid']]) }})">
                                        <x-bs-admin::icon name="ri-check-double" class="size-4" />{{ $action['label'] }}
                                    </x-bs-admin::button>
                                @elseif ($action['to_published'])
                                    {{-- Going live is asked first (board 17 «Duyệt đăng “…”?», the same question as the posts list): the
                                         globe in a green circle, and «Để tôi đọc lại đã» focused, so a second Enter never publishes --}}
                                    <x-bs-admin::button color="primary" wire:key="act-{{ $action['key'] }}" wire:loading.attr="disabled" data-inbox-act="{{ $action['key'] }}" :data-inbox-primary="$action['primary']"
                                                     x-on:click="inboxAsking = true; $bsConfirm({
                                                         icon: 'publish',
                                                         danger: false,
                                                         focusCancel: true,
                                                         title: {{ $Js::from(__('entries.wf_ap_title', ['title' => \Illuminate\Support\Str::limit($reader['title'], 42, '…')])) }},
                                                         text: {{ $Js::from(__('entries.wf_ap_text')) }},
                                                         confirm: {{ $Js::from(__('entries.wf_ap_ok')) }},
                                                         cancel: {{ $Js::from(__('entries.wf_ap_cancel')) }},
                                                     }).then(ok => ok && $wire.apply({{ $Js::from($action['key']) }}, {{ $Js::from($reader['ulid']) }})).finally(() => { inboxAsking = false })">
                                        <x-bs-admin::icon name="ri-check-double" class="size-4" />{{ $action['label'] }}
                                    </x-bs-admin::button>
                                @else
                                    <x-bs-admin::button color="light" wire:key="act-{{ $action['key'] }}" data-inbox-act="{{ $action['key'] }}"
                                                     wire:click="apply({{ $Js::from($action['key']) }})" wire:loading.attr="disabled">
                                        <x-bs-admin::icon name="ri-send-plane" class="size-4" />{{ $action['label'] }}
                                    </x-bs-admin::button>
                                @endif
                            @endforeach
                        </div>
                    @endif
                    @if ($nav !== null)
                        <span class="ms-auto flex items-center gap-1.5 max-[599.98px]:ms-0 max-[599.98px]:w-full max-[599.98px]:justify-between" data-guide="nav">
                            <span class="flex items-center gap-1.5">
                                <x-bs-admin::button variant="ghost" color="primary" icon wire:click="step(-1)" :disabled="$nav['at'] <= 1" :title="__('admin.inbox.prev')" :aria-label="__('admin.inbox.prev')">
                                    <x-bs-admin::icon name="ri-arrow-up-s" class="size-[min(1.5rem,calc(1.125rem+var(--user-text-boost)))]" />
                                </x-bs-admin::button>
                                <x-bs-admin::button variant="ghost" color="primary" icon wire:click="step(1)" :disabled="$nav['at'] >= $nav['of']" :title="__('admin.inbox.next')" :aria-label="__('admin.inbox.next')">
                                    <x-bs-admin::icon name="ri-arrow-down-s" class="size-[min(1.5rem,calc(1.125rem+var(--user-text-boost)))]" />
                                </x-bs-admin::button>
                            </span>
                            <span class="whitespace-nowrap text-caption text-text-muted">{{ __('admin.inbox.position', ['at' => $nav['at'], 'of' => $nav['of']]) }}</span>
                        </span>
                    @endif
                </div>
            </section>
            </div>
        @endif
    </div>
    @endunless

    {{-- The reason dialog (board 17 .sp-modal): the post, what the move does, «Lý do *», «Giao cho».
         Focus starts on «Hủy» and goes back to the button that opened it; Esc and the backdrop close it.
         An empty reason is caught here first and again by the server. --}}
    <x-bs-admin::modal model="noteOpen" size="md" :close-label="__('admin.close')" data-inbox-note>
        <x-slot:heading>
            <span class="inline-flex items-center gap-2">
                <x-bs-admin::icon :name="$noteDialog['icon'] ?? 'ri-arrow-go-back'" @class(['size-[calc(1.0625rem+var(--user-text-boost))] shrink-0', 'text-primary-text' => $noteDialog['live'] ?? false, 'text-danger-text' => ! ($noteDialog['live'] ?? false)]) />
                <span>{{ $noteDialog['label'] ?? '' }}</span>
            </span>
        </x-slot:heading>

        @if ($noteDialog !== null)
            <div class="flex flex-col gap-3.5" x-data="{ bad: false }">
                <p class="text-[calc(0.78125rem+var(--user-text-boost))] font-semibold leading-snug text-text [overflow-wrap:anywhere]">{{ $noteDialog['post'] }}</p>
                <p class="flex items-start gap-2 rounded-r border-l-2 border-warning-base bg-warning-base/15 px-3 py-2.5 text-[12.5px] leading-relaxed text-warning-text">
                    <x-bs-admin::icon name="ri-alert" class="mt-0.5 size-4 shrink-0" />
                    <span>{{ $noteDialog['warning'] }}</span>
                </p>
                @error('inbox') <x-bs-admin::alert color="danger" icon="ri-error-warning">{{ $message }}</x-bs-admin::alert> @enderror
                <div class="flex flex-col gap-1.5">
                    <label for="inbox-note" class="text-xs font-semibold text-text">{{ __('admin.wfb.note_label') }} <span class="text-danger-text" aria-hidden="true">*</span></label>
                    <textarea id="inbox-note" x-ref="note" wire:model="note" x-on:input="bad = false" rows="4" maxlength="2000" required
                              aria-describedby="inbox-note-err"
                              x-bind:aria-invalid="(bad || {{ $errors->has('note') ? 'true' : 'false' }}).toString()"
                              class="w-full rounded border px-3 py-2.5 text-[13px] text-text focus:border-primary-text"
                              x-bind:class="bad ? 'border-danger-text ring-[3px] ring-danger-base/15' : 'border-border'"
                              placeholder="{{ __('admin.inbox.note_placeholder') }}"></textarea>
                    <div id="inbox-note-err">
                        <p x-cloak x-show="bad" class="flex items-center gap-1.5 text-xs font-semibold text-danger-text">
                            <x-bs-admin::icon name="ri-error-warning" class="size-3.5" />{{ __('admin.wfb.note_missing') }}
                        </p>
                        @error('note')
                            <p x-show="! bad" class="flex items-center gap-1.5 text-xs font-semibold text-danger-text">
                                <x-bs-admin::icon name="ri-error-warning" class="size-3.5" />{{ $message }}
                            </p>
                        @enderror
                    </div>
                </div>
                @if (! ($noteDialog['live'] ?? false) && ($noteDialog['members'] ?? []) !== [])
                <div class="flex flex-col gap-1.5">
                    <label for="inbox-assign" class="text-xs font-semibold text-text">{{ __('admin.wfb.assign_label') }} <span class="font-normal text-text-muted">{{ __('admin.wfb.assign_optional') }}</span></label>
                    <select id="inbox-assign" wire:model="assignTo" aria-describedby="inbox-assign-err" class="field w-full rounded border border-border bg-surface px-2.5 text-text focus:border-primary-text">
                        <option value="">{{ __('admin.wfb.assign_none') }}</option>
                        @foreach ($noteDialog['members'] as $member)
                            <option value="{{ $member['id'] }}">{{ $member['label'] }}</option>
                        @endforeach
                    </select>
                    <div id="inbox-assign-err">
                        @error('assignTo') <p class="text-xs font-semibold text-danger-text">{{ $message }}</p> @enderror
                    </div>
                </div>
                @endif
                <div class="flex flex-wrap justify-end gap-2 border-t border-border-soft pt-3 max-[599.98px]:flex-col-reverse max-[599.98px]:*:w-full">
                    <x-bs-admin::button color="light" autofocus wire:click="closeNote">{{ __('admin.cancel') }}</x-bs-admin::button>
                    <x-bs-admin::button :color="$noteDialog['live'] ? 'primary' : 'danger'" :variant="$noteDialog['live'] ? 'solid' : 'outline'" wire:loading.attr="disabled" wire:target="apply" data-inbox-note-ok
                                        x-on:click="if (! String($wire.note ?? '').trim()) { bad = true; $refs.note.focus(); return; } $wire.apply({{ $Js::from($noteDialog['key']) }})">{{ $noteDialog['label'] }}</x-bs-admin::button>
                </div>
            </div>
        @endif
    </x-bs-admin::modal>

    {{-- «Duyệt đăng “…”?» of a first publication (board 17 «Khi nào đăng?»): «Đăng ngay» or «Hẹn giờ đăng» with
         «Đăng lúc» (first value tomorrow 08:00, the website's clock). A missing or past time is the field's error,
         checked when the field is left and on «Duyệt và hẹn giờ đăng», kept until it is put right (a change
         checks it again); the server judges the time again and answers with the same error. Focus starts on
         «Để tôi đọc lại đã» and goes back to the button that opened it. --}}
    @if ($reader !== null && in_array(true, array_column($reader['actions'], 'first'), true))
        @php($sched = $reader['schedule'])
        <div x-data="{ ap: null, mode: 'now', value: '', err: '', busy: false,
                days: {{ $Js::from(explode('|', __('admin.inbox.ap_days'))) }},
                open(d) { this.ap = d; this.mode = 'now'; this.value = {{ $Js::from($sched['at']) }}; this.err = ''; this.busy = false; },
                siteNow() { return new Date(Date.now() + {{ $Js::from($sched['offset']) }} * 1000).toISOString().slice(0, 16); },
                check() { if (this.mode !== 'sched') { return '' } if (! this.value.trim() || ! /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/.test(this.value)) { return {{ $Js::from(__('entries.sched_err_empty')) }} } return this.value.slice(0, 16) <= this.siteNow() ? {{ $Js::from(__('entries.sched_err_past')) }} : '' },
                pick(mode) { this.mode = mode; if (this.err) { this.err = this.check(); } },
                words(v) { const m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(v || ''); if (! m) { return '' } const day = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3])).getUTCDay(); return m[4] + ':' + m[5] + ' ' + (this.days[day] || '') + ', ' + m[3] + '/' + m[2] + '/' + m[1]; },
                conseq() { if (this.mode !== 'sched') { return {{ $Js::from(__('entries.wf_ap_text')) }} } return this.check() ? '' : {{ $Js::from(__('admin.inbox.ap_conseq')) }}.replace(':when', this.words(this.value)); },
                submit() {
                    if (this.busy || ! this.ap) { return }
                    this.err = this.check();
                    if (this.err) { document.getElementById('inbox-ap-at')?.focus(); return }
                    const key = this.ap.key, ulid = this.ap.ulid;
                    if (this.mode !== 'sched') { this.ap = null; $wire.apply(key, ulid); return }
                    this.busy = true;
                    $wire.approveLater(key, this.value, ulid).then((e) => { this.busy = false; if (e) { this.err = e; document.getElementById('inbox-ap-at')?.focus(); return } this.ap = null; }, () => { this.busy = false; });
                } }"
             x-on:inbox-ap.window="open($event.detail)" data-inbox-ap>
            <x-bs-admin::modal state="ap" close="ap = null" size="narrow" alert :close-label="__('admin.close')"
                               :title="__('entries.wf_ap_title', ['title' => \Illuminate\Support\Str::limit($reader['title'], 42, '…')])">
                <div class="flex flex-col gap-2" role="radiogroup" aria-labelledby="inbox-ap-g">
                    <span id="inbox-ap-g" class="text-small font-semibold text-text-strong">{{ __('admin.inbox.ap_group') }}</span>
                    <label class="flex min-h-hit cursor-pointer items-center gap-2.5 rounded-md border border-border-input px-3 has-checked:border-primary-text has-checked:bg-primary-text/6">
                        <input type="radio" name="inbox-ap" value="now" x-bind:checked="mode === 'now'" x-on:change="pick('now')" aria-describedby="inbox-ap-c" class="size-4.5 shrink-0 accent-primary">
                        <span class="min-w-0 text-text [overflow-wrap:anywhere]">{{ __('admin.inbox.ap_now') }}</span>
                    </label>
                    <label class="flex min-h-hit cursor-pointer items-center gap-2.5 rounded-md border border-border-input px-3 has-checked:border-primary-text has-checked:bg-primary-text/6">
                        <input type="radio" name="inbox-ap" value="sched" x-bind:checked="mode === 'sched'" x-on:change="pick('sched')" aria-describedby="inbox-ap-c" class="size-4.5 shrink-0 accent-primary" data-inbox-ap-sched>
                        <span class="min-w-0 text-text [overflow-wrap:anywhere]">{{ __('entries.sched_title') }}</span>
                    </label>
                </div>
                <div x-show="mode === 'sched'" x-cloak class="flex flex-col gap-1.5">
                    <label for="inbox-ap-at" class="text-small font-semibold text-text">{{ __('entries.sched_label') }}</label>
                    <input id="inbox-ap-at" type="datetime-local" x-model="value" x-on:blur="err = check()" x-on:input="err && (err = check())" x-on:keydown.enter.prevent="submit()"
                           aria-describedby="inbox-ap-hint inbox-ap-err" x-bind:aria-invalid="(err !== '').toString()"
                           class="field w-full rounded border bg-surface px-3 text-text" x-bind:class="err ? 'border-danger-text' : 'border-border-input'">
                    <p id="inbox-ap-hint" class="m-0 text-caption text-text-muted">{{ __('admin.inbox.ap_hint', ['zone' => $sched['zone']]) }}</p>
                    <x-bs-admin::field-error id="inbox-ap-err" x-cloak x-show="err" role="alert"><span x-text="err"></span></x-bs-admin::field-error>
                </div>
                <p id="inbox-ap-c" class="m-0 text-small text-text-muted [overflow-wrap:anywhere]" x-show="conseq() !== ''" x-text="conseq()"></p>
                <x-slot:footer>
                    <x-bs-admin::button color="light" autofocus x-on:click="ap = null">{{ __('entries.wf_ap_cancel') }}</x-bs-admin::button>
                    <x-bs-admin::button color="primary" data-inbox-ap-ok x-on:click="submit()" x-bind:disabled="busy">
                        <x-bs-admin::icon name="ri-check-double" class="size-4" /><span x-text="mode === 'sched' ? {{ $Js::from(__('admin.inbox.ap_ok_sched')) }} : {{ $Js::from(__('entries.wf_ap_ok')) }}">{{ __('entries.wf_ap_ok') }}</span>
                    </x-bs-admin::button>
                </x-slot:footer>
            </x-bs-admin::modal>
        </div>
    @endif
</div>
