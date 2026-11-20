{{--
    "Hộp duyệt" — board 17, ported onto the admin tokens: a list of what is waiting on the left,
    the picked post on the right, and one set of approval buttons at the foot of the reader. Below
    940px only one pane shows at a time.

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
<div class="space-y-4">
    <x-bs-admin::page-header :title="__('admin.inbox.title')" :subtitle="__('admin.inbox.subtitle')" />

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
    <div class="flex flex-wrap gap-2">
        @foreach ([['queue', 'ri-inbox-archive', $queueCount], ['mine', 'ri-user-follow', $mineCount]] as [$key, $icon, $count])
            <button type="button" wire:click="setTab('{{ $key }}')" @class([
                'inline-flex items-center gap-2 rounded border px-3.5 py-2 text-sm font-medium transition-colors',
                'border-accent bg-admin-primary/5 text-accent' => $tab === $key,
                'border-border text-body-muted hover:border-border-strong' => $tab !== $key,
            ])>
                <x-bs-admin::icon :name="$icon" class="size-4" />
                {{ __('admin.inbox.tab_'.$key) }}
                @unless ($loadFailed)
                    <span @class([
                        'rounded-full px-1.5 text-[11px] font-bold',
                        'bg-primary text-white' => $tab === $key,
                        'text-text-muted' => $tab !== $key,
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
    @else
    <div class="grid gap-4 @container lg:grid-cols-[352px_1fr]" wire:loading.delay.remove wire:target="{{ $reloads }}">
        {{-- LEFT: the queue --}}
        <x-bs-admin::card class="{{ $reader !== null ? 'hidden lg:block' : '' }}">
            <x-slot:header>
                <div class="flex min-w-0 flex-1 items-center gap-2">
                    <span class="text-sm font-semibold text-heading">{{ __('admin.inbox.tab_'.$tab) }}</span>
                    <span class="ml-auto text-[11.5px] text-body-muted">{{ __('admin.inbox.showing', ['shown' => count($rows), 'total' => $total]) }}</span>
                </div>
            </x-slot:header>

            {{-- Ticked posts: «Đã chọn N bài» · «Duyệt đăng M bài» (asked first) · «Bỏ chọn», and what happens to the rest --}}
            @if ($bulk !== null)
                <x-bs-admin::list.bulk :label="__('admin.inbox.bulk_region')" :status="__('admin.inbox.picked', ['count' => $bulk['picked']])"
                                       clear="clearPicked" :clear-label="__('admin.inbox.bulk_clear')" :skip="$bulk['why']" class="min-[600px]:mx-0!" data-inbox-bulk>
                    @if ($bulk['ready'] > 0)
                        <x-bs-admin::button size="sm" color="primary" :title="$bulk['why']" wire:loading.attr="disabled" wire:target="approvePicked"
                                            x-on:click="$bsConfirm({
                                                icon: 'question',
                                                danger: false,
                                                title: {{ $Js::from(__('admin.inbox.bulk_title', ['count' => $bulk['ready']])) }},
                                                text: {{ $Js::from(__('admin.inbox.bulk_text', ['count' => $bulk['ready']])) }},
                                                confirm: {{ $Js::from(__('admin.inbox.bulk_ok', ['count' => $bulk['ready']])) }},
                                                cancel: {{ $Js::from(__('entries.wf_ap_cancel')) }},
                                            }).then(ok => ok && $wire.approvePicked())">
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
                <label class="field flex items-center gap-2 rounded border border-border px-2.5 focus-within:border-primary-text focus-within:ring-[3px] focus-within:ring-primary/20">
                    <x-bs-admin::icon name="ri-search" class="size-4 shrink-0 text-body-muted" />
                    <input type="search" wire:model.live.debounce.300ms="search" class="w-full border-0 bg-transparent p-0 text-text"
                           placeholder="{{ __('admin.inbox.search_placeholder') }}" aria-label="{{ __('admin.inbox.search_placeholder') }}">
                </label>
                @if (count($sections) > 1)
                    <select wire:model.live="sectionFilter" class="field w-full rounded border border-border bg-surface px-2.5 text-text focus:border-primary-text" aria-label="{{ __('admin.inbox.all_types') }}">
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
                <ul class="-mx-4 divide-y divide-border-soft">
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
                            <button type="button" wire:click="open(@js($row['ulid']))" class="flex min-w-0 flex-1 gap-2.5 py-3 pe-4 ps-1 text-left transition-colors hover:bg-surface-hover">
                                <span class="flex min-w-0 flex-1 flex-col gap-1">
                                    <span class="truncate text-[13px] font-semibold text-heading">{{ $row['title'] }}</span>
                                    <span class="flex flex-wrap items-center gap-1.5 text-[11.5px] text-body-muted">
                                        <span class="size-1.5 shrink-0 rounded-full bg-admin-warning"></span>
                                        <span class="font-medium text-body-text">{{ $row['stage'] }}</span>
                                        @if ($row['section'] !== '')
                                            <span class="text-placeholder">·</span><span>{{ $row['section'] }}</span>
                                        @endif
                                    </span>
                                    <span class="flex flex-wrap items-center gap-1.5 text-[11.5px] text-body-muted">
                                        <span>{{ $row['author'] }}</span>
                                        <span class="text-placeholder">·</span>
                                        <span class="{{ $row['late'] ? 'font-semibold text-[#8a5f18]' : '' }}">{{ $row['waited_at']?->locale(app()->getLocale())->diffForHumans() }}</span>
                                    </span>
                                </span>
                            </button>
                        </li>
                    @endforeach
                </ul>

                @if ($total > count($rows))
                    <div class="mt-3 flex justify-center">
                        <x-bs-admin::button color="light" size="sm" wire:click="loadMore" wire:loading.attr="disabled">
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
                    <span class="flex size-13 items-center justify-center rounded-full bg-admin-primary/10">
                        <x-bs-admin::icon name="ri-file-list-3" class="size-6 text-accent" />
                    </span>
                    <p class="text-[15px] font-semibold text-heading">{{ __('admin.inbox.pick_title') }}</p>
                    <p class="max-w-[420px] text-[13px] leading-relaxed text-body-muted">{{ __('admin.inbox.pick_body') }}</p>
                </div>
            </x-bs-admin::card>
        @else
            <x-bs-admin::card>
                <x-slot:header>
                    <div class="flex min-w-0 flex-1 items-center gap-2">
                        <x-bs-admin::button color="light" size="sm" class="shrink-0 lg:hidden" wire:click="closeReader">
                            <x-bs-admin::icon name="ri-arrow-left" class="size-4" />{{ __('admin.inbox.back') }}
                        </x-bs-admin::button>
                        <span class="min-w-0 truncate text-sm font-semibold text-heading">{{ $reader['title'] }}</span>
                        @if ($reader['section'] !== '')
                            <span class="ml-auto shrink-0 rounded-full bg-surface-hover px-2.5 py-0.5 text-[10.5px] font-bold text-body-muted">{{ $reader['section'] }}</span>
                        @endif
                    </div>
                </x-slot:header>

                <div class="space-y-4">
                    {{-- Who sent it --}}
                    <div class="flex flex-wrap items-center gap-2.5">
                        <span class="inline-flex size-8 items-center justify-center rounded-full bg-admin-primary/10 text-[12px] font-bold text-accent">
                            {{ mb_strtoupper(mb_substr(\Illuminate\Support\Str::of($reader['author'])->trim()->afterLast(' '), 0, 1)) }}
                        </span>
                        <span class="flex flex-col">
                            <span class="text-[12.5px] font-semibold text-heading">{{ $reader['author'] }}</span>
                            <span class="text-[11.5px] text-body-muted">{{ __('admin.inbox.sent_line', ['when' => $reader['sent_at']?->locale(app()->getLocale())->diffForHumans() ?? '']) }}</span>
                        </span>
                    </div>

                    {{-- Stage --}}
                    <div class="space-y-2">
                        <p class="text-[12.5px] font-bold text-accent">
                            {{ __('admin.wfb.step_of', ['n' => $reader['step_no'], 'total' => $reader['step_count'], 'label' => $reader['step_label']]) }}
                            <span class="font-normal text-body-muted">· {{ $reader['workflow'] }}</span>
                        </p>
                        <ol class="flex flex-wrap items-center gap-1.5">
                            @foreach ($reader['steps'] as $step)
                                <li @class([
                                    'inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-[11.5px]',
                                    'border-admin-primary bg-admin-primary text-white' => $step['state'] === 'done',
                                    'border-accent font-bold text-accent' => $step['state'] === 'current' && ! $step['is_published'],
                                    'border-admin-success-text font-bold text-[#0a6d60]' => $step['state'] === 'current' && $step['is_published'],
                                    'border-border text-body-muted' => $step['state'] === 'todo',
                                ])>{{ $step['label'] }}</li>
                            @endforeach
                        </ol>
                    </div>

                    {{-- Cover --}}
                    @if ($reader['cover'] !== null)
                        <img src="{{ $reader['cover'] }}" alt="{{ __('entries.cover_alt') }}" class="max-h-64 w-auto rounded border border-border-soft" loading="lazy">
                    @else
                        <p class="flex items-center gap-2 rounded bg-admin-warning/15 px-3 py-2.5 text-[12.5px] text-[#8a5f18]">
                            <x-bs-admin::icon name="ri-image-add" class="size-4 shrink-0" />{{ __('admin.inbox.no_cover') }}
                        </p>
                    @endif

                    {{-- The post, as text --}}
                    <div class="space-y-2">
                        <p class="text-[11px] font-bold uppercase tracking-[.06em] text-body-muted">{{ __('admin.inbox.content') }}</p>
                        @forelse ($reader['blocks'] as $block)
                            @php
                                $text = trim(strip_tags(collect($block['props'] ?? [])->flatten()->filter(fn ($v) => is_string($v))->implode("\n")));
                            @endphp
                            @if ($text !== '')
                                <p class="whitespace-pre-line text-[13px] leading-relaxed text-body-text">{{ \Illuminate\Support\Str::limit($text, 1200) }}</p>
                            @endif
                        @empty
                            <p class="rounded bg-admin-warning/15 px-3 py-2.5 text-[12.5px] text-[#8a5f18]">{{ __('admin.inbox.no_content') }}</p>
                        @endforelse
                    </div>

                    {{-- What visitors and Google will see --}}
                    <div class="space-y-1 rounded border border-border-soft p-3">
                        <p class="text-[11px] font-bold uppercase tracking-[.06em] text-body-muted">{{ __('admin.inbox.seo_title') }}</p>
                        <p class="text-[13px] text-body-text">{{ $reader['seo_title'] ?: $reader['title'] }}</p>
                        <p class="text-[12.5px] leading-relaxed text-body-muted">{{ $reader['seo_description'] ?: $reader['excerpt'] ?: __('admin.inbox.seo_auto') }}</p>
                        @if ($reader['path'] !== null)
                            <p class="text-[11.5px] text-[#22688f]">{{ $reader['path'] }}</p>
                        @endif
                    </div>

                    {{-- Trail --}}
                    @if ($reader['trail'] !== [])
                        <div class="space-y-1.5">
                            <p class="text-[11px] font-bold uppercase tracking-[.06em] text-body-muted">{{ __('admin.wfb.trail_title') }}</p>
                            @foreach ($reader['trail'] as $row)
                                <p class="text-[12px] text-body-muted" wire:key="inbox-log-{{ $row['id'] }}">
                                    <span class="font-semibold text-body-text">{{ $row['actor'] ?? __('admin.wfb.actor_gone') }}</span>
                                    {{ mb_strtolower($row['transition']) }} · {{ $row['from'] }} → {{ $row['to'] }} · {{ $row['at'] }}
                                    @if ($row['note'] !== null)
                                        <span class="mt-1 block rounded-r border-l-2 border-border bg-surface-muted px-3 py-2 text-[12.5px] text-body-text">{{ $row['note'] }}</span>
                                    @endif
                                </p>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- The only place approval actions live (design: foot of the reader) --}}
                <x-slot:footer>
                    @if ($reader['actions'] === [])
                        <p class="text-[12.5px] text-body-muted">{{ __('admin.inbox.no_actions') }}</p>
                    @else
                        <div class="flex flex-wrap gap-2">
                            @foreach ($reader['actions'] as $action)
                                @if ($action['note'])
                                    {{-- A move that demands a reason opens the reason dialog (with «Giao cho») --}}
                                    <x-bs-admin::button :color="$action['to_published'] ? 'primary' : 'danger'" :variant="$action['to_published'] ? 'solid' : 'outline'" wire:key="act-{{ $action['key'] }}" data-inbox-act="{{ $action['key'] }}"
                                                     wire:click="askNote({{ $Js::from($action['key']) }})" wire:loading.attr="disabled">
                                        <x-bs-admin::icon :name="$action['icon']" class="size-4" />{{ $action['label'] }}
                                    </x-bs-admin::button>
                                @elseif ($action['first'])
                                    {{-- The first publication asks «Khi nào đăng?» first (board 17): now, or approved for a later time --}}
                                    <x-bs-admin::button color="primary" wire:key="act-{{ $action['key'] }}" wire:loading.attr="disabled" data-inbox-act="{{ $action['key'] }}"
                                                     x-on:click="$dispatch('inbox-ap', {{ $Js::from(['key' => $action['key']]) }})">
                                        <x-bs-admin::icon name="ri-check-double" class="size-4" />{{ $action['label'] }}
                                    </x-bs-admin::button>
                                @elseif ($action['to_published'])
                                    {{-- Going live is asked first (board 17 «Duyệt đăng “…”?», the same question as the posts list) --}}
                                    <x-bs-admin::button color="primary" wire:key="act-{{ $action['key'] }}" wire:loading.attr="disabled" data-inbox-act="{{ $action['key'] }}"
                                                     x-on:click="$bsConfirm({
                                                         icon: 'question',
                                                         danger: false,
                                                         title: {{ $Js::from(__('entries.wf_ap_title', ['title' => \Illuminate\Support\Str::limit($reader['title'], 42, '…')])) }},
                                                         text: {{ $Js::from(__('entries.wf_ap_text')) }},
                                                         confirm: {{ $Js::from(__('entries.wf_ap_ok')) }},
                                                         cancel: {{ $Js::from(__('entries.wf_ap_cancel')) }},
                                                     }).then(ok => ok && $wire.apply({{ $Js::from($action['key']) }}))">
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
                </x-slot:footer>
            </x-bs-admin::card>
        @endif
    </div>
    @endif

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
                <div class="flex flex-wrap justify-end gap-2 border-t border-border-soft pt-3 max-[599.98px]:*:flex-[1_1_100%]">
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
                    const key = this.ap.key;
                    if (this.mode !== 'sched') { this.ap = null; $wire.apply(key); return }
                    this.busy = true;
                    $wire.approveLater(key, this.value).then((e) => { this.busy = false; if (e) { this.err = e; document.getElementById('inbox-ap-at')?.focus(); return } this.ap = null; }, () => { this.busy = false; });
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
