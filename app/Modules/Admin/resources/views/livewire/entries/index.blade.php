@php
    use Illuminate\Support\Js;
    use App\Modules\Admin\Livewire\Entries\Index as EntriesIndex;

    $sectionLabel = $sectionModel?->localizedLabel() ?? __('entries.section_fallback');
    $pageTitle = __('entries.manage_title', ['label' => $sectionLabel]);
    $isPost = $type === 'post';
    $noun = __('entries.noun_'.$type);
    $fmt = fn (int $n): string => \Illuminate\Support\Number::format($n, locale: app()->getLocale());
    $avatarPalette = [
        ['bg' => '#e0e7ff', 'fg' => '#4338ca'], ['bg' => '#fce7f3', 'fg' => '#be185d'],
        ['bg' => '#d1fae5', 'fg' => '#047857'], ['bg' => '#fef3c7', 'fg' => '#b45309'],
        ['bg' => '#dbeafe', 'fg' => '#1d4ed8'], ['bg' => '#fee2e2', 'fg' => '#b91c1c'],
    ];
    // Each status group carries its own leading glyph; Trash sits apart at the far end.
    $tabIcon = fn (string $key): string => match ($key) {
        'published' => 'ri-global',
        'draft' => 'ri-draft',
        'scheduled' => 'ri-calendar-schedule',
        'private' => 'ri-lock',
        'pending' => 'ri-time',
        'archived' => 'ri-archive',
        'trash' => 'ri-delete-bin',
        default => 'ri-file-list-3',
    };

    // Status groups, as in the design: "Chờ duyệt" only once something waits; Thùng rác only for who may empty it.
    $groupKeys = ['all', 'published', 'scheduled', 'pending', 'draft', 'private', 'archived'];
    $groups = [];
    foreach ($groupKeys as $key) {
        if ($key === 'pending' && ($counts['pending'] ?? 0) === 0 && $tab !== 'pending') {
            continue;
        }
        $groups[] = ['key' => $key, 'label' => $key === 'all' ? __('entries.tab_all') : __('entries.status_'.$key), 'icon' => $tabIcon($key), 'count' => $counts[$key] ?? 0];
    }
    if ($canSeeTrash || $tab === 'trash') {
        $groups[] = ['key' => 'trash', 'label' => __('entries.tab_trash'), 'icon' => 'ri-delete-bin', 'count' => $counts['trash'] ?? 0, 'trash' => true];
    }
    $groupLabel = $tab === 'all' ? null : (collect($groups)->firstWhere('key', $tab)['label'] ?? null);

    // "Thời gian" of a post list (by publish date).
    $day = fn (string $date): string => $date === '' ? '…' : implode('/', array_reverse(explode('-', $date)));
    $whenLabels = ['' => __('entries.when_all'), 'today' => __('entries.when_today')];
    foreach (['7', '30', '90'] as $n) {
        $whenLabels[$n] = __('entries.when_days', ['n' => $n]);
    }
    $whenText = $when === EntriesIndex::RANGE ? __('entries.when_range', ['from' => $day($from), 'to' => $day($to)]) : ($when !== '' ? ($whenLabels[$when] ?? '') : '');

    // What a member may choose to show: "Cột" on a wide list, "Hiện trên thẻ" on phone cards.
    $colOptions = ['path' => __('entries.col_path')];
    if ($multilingual) {
        $colOptions['langs'] = __('entries.col_language');
    }
    $colOptions['creator'] = __('entries.col_creator');
    if (! $isPost) {
        $colOptions['tpl'] = __('entries.filter_template');
    }
    $cardOptions = array_intersect_key($colOptions, array_flip(['tpl', 'path', 'langs']));
    $cardOptions = (! $isPost ? ['tpl' => $colOptions['tpl']] : []) + $cardOptions;

    // The Filter sheet (and from 1024 one button per section). Status groups lead it below 900, where their row hides.
    $sections = [
        ['key' => 'status', 'label' => __('entries.status_label'), 'icon' => 'ri-flag', 'model' => 'tab', 'value' => $tab, 'default' => 'all',
            'sheet' => true, 'class' => 'min-[900px]:hidden',
            'options' => array_map(fn (array $g): array => ['value' => $g['key'], 'label' => $g['label'], 'count' => $g['count'], 'divider' => $g['trash'] ?? false], $groups)],
    ];
    if (! $isPost) {
        $sections[] = ['key' => 'template', 'label' => __('entries.filter_template'), 'icon' => 'ri-layout-2', 'model' => 'templateF', 'multiple' => true, 'value' => $templateF, 'options' => $facets['template']];
    }
    if ($multilingual) {
        $sections[] = ['key' => 'trans', 'label' => __('entries.f_trans'), 'icon' => 'ri-translate-2', 'model' => 'transF', 'multiple' => true, 'value' => $transF, 'options' => $facets['trans']];
    }
    if ($multilingual || $langF !== []) {
        $sections[] = ['key' => 'lang', 'label' => __('entries.col_language'), 'icon' => 'ri-global', 'model' => 'langF', 'multiple' => true, 'value' => $langF, 'options' => $facets['lang']];
    }
    if (count($facets['creator']) >= 2 || $creatorF !== []) {
        $sections[] = ['key' => 'creator', 'label' => __('entries.col_creator'), 'icon' => 'ri-user-3', 'model' => 'creatorF', 'multiple' => true, 'value' => $creatorF, 'options' => $facets['creator']];
    }
    if ($isPost) {
        $sections[] = ['key' => 'when', 'label' => __('entries.f_when'), 'icon' => 'ri-calendar', 'model' => 'when', 'value' => $when, 'default' => '', 'note' => __('entries.when_note'),
            'options' => array_map(fn (string $v): array => ['value' => $v, 'label' => $whenLabels[$v]], EntriesIndex::WHEN),
            'range' => ['from' => 'from', 'to' => 'to', 'from_label' => __('entries.when_from'), 'to_label' => __('entries.when_to')]]
            + ($when === EntriesIndex::RANGE ? ['on' => true, 'summary' => $whenText, 'suffix' => ': '.$whenText] : []);
    }
    // a view choice, not a filter: never counted, never marked as filtering
    $sections[] = ['key' => 'card', 'label' => __('entries.f_card'), 'model' => 'card', 'multiple' => true, 'value' => $card, 'sheet' => true, 'class' => 'min-[600px]:hidden', 'on' => false,
        'options' => array_map(fn (string $k, string $l): array => ['value' => $k, 'label' => $l], array_keys($cardOptions), $cardOptions)];

    $sortOptions = [];
    $sortIcons = ['order' => 'ri-node-tree', 'name' => 'ri-sort-alphabet-asc', 'updated' => 'ri-history', 'pub' => 'ri-calendar', 'status' => 'ri-flag', 'views' => 'ri-eye', 'creator' => 'ri-user-3'];
    foreach ($sorts as $key) {
        $sortOptions[] = ['key' => $key, 'label' => __('entries.sort_'.$key), 'icon' => $sortIcons[$key]];
    }

    // The active-filter line: "Group · N trong M <unit> · Bỏ lọc", then a chip per condition (and the sort, when changed).
    // remove calls are fixed method names with Js::from() arguments, never raw input (filter-status contract).
    $filtering = $tab !== 'all' || $filterCount > 0 || trim($search) !== '';
    $chips = [];
    if (trim($search) !== '') {
        $chips[] = ['label' => __('entries.chip_search', ['q' => trim($search)]), 'remove' => '$set(\'search\', \'\')'];
    }
    foreach ($activeChips as $chip) {
        $chips[] = ['label' => $chip['label'], 'remove' => 'toggleFilterValue('.Js::from($chip['group']).', '.Js::from($chip['value']).')'];
    }
    if ($whenText !== '') {
        $chips[] = ['label' => __('entries.chip_when', ['when' => $whenText]), 'remove' => 'clearWhen'];
    }
    if (! $sortIsDefault) {
        $chips[] = ['label' => __('entries.sort_chip_'.$sortOn.'_'.$sortWay), 'remove' => 'resetSort'];
    }

    // Footer pager: windowed to first, last and current±1; any gap collapses to one "…" (a null cell).
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
    $pageSelected = count(array_intersect($pageUlids, $selected));
    $allState = $pageUlids !== [] && $pageSelected === count($pageUlids) ? true : ($pageSelected > 0 ? 'mixed' : false);
    $selectAllCall = 'toggleSelectAll(['.collect($pageUlids)->map(fn (string $u): string => "'".$u."'")->implode(',').'])';
@endphp
<div class="entries-index flex flex-col gap-3">

    {{-- Title row: the name · (?) · the main button on one line (the name wraps, never clipped), then one line on
         what the list holds. Below 600 the way back is the menu, so the breadcrumb gives its room to the list. --}}
    <header class="flex flex-col gap-1">
        <nav aria-label="{{ __('admin.breadcrumb') }}" class="hidden text-caption text-text-muted min-[600px]:block">
            <div class="flex items-center gap-1.5">
                <a href="{{ route('admin.dashboard') }}" wire:navigate class="inline-flex items-center gap-1 transition-colors hover:text-primary-text">
                    <x-bs-admin::icon name="home" class="size-3.5" /> {{ __('admin.breadcrumb_home') }}
                </a>
                <x-bs-admin::icon name="chevron-right" class="size-3.5 text-placeholder" />
                <span class="font-medium">{{ $sectionLabel }}</span>
            </div>
        </nav>
        <div class="flex items-center gap-3">
            {{-- the name is inline, so the (?) follows its last word when it wraps --}}
            <div class="min-w-0 flex-1">
                <h1 class="m-0 inline text-[calc(1.25rem+var(--user-text-boost))] font-semibold leading-snug text-text-strong [overflow-wrap:anywhere]">{{ $pageTitle }}</h1>
                <div class="relative ms-1 inline-block align-middle" x-data="{ open: false }" @keydown.escape.prevent.stop="open && (open = false, $refs.help.focus())" @click.outside="open = false">
                    <button type="button" x-ref="help" @click="open = ! open" :aria-expanded="open ? 'true' : 'false'" aria-controls="entries-help"
                            aria-label="{{ __('admin.list.help_named', ['name' => $pageTitle]) }}" title="{{ __('admin.list.help') }}"
                            class="hit-area inline-flex size-control items-center justify-center rounded-full text-text-muted transition-colors hover:bg-surface-hover hover:text-text-strong">
                        <x-bs-admin::icon name="ri-question-mark" class="size-5" />
                    </button>
                    <div x-show="open" x-cloak id="entries-help" role="region" aria-label="{{ __('admin.list.help') }}"
                         class="absolute start-0 top-full z-60 mt-1.5 w-[min(22rem,calc(100vw-2rem))] rounded-md border border-border bg-surface p-3 text-body text-text shadow-lg">
                        <p class="m-0 [overflow-wrap:anywhere]">{{ __('entries.help_'.$type) }}</p>
                    </div>
                </div>
            </div>
            <x-bs-admin::button color="primary" class="shrink-0" :href="route('admin.entries.create', $sectionKey ? ['section' => $sectionKey] : [])">
                <x-bs-admin::icon name="ri-add" class="size-4" /> {{ __('entries.create_new') }}
            </x-bs-admin::button>
        </div>
        <p class="m-0 text-body text-text-muted [overflow-wrap:anywhere]">{{ __('entries.desc_'.$type) }}</p>
    </header>

    {{-- The list card: groups (from 900) · search + tools · the active-filter line · the rows · the pager. Below 600
         the card frame moves onto the rows, so the tools stand on the page and the first record comes up sooner. --}}
    <div class="min-w-0 min-[600px]:rounded-md min-[600px]:border min-[600px]:border-border min-[600px]:bg-surface min-[600px]:pb-4 min-[600px]:shadow-card">
        <x-bs-admin::group-row :groups="$groups" :active="$tab" model="tab" :label="__('entries.groups_label', ['noun' => $noun])" />

        {{-- What shows on each record follows the list's own width: below 600 the member's card choice, 600–899
             every detail, from 900 their column choice (both kept in their own preferences). --}}
        <div class="@container/lt min-w-0"
             x-data="{
                 mode: 'cols',
                 size() { const w = this.$refs.rows?.clientWidth ?? 0, rem = parseFloat(getComputedStyle(document.documentElement).fontSize) || 16; this.mode = w < 37.5 * rem ? 'card' : (w < 56.25 * rem ? 'line' : 'cols'); },
                 show(k) { return this.mode === 'line' || (this.mode === 'cols' ? this.$wire.cols : this.$wire.card).includes(k); },
                 get vPath() { return this.show('path'); },
                 get vLangs() { return this.show('langs'); },
                 get vTpl() { return this.show('tpl'); },
             }"
             x-init="size(); new ResizeObserver(() => size()).observe($el)">
            <div class="flex flex-col gap-3 pb-3 min-[600px]:px-4 min-[600px]:pt-4 min-[900px]:pt-3 @min-[56.25rem]/lt:flex-row @min-[56.25rem]/lt:flex-wrap @min-[56.25rem]/lt:items-center">
                <div class="min-w-0 @min-[56.25rem]/lt:max-w-[26rem] @min-[56.25rem]/lt:flex-[1_1_16rem]">
                    <x-bs-admin::search id="entries-search" :label="__('entries.search_placeholder')" wire:model.live.debounce.300ms="search" />
                </div>
                <x-bs-admin::tool-row class="@min-[56.25rem]/lt:contents">
                    @if ($pageUlids !== [])
                        <div class="min-w-0 @min-[56.25rem]/lt:hidden">
                            <x-bs-admin::list.check :checked="$allState" :label="__('entries.select_all')" visible :wire:click="$selectAllCall" />
                        </div>
                    @endif
                    <x-bs-admin::filter-panel class="ms-auto flex-nowrap @min-[56.25rem]/lt:ms-0 @min-[56.25rem]/lt:flex-wrap" :sections="$sections" :active="$filterCount"
                        :results="__('entries.show_n', ['n' => $fmt($matchCount), 'unit' => $noun])" clear="clearAllFilters" />
                    <x-bs-admin::sort-menu class="@min-[56.25rem]/lt:ms-auto" :options="$sortOptions" :active="$sortOn" :lit="! $sortIsDefault" set="setSort" />
                    <div class="relative hidden shrink-0 @min-[56.25rem]/lt:block" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.prevent.stop="open && (open = false, $refs.cols.focus())">
                        <button type="button" x-ref="cols" @click="open = ! open" :aria-expanded="open ? 'true' : 'false'" aria-controls="entries-cols"
                            class="hit-area inline-flex min-h-control items-center gap-1.5 rounded-full border border-transparent bg-primary/7 px-3.5 text-body font-medium text-text transition-colors hover:bg-primary/12">
                            <x-bs-admin::icon name="ri-layout-column" class="size-4 text-text-muted" /> {{ __('entries.cols_button') }}
                            <x-bs-admin::icon name="chevron-down" class="size-4 text-text-muted transition-transform" ::class="open && 'rotate-180'" />
                        </button>
                        <div x-show="open" x-cloak x-transition id="entries-cols" role="group" aria-label="{{ __('entries.cols_button') }}"
                            class="absolute end-0 top-full z-60 mt-1.5 w-60 rounded-md border border-border bg-surface p-1.5 shadow-lg">
                            <p class="m-0 px-2 pb-1 pt-1.5 text-caption font-semibold text-text-muted">{{ __('entries.cols_required') }}</p>
                            @foreach ([__('entries.title_label'), __('entries.col_status'), __('admin.actions')] as $locked)
                                <label class="flex min-h-control cursor-not-allowed items-center gap-2.5 rounded px-2 text-body text-text-muted">
                                    <input type="checkbox" checked disabled class="size-4 rounded border-border-strong">
                                    {{ $locked }}
                                </label>
                            @endforeach
                            <p class="m-0 px-2 pb-1 pt-2 text-caption font-semibold text-text-muted">{{ __('entries.cols_optional') }}</p>
                            @foreach ($colOptions as $key => $label)
                                <label class="flex min-h-control cursor-pointer items-center gap-2.5 rounded px-2 text-body text-text hover:bg-surface-hover">
                                    <input type="checkbox" value="{{ $key }}" wire:model.live="cols" class="size-4 shrink-0 accent-primary-text">
                                    {{ $label }}
                                </label>
                            @endforeach
                        </div>
                    </div>
                </x-bs-admin::tool-row>
            </div>

            <x-bs-admin::filter-status class="-mx-3 -mt-1 mb-2 min-[600px]:mx-1" :group="$groupLabel"
                :summary="$filtering ? __('entries.match_of', ['n' => $fmt($matchCount), 'm' => $fmt($counts[$tab] ?? $baseCount), 'unit' => $noun]) : null"
                :chips="$chips" :clear="$filtering ? 'clearAllFilters' : null" />

            {{-- Bulk action bar — appears once one or more rows are selected --}}
            @if (count($selected) > 0)
                <div class="mb-3 flex flex-wrap items-center gap-2 rounded-lg border border-primary/30 bg-primary/5 px-3 py-2 min-[600px]:mx-4">
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
                $ariaSort = fn (string $key): string => $sortOn !== $key ? 'none' : ($sortWay === 'asc' ? 'ascending' : 'descending');
                $Js = \Illuminate\Support\Js::class;
            @endphp
            {{-- The shared list row: a card below 600, one record per line to 900, a table from 900 (by the list's own
                 width). Below 600 the rows carry the card frame; select-all lives in the tools row above. --}}
            <div x-ref="rows" class="min-w-0 max-[599.98px]:rounded-md max-[599.98px]:border max-[599.98px]:border-border max-[599.98px]:bg-surface max-[599.98px]:[&_.list-g]:border-t-0">
            <x-bs-admin::list :label="__('entries.list_label', ['label' => $sectionLabel])" x-bind:data-hide="mode === 'cols' && ! show('creator') ? 'au' : ''">

                <x-bs-admin::list.head>
                    <x-bs-admin::list.cell head col="chk">
                        @if ($pageUlids !== [])
                            <x-bs-admin::list.check :checked="$allState" :label="__('entries.select_all')" :wire:click="$selectAllCall" />
                        @endif
                    </x-bs-admin::list.cell>
                    <x-bs-admin::list.cell head col="main" :sort="$ariaSort('name')">
                        <x-bs-admin::list.sort :active="$sortOn === 'name'" :dir="$sortWay" wire:click="sortBy('name')">{{ __('entries.title_label') }}</x-bs-admin::list.sort>
                    </x-bs-admin::list.cell>
                    <x-bs-admin::list.cell head col="st" :sort="$ariaSort('status')">
                        <x-bs-admin::list.sort :active="$sortOn === 'status'" :dir="$sortWay" wire:click="sortBy('status')">{{ __('entries.col_status') }}</x-bs-admin::list.sort>
                    </x-bs-admin::list.cell>
                    <x-bs-admin::list.cell head col="au" :sort="$ariaSort('creator')">
                        <x-bs-admin::list.sort :active="$sortOn === 'creator'" :dir="$sortWay" wire:click="sortBy('creator')">{{ __('entries.col_creator') }}</x-bs-admin::list.sort>
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
                                    <span class="list-m" x-show="vPath">
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
                                    <x-bs-admin::list.langs :locales="$locales" :source="$locale" :name="$title" show="vLangs"
                                        :has="$entry->translations->pluck('locale')->all()"
                                        :href="$quickEdit ? fn (string $lc): string => route('admin.entries.edit', ['ulid' => $entry->ulid, 'locale' => $lc]) : null"
                                        :why="$trashed ? __('entries.lc_why_trashed') : __('entries.lc_why_view_only', ['noun' => __('entries.noun_'.$entry->type)])" />
                                    @if (! $isPost && $entry->template_override !== null)
                                        <span class="list-m" data-for="tpl" x-show="vTpl">{{ __('entries.meta_tpl', ['name' => $entry->template_override]) }}</span>
                                    @endif
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
            </div>

            <div class="mt-4 flex flex-wrap items-center justify-between gap-3 min-[600px]:px-4">
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
    </div>
</div>
