@php
    use Illuminate\Support\Js;
    use App\Modules\Admin\Livewire\Entries\Index as EntriesIndex;

    $sectionLabel = $sectionModel?->localizedLabel() ?? __('entries.section_fallback');
    // the list is named as its section in the menu ("Tin tức", "Trang"), with no "Quản lý" before it
    $pageTitle = $sectionLabel;
    $isPost = $type === 'post';
    $noun = __('entries.noun_'.$type);
    // A locked item's click: its reason in a toast with the lock mark, kept until the member closes it.
    $lockMark = \Illuminate\Support\Facades\Blade::render('<x-bs-admin::icon name="ri-lock" class="size-4" />');
    $lockToast = fn (string $why): Js => Js::from(['text' => $why, 'icon' => $lockMark, 'stay' => true, 'close' => __('admin.close')]);
    // a list stops counting past 10,000 rows: such a count reads "hơn 10.000"
    $fmt = fn (int|string $n): string => is_string($n) ? $n : ($n > \App\Modules\Admin\Support\Entries\EntryListQuery::CAP ? __('entries.count_over') : \Illuminate\Support\Number::format($n, locale: app()->getLocale()));
    $num = fn (int $n): string => \Illuminate\Support\Number::format($n, locale: app()->getLocale());
    $countOf = fn (int $n): int|string => $n > \App\Modules\Admin\Support\Entries\EntryListQuery::CAP ? __('entries.count_over') : $n;
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
    // Each count is drawn in its status's own ink, as the pills on the rows.
    $groupKeys = ['all', 'published', 'draft', 'scheduled', 'pending', 'private', 'archived'];
    $groups = [];
    foreach ($groupKeys as $key) {
        if ($key === 'pending' && ($counts['pending'] ?? 0) === 0 && $tab !== 'pending') {
            continue;
        }
        $groups[] = ['key' => $key, 'label' => $key === 'all' ? __('entries.tab_all') : __('entries.status_'.$key), 'icon' => $tabIcon($key), 'count' => $countOf($counts[$key] ?? 0), 'tone' => $key];
    }
    if ($canSeeTrash || $tab === 'trash') {
        $groups[] = ['key' => 'trash', 'label' => __('entries.tab_trash'), 'icon' => 'ri-delete-bin', 'count' => $countOf($counts['trash'] ?? 0), 'trash' => true, 'tone' => 'trash'];
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
    $colOptions = $isPost ? ['cat' => __('entries.col_category')] : [];
    $colOptions['path'] = __('entries.col_path');
    if ($multilingual) {
        $colOptions['langs'] = __('entries.col_language');
    }
    $colOptions['creator'] = __('entries.col_creator');
    if (! $isPost) {
        $colOptions['tpl'] = __('entries.filter_template');
    }
    $cardOptions = array_intersect_key($colOptions, array_flip(['cat', 'tpl', 'path', 'langs']));
    $cardOptions = (! $isPost ? ['tpl' => $colOptions['tpl']] : []) + $cardOptions;

    // The Filter sheet (and from 1024 one button per section). Status groups lead it below 900, where their row hides.
    $sections = [
        ['key' => 'status', 'label' => __('entries.status_label'), 'icon' => 'ri-flag', 'model' => 'tab', 'value' => $tab, 'default' => 'all',
            'sheet' => true, 'class' => 'min-[900px]:hidden',
            'options' => array_map(fn (array $g): array => ['value' => $g['key'], 'label' => $g['label'], 'count' => $g['count'], 'divider' => $g['trash'] ?? false], $groups)],
    ];
    // a post list's categories lead its filters, as in the design (offered while the section has any)
    if ($isPost && ($catOptions !== [] || $catF !== [])) {
        $sections[] = ['key' => 'cat', 'label' => __('entries.col_category'), 'icon' => 'ri-price-tag-3', 'model' => 'catF', 'multiple' => true, 'value' => $catF, 'options' => $catOptions];
    }
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
    // a view choice, not a filter: never counted, never marked as filtering. It is there exactly while the rows are
    // cards (the list narrower than 37.5rem, as mode below); from there to 900 every detail shows, and from 900 Cột decides.
    $sections[] = ['key' => 'card', 'label' => __('entries.f_card'), 'model' => 'card', 'multiple' => true, 'value' => $card, 'sheet' => true, 'class' => '@min-[37.5rem]/lt:hidden', 'on' => false,
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

    // the select-all box ticks the rows of this page: named "Chọn cả trang" once the list has more than one page
    $selectAllLabel = $pager['last'] === null || $pager['last'] > 1 ? __('entries.select_page') : __('entries.select_all');
    // a row's last edit (or, scheduled, the moment it goes up), in the website's clock
    $nowAt = $siteTime->now();
    $pageSelected = count(array_intersect($pageUlids, $selected));
    $allState = $pageUlids !== [] && $pageSelected === count($pageUlids) ? true : ($pageSelected > 0 ? 'mixed' : false);
    $selectAllCall = 'toggleSelectAll(['.collect($pageUlids)->map(fn (string $u): string => "'".$u."'")->implode(',').'])';
@endphp
<div class="entries-index flex flex-col gap-3">

    {{-- The header, as the admin shell draws it: the trail «⌂ › section › Danh sách» (the section a link back to its
         list), the title row (the name, then the (?) guide and the main button as one group at the end of the row),
         then one line on what the list holds. Below 600, or on a short landscape screen, the way back is the menu, so
         the trail gives its room to the list and the line under the name is its short phone wording. --}}
    <header class="flex flex-col gap-1">
        <x-bs-admin::breadcrumb data-guide="crumb" class="max-[599.98px]:hidden [@media(max-height:500px)]:hidden" :items="[
            ['label' => $sectionLabel, 'url' => route('admin.entries.index', $sectionKey ? ['section' => $sectionKey] : [])],
            ['label' => __('admin.list')],
        ]" />
        <x-bs-admin::title-row :title="$pageTitle" title-id="entries-title">
            <x-slot:help>
                <x-bs-admin::guide :guide="$guide" />
            </x-slot:help>
            {{-- Only with the right to create; a member limited to some categories gets the page list's button locked,
                 with the reason (a page has no category). --}}
            @if ($addLock)
                <x-bs-admin::button color="primary" class="shrink-0 aria-disabled:cursor-not-allowed aria-disabled:opacity-60" data-guide="add" aria-disabled="true" :title="$addLock"
                    :aria-label="__('entries.add_'.$type).' — '.$addLock" x-on:click="$bsToast({{ $lockToast($addLock) }})"
                    :label="__('entries.add_'.$type)" :short="__('admin.list.add_short')">
                    <x-bs-admin::icon name="ri-lock" class="size-4" />
                </x-bs-admin::button>
            @elseif ($canCreate)
                <x-bs-admin::button color="primary" class="shrink-0" data-guide="add" :href="route('admin.entries.create', $sectionKey ? ['section' => $sectionKey] : [])"
                    :label="__('entries.add_'.$type)" :short="__('admin.list.add_short')">
                    <x-bs-admin::icon name="ri-add" class="size-4" />
                </x-bs-admin::button>
            @endif
        </x-bs-admin::title-row>
        <p class="m-0 text-body text-text-muted [overflow-wrap:anywhere]">
            <span data-desc="full" class="max-[599.98px]:hidden [@media(max-height:500px)]:hidden">{{ __('entries.desc_'.$type, ['label' => mb_strtolower(mb_substr($sectionLabel, 0, 1)).mb_substr($sectionLabel, 1)]) }}</span>
            <span data-desc="short" class="hidden max-[599.98px]:inline [@media(max-height:500px)]:inline">{{ __('entries.mdesc_'.$type) }}</span>
        </p>
    </header>

    {{-- Where the member stands here: their role, how this section publishes (straight, or through its approval
         workflow — then the card takes the accent tint) and what they may do. It stays until they press "Đã hiểu",
         remembered for them on the server; focus then goes to the list's name. Once put away, the first draw prints
         it once more, hidden, inside a part redraws leave alone: the guide's tour opens it for its first step and
         closes it again after (resources/js/admin-guide.js). --}}
    @if ($roleNote)
        @include('admin::livewire.entries.partials.role-card', ['note' => $roleNote, 'copy' => false])
    @endif
    @if ($isPost)
        <div wire:ignore class="contents" data-guide-role-box>
            @if ($tourRoleNote)
                @include('admin::livewire.entries.partials.role-card', ['note' => $tourRoleNote, 'copy' => true])
            @endif
        </div>
    @endif

    {{-- The list card: groups (from 900) · search + tools · the active-filter line · the rows · the pager. Below 600
         the card frame moves onto the rows, so the tools stand on the page and the first record comes up sooner. --}}
    <div class="min-w-0 min-[600px]:rounded-md min-[600px]:border min-[600px]:border-border min-[600px]:bg-surface min-[600px]:pb-4 min-[600px]:shadow-card">
        <x-bs-admin::group-row data-guide="tabs" :groups="$groups" :active="$tab" model="tab" :label="__('entries.groups_label', ['noun' => $noun])" />

        {{-- What shows on each record follows the list's own width: below 600 the member's card choice, 600–899
             every detail, from 900 their column choice (both kept in their own preferences). --}}
        <div class="@container/lt min-w-0"
             x-data="{
                 mode: 'cols',
                 fold: '',
                 seen: '',
                 ro: null,
                 destroy() { this.ro?.disconnect(); },
                 size(force = false) { const w = this.$refs.rows?.clientWidth ?? 0, rem = parseFloat(getComputedStyle(document.documentElement).fontSize) || 16, boost = window.bsListFold.boostPx(), key = w + ':' + boost; if (! force && key === this.seen) { return; } this.seen = key; this.mode = w < 37.5 * rem ? 'card' : (w < 56.25 * rem ? 'line' : 'cols'); this.$nextTick(() => this.foldCols(boost)); },
                 {{-- a side column shows whenever the name text keeps the shared minimum beside it: as the table narrows or the text grows, the category and then the author fold into the line under the name. One pass after the chosen columns are drawn (next tick): clear the fold, read every width, write the fold once. The name text's room is the name cell less what stands beside the text in it (padding, the cover, the indent, the ✎), read on the first row. --}}
                 {{-- the rows stay hidden (visibility, so they can still be measured) from the first paint until this first pass has folded them: no flash of columns that then fold --}}
                 foldCols(boost) {
                     const list = this.$refs.rows?.querySelector('.list-rows'), head = list?.querySelector('.list-hd'), main = head?.querySelector('.list-c-main');
                     this.$nextTick(() => this.$refs.rows?.removeAttribute('data-fold-wait'));
                     if (! list || ! main) { return; }
                     let out = [];
                     if (this.mode === 'cols') {
                         list.removeAttribute('data-fold');
                         const cols = ['cat', 'au'].map((k) => [k, head.querySelector('.list-c-' + k)?.getBoundingClientRect().width ?? 0]);
                         const cell = list.querySelector('.list-r:not(.list-hd) > .list-c-main'), tl = cell?.querySelector('.list-tl'), name = tl?.querySelector('.list-name');
                         let beside = 0;
                         if (tl && name && tl.getClientRects().length) { beside = cell.getBoundingClientRect().width - tl.getBoundingClientRect().width; for (const c of tl.children) { if (c !== name && c.getClientRects().length) { const cs = getComputedStyle(c); beside += c.getBoundingClientRect().width + (parseFloat(cs.marginLeft) || 0) + (parseFloat(cs.marginRight) || 0); } } }
                         else { const cs = getComputedStyle(main); beside = (parseFloat(cs.paddingLeft) || 0) + (parseFloat(cs.paddingRight) || 0); }
                         out = window.bsListFold.nameFold(main.getBoundingClientRect().width - beside, cols, boost);
                     }
                     this.fold = out.join(' ');
                     this.fold === '' ? list.removeAttribute('data-fold') : list.setAttribute('data-fold', this.fold);
                 },
                 show(k) { return this.mode === 'line' || (this.mode === 'cols' ? this.$wire.cols : this.$wire.card).includes(k); },
                 get vPath() { return this.show('path'); },
                 get vLangs() { return this.show('langs'); },
                 get vTpl() { return this.show('tpl'); },
                 get vCat() { return this.show('cat'); },
             }"
             x-init="size(true); ro = new ResizeObserver(() => size()); ro.observe($el); $watch('$wire.cols', () => $nextTick(() => size(true))); $wire.$hook('commit', ({ succeed }) => succeed(() => $nextTick(() => size(true))))">
            <div class="flex flex-col gap-3 pb-3 min-[600px]:px-4 min-[600px]:pt-4 min-[900px]:pt-3 @min-[56.25rem]/lt:flex-row @min-[56.25rem]/lt:flex-wrap @min-[56.25rem]/lt:items-center">
                {{-- From 900 the search and the tools share a row: the search keeps room for its whole short hint
                     ("Ví dụ: bảng giá", sized in its own text size, so it grows with the member's text size) and takes
                     the free space; when the
                     tools no longer fit beside it they go down together, as one group, and the search takes the whole
                     line (the tools row marks itself data-below). --}}
                <div data-guide="search" class="min-w-0 text-body @min-[56.25rem]/lt:min-w-[11em] @min-[56.25rem]/lt:max-w-[30em] @min-[56.25rem]/lt:flex-[999_1_11em] @min-[56.25rem]/lt:[&:has(+[data-below='1'])]:max-w-none">
                    <x-bs-admin::search id="entries-search" :label="__('entries.search_placeholder')" :placeholder="__('entries.search_hint_'.$type)" wire:model.live.debounce.300ms="search" />
                </div>
                <x-bs-admin::tool-row class="@min-[56.25rem]/lt:min-w-0 @min-[56.25rem]/lt:flex-[1_1_auto] @min-[56.25rem]/lt:flex-wrap @min-[56.25rem]/lt:gap-2">
                    @if ($pageUlids !== [])
                        <div class="min-w-0 @min-[56.25rem]/lt:hidden" data-guide="select-tool">
                            <x-bs-admin::list.check :checked="$allState" :label="$selectAllLabel" visible :wire:click="$selectAllCall" />
                        </div>
                    @endif
                    <x-bs-admin::filter-panel data-guide="filters" class="ms-auto flex-nowrap @min-[56.25rem]/lt:ms-0 @min-[56.25rem]/lt:min-w-0 @min-[56.25rem]/lt:shrink @min-[56.25rem]/lt:flex-wrap" :sections="$sections"
                        :active="$filterCount" :active-narrow="$filterCountNarrow" :results="__('entries.show_n', ['n' => $fmt($matchCount), 'unit' => $noun])" clear="clearAllFilters" />
                    <x-bs-admin::sort-menu data-guide="sort" class="@min-[56.25rem]/lt:ms-auto" :options="$sortOptions" :active="$sortOn" :lit="! $sortIsDefault" set="setSort" />
                    <x-bs-admin::cols-menu data-guide="cols" class="hidden @min-[56.25rem]/lt:block" id="entries-cols" :options="$colOptions" :selected="$cols"
                        :locked="[__('entries.title_label'), __('entries.col_status'), __('admin.actions')]" />
                </x-bs-admin::tool-row>
            </div>

            <x-bs-admin::filter-status :data-guide="$filtering ? 'filter-line' : 'filter-sort'" class="-mx-3 -mt-1 mb-2 min-[600px]:mx-1" :group="$groupLabel"
                :summary="$filtering ? __('entries.match_of', ['n' => $fmt($matchCount), 'm' => $fmt($counts[$tab] ?? $baseCount), 'unit' => $noun]) : null"
                :chips="$chips" :clear="$filtering ? 'clearAllFilters' : null" />

            {{-- Bulk action bar — appears once one or more rows are selected --}}
            @if (count($selected) > 0)
                <div data-guide="bulk-bar" class="mb-3 flex flex-wrap items-center gap-2 rounded-lg border border-primary/30 bg-primary/5 px-3 py-2 min-[600px]:mx-4">
                    <span class="text-sm font-medium text-primary-text">{{ __('entries.bulk_selected', ['noun' => $noun, 'count' => count($selected)]) }}</span>
                    @if ($tab === 'trash')
                        @if ($canDelete)
                            <button type="button" wire:click="bulkRestore"
                                class="inline-flex items-center gap-1.5 rounded border border-border-strong bg-surface px-2.5 py-1 text-sm font-medium text-body-text transition-colors hover:border-accent hover:text-accent">
                                <x-bs-admin::icon name="ri-refresh" class="size-3.5 text-body-muted" /> {{ __('entries.action_restore') }}
                            </button>
                            <button type="button"
                                x-on:click="$bsConfirm({ icon: 'trash-danger', text: @js(__('entries.force_delete_confirm_selected', ['noun' => $noun])), confirm: @js(__('entries.action_destroy')), cancel: @js(__('admin.cancel')) }).then(ok => ok && $wire.bulkForceDelete())"
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
                        @elseif ($canSend)
                            <button type="button" wire:click="bulkPublish"
                                class="inline-flex items-center gap-1.5 rounded border border-border-strong bg-surface px-2.5 py-1 text-sm font-medium text-body-text transition-colors hover:border-accent hover:text-accent">
                                <x-bs-admin::icon name="ri-inbox-unarchive" class="size-3.5 text-body-muted" /> {{ __('entries.ed.submit') }}
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
                                x-on:click="$bsConfirm({ icon: 'trash', text: @js(__('entries.trash_confirm_selected', ['noun' => $noun])), confirm: @js(__('entries.action_trash')), cancel: @js(__('admin.cancel')) }).then(ok => ok && $wire.bulkTrash())"
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
            <div x-ref="rows" data-fold-wait class="min-w-0 max-[599.98px]:rounded-md max-[599.98px]:border max-[599.98px]:border-border max-[599.98px]:bg-surface max-[599.98px]:[&_.list-g]:border-t-0">
            <x-bs-admin::list :label="__('entries.list_label', ['label' => $sectionLabel])" :kind="$isPost ? 'posts' : null"
                x-bind:data-hide="[mode === 'cols' && ! show('creator') ? 'au' : '', mode === 'cols' && ! show('cat') ? 'cat' : ''].join(' ').trim()" x-bind:data-fold="fold">

                <x-bs-admin::list.head data-guide="list-head">
                    <x-bs-admin::list.cell head col="chk">
                        @if ($pageUlids !== [])
                            <x-bs-admin::list.check data-guide="select-head" :checked="$allState" :label="$selectAllLabel" :wire:click="$selectAllCall" />
                        @endif
                    </x-bs-admin::list.cell>
                    <x-bs-admin::list.cell head col="main" :sort="$ariaSort('name')">
                        <x-bs-admin::list.sort :active="$sortOn === 'name'" :dir="$sortWay" wire:click="sortBy('name')">{{ __('entries.title_label') }}</x-bs-admin::list.sort>
                    </x-bs-admin::list.cell>
                    @if ($isPost)
                        <x-bs-admin::list.cell head col="cat">{{ __('entries.col_category') }}</x-bs-admin::list.cell>
                    @endif
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
                        // "Sửa hôm nay, 10:20" … "Sửa 7 ngày trước", then the day; a scheduled one "Đăng lúc 23/11/2026 · 08:00"
                        $when = $siteTime->forDisplay($isScheduled ? $entry->published_at : $entry->updated_at);
                        $whenText = $when ? ($isScheduled
                            ? __('entries.scheduled_at', ['date' => $when->format('d/m/Y'), 'time' => $when->format('H:i')])
                            : \App\Modules\Admin\Support\EditedWhen::short($when, $nowAt, recentDays: 7, dayThisYear: false)) : null;
                        $whenFull = $when ? \App\Modules\Admin\Support\EditedWhen::full($when) : null;
                        // visitors see only a published record, so only its views are named
                        $isLive = $status === 'published' && ! $trashed;
                        $slugLeaf = $t?->slug;
                        $slugPrefix = ($path !== null && $slugLeaf) ? \Illuminate\Support\Str::beforeLast($path, $slugLeaf) : '/';
                        $quickEdit = ($rowEditable[$entry->ulid] ?? false) && ! $trashed;
                        $may = $rowCan[$entry->ulid];
                        // outside the member's limits: «🔒 Xem» and the reason instead of «Sửa», its items locked
                        $out = $trashed ? null : $may['out'];
                        $outWhy = $out !== null ? (preg_replace('/[.。]$/u', '', $out) ?? $out) : null;
                    @endphp
                    <x-bs-admin::list.row :selected="$isSelected" wire:key="entry-{{ $entry->ulid }}"
                        x-data="{ editing: false, value: {{ $Js::from($t?->title ?? '') }}, cancel: false }"
                        x-on:list-quick-edit="editing = true; cancel = false">
                        <x-bs-admin::list.cell col="chk">
                            <x-bs-admin::list.check data-guide="row-check" :checked="$isSelected" :label="__('entries.select_named', ['title' => $title])" wire:click="toggleSelectAll(['{{ $entry->ulid }}'])" />
                        </x-bs-admin::list.cell>

                        <x-bs-admin::list.cell col="main">
                            @if ($isPost)
                                <div class="list-pw">
                                @if ($rowCover[$entry->ulid] ?? null)
                                    <span class="list-th" title="{{ __('entries.cover') }}"><img src="{{ $rowCover[$entry->ulid] }}" alt="" loading="lazy" decoding="async" width="64" height="36"></span>
                                @else
                                    <span class="list-th" data-img="0" title="{{ __('entries.cover_none') }}" aria-hidden="true"><x-bs-admin::icon name="ri-image-add" class="size-5" /></span>
                                @endif
                            @endif
                            <div @class(['pl-5' => $depth, 'min-w-0 flex-1' => $isPost])>
                                <div x-show="! editing">
                                    <x-bs-admin::list.name :data-guide="$quickEdit ? 'row-title-qe' : 'row-title'" :href="$quickEdit ? route('admin.entries.edit', ['ulid' => $entry->ulid]) : ($trashed ? null : $path)" :external="! $quickEdit"
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
                                            <span class="inline-flex items-center gap-1 font-mono text-caption" data-guide="row-path-home" title="{{ __('entries.home_locked') }}">{{ $path ?? '/' }} <x-bs-admin::icon name="ri-lock" class="size-3" /></span>
                                        @elseif ($quickEdit)
                                            <span wire:key="slug-{{ $entry->ulid }}-{{ $slugLeaf }}" x-data="{ editing: false, value: @js($slugLeaf ?? ''), cancel: false }">
                                                <button type="button" x-show="! editing" data-guide="row-path-edit"
                                                    @click="editing = true; cancel = false; $nextTick(() => { $refs.slugInput.focus(); $refs.slugInput.select() })"
                                                    title="{{ $path }} · {{ __('entries.edit_hint') }}"
                                                    class="list-path font-mono text-caption text-text-muted [overflow-wrap:anywhere] transition-colors hover:bg-primary-soft hover:text-primary-text">{{ $path ?? '—' }}</button>
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
                                            <span class="font-mono text-caption" data-guide="row-path" title="{{ $path }}">{{ $path ?? '—' }}</span>
                                        @endif
                                    </span>
                                    @if ($isPost)
                                        <span class="list-m [overflow-wrap:anywhere]" data-for="cat" x-show="vCat">{{ $rowCategory[$entry->ulid] ?? __('entries.cat_none') }}</span>
                                    @endif
                                    @if (isset($parentPaths[$entry->ulid]))
                                        <span class="list-m [overflow-wrap:anywhere]" data-for="parent">{{ __('entries.meta_parent', ['path' => $parentPaths[$entry->ulid]]) }}</span>
                                    @endif
                                    <x-bs-admin::list.langs :locales="$locales" :source="$locale" :name="$title" show="vLangs"
                                        :has="$entry->translations->pluck('locale')->all()"
                                        :href="$quickEdit ? fn (string $lc): string => route('admin.entries.edit', ['ulid' => $entry->ulid, 'locale' => $lc]) : null"
                                        :why="$trashed ? __('entries.lc_why_trashed') : __('entries.lc_why_view_only', ['noun' => __('entries.noun_'.$entry->type)])" />
                                    @if (! $isPost && $entry->template_override !== null)
                                        <span class="list-m" data-for="tpl" x-show="vTpl">{{ __('entries.meta_tpl', ['name' => $entry->template_override]) }}</span>
                                    @endif
                                    {{-- as in the design, views are named only once there are some --}}
                                    @if ($isLive && $entry->view_count > 0)
                                        <span class="list-m" data-for="views" data-guide="row-views">{{ __('entries.views_count', ['count' => \Illuminate\Support\Number::format($entry->view_count, locale: app()->getLocale())]) }}</span>
                                    @endif
                                    @if ($creator)
                                        <span class="list-m" data-for="au">{{ $creator }}</span>
                                    @endif
                                    @if ($whenText)
                                        <span class="list-m" data-for="when"><x-bs-admin::list.when :at="$when" :text="$whenText" :full="$whenFull" :exact="$isScheduled" /></span>
                                    @endif
                                </x-bs-admin::list.meta>
                            </div>
                            @if ($isPost)
                                </div>
                            @endif
                        </x-bs-admin::list.cell>

                        @if ($isPost)
                            <x-bs-admin::list.cell col="cat"><span class="list-ell" title="{{ $rowCategory[$entry->ulid] ?? '' }}">{{ $rowCategory[$entry->ulid] ?? __('entries.cat_none') }}</span></x-bs-admin::list.cell>
                        @endif

                        <x-bs-admin::list.cell col="st">
                            <div class="list-stw" data-guide="row-status">
                                @if ($may['send'] && ! $trashed)
                                    {{-- a sender's one move here: «Gửi duyệt» on their draft --}}
                                    <div x-data="{ open: false }" @click.outside="open = false" @keydown.escape="if (open) { open = false; $refs.pill.focus() }" class="relative inline-block">
                                        <x-bs-admin::status-pill button x-ref="pill" :status="$status" x-on:click="open = ! open" x-bind:aria-expanded="open.toString()" aria-haspopup="true" title="{{ __('entries.status_menu_label') }}">{{ __('entries.status_'.$status) }}<span class="sr-only"> · {{ __('entries.status_menu_label') }}</span></x-bs-admin::status-pill>
                                        <div x-show="open" x-cloak x-transition
                                            class="absolute left-0 z-30 mt-1 w-56 rounded-lg border border-border bg-surface p-1 shadow-lg">
                                            <button type="button" x-on:click="$wire.changeStatus(@js($entry->ulid), 'pending'); open = false" class="list-mi rounded">
                                                <x-bs-admin::status-pill status="pending">{{ __('entries.ed.submit') }}</x-bs-admin::status-pill>
                                            </button>
                                        </div>
                                    </div>
                                @elseif ($may['publish'] && ! $trashed)
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
                                                        class="field w-full rounded border border-border-input bg-surface px-2 text-text">
                                                    <x-bs-admin::button size="sm" class="mt-1.5 w-full" x-on:click="$wire.scheduleEntry({{ $Js::from($entry->ulid) }}, $refs.when.value); open = false; sched = false">{{ __('entries.schedule_set_action') }}</x-bs-admin::button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                @else
                                    <x-bs-admin::status-pill :status="$status">{{ __('entries.status_'.$status) }}</x-bs-admin::status-pill>
                                @endif
                            </div>
                            @if ($out !== null)
                                <div class="list-lk" data-guide="row-lock"><x-bs-admin::icon name="ri-lock" class="size-3 shrink-0" /><span>{{ $out }}</span></div>
                            @elseif ($whenText)
                                <div class="list-st-meta">
                                    <x-bs-admin::icon :name="$isScheduled ? 'ri-time' : 'ri-history'" class="size-3 shrink-0" />
                                    <x-bs-admin::list.when :at="$when" :text="$whenText" :full="$whenFull" :exact="$isScheduled" />
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
                                    @if ($may['delete'])
                                        <x-bs-admin::button size="sm" variant="soft" color="primary" wire:click="restore('{{ $entry->ulid }}')">
                                            <x-bs-admin::icon name="ri-arrow-go-back" class="size-4" /> {{ __('entries.action_restore') }}
                                        </x-bs-admin::button>
                                        <x-slot:menu>
                                            <x-bs-admin::list.menu-item icon="ri-delete-bin" danger
                                                x-on:click="$bsConfirm({ icon: 'trash-danger', text: {{ $Js::from(__('entries.force_delete_confirm', ['noun' => __('entries.noun_'.$entry->type)])) }}, confirm: {{ $Js::from(__('entries.action_destroy')) }}, cancel: {{ $Js::from(__('admin.cancel')) }} }).then(ok => ok && $wire.forceDelete({{ $Js::from($entry->ulid) }}))">{{ __('entries.action_destroy') }}</x-bs-admin::list.menu-item>
                                        </x-slot:menu>
                                    @endif
                                @elseif ($out !== null)
                                    {{-- outside the member's limits: one «🔒 Xem» that opens the record (on the website, or a preview), saying why it does not open the editor --}}
                                    @if ($path)
                                        <x-bs-admin::button size="sm" variant="soft" color="secondary" :href="$path" :navigate="false" target="_blank" rel="noopener" data-guide="row-view"
                                            :title="__($isLive ? 'entries.locked_view_live' : 'entries.locked_view_preview', ['why' => $outWhy])"
                                            :aria-label="__('entries.locked_view_named', ['title' => $title, 'why' => $outWhy]).' ('.__('entries.opens_new_tab').')'">
                                            <x-bs-admin::icon name="ri-lock" class="size-4" /> {{ __('admin.view') }}
                                        </x-bs-admin::button>
                                    @else
                                        <x-bs-admin::button size="sm" variant="soft" color="secondary" class="aria-disabled:cursor-not-allowed aria-disabled:opacity-60" aria-disabled="true" data-guide="row-view" :title="$out"
                                            :aria-label="__('entries.locked_view_named', ['title' => $title, 'why' => $outWhy])" x-on:click="$bsToast({{ $lockToast($out) }})">
                                            <x-bs-admin::icon name="ri-lock" class="size-4" /> {{ __('admin.view') }}
                                        </x-bs-admin::button>
                                    @endif
                                    <x-slot:menu>
                                        <x-bs-admin::list.menu-item icon="ri-pencil" :why="$outWhy" x-on:click="$bsToast({{ $lockToast($out) }})">{{ __('entries.quick_edit_title') }}</x-bs-admin::list.menu-item>
                                        @if ($canCreate)
                                            <x-bs-admin::list.menu-item icon="ri-file-copy" :why="$outWhy" x-on:click="$bsToast({{ $lockToast($out) }})">{{ __('entries.action_duplicate') }}</x-bs-admin::list.menu-item>
                                        @endif
                                        @if ($may['deleteRole'])
                                            <x-bs-admin::list.menu-sep />
                                            <x-bs-admin::list.menu-item icon="ri-delete-bin" danger :why="$outWhy" x-on:click="$bsToast({{ $lockToast($out) }})">{{ __('entries.action_trash') }}</x-bs-admin::list.menu-item>
                                        @endif
                                    </x-slot:menu>
                                @else
                                    @if ($path)
                                        {{-- both open in a new tab and say so: a published record on the website, any other as a preview --}}
                                        <x-bs-admin::button size="sm" variant="soft" color="secondary" :href="$path" :navigate="false" target="_blank" rel="noopener"
                                            :title="$isLive ? __('entries.opens_new_tab') : __('entries.action_preview')" data-guide="row-view" :aria-label="__('entries.view_named', ['title' => $title]).' ('.__('entries.opens_new_tab').')'">
                                            <x-bs-admin::icon :name="$isLive ? 'ri-external-link' : 'ri-eye'" class="size-4" /> {{ __('admin.view') }}
                                        </x-bs-admin::button>
                                    @endif
                                    {{-- «Sửa» only on a row this member may edit: the editor would refuse the others --}}
                                    @if ($quickEdit)
                                        <x-bs-admin::button size="sm" variant="soft" color="primary" data-guide="row-edit" :href="route('admin.entries.edit', ['ulid' => $entry->ulid])"
                                            :aria-label="__('entries.edit_named', ['title' => $title])">
                                            <x-bs-admin::icon name="ri-pencil" class="size-4" /> {{ __('entries.edit_action') }}
                                        </x-bs-admin::button>
                                    @endif
                                    <x-slot:menu>
                                        @if ($quickEdit)
                                            <x-bs-admin::list.menu-item icon="ri-pencil" x-on:click="$dispatch('list-quick-edit')">{{ __('entries.quick_edit_title') }}</x-bs-admin::list.menu-item>
                                        @endif
                                        @if ($may['duplicate'])
                                            <x-bs-admin::list.menu-item icon="ri-file-copy" wire:click="duplicate('{{ $entry->ulid }}')">{{ __('entries.action_duplicate') }}</x-bs-admin::list.menu-item>
                                        @endif
                                        @if ($may['delete'])
                                            @if ($quickEdit || $may['duplicate'])
                                                <x-bs-admin::list.menu-sep />
                                            @endif
                                            <x-bs-admin::list.menu-item icon="ri-delete-bin" danger
                                                x-on:click="$bsConfirm({ icon: 'trash', text: {{ $Js::from(__('entries.trash_confirm', ['noun' => __('entries.noun_'.$entry->type)])) }}, confirm: {{ $Js::from(__('entries.action_trash')) }}, cancel: {{ $Js::from(__('admin.cancel')) }} }).then(ok => ok && $wire.trash({{ $Js::from($entry->ulid) }}))">{{ __('entries.action_trash') }}</x-bs-admin::list.menu-item>
                                        @endif
                                    </x-slot:menu>
                                @endif
                            </x-bs-admin::list.actions>
                        </x-bs-admin::list.cell>
                    </x-bs-admin::list.row>
                @empty
                    <x-bs-admin::list.row>
                        <x-bs-admin::list.cell col="full" aria-colspan="{{ $isPost ? 6 : 5 }}">
                            @if ($tab === 'trash')
                                {{ __('entries.trash_empty') }}
                            @elseif (trim($search) !== '')
                                {{ __('entries.search_empty', ['noun' => $noun]) }}
                            @else
                                {{ __('entries.empty') }}
                            @endif
                        </x-bs-admin::list.cell>
                    </x-bs-admin::list.row>
                @endforelse
            </x-bs-admin::list>
            </div>

            <div class="mt-4 flex flex-wrap items-center justify-between gap-3 min-[600px]:px-4">
                <span class="text-caption text-text-muted">
                    {{ __('entries.footer_showing', ['noun' => $noun, 'first' => $num($pager['from']), 'last' => $num($pager['to']), 'total' => $fmt($pager['total'])]) }}
                </span>
                <div class="flex flex-wrap items-center gap-4">
                    <div role="group" data-guide="per-page" aria-label="{{ __('entries.rows_per_page_label') }}" class="flex items-center gap-2 text-caption text-text-muted">
                        <span class="mr-1">{{ __('entries.rows_per_page') }}</span>
                        {{-- Drawn at the row-control size (36 / 40 / 44px across the text sizes) like the pager
                             beside it; the shared row hit area makes each 40 / 44px with a mouse, 44px on touch. --}}
                        @foreach ([10, 20, 50] as $rows)
                            <button type="button" wire:click="setPerPage({{ $rows }})" aria-pressed="{{ $perPage === $rows ? 'true' : 'false' }}"
                                @class(['pager-link border', 'border-primary bg-primary-base/8 font-semibold text-primary-text' => $perPage === $rows, 'border-border-strong bg-surface font-medium text-text hover:bg-surface-hover' => $perPage !== $rows])>
                                {{ $rows }}
                            </button>
                        @endforeach
                    </div>
                    @php
                        // an arrow turns to a page number, or, past the numbered pages, to the row the page follows
                        $pgCurrent = $pager['current'];
                        $turn = fn (?array $to): ?string => $to === null ? null : (isset($to['token']) ? 'turnPage('.$Js::from($to['token']).')' : 'gotoPage('.(int) $to['page'].')');
                        $pgPrev = $turn($pager['prev']);
                        $pgNext = $turn($pager['next']);
                    @endphp
                    <nav aria-label="Pagination" data-guide="pager" class="flex flex-wrap items-center gap-2">
                        <button type="button" @if ($pgPrev) wire:click="{{ $pgPrev }}" @endif @disabled($pgPrev === null)
                            aria-label="{{ __('admin.pagination.previous') }}"
                            @class(['pager-link', 'text-text-muted hover:bg-surface-hover' => $pgPrev !== null, 'cursor-not-allowed text-icon-ghost' => $pgPrev === null])>
                            <x-bs-admin::icon name="chevron-left" class="size-4" />
                        </button>
                        @foreach ($pager['cells'] as $cell)
                            @if ($cell === null)
                                <span class="px-1 text-placeholder">&hellip;</span>
                            @else
                                <button type="button" wire:click="gotoPage({{ $cell }})" @if ($cell === $pgCurrent) aria-current="page" @endif
                                    @class(['pager-link', 'bg-primary font-medium text-white' => $cell === $pgCurrent, 'text-text-muted hover:bg-surface-hover' => $cell !== $pgCurrent])>
                                    {{ $num($cell) }}
                                </button>
                            @endif
                        @endforeach
                        <button type="button" @if ($pgNext) wire:click="{{ $pgNext }}" @endif @disabled($pgNext === null)
                            aria-label="{{ __('admin.pagination.next') }}"
                            @class(['pager-link', 'text-text-muted hover:bg-surface-hover' => $pgNext !== null, 'cursor-not-allowed text-icon-ghost' => $pgNext === null])>
                            <x-bs-admin::icon name="chevron-right" class="size-4" />
                        </button>
                    </nav>
                </div>
            </div>
        </div>
    </div>
</div>
