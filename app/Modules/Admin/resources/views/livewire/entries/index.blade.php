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
        $avatarPalette = [
            ['bg' => '#e0e7ff', 'fg' => '#4338ca'], ['bg' => '#fce7f3', 'fg' => '#be185d'],
            ['bg' => '#d1fae5', 'fg' => '#047857'], ['bg' => '#fef3c7', 'fg' => '#b45309'],
            ['bg' => '#dbeafe', 'fg' => '#1d4ed8'], ['bg' => '#fee2e2', 'fg' => '#b91c1c'],
        ];
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

        {{-- Column visibility lives client-side (instant + persisted), so paging never resets it.
             Until the user picks columns, a list narrower than 37.5rem (phone cards) starts with the path
             and language chips off, so a card stays name + one detail line; "Cột" turns them back on. --}}
        <div x-data="{
            cols: (() => { try { return JSON.parse(localStorage.getItem('pagesCols')) } catch (e) { return null } })() || { path: null, langs: null, creator: true },
            init() {
                if (this.cols.path !== null) { return }
                const list = this.$el.querySelector('.list-rows') ?? this.$el;
                const wide = list.clientWidth >= 37.5 * parseFloat(getComputedStyle(document.documentElement).fontSize);
                this.cols.path = wide;
                this.cols.langs = wide;
            },
            toggleCol(k) { this.cols[k] = !this.cols[k]; try { localStorage.setItem('pagesCols', JSON.stringify(this.cols)) } catch (e) {} },
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
                        @foreach ([__('entries.title_label'), __('entries.col_status'), __('admin.actions')] as $locked)
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

            @php
                $pageSelected = count(array_intersect($pageUlids, $selected));
                $allState = $pageUlids !== [] && $pageSelected === count($pageUlids) ? true : ($pageSelected > 0 ? 'mixed' : false);
                $selectAllCall = 'toggleSelectAll(['.collect($pageUlids)->map(fn (string $u): string => "'".$u."'")->implode(',').'])';
                $ariaSort = fn (string $key): string => $sortKey !== $key ? 'none' : ($sortDir === 'asc' ? 'ascending' : 'descending');
                $Js = \Illuminate\Support\Js::class;
            @endphp
            {{-- The shared list row: a card below 600, one record per line to 900, a table from 900
                 (by the list's own width). The author column hides with the column picker. --}}
            <x-bs-admin::list :label="__('entries.list_label', ['label' => $sectionLabel])" class="-mx-4" x-bind:data-hide="cols.creator ? '' : 'au'">
                @if ($pageUlids !== [])
                    <x-slot:select>
                        <x-bs-admin::list.check :checked="$allState" :label="__('entries.select_all')" visible :wire:click="$selectAllCall" />
                    </x-slot:select>
                @endif

                <x-bs-admin::list.head>
                    <x-bs-admin::list.cell head col="chk">
                        @if ($pageUlids !== [])
                            <x-bs-admin::list.check :checked="$allState" :label="__('entries.select_all')" :wire:click="$selectAllCall" />
                        @endif
                    </x-bs-admin::list.cell>
                    <x-bs-admin::list.cell head col="main" :sort="$ariaSort('name')">
                        <x-bs-admin::list.sort :active="$sortKey === 'name'" :dir="$sortDir" wire:click="sortBy('name')">{{ __('entries.title_label') }}</x-bs-admin::list.sort>
                    </x-bs-admin::list.cell>
                    <x-bs-admin::list.cell head col="st" :sort="$ariaSort('status')">
                        <x-bs-admin::list.sort :active="$sortKey === 'status'" :dir="$sortDir" wire:click="sortBy('status')">{{ __('entries.col_status') }}</x-bs-admin::list.sort>
                    </x-bs-admin::list.cell>
                    <x-bs-admin::list.cell head col="au" :sort="$ariaSort('creator')">
                        <x-bs-admin::list.sort :active="$sortKey === 'creator'" :dir="$sortDir" wire:click="sortBy('creator')">{{ __('entries.col_creator') }}</x-bs-admin::list.sort>
                    </x-bs-admin::list.cell>
                    <x-bs-admin::list.cell head col="act">{{ __('admin.actions') }}</x-bs-admin::list.cell>
                </x-bs-admin::list.head>

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
                        $trashed = $entry->trashed();
                        $title = $t?->title ?? __('entries.untitled');
                        $isSelected = in_array($entry->ulid, $selected, true);
                        $creator = $entry->author?->name;
                        $parts = $creator ? preg_split('/\s+/', trim($creator)) : [];
                        $initials = $parts ? mb_strtoupper(mb_substr($parts[0], 0, 1).(count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '')) : '—';
                        $c = $avatarPalette[$creator ? abs(crc32($creator)) % count($avatarPalette) : 0];
                        $when = $siteTime->forDisplay($isScheduled ? $entry->published_at : $entry->updated_at);
                        $whenText = $when ? ($isScheduled
                            ? __('entries.scheduled_prefix').' '.$when->locale(app()->getLocale())->isoFormat('D MMM, Y').' · '.$when->format('H:i')
                            : $when->locale(app()->getLocale())->isoFormat('D MMM, Y')) : null;
                        $slugLeaf = $t?->slug;
                        $slugPrefix = ($path !== null && $slugLeaf) ? \Illuminate\Support\Str::beforeLast($path, $slugLeaf) : '/';
                        $quickEdit = ($rowEditable[$entry->ulid] ?? false) && ! $trashed;
                    @endphp
                    <x-bs-admin::list.row :selected="$isSelected" wire:key="entry-{{ $entry->ulid }}"
                        x-data="{ editing: false, value: {{ $Js::from($t?->title ?? '') }}, cancel: false }"
                        x-on:list-quick-edit="editing = true; cancel = false">
                        <x-bs-admin::list.cell col="chk">
                            <x-bs-admin::list.check :checked="$isSelected" :label="__('entries.select_named', ['title' => $title])" wire:click="toggleSelectAll(['{{ $entry->ulid }}'])" />
                        </x-bs-admin::list.cell>

                        <x-bs-admin::list.cell col="main">
                            <div @class(['pl-5' => $depth])>
                                <div x-show="! editing">
                                    <x-bs-admin::list.name :href="$trashed ? null : route('admin.entries.edit', ['ulid' => $entry->ulid])"
                                        :quick-edit="$quickEdit ? __('entries.quick_edit_named', ['title' => $title]) : null" :child="$depth > 0">{{ $title }}</x-bs-admin::list.name>
                                </div>
                                @if ($quickEdit)
                                    <input type="text" x-show="editing" x-cloak x-model="value" class="list-in"
                                        aria-label="{{ __('entries.quick_edit_named', ['title' => $title]) }}"
                                        x-init="$watch('editing', on => on && $nextTick(() => { $el.focus(); $el.select() }))"
                                        @keydown.enter.prevent="cancel = false; $el.blur()"
                                        @keydown.escape="cancel = true; $el.blur()"
                                        @blur="editing = false; if (! cancel) { $wire.saveTitle(@js($entry->ulid), value) }">
                                @endif

                                <x-bs-admin::list.meta>
                                    <span class="list-m" x-show="cols.path">
                                        @if ($isHome)
                                            <span class="inline-flex items-center gap-1 font-mono text-caption" title="{{ __('entries.home_locked') }}">{{ $path ?? '/' }} <x-bs-admin::icon name="ri-lock" class="size-3" /></span>
                                        @elseif ($quickEdit)
                                            <span wire:key="slug-{{ $entry->ulid }}-{{ $slugLeaf }}" x-data="{ editing: false, value: @js($slugLeaf ?? ''), cancel: false }">
                                                <button type="button" x-show="! editing"
                                                    @click="editing = true; cancel = false; $nextTick(() => { $refs.slugInput.focus(); $refs.slugInput.select() })"
                                                    title="{{ $path }} · {{ __('entries.edit_hint') }}"
                                                    class="hit-area min-h-tap-cell rounded px-1 -mx-1 text-left font-mono text-caption text-text-muted [overflow-wrap:anywhere] transition-colors hover:bg-primary-soft hover:text-primary-text">{{ $path ?? '—' }}</button>
                                                <span x-show="editing" x-cloak class="inline-flex w-full items-center gap-0.5 font-mono text-caption">
                                                    <span class="whitespace-nowrap text-placeholder">{{ $slugPrefix }}</span>
                                                    <input type="text" x-ref="slugInput" x-model="value" aria-label="{{ __('entries.col_path') }}"
                                                        @keydown.enter.prevent="cancel = false; $el.blur()"
                                                        @keydown.escape="cancel = true; $el.blur()"
                                                        @blur="editing = false; if (! cancel) { $wire.saveSlug(@js($entry->ulid), value) }"
                                                        class="min-h-tap-cell min-w-0 flex-1 rounded border border-primary-text bg-surface px-1">
                                                </span>
                                            </span>
                                        @else
                                            <span class="font-mono text-caption" title="{{ $path }}">{{ $path ?? '—' }}</span>
                                        @endif
                                    </span>
                                    <x-bs-admin::list.langs :locales="$locales" :source="$locale" :name="$title" show="cols.langs"
                                        :has="$entry->translations->pluck('locale')->all()"
                                        :href="$quickEdit ? fn (string $lc): string => route('admin.entries.edit', ['ulid' => $entry->ulid, 'locale' => $lc]) : null"
                                        :why="$trashed ? __('entries.lc_why_trashed') : __('entries.lc_why_view_only', ['noun' => __('entries.noun_'.$entry->type)])" />
                                    {{-- as in the design, views are named only once there are some --}}
                                    @if ($entry->view_count > 0)
                                        <span class="list-m" data-for="views">{{ __('entries.views_count', ['count' => \Illuminate\Support\Number::format($entry->view_count, locale: app()->getLocale())]) }}</span>
                                    @endif
                                    @if ($creator)
                                        <span class="list-m" data-for="au">{{ $creator }}</span>
                                    @endif
                                    @if ($whenText)
                                        <span class="list-m" data-for="when">{{ $whenText }}</span>
                                    @endif
                                </x-bs-admin::list.meta>
                            </div>
                        </x-bs-admin::list.cell>

                        <x-bs-admin::list.cell col="st">
                            <div class="list-stw">
                                @if ($canPublish && ! $trashed)
                                    <div x-data="{ open: false, sched: false }" @click.outside="open = false; sched = false" @keydown.escape="if (open || sched) { open = false; sched = false; $refs.pill.focus() }" class="relative inline-block">
                                        <x-bs-admin::status-pill button x-ref="pill" :status="$status" x-on:click="open = ! open" x-bind:aria-expanded="open.toString()" aria-haspopup="true" title="{{ __('entries.status_menu_label') }}">{{ __('entries.status_'.$status) }}<span class="sr-only"> · {{ __('entries.status_menu_label') }}</span></x-bs-admin::status-pill>
                                        <div x-show="open" x-cloak x-transition
                                            class="absolute left-0 z-30 mt-1 w-56 rounded-lg border border-border bg-surface p-1 shadow-lg">
                                            @foreach (['published', 'draft', 'private'] as $opt)
                                                <button type="button" x-on:click="$wire.changeStatus(@js($entry->ulid), @js($opt)); open = false" class="list-mi rounded">
                                                    <x-bs-admin::status-pill :status="$opt">{{ __('entries.status_'.$opt) }}</x-bs-admin::status-pill>
                                                    @if ($status === $opt)<x-bs-admin::icon name="ri-check" class="ml-auto size-3.5 text-primary-text" />@endif
                                                </button>
                                            @endforeach
                                            <div class="mt-0.5 border-t border-border pt-0.5">
                                                <button type="button" @click="sched = ! sched" x-bind:aria-expanded="sched.toString()" class="list-mi rounded">
                                                    <x-bs-admin::status-pill status="scheduled">{{ __('entries.status_scheduled') }}</x-bs-admin::status-pill>
                                                    <x-bs-admin::icon name="chevron-down" class="ml-auto size-3.5 text-placeholder transition-transform" ::class="sched && 'rotate-180'" />
                                                </button>
                                                <div x-show="sched" x-cloak class="px-2 pb-1.5 pt-1">
                                                    <label for="sched-{{ $entry->ulid }}" class="mb-1 block text-caption text-text-muted">{{ __('entries.schedule_at') }}</label>
                                                    <input id="sched-{{ $entry->ulid }}" type="datetime-local" x-ref="when" min="{{ $siteTime->now()->format('Y-m-d\TH:i') }}"
                                                        class="min-h-control w-full rounded border border-border-input bg-surface px-2 text-small text-text">
                                                    <x-bs-admin::button size="sm" class="mt-1.5 w-full" x-on:click="$wire.scheduleEntry({{ $Js::from($entry->ulid) }}, $refs.when.value); open = false; sched = false">{{ __('entries.schedule_set_action') }}</x-bs-admin::button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                @else
                                    <x-bs-admin::status-pill :status="$status">{{ __('entries.status_'.$status) }}</x-bs-admin::status-pill>
                                @endif
                            </div>
                            @if ($whenText)
                                <div class="list-st-meta">
                                    <x-bs-admin::icon :name="$isScheduled ? 'ri-time' : 'ri-history'" class="size-3 shrink-0" />
                                    <span>{{ $whenText }}</span>
                                </div>
                            @endif
                        </x-bs-admin::list.cell>

                        <x-bs-admin::list.cell col="au">
                            <span class="list-au">
                                <span class="list-avi" aria-hidden="true" style="background: {{ $c['bg'] }}; color: {{ $c['fg'] }};">{{ $initials }}</span>
                                <span>{{ $creator ?? '—' }}</span>
                            </span>
                        </x-bs-admin::list.cell>

                        <x-bs-admin::list.cell col="act">
                            <x-bs-admin::list.actions :name="$title">
                                @if ($trashed)
                                    @if ($canDelete)
                                        <x-bs-admin::button size="sm" variant="soft" color="primary" wire:click="restore('{{ $entry->ulid }}')">
                                            <x-bs-admin::icon name="ri-arrow-go-back" class="size-4" /> {{ __('entries.action_restore') }}
                                        </x-bs-admin::button>
                                        <x-slot:menu>
                                            <x-bs-admin::list.menu-item icon="ri-delete-bin" danger
                                                x-on:click="$bsConfirm({ icon: 'trash-danger', text: {{ $Js::from(__('entries.force_delete_confirm')) }}, confirm: {{ $Js::from(__('entries.action_destroy')) }}, cancel: {{ $Js::from(__('admin.cancel')) }} }).then(ok => ok && $wire.forceDelete({{ $Js::from($entry->ulid) }}))">{{ __('entries.action_destroy') }}</x-bs-admin::list.menu-item>
                                        </x-slot:menu>
                                    @endif
                                @else
                                    @if ($path)
                                        <x-bs-admin::button size="sm" variant="soft" color="secondary" :href="$path" :navigate="false" target="_blank" rel="noopener"
                                            :title="__('entries.action_preview')" :aria-label="__('entries.view_named', ['title' => $title])">
                                            <x-bs-admin::icon name="ri-eye" class="size-4" /> {{ __('admin.view') }}
                                        </x-bs-admin::button>
                                    @endif
                                    <x-bs-admin::button size="sm" variant="soft" color="primary" :href="route('admin.entries.edit', ['ulid' => $entry->ulid])"
                                        :aria-label="__('entries.edit_named', ['title' => $title])">
                                        <x-bs-admin::icon name="ri-pencil" class="size-4" /> {{ __('entries.edit_action') }}
                                    </x-bs-admin::button>
                                    <x-slot:menu>
                                        @if ($quickEdit)
                                            <x-bs-admin::list.menu-item icon="ri-pencil" x-on:click="$dispatch('list-quick-edit')">{{ __('entries.quick_edit_title') }}</x-bs-admin::list.menu-item>
                                        @endif
                                        @if ($canCreate)
                                            <x-bs-admin::list.menu-item icon="ri-file-copy" wire:click="duplicate('{{ $entry->ulid }}')">{{ __('entries.action_duplicate') }}</x-bs-admin::list.menu-item>
                                        @endif
                                        @if ($canDelete)
                                            @if ($quickEdit || $canCreate)
                                                <x-bs-admin::list.menu-sep />
                                            @endif
                                            <x-bs-admin::list.menu-item icon="ri-delete-bin" danger
                                                x-on:click="$bsConfirm({ icon: 'trash', text: {{ $Js::from(__('entries.trash_confirm')) }}, confirm: {{ $Js::from(__('entries.action_trash')) }}, cancel: {{ $Js::from(__('admin.cancel')) }} }).then(ok => ok && $wire.trash({{ $Js::from($entry->ulid) }}))">{{ __('entries.action_trash') }}</x-bs-admin::list.menu-item>
                                        @endif
                                    </x-slot:menu>
                                @endif
                            </x-bs-admin::list.actions>
                        </x-bs-admin::list.cell>
                    </x-bs-admin::list.row>
                @empty
                    <x-bs-admin::list.row>
                        <x-bs-admin::list.cell col="full" aria-colspan="5">
                            @if ($tab === 'trash')
                                {{ __('entries.trash_empty') }}
                            @elseif (trim($search) !== '')
                                {{ __('entries.search_empty') }}
                            @else
                                {{ __('entries.empty') }}
                            @endif
                        </x-bs-admin::list.cell>
                    </x-bs-admin::list.row>
                @endforelse
            </x-bs-admin::list>

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
