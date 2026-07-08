<div class="entries-index">
    <x-admin::page-header :title="$sectionModel?->localizedLabel() ?? __('entries.section_fallback')">
        <x-slot:actions>
            <x-admin::button size="sm" color="primary" :href="route('admin.entries.create', $sectionKey ? ['section' => $sectionKey] : [])">
                <x-admin::icon name="ri-add" class="size-4" /> {{ __('entries.create_new') }}
            </x-admin::button>
        </x-slot:actions>
    </x-admin::page-header>

    @php
        $tabDefs = [
            'all' => __('entries.tab_all'),
            'published' => __('entries.status_published'),
            'draft' => __('entries.status_draft'),
            'scheduled' => __('entries.status_scheduled'),
            'private' => __('entries.status_private'),
        ];
        $statusColor = ['published' => 'success', 'draft' => 'secondary', 'scheduled' => 'warning', 'private' => 'info', 'pending' => 'warning', 'archived' => 'dark'];
        $avatarPalette = [
            ['bg' => '#e0e7ff', 'fg' => '#4338ca'], ['bg' => '#fce7f3', 'fg' => '#be185d'],
            ['bg' => '#d1fae5', 'fg' => '#047857'], ['bg' => '#fef3c7', 'fg' => '#b45309'],
            ['bg' => '#dbeafe', 'fg' => '#1d4ed8'], ['bg' => '#fee2e2', 'fg' => '#b91c1c'],
        ];
        $sortIcon = fn (string $key): string => $sortKey !== $key ? 'chevron-up-down' : ($sortDir === 'asc' ? 'chevron-up' : 'chevron-down');
    @endphp

    <x-admin::card class="mt-4">
        {{-- Status tabs + Trash (server-driven; counts are live) --}}
        <div class="-mt-1 mb-4 flex flex-wrap items-center gap-1 border-b border-border">
            @foreach ($tabDefs as $key => $label)
                <button type="button" wire:click="setTab('{{ $key }}')"
                    @class([
                        '-mb-px inline-flex items-center gap-2 border-b-2 px-3 py-2.5 text-sm font-medium transition-colors',
                        'border-admin-primary text-admin-primary' => $tab === $key,
                        'border-transparent text-body-faint hover:text-body-text' => $tab !== $key,
                    ])>
                    {{ $label }}
                    <span @class([
                        'rounded px-1.5 py-0.5 text-xs font-semibold leading-none',
                        'bg-admin-primary/10 text-admin-primary' => $tab === $key,
                        'bg-surface-muted text-body-faint' => $tab !== $key,
                    ])>{{ $counts[$key] ?? 0 }}</span>
                </button>
            @endforeach
            <button type="button" wire:click="setTab('trash')"
                @class([
                    '-mb-px ml-auto inline-flex items-center gap-2 border-b-2 px-3 py-2.5 text-sm font-medium transition-colors',
                    'border-admin-danger text-admin-danger' => $tab === 'trash',
                    'border-transparent text-body-faint hover:text-body-text' => $tab !== 'trash',
                ])>
                <x-admin::icon name="ri-delete-bin" class="size-4" /> {{ __('entries.tab_trash') }}
                <span class="rounded bg-admin-danger/10 px-1.5 py-0.5 text-xs font-semibold leading-none text-admin-danger">{{ $counts['trash'] }}</span>
            </button>
        </div>

        {{-- Column visibility lives client-side (instant + persisted), so paging never resets it. --}}
        <div x-data="{
            cols: JSON.parse(localStorage.getItem('pagesCols')) || { path: true, langs: true, creator: true },
            toggleCol(k) { this.cols[k] = !this.cols[k]; localStorage.setItem('pagesCols', JSON.stringify(this.cols)); },
        }">
            {{-- Toolbar: search · filters · column picker --}}
            <div class="mb-3 flex flex-wrap items-center gap-2">
                <div class="relative w-full max-w-xs">
                    <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-placeholder">
                        <x-admin::icon name="ri-search" class="size-4" />
                    </span>
                    <label for="pages-search" class="sr-only">{{ __('entries.search_placeholder') }}</label>
                    <input id="pages-search" type="search" wire:model.live.debounce.300ms="search"
                        placeholder="{{ __('entries.search_placeholder') }}"
                        class="block w-full rounded border border-border-strong bg-surface py-2 pl-9 pr-3 text-sm text-body-text placeholder:text-placeholder focus:border-admin-primary focus:outline-none focus:ring-2 focus:ring-admin-primary/20">
                </div>

                @include('admin::livewire.entries.partials.filter-dropdown', ['group' => 'lang', 'label' => __('entries.col_language'), 'options' => $facets['lang'], 'activeCount' => count($langF)])
                @include('admin::livewire.entries.partials.filter-dropdown', ['group' => 'creator', 'label' => __('entries.col_creator'), 'options' => $facets['creator'], 'activeCount' => count($creatorF)])
                @include('admin::livewire.entries.partials.filter-dropdown', ['group' => 'template', 'label' => __('entries.filter_template'), 'options' => $facets['template'], 'activeCount' => count($templateF)])

                <div class="ml-auto h-6 w-px bg-border"></div>

                <div x-data="{ open: false }" @click.outside="open = false" class="relative">
                    <button type="button" @click="open = !open"
                        class="inline-flex items-center gap-2 rounded border border-border-strong bg-surface px-3 py-2 text-sm font-medium text-body-text transition-colors hover:border-admin-primary">
                        <x-admin::icon name="ri-grid" class="size-4" /> {{ __('entries.cols_button') }}
                        <x-admin::icon name="chevron-down" class="size-3.5 transition-transform" ::class="open && 'rotate-180'" />
                    </button>
                    <div x-show="open" x-cloak x-transition
                        class="absolute right-0 z-30 mt-1 w-56 rounded-lg border border-border bg-surface p-1.5 shadow-lg">
                        <p class="px-2 pb-1 pt-1.5 text-xs font-semibold uppercase tracking-wide text-placeholder">{{ __('entries.cols_required') }}</p>
                        @foreach ([__('entries.title_label'), __('entries.col_status'), __('entries.col_actions')] as $locked)
                            <label class="flex cursor-not-allowed items-center gap-2.5 rounded px-2 py-1.5 text-sm text-body-faint">
                                <input type="checkbox" checked disabled class="size-4 rounded border-border-strong">
                                {{ $locked }}
                            </label>
                        @endforeach
                        <p class="px-2 pb-1 pt-2 text-xs font-semibold uppercase tracking-wide text-placeholder">{{ __('entries.cols_optional') }}</p>
                        @foreach (['path' => __('entries.col_path'), 'langs' => __('entries.col_language'), 'creator' => __('entries.col_creator')] as $key => $label)
                            <label class="flex cursor-pointer items-center gap-2.5 rounded px-2 py-1.5 text-sm text-body-text hover:bg-surface-hover">
                                <input type="checkbox" :checked="cols.{{ $key }}" @change="toggleCol('{{ $key }}')"
                                    class="size-4 rounded border-border-strong text-admin-primary focus:ring-admin-primary/30">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Active filter chips --}}
            @if (count($activeChips) > 0)
                <div class="mb-4 flex flex-wrap items-center gap-2">
                    <span class="text-xs font-medium text-body-faint">{{ __('entries.filter_active') }}</span>
                    @foreach ($activeChips as $chip)
                        <button type="button" wire:click="toggleFilterValue('{{ $chip['group'] }}', '{{ $chip['value'] }}')"
                            class="inline-flex items-center gap-1 rounded-full bg-admin-primary/10 py-1 pl-2.5 pr-1.5 text-xs font-medium text-admin-primary transition-colors hover:bg-admin-primary/20">
                            {{ $chip['label'] }} <x-admin::icon name="ri-close" class="size-3.5" />
                        </button>
                    @endforeach
                    <button type="button" wire:click="clearAllFilters" class="ml-1 text-xs text-admin-danger hover:underline">{{ __('entries.filter_clear_all') }}</button>
                    <span class="ml-auto text-xs text-placeholder">{{ __('entries.filter_match', ['count' => $matchCount, 'total' => $baseCount]) }}</span>
                </div>
            @endif

            {{-- Bulk action bar — appears once one or more rows are selected --}}
            @if (count($selected) > 0)
                <div class="mb-4 flex flex-wrap items-center gap-2 rounded-lg border border-admin-primary/30 bg-admin-primary/5 px-3 py-2">
                    <span class="text-sm font-medium text-admin-primary">{{ __('entries.bulk_selected', ['count' => count($selected)]) }}</span>
                    @if ($tab === 'trash')
                        @if ($canDelete)
                            <button type="button" wire:click="bulkRestore"
                                class="rounded border border-border-strong bg-surface px-2.5 py-1 text-sm font-medium text-body-text transition-colors hover:border-admin-primary hover:text-admin-primary">{{ __('entries.action_restore') }}</button>
                            <button type="button"
                                x-on:click="$bsConfirm({ icon: 'trash-danger', text: @js(__('entries.force_delete_confirm')), confirm: @js(__('entries.action_destroy')), cancel: @js(__('admin.cancel')) }).then(ok => ok && $wire.bulkForceDelete())"
                                class="rounded border border-border-strong bg-surface px-2.5 py-1 text-sm font-medium text-body-text transition-colors hover:border-admin-danger hover:text-admin-danger">{{ __('entries.action_destroy') }}</button>
                        @endif
                    @else
                        @if ($canPublish)
                            <button type="button" wire:click="bulkPublish"
                                class="rounded border border-border-strong bg-surface px-2.5 py-1 text-sm font-medium text-body-text transition-colors hover:border-admin-primary hover:text-admin-primary">{{ __('entries.bulk_publish') }}</button>
                            <button type="button" wire:click="bulkDraft"
                                class="rounded border border-border-strong bg-surface px-2.5 py-1 text-sm font-medium text-body-text transition-colors hover:border-admin-primary hover:text-admin-primary">{{ __('entries.bulk_draft') }}</button>
                        @endif
                        @if ($canCreate)
                            <button type="button" wire:click="bulkDuplicate"
                                class="rounded border border-border-strong bg-surface px-2.5 py-1 text-sm font-medium text-body-text transition-colors hover:border-admin-primary hover:text-admin-primary">{{ __('entries.action_duplicate') }}</button>
                        @endif
                        @if ($canDelete)
                            <button type="button"
                                x-on:click="$bsConfirm({ icon: 'trash', text: @js(__('entries.trash_confirm')), confirm: @js(__('entries.action_trash')), cancel: @js(__('admin.cancel')) }).then(ok => ok && $wire.bulkTrash())"
                                class="rounded border border-border-strong bg-surface px-2.5 py-1 text-sm font-medium text-body-text transition-colors hover:border-admin-danger hover:text-admin-danger">{{ __('entries.action_trash') }}</button>
                        @endif
                    @endif
                    <button type="button" wire:click="clearSelection" class="ml-auto text-sm text-body-faint transition-colors hover:text-body-text">{{ __('entries.bulk_deselect') }}</button>
                </div>
            @endif

            <x-admin::table>
                <thead>
                    <tr>
                        <th class="w-10">
                            <input type="checkbox" @checked($pageUlids !== [] && array_diff($pageUlids, $selected) === [])
                                wire:click="toggleSelectAll(@js($pageUlids))" aria-label="{{ __('entries.select_all') }}"
                                class="size-4 rounded border-border-strong text-admin-primary focus:ring-admin-primary/30">
                        </th>
                        <th class="w-full">
                            <button type="button" wire:click="sortBy('name')" class="inline-flex items-center gap-1 hover:text-body-text">
                                {{ __('entries.title_label') }} <x-admin::icon :name="$sortIcon('name')" class="size-3.5" />
                            </button>
                        </th>
                        <th x-show="cols.path">{{ __('entries.col_path') }}</th>
                        <th>
                            <button type="button" wire:click="sortBy('status')" class="inline-flex items-center gap-1 hover:text-body-text">
                                {{ __('entries.col_status') }} <x-admin::icon :name="$sortIcon('status')" class="size-3.5" />
                            </button>
                        </th>
                        <th x-show="cols.langs">{{ __('entries.col_language') }}</th>
                        <th x-show="cols.creator">
                            <button type="button" wire:click="sortBy('creator')" class="inline-flex items-center gap-1 hover:text-body-text">
                                {{ __('entries.col_creator') }} <x-admin::icon :name="$sortIcon('creator')" class="size-3.5" />
                            </button>
                        </th>
                        <th class="text-right"><span class="sr-only">{{ __('entries.col_actions') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        @php
                            $entry = $row['entry'];
                            $depth = $row['depth'];
                            $t = $entry->translations->firstWhere('locale', $locale);
                            $route = $entry->routes->firstWhere('locale', $locale);
                            $path = $route?->path ?? ($t?->slug ? '/'.ltrim($t->slug, '/') : null);
                            $isHome = $path === '/' || $t?->slug === $homeSlug;
                            $status = $entry->status;
                            $isScheduled = $status === 'scheduled';
                            $creator = $entry->author?->name;
                            $parts = $creator ? preg_split('/\s+/', trim($creator)) : [];
                            $initials = $parts ? mb_strtoupper(mb_substr($parts[0], 0, 1).(count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '')) : '—';
                            $c = $avatarPalette[$creator ? abs(crc32($creator)) % count($avatarPalette) : 0];
                            $when = $isScheduled ? $entry->published_at : $entry->updated_at;
                        @endphp
                        <tr wire:key="entry-{{ $entry->ulid }}">
                            <td class="align-top">
                                <input type="checkbox" wire:model.live="selected" value="{{ $entry->ulid }}"
                                    aria-label="{{ __('entries.select_row') }}"
                                    class="mt-0.5 size-4 rounded border-border-strong text-admin-primary focus:ring-admin-primary/30">
                            </td>
                            <td class="w-full">
                                <div @class(['flex items-center gap-1.5', 'pl-5' => $depth])>
                                    @if ($depth)
                                        <x-admin::icon name="chevron-right" class="size-3.5 shrink-0 text-placeholder" />
                                    @endif
                                    <span class="font-medium text-body-text">{{ $t?->title ?? __('entries.untitled') }}</span>
                                </div>
                            </td>
                            <td x-show="cols.path" class="max-w-[240px] truncate font-mono text-xs text-body-faint" title="{{ $path }}">
                                @if ($isHome)
                                    <span class="inline-flex items-center gap-1 text-placeholder"><x-admin::icon name="home" class="size-3.5" /> /</span>
                                @else
                                    {{ $path ?? '—' }}
                                @endif
                            </td>
                            <td class="whitespace-nowrap align-top">
                                @if ($canPublish && ! $entry->trashed())
                                    <div x-data="{ open: false, sched: false }" @click.outside="open = false; sched = false" class="relative inline-block">
                                        <button type="button" @click="open = ! open" class="inline-flex items-center" aria-label="{{ __('entries.status_menu_label') }}">
                                            <x-admin::badge soft :color="$statusColor[$status] ?? 'secondary'">
                                                {{ __('entries.status_'.$status) }} <x-admin::icon name="chevron-down" class="size-3" />
                                            </x-admin::badge>
                                        </button>
                                        <div x-show="open" x-cloak x-transition
                                            class="absolute left-0 z-30 mt-1 w-48 rounded-lg border border-border bg-surface p-1 shadow-lg">
                                            @foreach (['published', 'draft', 'private'] as $opt)
                                                <button type="button" x-on:click="$wire.changeStatus(@js($entry->ulid), @js($opt)); open = false"
                                                    class="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left hover:bg-surface-hover">
                                                    <x-admin::badge soft :color="$statusColor[$opt]">{{ __('entries.status_'.$opt) }}</x-admin::badge>
                                                    @if ($status === $opt)<x-admin::icon name="ri-check" class="ml-auto size-3.5 text-admin-primary" />@endif
                                                </button>
                                            @endforeach
                                            <div class="mt-0.5 border-t border-border pt-0.5">
                                                <button type="button" @click="sched = ! sched"
                                                    class="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left hover:bg-surface-hover">
                                                    <x-admin::badge soft color="warning">{{ __('entries.status_scheduled') }}</x-admin::badge>
                                                    <x-admin::icon name="chevron-down" class="ml-auto size-3.5 text-placeholder transition-transform" ::class="sched && 'rotate-180'" />
                                                </button>
                                                <div x-show="sched" x-cloak class="px-2 pb-1.5 pt-1">
                                                    <label class="mb-1 block text-[11px] text-body-faint">{{ __('entries.schedule_at') }}</label>
                                                    <input type="datetime-local" x-ref="when"
                                                        class="w-full rounded border border-border-strong bg-surface px-2 py-1 text-xs text-body-text">
                                                    <button type="button" x-on:click="$wire.scheduleEntry(@js($entry->ulid), $refs.when.value); open = false; sched = false"
                                                        class="mt-1.5 w-full rounded bg-admin-primary py-1 text-xs font-medium text-white transition-opacity hover:opacity-90">{{ __('entries.schedule_set_action') }}</button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                @else
                                    <x-admin::badge soft :color="$statusColor[$status] ?? 'secondary'">{{ __('entries.status_'.$status) }}</x-admin::badge>
                                @endif
                                @if ($when)
                                    <div class="mt-1 text-[11px] text-placeholder">
                                        @if ($isScheduled)
                                            {{ __('entries.scheduled_prefix') }} {{ $when->locale(app()->getLocale())->isoFormat('D MMM, Y') }} · {{ $when->format('H:i') }}
                                        @else
                                            {{ $when->locale(app()->getLocale())->isoFormat('D MMM, Y') }}
                                        @endif
                                    </div>
                                @endif
                            </td>
                            <td x-show="cols.langs" class="whitespace-nowrap">
                                <div class="flex flex-wrap items-center gap-1">
                                    @foreach ($locales as $lc)
                                        @php $has = $entry->translations->contains(fn ($tr) => $tr->locale === $lc); @endphp
                                        <span @class([
                                            'inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-bold uppercase leading-none',
                                            'border border-border bg-surface-muted text-body-text' => $has,
                                            'border border-dashed border-border-strong text-placeholder' => ! $has,
                                        ])>{{ strtoupper($lc) }}</span>
                                    @endforeach
                                </div>
                            </td>
                            <td x-show="cols.creator" class="whitespace-nowrap">
                                <div class="flex items-center gap-2">
                                    <span class="grid size-6 shrink-0 place-items-center rounded-full text-[10px] font-bold" style="background: {{ $c['bg'] }}; color: {{ $c['fg'] }};">{{ $initials }}</span>
                                    <span class="text-sm text-body-text">{{ $creator ?? '—' }}</span>
                                </div>
                            </td>
                            <td class="whitespace-nowrap text-right align-top">
                                <div class="inline-flex items-center gap-0.5">
                                    @if ($entry->trashed())
                                        @if ($canDelete)
                                            <button type="button" wire:click="restore('{{ $entry->ulid }}')" title="{{ __('entries.action_restore') }}"
                                                class="grid size-7 place-items-center rounded text-body-faint transition-colors hover:bg-surface-hover hover:text-body-text">
                                                <x-admin::icon name="ri-refresh" class="size-4" />
                                            </button>
                                            <button type="button" title="{{ __('entries.action_destroy') }}"
                                                x-on:click="$bsConfirm({ icon: 'trash-danger', text: @js(__('entries.force_delete_confirm')), confirm: @js(__('entries.action_destroy')), cancel: @js(__('admin.cancel')) }).then(ok => ok && $wire.forceDelete(@js($entry->ulid)))"
                                                class="grid size-7 place-items-center rounded text-body-faint transition-colors hover:bg-surface-hover hover:text-admin-danger">
                                                <x-admin::icon name="ri-delete-bin" class="size-4" />
                                            </button>
                                        @endif
                                    @else
                                        @if ($path)
                                            <a href="{{ $path }}" target="_blank" rel="noopener" title="{{ __('entries.action_preview') }}"
                                                class="grid size-7 place-items-center rounded text-body-faint transition-colors hover:bg-surface-hover hover:text-body-text">
                                                <x-admin::icon name="ri-eye" class="size-4" />
                                            </a>
                                        @endif
                                        <a href="{{ route('admin.entries.edit', ['ulid' => $entry->ulid]) }}" wire:navigate title="{{ __('entries.edit_action') }}"
                                            class="grid size-7 place-items-center rounded text-body-faint transition-colors hover:bg-surface-hover hover:text-body-text">
                                            <x-admin::icon name="ri-pencil" class="size-4" />
                                        </a>
                                        @if ($canCreate)
                                            <button type="button" wire:click="duplicate('{{ $entry->ulid }}')" title="{{ __('entries.action_duplicate') }}"
                                                class="grid size-7 place-items-center rounded text-body-faint transition-colors hover:bg-surface-hover hover:text-body-text">
                                                <x-admin::icon name="ri-file-copy" class="size-4" />
                                            </button>
                                        @endif
                                        @if ($canDelete)
                                            <button type="button" title="{{ __('entries.action_trash') }}"
                                                x-on:click="$bsConfirm({ icon: 'trash', text: @js(__('entries.trash_confirm')), confirm: @js(__('entries.action_trash')), cancel: @js(__('admin.cancel')) }).then(ok => ok && $wire.trash(@js($entry->ulid)))"
                                                class="grid size-7 place-items-center rounded text-body-faint transition-colors hover:bg-surface-hover hover:text-admin-danger">
                                                <x-admin::icon name="ri-delete-bin" class="size-4" />
                                            </button>
                                        @endif
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-12 text-center text-body-faint">
                                @if ($tab === 'trash')
                                    {{ __('entries.trash_empty') }}
                                @elseif (trim($search) !== '')
                                    {{ __('entries.search_empty') }}
                                @else
                                    {{ __('entries.empty') }}
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </x-admin::table>

            <div class="mt-4 flex justify-end">
                <x-admin::pagination :paginator="$rows" />
            </div>
        </div>
    </x-admin::card>
</div>
