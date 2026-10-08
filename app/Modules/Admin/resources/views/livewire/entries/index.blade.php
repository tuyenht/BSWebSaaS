<div class="entries-index">
    @php $sectionLabel = $sectionModel?->localizedLabel() ?? __('entries.section_fallback'); @endphp
    <x-bs-admin::page-header :title="__('entries.manage_title', ['label' => $sectionLabel])">
        <x-slot:breadcrumbs>
            <div class="flex items-center gap-1.5">
                <a href="{{ route('admin.dashboard') }}" wire:navigate class="inline-flex items-center gap-1 text-body-muted transition-colors hover:text-accent">
                    <x-bs-admin::icon name="home" class="size-3.5" /> {{ __('admin.breadcrumb_home') }}
                </a>
                <x-bs-admin::icon name="chevron-right" class="size-3.5 text-placeholder" />
                <span class="font-medium text-body-muted">{{ $sectionLabel }}</span>
            </div>
        </x-slot:breadcrumbs>
        <x-slot:actions>
            <x-bs-admin::button size="sm" color="primary" :href="route('admin.entries.create', $sectionKey ? ['section' => $sectionKey] : [])">
                <x-bs-admin::icon name="ri-add" class="size-4" /> {{ __('entries.create_new') }}
            </x-bs-admin::button>
        </x-slot:actions>
    </x-bs-admin::page-header>

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
        // Gap 2 (design §3a): each tab's count badge is tinted by its OWN status, not by
        // whether the tab is active — literal Tailwind strings only (bg-{$x}/10 never compiles).
        $tabBadgeClass = fn (string $key): string => match ($key) {
            'all' => 'bg-admin-primary/10 text-accent',
            'published' => 'bg-admin-success/10 text-admin-success-text',
            'scheduled' => 'bg-admin-warning/10 text-admin-warning-text',
            'private' => 'bg-admin-info/10 text-admin-info-text',
            'trash' => 'bg-admin-danger/10 text-admin-danger-text',
            default => 'bg-surface-muted text-body-muted', // draft — design: grey chip, not blue
        };
        // Gap (design §3a JS lines 791-795): each status tab carries its own leading glyph,
        // not just the "all" tab — Trash keeps its separate hard-coded ri-delete-bin.
        $tabIcon = fn (string $key): string => match ($key) {
            'all' => 'ri-file-list-3',
            'published' => 'ri-global',
            'draft' => 'ri-draft',
            'scheduled' => 'ri-calendar-schedule',
            'private' => 'ri-lock',
            default => 'ri-file-list-3',
        };
        // Gap 5: status dot colored from the same badge color NAME ($statusColor's values) —
        // one source of truth instead of a parallel status→class map.
        $dotClass = fn (string $color): string => match ($color) {
            'success' => 'bg-admin-success',
            'warning' => 'bg-admin-warning',
            'info' => 'bg-admin-info',
            'danger' => 'bg-admin-danger',
            'dark' => 'bg-admin-dark',
            'primary' => 'bg-admin-primary',
            default => 'bg-admin-secondary',
        };
        // Footer pager (design lines 255-262): always renders — even a single page shows "[1]"
        // with dimmed arrows. Windowed to first, last, and current±1; any gap collapses to one
        // "…" (a `null` cell) so a large page count never floods the footer with every number.
        $pagerWindow = function (int $current, int $last): array {
            $keep = collect([1, $last, $current - 1, $current, $current + 1])
                ->filter(fn (int $p): bool => $p >= 1 && $p <= $last)
                ->unique()->sort()->values();

            $cells = [];
            $prev = null;
            foreach ($keep as $p) {
                if ($prev !== null && $p - $prev > 1) {
                    $cells[] = null;
                }
                $cells[] = $p;
                $prev = $p;
            }

            return $cells;
        };
    @endphp

    <x-bs-admin::card class="mt-4">
        {{-- Status tabs + Trash (server-driven; counts are live) --}}
        <div class="-mt-1 mb-4 flex flex-wrap items-center gap-1 border-b border-border">
            @foreach ($tabDefs as $key => $label)
                <button type="button" wire:click="setTab('{{ $key }}')"
                    @class([
                        '-mb-px inline-flex items-center gap-2 border-b-2 px-3 py-2.5 text-sm font-medium transition-colors',
                        'border-accent text-accent' => $tab === $key,
                        'border-transparent text-body-muted hover:text-body-text' => $tab !== $key,
                    ])>
                    <x-bs-admin::icon :name="$tabIcon($key)" class="size-4" />
                    {{ $label }}
                    <span class="rounded px-1.5 py-0.5 text-xs font-semibold leading-none {{ $tabBadgeClass($key) }}">{{ $counts[$key] ?? 0 }}</span>
                </button>
            @endforeach
            <button type="button" wire:click="setTab('trash')"
                @class([
                    '-mb-px ml-auto inline-flex items-center gap-2 border-b-2 px-3 py-2.5 text-sm font-medium transition-colors',
                    'border-admin-danger-text text-admin-danger-text' => $tab === 'trash',
                    'border-transparent text-body-muted hover:text-body-text' => $tab !== 'trash',
                ])>
                <x-bs-admin::icon name="ri-delete-bin" class="size-4" /> {{ __('entries.tab_trash') }}
                <span class="rounded px-1.5 py-0.5 text-xs font-semibold leading-none {{ $tabBadgeClass('trash') }}">{{ $counts['trash'] }}</span>
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
                        <x-bs-admin::icon name="ri-search" class="size-4" />
                    </span>
                    <label for="pages-search" class="sr-only">{{ __('entries.search_placeholder') }}</label>
                    <input id="pages-search" type="search" wire:model.live.debounce.300ms="search"
                        placeholder="{{ __('entries.search_placeholder') }}"
                        class="block w-full rounded border border-border-strong bg-surface py-2 pl-9 pr-3 text-sm text-body-text placeholder:text-placeholder focus:border-accent">
                </div>

                @include('admin::livewire.entries.partials.filter-dropdown', ['group' => 'lang', 'label' => __('entries.col_language'), 'options' => $facets['lang'], 'activeCount' => count($langF), 'icon' => 'ri-translate-2', 'heading' => __('entries.filter_by_language')])
                @include('admin::livewire.entries.partials.filter-dropdown', ['group' => 'creator', 'label' => __('entries.col_creator'), 'options' => $facets['creator'], 'activeCount' => count($creatorF), 'icon' => 'ri-user-3', 'heading' => __('entries.filter_by_creator')])
                @include('admin::livewire.entries.partials.filter-dropdown', ['group' => 'template', 'label' => __('entries.filter_template'), 'options' => $facets['template'], 'activeCount' => count($templateF), 'icon' => 'ri-layout-2', 'heading' => __('entries.filter_by_template')])

                <div class="ml-auto h-6 w-px bg-border"></div>

                <div x-data="{ open: false }" @click.outside="open = false" class="relative">
                    <button type="button" @click="open = !open"
                        class="inline-flex items-center gap-2 rounded border border-border-strong bg-surface px-3 py-2 text-sm font-medium text-body-text transition-colors hover:border-accent">
                        <x-bs-admin::icon name="ri-layout-column" class="size-4" /> {{ __('entries.cols_button') }}
                        <x-bs-admin::icon name="chevron-down" class="size-3.5 transition-transform" ::class="open && 'rotate-180'" />
                    </button>
                    <div x-show="open" x-cloak x-transition
                        class="absolute right-0 z-30 mt-1 w-56 rounded-lg border border-border bg-surface p-1.5 shadow-lg">
                        <p class="px-2 pb-1 pt-1.5 text-xs font-semibold uppercase tracking-wide text-placeholder">{{ __('entries.cols_required') }}</p>
                        @foreach ([__('entries.title_label'), __('entries.col_status'), __('entries.col_actions')] as $locked)
                            <label class="flex cursor-not-allowed items-center gap-2.5 rounded px-2 py-1.5 text-sm text-body-muted">
                                <input type="checkbox" checked disabled class="size-4 rounded border-border-strong">
                                {{ $locked }}
                            </label>
                        @endforeach
                        <p class="px-2 pb-1 pt-2 text-xs font-semibold uppercase tracking-wide text-placeholder">{{ __('entries.cols_optional') }}</p>
                        @foreach (['path' => __('entries.col_path'), 'langs' => __('entries.col_language'), 'creator' => __('entries.col_creator')] as $key => $label)
                            <label class="flex cursor-pointer items-center gap-2.5 rounded px-2 py-1.5 text-sm text-body-text hover:bg-surface-hover">
                                <input type="checkbox" :checked="cols.{{ $key }}" @change="toggleCol('{{ $key }}')"
                                    class="size-4 rounded border-border-strong text-accent focus:ring-admin-primary/30">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Active filter chips --}}
            @if (count($activeChips) > 0)
                <div class="mb-4 flex flex-wrap items-center gap-2">
                    <span class="text-xs font-medium text-body-muted">{{ __('entries.filter_active') }}</span>
                    @foreach ($activeChips as $chip)
                        <button type="button" wire:click="toggleFilterValue('{{ $chip['group'] }}', '{{ $chip['value'] }}')"
                            class="inline-flex items-center gap-1 rounded-full bg-admin-primary/10 py-1 pl-2.5 pr-1.5 text-xs font-medium text-accent transition-colors hover:bg-admin-primary/20">
                            {{ $chip['label'] }} <x-bs-admin::icon name="ri-close" class="size-3.5" />
                        </button>
                    @endforeach
                    <button type="button" wire:click="clearAllFilters" class="ml-1 text-xs text-admin-danger-text hover:underline">{{ __('entries.filter_clear_all') }}</button>
                    <span class="ml-auto text-xs text-placeholder">{{ __('entries.filter_match', ['count' => $matchCount, 'total' => $baseCount]) }}</span>
                </div>
            @endif

            {{-- Bulk action bar — appears once one or more rows are selected --}}
            @if (count($selected) > 0)
                <div class="mb-4 flex flex-wrap items-center gap-2 rounded-lg border border-admin-primary/30 bg-admin-primary/5 px-3 py-2">
                    <span class="text-sm font-medium text-accent">{{ __('entries.bulk_selected', ['count' => count($selected)]) }}</span>
                    @if ($tab === 'trash')
                        @if ($canDelete)
                            <button type="button" wire:click="bulkRestore"
                                class="inline-flex items-center gap-1.5 rounded border border-border-strong bg-surface px-2.5 py-1 text-sm font-medium text-body-text transition-colors hover:border-accent hover:text-accent">
                                <x-bs-admin::icon name="ri-refresh" class="size-3.5 text-body-muted" /> {{ __('entries.action_restore') }}
                            </button>
                            <button type="button"
                                x-on:click="$bsConfirm({ icon: 'trash-danger', text: @js(__('entries.force_delete_confirm')), confirm: @js(__('entries.action_destroy')), cancel: @js(__('admin.cancel')) }).then(ok => ok && $wire.bulkForceDelete())"
                                class="inline-flex items-center gap-1.5 rounded border border-admin-danger/30 bg-surface px-2.5 py-1 text-sm font-medium text-admin-danger-text transition-colors hover:bg-admin-danger/10">
                                <x-bs-admin::icon name="ri-delete-bin" class="size-3.5" /> {{ __('entries.action_destroy') }}
                            </button>
                        @endif
                    @else
                        @if ($canPublish)
                            <button type="button" wire:click="bulkPublish"
                                class="inline-flex items-center gap-1.5 rounded border border-border-strong bg-surface px-2.5 py-1 text-sm font-medium text-body-text transition-colors hover:border-accent hover:text-accent">
                                <x-bs-admin::icon name="ri-check" class="size-3.5 text-admin-success-text" /> {{ __('entries.bulk_publish') }}
                            </button>
                            <button type="button" wire:click="bulkDraft"
                                class="inline-flex items-center gap-1.5 rounded border border-border-strong bg-surface px-2.5 py-1 text-sm font-medium text-body-text transition-colors hover:border-accent hover:text-accent">
                                <x-bs-admin::icon name="ri-pencil" class="size-3.5 text-body-muted" /> {{ __('entries.bulk_draft') }}
                            </button>
                        @endif
                        @if ($canCreate)
                            <button type="button" wire:click="bulkDuplicate"
                                class="inline-flex items-center gap-1.5 rounded border border-border-strong bg-surface px-2.5 py-1 text-sm font-medium text-body-text transition-colors hover:border-accent hover:text-accent">
                                <x-bs-admin::icon name="ri-file-copy" class="size-3.5 text-body-muted" /> {{ __('entries.action_duplicate') }}
                            </button>
                        @endif
                        @if ($canDelete)
                            <button type="button"
                                x-on:click="$bsConfirm({ icon: 'trash', text: @js(__('entries.trash_confirm')), confirm: @js(__('entries.action_trash')), cancel: @js(__('admin.cancel')) }).then(ok => ok && $wire.bulkTrash())"
                                class="inline-flex items-center gap-1.5 rounded border border-admin-danger/30 bg-surface px-2.5 py-1 text-sm font-medium text-admin-danger-text transition-colors hover:bg-admin-danger/10">
                                <x-bs-admin::icon name="ri-delete-bin" class="size-3.5" /> {{ __('entries.action_trash') }}
                            </button>
                        @endif
                    @endif
                    <button type="button" wire:click="clearSelection" class="ml-auto inline-flex items-center gap-1 text-sm text-body-muted transition-colors hover:text-body-text">
                        <x-bs-admin::icon name="ri-close" class="size-3.5" /> {{ __('entries.bulk_deselect') }}
                    </button>
                </div>
            @endif

            <x-bs-admin::table>
                <thead>
                    <tr>
                        <th class="w-10">
                            <input type="checkbox" @checked($pageUlids !== [] && array_diff($pageUlids, $selected) === [])
                                wire:click="toggleSelectAll(@js($pageUlids))" aria-label="{{ __('entries.select_all') }}"
                                class="size-4 rounded border-border-strong text-accent focus:ring-admin-primary/30">
                        </th>
                        <th class="w-full">
                            <button type="button" wire:click="sortBy('name')" class="inline-flex items-center gap-1 hover:text-body-text">
                                {{ __('entries.title_label') }} <x-bs-admin::icon :name="$sortIcon('name')" class="size-3.5 {{ $sortKey === 'name' ? 'text-accent' : '' }}" />
                            </button>
                        </th>
                        <th x-show="cols.path">{{ __('entries.col_path') }}</th>
                        <th>
                            <button type="button" wire:click="sortBy('status')" class="inline-flex items-center gap-1 hover:text-body-text">
                                {{ __('entries.col_status') }} <x-bs-admin::icon :name="$sortIcon('status')" class="size-3.5 {{ $sortKey === 'status' ? 'text-accent' : '' }}" />
                            </button>
                        </th>
                        <th x-show="cols.langs">{{ __('entries.col_language') }}</th>
                        <th x-show="cols.creator">
                            <button type="button" wire:click="sortBy('creator')" class="inline-flex items-center gap-1 hover:text-body-text">
                                {{ __('entries.col_creator') }} <x-bs-admin::icon :name="$sortIcon('creator')" class="size-3.5 {{ $sortKey === 'creator' ? 'text-accent' : '' }}" />
                            </button>
                        </th>
                        <th class="w-[132px] text-center">{{ __('entries.col_actions') }}</th>
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
                            $when = $siteTime->forDisplay($isScheduled ? $entry->published_at : $entry->updated_at);
                            $slugLeaf = $t?->slug;
                            $slugPrefix = ($path !== null && $slugLeaf) ? \Illuminate\Support\Str::beforeLast($path, $slugLeaf) : '/';
                        @endphp
                        <tr wire:key="entry-{{ $entry->ulid }}">
                            <td class="align-top">
                                <input type="checkbox" wire:model.live="selected" value="{{ $entry->ulid }}"
                                    aria-label="{{ __('entries.select_row') }}"
                                    class="mt-0.5 size-4 rounded border-border-strong text-accent focus:ring-admin-primary/30">
                            </td>
                            <td class="w-full">
                                <div @class(['flex items-center gap-1.5', 'pl-5' => $depth]) x-data="{ editing: false, value: @js($t?->title ?? ''), cancel: false }">
                                    @if ($depth)
                                        <x-bs-admin::icon name="chevron-right" class="size-3.5 shrink-0 text-placeholder" />
                                    @endif
                                    @if ($canEdit && ! $entry->trashed())
                                        <button type="button" x-show="!editing"
                                            @click="editing = true; cancel = false; $nextTick(() => { $refs.titleInput.focus(); $refs.titleInput.select() })"
                                            title="{{ __('entries.edit_name_hint') }}"
                                            class="group relative -mx-1 rounded py-0.5 pl-1 pr-5 text-left font-medium text-text transition-colors hover:bg-primary-soft hover:text-primary-text">{{ $t?->title ?? __('entries.untitled') }}
                                            <x-bs-admin::icon name="ri-pencil" class="pointer-events-none absolute right-1.5 top-1/2 size-3 -translate-y-1/2 text-accent opacity-0 transition-opacity group-hover:opacity-100" />
                                        </button>
                                        <input type="text" x-show="editing" x-cloak x-ref="titleInput" x-model="value"
                                            @keydown.enter.prevent="cancel = false; $el.blur()"
                                            @keydown.escape="cancel = true; $el.blur()"
                                            @blur="editing = false; if (! cancel) { $wire.saveTitle(@js($entry->ulid), value) }"
                                            class="w-full rounded border border-accent bg-white px-1 py-0.5 text-sm font-medium text-body-text">
                                    @else
                                        <span class="font-medium text-body-text">{{ $t?->title ?? __('entries.untitled') }}</span>
                                    @endif
                                </div>
                                <div @class(['mt-0.5 flex items-center gap-1 text-[11px] text-placeholder', 'pl-5' => $depth])>
                                    <x-bs-admin::icon name="ri-eye" class="size-3" />
                                    {{ $entry->view_count > 0 ? __('entries.views_count', ['count' => number_format($entry->view_count)]) : __('entries.no_views') }}
                                </div>
                            </td>
                            <td x-show="cols.path" class="align-top font-mono text-xs text-body-muted">
                                @if ($isHome)
                                    <span class="inline-flex items-center gap-1 text-placeholder" title="{{ __('entries.home_locked') }}">{{ $path ?? '/' }} <x-bs-admin::icon name="ri-lock" class="size-3" /></span>
                                @elseif ($canEdit && ! $entry->trashed())
                                    <div wire:key="slug-{{ $entry->ulid }}-{{ $slugLeaf }}" x-data="{ editing: false, value: @js($slugLeaf ?? ''), cancel: false }" class="flex max-w-[240px] items-center">
                                        <button type="button" x-show="!editing"
                                            @click="editing = true; cancel = false; $nextTick(() => { $refs.slugInput.focus(); $refs.slugInput.select() })"
                                            title="{{ $path }} · {{ __('entries.edit_hint') }}"
                                            class="group relative -mx-1 truncate rounded py-0.5 pl-1 pr-4 text-text-muted transition-colors hover:bg-primary-soft hover:text-primary-text">{{ $path ?? '—' }}
                                            <x-bs-admin::icon name="ri-pencil" class="pointer-events-none absolute right-1 top-1/2 size-2.5 -translate-y-1/2 text-accent opacity-0 transition-opacity group-hover:opacity-100" />
                                        </button>
                                        <span x-show="editing" x-cloak class="flex w-full items-center gap-0.5">
                                            <span class="whitespace-nowrap text-placeholder">{{ $slugPrefix }}</span>
                                            <input type="text" x-ref="slugInput" x-model="value"
                                                @keydown.enter.prevent="cancel = false; $el.blur()"
                                                @keydown.escape="cancel = true; $el.blur()"
                                                @blur="editing = false; if (! cancel) { $wire.saveSlug(@js($entry->ulid), value) }"
                                                class="min-w-0 flex-1 rounded border border-accent bg-white px-1 py-0.5">
                                        </span>
                                    </div>
                                @else
                                    <span class="block max-w-[240px] truncate" title="{{ $path }}">{{ $path ?? '—' }}</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap align-top">
                                @if ($canPublish && ! $entry->trashed())
                                    <div x-data="{ open: false, sched: false }" @click.outside="open = false; sched = false" class="relative inline-block">
                                        <button type="button" @click="open = ! open" class="inline-flex items-center" aria-label="{{ __('entries.status_menu_label') }}">
                                            <x-bs-admin::badge soft :color="$statusColor[$status] ?? 'secondary'">
                                                <span class="size-1.5 shrink-0 rounded-full {{ $dotClass($statusColor[$status] ?? 'secondary') }}"></span>
                                                {{ __('entries.status_'.$status) }} <x-bs-admin::icon name="chevron-down" class="size-3" />
                                            </x-bs-admin::badge>
                                        </button>
                                        <div x-show="open" x-cloak x-transition
                                            class="absolute left-0 z-30 mt-1 w-48 rounded-lg border border-border bg-surface p-1 shadow-lg">
                                            @foreach (['published', 'draft', 'private'] as $opt)
                                                <button type="button" x-on:click="$wire.changeStatus(@js($entry->ulid), @js($opt)); open = false"
                                                    class="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left hover:bg-surface-hover">
                                                    <x-bs-admin::badge soft :color="$statusColor[$opt]">
                                                        <span class="size-1.5 shrink-0 rounded-full {{ $dotClass($statusColor[$opt]) }}"></span>
                                                        {{ __('entries.status_'.$opt) }}
                                                    </x-bs-admin::badge>
                                                    @if ($status === $opt)<x-bs-admin::icon name="ri-check" class="ml-auto size-3.5 text-accent" />@endif
                                                </button>
                                            @endforeach
                                            <div class="mt-0.5 border-t border-border pt-0.5">
                                                <button type="button" @click="sched = ! sched"
                                                    class="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left hover:bg-surface-hover">
                                                    <x-bs-admin::badge soft color="warning">
                                                        <span class="size-1.5 shrink-0 rounded-full {{ $dotClass('warning') }}"></span>
                                                        {{ __('entries.status_scheduled') }}
                                                    </x-bs-admin::badge>
                                                    <x-bs-admin::icon name="chevron-down" class="ml-auto size-3.5 text-placeholder transition-transform" ::class="sched && 'rotate-180'" />
                                                </button>
                                                <div x-show="sched" x-cloak class="px-2 pb-1.5 pt-1">
                                                    <label class="mb-1 block text-[11px] text-body-muted">{{ __('entries.schedule_at') }}</label>
                                                    <input type="datetime-local" x-ref="when" min="{{ $siteTime->now()->format('Y-m-d\TH:i') }}"
                                                        class="w-full rounded border border-border-strong bg-surface px-2 py-1 text-xs text-body-text">
                                                    <button type="button" x-on:click="$wire.scheduleEntry(@js($entry->ulid), $refs.when.value); open = false; sched = false"
                                                        class="mt-1.5 w-full rounded bg-admin-primary py-1 text-xs font-medium text-white transition-opacity hover:opacity-90">{{ __('entries.schedule_set_action') }}</button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                @else
                                    <x-bs-admin::badge soft :color="$statusColor[$status] ?? 'secondary'">
                                        <span class="size-1.5 shrink-0 rounded-full {{ $dotClass($statusColor[$status] ?? 'secondary') }}"></span>
                                        {{ __('entries.status_'.$status) }}
                                    </x-bs-admin::badge>
                                @endif
                                @if ($when)
                                    <div class="mt-1 flex items-center gap-1 text-[11px] text-placeholder">
                                        <x-bs-admin::icon :name="$isScheduled ? 'ri-time' : 'ri-history'" class="size-3" />
                                        @if ($isScheduled)
                                            {{ __('entries.scheduled_prefix') }} {{ $when->locale(app()->getLocale())->isoFormat('D MMM, Y') }} · {{ $when->format('H:i') }}
                                        @else
                                            {{ $when->locale(app()->getLocale())->isoFormat('D MMM, Y') }}
                                        @endif
                                    </div>
                                @endif
                            </td>
                            <td x-show="cols.langs" class="whitespace-nowrap align-top">
                                <div class="flex flex-wrap items-center gap-1">
                                    @foreach ($locales as $lc)
                                        @php $has = $entry->translations->contains(fn ($tr) => $tr->locale === $lc); @endphp
                                        {{-- The code itself isn't clickable; hover reveals a floating Sửa/Thêm that opens the editor for that locale
                                             (not on a Trash row: the editor does not open trashed content). --}}
                                        <div class="group relative">
                                            <span @if ($canEdit && ! $entry->trashed()) title="{{ $has ? __('entries.edit_translation_hint', ['lang' => __('entries.language_'.$lc), 'title' => $t?->title ?? '—']) : __('entries.add_translation_hint', ['lang' => __('entries.language_'.$lc), 'title' => $t?->title ?? '—']) }}" @endif @class([
                                                'inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-bold uppercase leading-none',
                                                'border border-border bg-surface-muted text-body-text' => $has,
                                                'border border-dashed border-border-strong text-placeholder' => ! $has,
                                            ])>{{ strtoupper($lc) }}</span>
                                            @if ($canEdit && ! $entry->trashed())
                                                {{-- §3a: the popover is wrapped with a padding-gap (not a margin) + pointer-events so the
                                                     cursor can travel from the chip onto the Sửa/Thêm button without the hover dropping. --}}
                                                <span class="pointer-events-none absolute bottom-full left-1/2 z-20 -translate-x-1/2 pb-1.5 opacity-0 transition-opacity group-hover:pointer-events-auto group-hover:opacity-100">
                                                    <a href="{{ route('admin.entries.edit', ['ulid' => $entry->ulid, 'locale' => $lc]) }}" wire:navigate
                                                        title="{{ $has ? __('entries.edit_translation_hint', ['lang' => __('entries.language_'.$lc), 'title' => $t?->title ?? '—']) : __('entries.add_translation_hint', ['lang' => __('entries.language_'.$lc), 'title' => $t?->title ?? '—']) }}"
                                                        class="inline-flex items-center gap-1 whitespace-nowrap rounded px-2 py-1 text-[11px] font-semibold text-white shadow-lg {{ $has ? 'bg-admin-primary' : 'bg-admin-success-solid' }}">
                                                        <x-bs-admin::icon :name="$has ? 'ri-pencil' : 'ri-add'" class="size-3" />
                                                        {{ $has ? __('entries.edit_action') : __('entries.add_translation') }}
                                                    </a>
                                                </span>
                                            @endif
                                        </div>
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
                                                class="grid size-7 place-items-center rounded text-body-muted transition-colors hover:bg-surface-hover hover:text-body-text">
                                                <x-bs-admin::icon name="ri-refresh" class="size-4" />
                                            </button>
                                            <button type="button" title="{{ __('entries.action_destroy') }}"
                                                x-on:click="$bsConfirm({ icon: 'trash-danger', text: @js(__('entries.force_delete_confirm')), confirm: @js(__('entries.action_destroy')), cancel: @js(__('admin.cancel')) }).then(ok => ok && $wire.forceDelete(@js($entry->ulid)))"
                                                class="grid size-7 place-items-center rounded text-body-muted transition-colors hover:bg-surface-hover hover:text-admin-danger-text">
                                                <x-bs-admin::icon name="ri-delete-bin" class="size-4" />
                                            </button>
                                        @endif
                                    @else
                                        @if ($path)
                                            <a href="{{ $path }}" target="_blank" rel="noopener" title="{{ __('entries.action_preview') }}"
                                                class="grid size-7 place-items-center rounded text-body-muted transition-colors hover:bg-surface-hover hover:text-body-text">
                                                <x-bs-admin::icon name="ri-eye" class="size-4" />
                                            </a>
                                        @endif
                                        <a href="{{ route('admin.entries.edit', ['ulid' => $entry->ulid]) }}" wire:navigate title="{{ __('entries.edit_action') }}"
                                            class="grid size-7 place-items-center rounded text-body-muted transition-colors hover:bg-surface-hover hover:text-body-text">
                                            <x-bs-admin::icon name="ri-pencil" class="size-4" />
                                        </a>
                                        @if ($canCreate)
                                            <button type="button" wire:click="duplicate('{{ $entry->ulid }}')" title="{{ __('entries.action_duplicate') }}"
                                                class="grid size-7 place-items-center rounded text-body-muted transition-colors hover:bg-surface-hover hover:text-body-text">
                                                <x-bs-admin::icon name="ri-file-copy" class="size-4" />
                                            </button>
                                        @endif
                                        @if ($canDelete)
                                            <button type="button" title="{{ __('entries.action_trash') }}"
                                                x-on:click="$bsConfirm({ icon: 'trash', text: @js(__('entries.trash_confirm')), confirm: @js(__('entries.action_trash')), cancel: @js(__('admin.cancel')) }).then(ok => ok && $wire.trash(@js($entry->ulid)))"
                                                class="grid size-7 place-items-center rounded text-body-muted transition-colors hover:bg-surface-hover hover:text-admin-danger-text">
                                                <x-bs-admin::icon name="ri-delete-bin" class="size-4" />
                                            </button>
                                        @endif
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-12 text-center text-body-muted">
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
            </x-bs-admin::table>

            <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                <span class="text-xs text-body-muted">
                    {{ __('entries.footer_showing', ['first' => $rows->firstItem() ?? 0, 'last' => $rows->lastItem() ?? 0, 'total' => $rows->total()]) }}
                </span>
                <div class="flex flex-wrap items-center gap-4">
                    <div class="flex items-center gap-2 text-xs text-body-muted">
                        {{ __('entries.rows_per_page') }}
                        <button type="button" wire:click="cyclePerPage" title="{{ __('entries.rows_per_page') }}"
                            class="inline-flex items-center gap-1.5 rounded border border-border-strong bg-surface px-2.5 py-1 font-medium text-body-text transition-colors hover:bg-surface-hover">
                            {{ $perPage }} <x-bs-admin::icon name="chevron-down" class="size-3.5 text-body-muted" />
                        </button>
                    </div>
                    @php
                        $pgCurrent = $rows->currentPage();
                        $pgLast = $rows->lastPage();
                        $pgBase = 'inline-flex h-9 min-w-9 items-center justify-center rounded px-3 text-sm transition-colors';
                    @endphp
                    <nav aria-label="Pagination" class="flex items-center gap-1">
                        <button type="button" wire:click="gotoPage({{ max(1, $pgCurrent - 1) }})" @disabled($pgCurrent <= 1)
                            aria-label="{{ __('admin.pagination.previous') }}"
                            @class([$pgBase, 'text-body-muted hover:bg-surface-hover' => $pgCurrent > 1, 'cursor-not-allowed text-ink-ghost' => $pgCurrent <= 1])>
                            <x-bs-admin::icon name="chevron-left" class="size-4" />
                        </button>
                        @foreach ($pagerWindow($pgCurrent, $pgLast) as $cell)
                            @if ($cell === null)
                                <span class="px-1 text-placeholder">&hellip;</span>
                            @else
                                <button type="button" wire:click="gotoPage({{ $cell }})"
                                    @class([$pgBase, 'bg-admin-primary font-medium text-white' => $cell === $pgCurrent, 'text-body-muted hover:bg-surface-hover' => $cell !== $pgCurrent])>
                                    {{ $cell }}
                                </button>
                            @endif
                        @endforeach
                        <button type="button" wire:click="gotoPage({{ min($pgLast, $pgCurrent + 1) }})" @disabled($pgCurrent >= $pgLast)
                            aria-label="{{ __('admin.pagination.next') }}"
                            @class([$pgBase, 'text-body-muted hover:bg-surface-hover' => $pgCurrent < $pgLast, 'cursor-not-allowed text-ink-ghost' => $pgCurrent >= $pgLast])>
                            <x-bs-admin::icon name="chevron-right" class="size-4" />
                        </button>
                    </nav>
                </div>
            </div>
        </div>
    </x-bs-admin::card>
</div>
