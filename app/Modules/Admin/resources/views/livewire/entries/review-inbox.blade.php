{{--
    "Hộp duyệt" — design_handoff_workflow screen 3, ported onto the admin tokens: a list of what
    is waiting on the left, the picked post on the right, and one set of approval buttons at the
    foot of the reader. Below 940px only one pane shows at a time.

    Content is rendered as TEXT, never as the stored HTML: a reviewer needs to read the post, and
    running a tenant's markup inside the admin shell would hand them the page.
--}}
<div class="space-y-4">
    <x-admin::page-header :title="__('admin.inbox.title')" :subtitle="__('admin.inbox.subtitle')" />

    @error('inbox')
        <x-admin::alert color="danger" icon="ri-error-warning">{{ $message }}</x-admin::alert>
    @enderror

    {{-- Tabs --}}
    <div class="flex flex-wrap gap-2">
        @foreach ([['queue', 'ri-inbox-archive', $queueCount], ['mine', 'ri-user-follow', $mineCount]] as [$key, $icon, $count])
            <button type="button" wire:click="setTab('{{ $key }}')" @class([
                'inline-flex items-center gap-2 rounded border px-3.5 py-2 text-sm font-medium transition-colors',
                'border-admin-primary bg-admin-primary/5 text-admin-primary' => $tab === $key,
                'border-border text-body-muted hover:border-border-strong' => $tab !== $key,
            ])>
                <x-admin::icon :name="$icon" class="size-4" />
                {{ __('admin.inbox.tab_'.$key) }}
                <span @class([
                    'rounded-full px-1.5 text-[11px] font-bold',
                    'bg-admin-primary text-white' => $tab === $key,
                    'text-body-muted' => $tab !== $key,
                ])>{{ $count }}</span>
            </button>
        @endforeach
    </div>

    <div class="grid gap-4 @container lg:grid-cols-[352px_1fr]">
        {{-- LEFT: the queue --}}
        <x-admin::card class="{{ $reader !== null ? 'hidden lg:block' : '' }}">
            <x-slot:header>
                <div class="flex min-w-0 flex-1 items-center gap-2">
                    <span class="text-sm font-semibold text-heading">{{ __('admin.inbox.tab_'.$tab) }}</span>
                    <span class="ml-auto text-[11.5px] text-body-muted">{{ __('admin.inbox.showing', ['shown' => count($rows), 'total' => $total]) }}</span>
                </div>
            </x-slot:header>

            {{-- Search + type filter --}}
            <div class="mb-3 flex flex-col gap-2">
                <label class="flex items-center gap-2 rounded border border-border px-2.5 focus-within:border-admin-primary focus-within:ring-[3px] focus-within:ring-admin-primary/20">
                    <x-admin::icon name="ri-search" class="size-4 shrink-0 text-body-muted" />
                    <input type="search" wire:model.live.debounce.300ms="search" class="min-h-9 w-full border-0 bg-transparent p-0 text-[13px] text-body-text"
                           placeholder="{{ __('admin.inbox.search_placeholder') }}" aria-label="{{ __('admin.inbox.search_placeholder') }}">
                </label>
                @if (count($sections) > 1)
                    <select wire:model.live="sectionFilter" class="min-h-9 w-full rounded border border-border bg-surface px-2.5 text-[13px] text-body-text focus:border-admin-primary">
                        <option value="">{{ __('admin.inbox.all_types') }}</option>
                        @foreach ($sections as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                @endif
            </div>

            @if ($rows === [])
                <div class="flex flex-col items-center gap-2.5 px-4 py-7 text-center">
                    <x-admin::icon :name="$search !== '' || $sectionFilter !== '' ? 'ri-search-eye' : 'ri-checkbox-circle'" class="size-6 text-placeholder" />
                    <p class="max-w-[280px] text-[13px] leading-relaxed text-body-muted">
                        {{ $search !== '' || $sectionFilter !== '' ? __('admin.inbox.no_match') : __('admin.inbox.empty_'.$tab) }}
                    </p>
                    @if ($search !== '' || $sectionFilter !== '')
                        <x-admin::button color="light" size="sm" wire:click="clearFilters">{{ __('admin.inbox.clear_filters') }}</x-admin::button>
                    @endif
                </div>
            @else
                <ul class="-mx-4 divide-y divide-border-soft">
                    @foreach ($rows as $row)
                        <li wire:key="inbox-{{ $row['ulid'] }}">
                            <button type="button" wire:click="open(@js($row['ulid']))" @class([
                                'flex w-full gap-2.5 px-4 py-3 text-left transition-colors hover:bg-surface-hover',
                                'border-l-2 border-admin-primary bg-admin-primary/5' => $row['current'],
                                'border-l-2 border-transparent' => ! $row['current'],
                            ])>
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
                        <x-admin::button color="light" size="sm" wire:click="loadMore" wire:loading.attr="disabled">
                            <x-admin::icon name="ri-arrow-down" class="size-4" />{{ __('admin.inbox.load_more', ['count' => $total - count($rows)]) }}
                        </x-admin::button>
                    </div>
                @endif
            @endif
        </x-admin::card>

        {{-- RIGHT: the reader --}}
        @if ($reader === null)
            <x-admin::card class="hidden lg:block">
                <div class="flex flex-col items-center gap-3 px-6 py-10 text-center">
                    <span class="flex size-13 items-center justify-center rounded-full bg-admin-primary/10">
                        <x-admin::icon name="ri-file-list-3" class="size-6 text-admin-primary" />
                    </span>
                    <p class="text-[15px] font-semibold text-heading">{{ __('admin.inbox.pick_title') }}</p>
                    <p class="max-w-[420px] text-[13px] leading-relaxed text-body-muted">{{ __('admin.inbox.pick_body') }}</p>
                </div>
            </x-admin::card>
        @else
            <x-admin::card>
                <x-slot:header>
                    <div class="flex min-w-0 flex-1 items-center gap-2">
                        <x-admin::button color="light" size="sm" class="lg:hidden" wire:click="closeReader">
                            <x-admin::icon name="ri-arrow-left" class="size-4" />{{ __('admin.inbox.back') }}
                        </x-admin::button>
                        <span class="min-w-0 truncate text-sm font-semibold text-heading">{{ $reader['title'] }}</span>
                        @if ($reader['section'] !== '')
                            <span class="ml-auto shrink-0 rounded-full bg-surface-hover px-2.5 py-0.5 text-[10.5px] font-bold text-body-muted">{{ $reader['section'] }}</span>
                        @endif
                    </div>
                </x-slot:header>

                <div class="space-y-4">
                    {{-- Who sent it --}}
                    <div class="flex flex-wrap items-center gap-2.5">
                        <span class="inline-flex size-8 items-center justify-center rounded-full bg-admin-primary/10 text-[12px] font-bold text-admin-primary">
                            {{ mb_strtoupper(mb_substr(\Illuminate\Support\Str::of($reader['author'])->trim()->afterLast(' '), 0, 1)) }}
                        </span>
                        <span class="flex flex-col">
                            <span class="text-[12.5px] font-semibold text-heading">{{ $reader['author'] }}</span>
                            <span class="text-[11.5px] text-body-muted">{{ __('admin.inbox.sent_line', ['when' => $reader['sent_at']?->locale(app()->getLocale())->diffForHumans() ?? '']) }}</span>
                        </span>
                    </div>

                    {{-- Stage --}}
                    <div class="space-y-2">
                        <p class="text-[12.5px] font-bold text-admin-primary">
                            {{ __('admin.wfb.step_of', ['n' => $reader['step_no'], 'total' => $reader['step_count'], 'label' => $reader['step_label']]) }}
                            <span class="font-normal text-body-muted">· {{ $reader['workflow'] }}</span>
                        </p>
                        <ol class="flex flex-wrap items-center gap-1.5">
                            @foreach ($reader['steps'] as $step)
                                <li @class([
                                    'inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-[11.5px]',
                                    'border-admin-primary bg-admin-primary text-white' => $step['state'] === 'done',
                                    'border-admin-primary font-bold text-admin-primary' => $step['state'] === 'current' && ! $step['is_published'],
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
                            <x-admin::icon name="ri-image-add" class="size-4 shrink-0" />{{ __('admin.inbox.no_cover') }}
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
                        <div class="space-y-2.5">
                            @if (collect($reader['actions'])->contains('note', true))
                                <label class="block">
                                    <span class="mb-1 block text-xs font-semibold text-body-text">{{ __('admin.wfb.note_label') }}</span>
                                    <textarea wire:model="note" rows="2"
                                              class="w-full rounded border border-border px-3 py-2 text-[13px] text-body-text focus:border-admin-primary"
                                              placeholder="{{ __('admin.wfb.note_placeholder') }}"></textarea>
                                    @error('note') <span class="mt-1 block text-xs font-semibold text-[#c0432c]">{{ $message }}</span> @enderror
                                </label>
                            @endif
                            <div class="flex flex-wrap gap-2">
                                @foreach ($reader['actions'] as $action)
                                    @if ($action['to_published'])
                                        <x-admin::button color="primary" wire:key="act-{{ $action['key'] }}" wire:loading.attr="disabled"
                                                         x-on:click="$bsConfirm({
                                                             icon: 'question',
                                                             text: @js(__('admin.inbox.confirm_publish', ['title' => $reader['title']])),
                                                             confirm: @js($action['label']),
                                                             cancel: @js(__('admin.cancel')),
                                                         }).then(ok => ok && $wire.apply(@js($action['key'])))">
                                            <x-admin::icon name="ri-check-double" class="size-4" />{{ $action['label'] }}
                                        </x-admin::button>
                                    @elseif ($action['note'])
                                        <x-admin::button color="danger" variant="outline" wire:key="act-{{ $action['key'] }}"
                                                         wire:click="apply(@js($action['key']))" wire:loading.attr="disabled">
                                            <x-admin::icon name="ri-arrow-go-back" class="size-4" />{{ $action['label'] }}
                                        </x-admin::button>
                                    @else
                                        <x-admin::button color="light" wire:key="act-{{ $action['key'] }}"
                                                         wire:click="apply(@js($action['key']))" wire:loading.attr="disabled">
                                            <x-admin::icon name="ri-send-plane" class="size-4" />{{ $action['label'] }}
                                        </x-admin::button>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endif
                </x-slot:footer>
            </x-admin::card>
        @endif
    </div>
</div>
