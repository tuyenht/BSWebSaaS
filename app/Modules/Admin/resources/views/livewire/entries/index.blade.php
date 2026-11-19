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
        'pending' => 'ri-time',
        'archived' => 'ri-archive',
        'trash' => 'ri-delete-bin',
        default => 'ri-file-list-3',
    };

    // Status groups, as in the design: "Chờ duyệt" only once something waits; Thùng rác only for who may empty it.
    // Each count is drawn in its status's own ink, as the pills on the rows.
    $groupKeys = ['all', 'published', 'draft', 'scheduled', 'pending', 'archived'];
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
    // "Cột" in the design's order: Đường dẫn · Mô tả · Bản dịch · Chuyên mục (Mẫu trang) · Bước duyệt (only
    // while the section runs an approval workflow) · Lượt xem · Người tạo.
    $colOptions = ['path' => __('entries.col_path'), 'desc' => __('entries.col_desc')];
    if ($multilingual) {
        $colOptions['langs'] = __('entries.col_translations');
    }
    $colOptions[$isPost ? 'cat' : 'tpl'] = $isPost ? __('entries.col_category') : __('entries.filter_template');
    if ($isPost && $governed) {
        $colOptions['step'] = __('entries.col_step');
    }
    $colOptions['views'] = __('entries.col_views');
    $colOptions['creator'] = __('entries.col_creator');
    $cardOptions = [];
    foreach ([$isPost ? 'cat' : 'tpl', 'path', 'langs'] as $k) {
        if (isset($colOptions[$k])) {
            $cardOptions[$k] = $colOptions[$k];
        }
    }
    // «Nút trên dòng»: offered while some row of this view could show Nhân bản or Thùng rác beside «Sửa»
    $xLabels = [];
    if ($tab !== 'trash') {
        foreach (['duplicate' => 'entries.action_duplicate', 'delete' => 'entries.row_trash'] as $can => $key) {
            if (collect($rowCan)->contains(fn (array $c): bool => $c[$can] && $c['out'] === null)) {
                $xLabels[] = __('entries.quoted', ['text' => __($key)]);
            }
        }
    }

    // The Filter sheet (and from 900 one button per section). Status groups lead it below 900, where their row hides.
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
    if (EntriesIndex::creatorShown($facets['creator']) || $creatorF !== []) {
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
    // The design's states when no row shows: a search or a filter that matches nothing says so with «Bỏ lọc» (a
    // status group alone is not a filter: its empty line says the group has nothing); a section with nothing in
    // it yet, not even in the Trash, shows one card in place of the list, with the add button for who may add.
    $narrowing = $filterCount > 0 || trim($search) !== '';
    $createOk = $canCreate && ! $addLock;
    $nothingYet = ! $narrowing && $pageUlids === [] && ($counts['all'] ?? 0) === 0 && ($counts['trash'] ?? 0) === 0;
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
<div class="entries-index flex flex-col gap-3"
    @if ($canDelete) x-on:entries-ask-trash.window="$bsConfirm({ icon: 'trash', title: $event.detail.title, text: $event.detail.text, confirm: $event.detail.ok, cancel: $event.detail.cancel }).then(ok => ok && $wire.trash($event.detail.ulid, true))" @endif>

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

    {{-- A section with nothing in it yet (not even in the Trash): one card in place of the list, as in the design —
         the section's glyph, «Chưa có bài nào», a line, and the add button for whoever may add here. --}}
    @if ($nothingYet)
        <div class="min-w-0 rounded-md border border-border bg-surface shadow-card" data-guide="list-empty">
            <x-bs-admin::list.state :icon="\App\Modules\Admin\Navigation\AdminNav::sectionIcon($sectionModel)" kind="empty" :title="__('entries.state_empty_title_'.$type)">
                {{ __($createOk ? 'entries.state_empty_create_'.$type : 'entries.state_empty_view_'.$type) }}
                @if ($createOk)
                    <x-slot:action>
                        <x-bs-admin::button color="primary" :href="route('admin.entries.create', $sectionKey ? ['section' => $sectionKey] : [])">
                            <x-bs-admin::icon name="ri-add" class="size-4" /> {{ __('entries.add_'.$type) }}
                        </x-bs-admin::button>
                    </x-slot:action>
                @endif
            </x-bs-admin::list.state>
        </div>
    @else
    {{-- The list card: groups (from 900) · search + tools · the active-filter line · the rows · the pager. Below 600
         the card frame moves onto the rows, so the tools stand on the page and the first record comes up sooner. --}}
    <div class="min-w-0 min-[600px]:rounded-md min-[600px]:border min-[600px]:border-border min-[600px]:bg-surface min-[600px]:pb-4 min-[600px]:shadow-card">
        <x-bs-admin::group-row data-guide="tabs" :groups="$groups" :active="$tab" model="tab" :label="__('entries.groups_label', ['noun' => $noun])" />

        {{-- What shows on each record follows the list's own width: below 600 the member's card choice, 600–899
             every detail, from 900 their column choice (both kept in their own preferences). --}}
        <div class="@container/lt flex min-w-0 flex-col"
             x-data="{
                 mode: 'cols',
                 fold: '',
                 acts: '',
                 xNoFit: false,
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
                     this.fitActs(list, main, boost);
                 },
                 {{-- «Hiện thêm nút khi đủ chỗ» (table only, never in the Trash): Nhân bản, then Thùng rác, join «Sửa» on the rows while the name column keeps 560px (+12 per pixel of text boost; 16 less for one already shown, so it does not flicker at the edge). Rows without the button keep its room, so the buttons stay in line. The ⋮ leaves out what its row shows. --}}
                 fitActs(list, main, boost) {
                     const prev = this.acts.split(' '), ae = document.activeElement;
                     let acts = [];
                     list.setAttribute('data-acts', '');
                     if (this.mode === 'cols' && this.$wire.tab !== 'trash') {
                         {{-- the room the name column needs before a button joins it (design board 05): Xem 340, Nhân bản and Thùng rác 560; with «Hiện thêm nút» off only Xem is tried --}}
                         const need = { xv: 340, xd: 560, xt: 560 };
                         for (const t of this.$wire.xbtn ? ['xv', 'xd', 'xt'] : ['xv']) {
                             if (! list.querySelector('.list-x[data-x=' + t + ']:not(.list-xe)')) { continue; }
                             const next = [...acts, t];
                             list.setAttribute('data-acts', next.join(' '));
                             let w = 0;
                             list.querySelectorAll('.list-x[data-x=' + t + ']:not(.list-xe)').forEach((b) => { w = Math.max(w, Math.ceil((b.firstElementChild ?? b).getBoundingClientRect().width)); });
                             list.style.setProperty('--list-b-' + t, w + 'px');
                             if (main.getBoundingClientRect().width >= need[t] + 12 * boost - (prev.includes(t) ? 16 : 0)) { acts = next; } else { break; }
                         }
                         this.xNoFit = this.$wire.xbtn && acts.filter((t) => t !== 'xv').length === 0;
                     } else {
                         {{-- the Trash and the narrow layouts keep Xem on the row --}}
                         acts = ['xv'];
                         this.xNoFit = false;
                     }
                     this.acts = acts.join(' ');
                     list.setAttribute('data-acts', this.acts);
                     if (ae?.closest?.('.list-x') && ! ae.getClientRects().length) { const row = ae.closest('.list-acts'), more = row?.querySelector('.list-more button'); (more && more.getClientRects().length ? more : row?.querySelector('[data-guide=row-edit]'))?.focus(); }
                 },
                 show(k) { return this.mode === 'line' || (this.mode === 'cols' ? this.$wire.cols : this.$wire.card).includes(k); },
                 get vPath() { return this.show('path'); },
                 get vLangs() { return this.show('langs'); },
                 get vTpl() { return this.show('tpl'); },
                 get vCat() { return this.show('cat'); },
                 {{-- as in the design, Mô tả, Bước duyệt and Lượt xem follow the member's Cột choice on every width --}}
                 get vDesc() { return this.$wire.cols.includes('desc'); },
                 get vStep() { return this.$wire.cols.includes('step'); },
                 get vViews() { return this.$wire.cols.includes('views'); },
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
                    <x-bs-admin::filter-panel data-guide="filters" class="ms-auto flex-nowrap min-[900px]:min-w-0 min-[900px]:shrink min-[900px]:flex-wrap @min-[56.25rem]/lt:ms-0 @min-[56.25rem]/lt:min-w-0 @min-[56.25rem]/lt:shrink @min-[56.25rem]/lt:flex-wrap" :sections="$sections"
                        :active="$filterCount" :active-narrow="$filterCountNarrow" :results="__('entries.show_n', ['n' => $fmt($matchCount), 'unit' => $noun])" clear="clearAllFilters" />
                    <x-bs-admin::sort-menu data-guide="sort" class="@min-[56.25rem]/lt:ms-auto @min-[56.25rem]/lt:in-data-[below=1]:ms-0" :options="$sortOptions" :active="$sortOn" :lit="! $sortIsDefault" set="setSort" />
                    <x-bs-admin::cols-menu data-guide="cols" class="hidden @min-[56.25rem]/lt:block" id="entries-cols" :options="$colOptions" :selected="$cols"
                        :locked="[__('entries.title_label'), __('entries.col_status'), __('admin.actions')]">
                        @if ($xLabels !== [])
                            <x-slot:extra>
                                <p class="m-0 px-2 pb-1 pt-1 text-caption font-semibold text-text-muted">{{ __('entries.xbtn_group') }}</p>
                                <div class="rounded px-2 py-2 hover:bg-surface-hover" data-guide="cols-xbtn">
                                    <label class="flex min-h-6 cursor-pointer items-start gap-2.5 text-body text-text any-pointer-coarse:min-h-8">
                                        <input type="checkbox" wire:model.live="xbtn" aria-describedby="entries-cols-xb-d" class="mt-1 size-4 shrink-0 accent-primary-text">
                                        <span class="min-w-0 flex-1">{{ __('entries.xbtn') }}</span>
                                    </label>
                                    <p id="entries-cols-xb-d" class="m-0 mt-0.5 pl-[1.625rem] text-caption text-text-muted"
                                        x-text="$wire.xbtn && xNoFit ? {{ \Illuminate\Support\Js::from(__('entries.xbtn_nofit')) }} : {{ \Illuminate\Support\Js::from(__('entries.xbtn_note', ['list' => implode(', ', $xLabels)])) }}">{{ __('entries.xbtn_note', ['list' => implode(', ', $xLabels)]) }}</p>
                                </div>
                            </x-slot:extra>
                        @endif
                    </x-bs-admin::cols-menu>
                </x-bs-admin::tool-row>
            </div>

            <x-bs-admin::filter-status :data-guide="$filtering ? 'filter-line' : 'filter-sort'" class="-mx-3 -mt-1 mb-2 min-[600px]:mx-1" :group="$groupLabel"
                :summary="$filtering ? __('entries.match_of', ['n' => $fmt($matchCount), 'm' => $fmt($counts[$tab] ?? $baseCount), 'unit' => $noun]) : null"
                :chips="$chips" :clear="$filtering ? 'clearAllFilters' : null" />

            {{-- The shared bulk bar (components/list/bulk): each button counts the ticked rows it would really act on,
                 and the line under it names what the first one leaves out and why. Below 900 it sticks to the bottom of
                 the screen, after the rows. A button with nothing to do says why instead of running; Xóa vĩnh viễn asks
                 first (it cannot be undone), Chuyển vào Thùng rác does not (its message has «Hoàn tác»). --}}
            @if (count($selected) > 0 && $bulk !== [])
                <x-bs-admin::list.bulk data-guide="bulk-bar" :label="__('entries.bulk_region')" :status="__('entries.bulk_selected', ['noun' => $noun, 'count' => $num(count($selected))])"
                    :clear-label="__('entries.bulk_deselect')" :skip="$bulkSkip">
                    @foreach ($bulk as $action)
                        @php
                            $click = match (true) {
                                $action['none'] !== null => '$bsToast('.Js::from(['text' => $action['none']]).')',
                                $action['confirm'] !== null => '$bsConfirm({ icon: '.Js::from($action['confirm']['icon']).', title: '.Js::from($action['confirm']['title']).', text: '.Js::from($action['confirm']['text']).', confirm: '.Js::from($action['confirm']['ok']).', cancel: '.Js::from($action['confirm']['cancel']).' }).then(ok => ok && $wire.'.$action['call'].'('.($action['arg'] !== null ? Js::from($action['arg']) : '').'))',
                                default => '$wire.'.$action['call'].'('.($action['arg'] !== null ? Js::from($action['arg']) : '').')',
                            };
                        @endphp
                        <x-bs-admin::button size="sm" variant="soft" :color="$action['color']" :title="$action['title']" data-bulk="{{ $action['key'] }}"
                            aria-disabled="{{ $action['ok'] > 0 ? 'false' : 'true' }}" class="aria-disabled:opacity-60" x-on:click="{{ $click }}">
                            <x-bs-admin::icon :name="$action['icon']" class="size-4" /> {{ $action['label'] }}
                        </x-bs-admin::button>
                    @endforeach
                </x-bs-admin::list.bulk>
            @endif


            @php
                $ariaSort = fn (string $key): string => $sortOn !== $key ? 'none' : ($sortWay === 'asc' ? 'ascending' : 'descending');
                $Js = \Illuminate\Support\Js::class;
            @endphp
            {{-- The shared list row: a card below 600, one record per line to 900, a table from 900 (by the list's own
                 width). Below 600 the rows carry the card frame; select-all lives in the tools row above. --}}
            @php
                // A page list shown flat (searched, filtered, a group, or another sort): the note says why the tree and
                // the drag are off, with «Về thứ tự trên website» while another sort is on (it clears the filters too)
                $orderOff = $sortOn !== 'order' || $sortWay !== 'asc';
                $flatNote = ! $isPost && ! $treeMode && $tab !== 'trash' && ($filtering || $orderOff);
            @endphp
            @if ($flatNote)
                <div class="list-note @max-[56.25rem]/lt:order-3" data-flat-note>
                    <x-bs-admin::icon name="ri-node-tree" class="list-note-i" />
                    <span class="list-note-t">{{ $filtering ? __('entries.flat_note_filter') : __('entries.flat_note_sort') }}</span>
                    @if ($orderOff)
                        <x-bs-admin::button size="sm" variant="outline" color="neutral" wire:click="backToSiteOrder">
                            <x-bs-admin::icon name="ri-node-tree" class="size-4" /> {{ __('entries.back_to_order') }}
                        </x-bs-admin::button>
                    @endif
                </div>
            @endif
            <div x-ref="rows" data-fold-wait class="min-w-0 @max-[56.25rem]/lt:order-3 max-[599.98px]:rounded-md max-[599.98px]:border max-[599.98px]:border-border max-[599.98px]:bg-surface max-[599.98px]:[&_.list-g]:border-t-0">
            <x-bs-admin::list :label="__('entries.list_label', ['label' => $sectionLabel])" :kind="$isPost ? 'posts' : null" :data-page-tree="$treeMode ? '1' : null"
                busy="tab,setTab,search,sortKey,sortDir,sortBy,setSort,resetSort,page,gotoPage,turnPage,perPage,setPerPage,cursor,catF,langF,creatorF,templateF,transF,when,from,to,clearWhen,toggleFilterValue,resetFilterGroup,clearAllFilters,$refresh"
                x-bind:data-hide="[mode === 'cols' && ! show('creator') ? 'au' : '', mode === 'cols' && ! show('cat') ? 'cat' : '', {{ $governed ? 'true' : 'false' }} && vStep ? '' : 'step'].join(' ').trim()" x-bind:data-fold="fold" x-bind:data-acts="acts">

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
                    @if ($isPost && $governed)
                        <x-bs-admin::list.cell head col="step">{{ __('entries.col_step') }}</x-bs-admin::list.cell>
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
                        // a trashed row says when it goes for good («Tự xóa vĩnh viễn sau N ngày») instead of its last edit
                        $purgeText = null;
                        if ($trashed && $entry->deleted_at !== null) {
                            $daysLeft = \App\Modules\Cms\Support\EntryTrash::daysLeft($entry->deleted_at, $nowAt);
                            $purgeText = $daysLeft > 0 ? __('entries.trash_days_left', ['n' => $num($daysLeft)]) : __('entries.trash_goes_today');
                            $whenText = null;
                        }
                        // visitors see only a published record, so only its views are named
                        $isLive = $status === 'published' && ! $trashed;
                        $slugLeaf = $t?->slug;
                        $slugPrefix = ($path !== null && $slugLeaf) ? \Illuminate\Support\Str::beforeLast($path, $slugLeaf) : '/';
                        $quickEdit = ($rowEditable[$entry->ulid] ?? false) && ! $trashed;
                        $may = $rowCan[$entry->ulid];
                        // outside the member's limits: «🔒 Xem» and the reason instead of «Sửa», its items locked
                        $out = $trashed ? null : $may['out'];
                        $outWhy = $out !== null ? (preg_replace('/[.。]$/u', '', $out) ?? $out) : null;
                        // «Xem trước» of a row visitors cannot see yet opens the admin's preview (for who may open it);
                        // a published one opens on the website
                        $viewHref = $isLive ? $path : $may['preview'];
                        // Thùng rác shown locked with its reason: the Home page (for who may remove the row otherwise), or a
                        // colleague's record for a member who may remove only their own
                        $trashLock = $trashed ? null : (($may['home'] && $may['delete']) ? __('entries.home_trash_why') : $may['trashWhy']);
                        // the page tree in website order: a page row carries its place (data-pk/pp/lv) for the drag; its grip
                        // for who may move it, greyed with the reason outside the member's limits (none on the Home page)
                        $inTree = ! $isPost && $treeMode && ! $trashed;
                        $grip = $inTree && ! $isHome && ($may['move'] || $out !== null)
                            ? ['title' => $may['move'] ? __('entries.grip_title') : $outWhy, 'off' => ! $may['move']] : null;
                    @endphp
                    {{-- data-rk: the row the focus comes back to after a pick from its ⋮ or its status (window.bsListFocus).
                         Quick title edit: it opens on the title the row shows now (data-t, kept current by each render);
                         Esc or an empty title puts that title back in the field. --}}
                    <x-bs-admin::list.row :selected="$isSelected" wire:key="entry-{{ $entry->ulid }}" data-rk="{{ $entry->ulid }}"
                        :data-pk="$inTree ? $entry->ulid : null" :data-pp="$inTree ? ($rowParent[$entry->ulid] ?? '') : null" :data-lv="$inTree ? $depth + 1 : null"
                        :data-home="$inTree && $isHome ? '1' : null" :data-name="$inTree ? $title : null"
                        x-data="{ editing: false, value: '', orig: '', cancel: false, slugEd: false }"
                        x-on:list-quick-edit="orig = value = $refs.ti?.dataset.t ?? ''; editing = true; cancel = false"
                        x-on:list-quick-slug="slugEd = true">
                        @if ($inTree && ($rowGuides[$entry->ulid] ?? []) !== [])
                            {{-- the lines joining a sub-page to its parent, the shared tree's rule (drawn from a list 768 wide) --}}
                            <span class="list-g-tree" aria-hidden="true">@foreach ($rowGuides[$entry->ulid] as $g)<span class="list-gl" data-d="{{ $g['d'] }}" data-k="{{ $g['k'] }}"></span>@endforeach</span>
                        @endif
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
                            <div @class(['list-ind' => $depth > 0, 'min-w-0 flex-1' => $isPost])@if ($depth > 0) style="--list-lv: {{ $depth }}"@endif>
                                {{-- «Thuộc: …»: a sub-page of the tree names its parent where the lines are not drawn (600–767; read
                                     aloud below 600); a page of a flat list names it for screen readers (its chain leads the name) --}}
                                @if ($inTree && $depth > 0 && isset($rowOf[$entry->ulid]))
                                    <span class="list-of" data-of="tree">{{ __('entries.meta_of', ['name' => $rowOf[$entry->ulid]]) }}</span>
                                @elseif (! $treeMode && isset($parentPaths[$entry->ulid]))
                                    <span class="list-of" data-of="flat">{{ __('entries.meta_of', ['name' => last($parentPaths[$entry->ulid])]) }}</span>
                                @endif
                                @php
                                    // a flat row leads with the pages above it, muted (no @if inside the slot: Livewire would mark it)
                                    $chain = $treeMode ? [] : ($parentPaths[$entry->ulid] ?? []);
                                    $nameHtml = $chain === [] ? e($title) : '<span><span class="list-anc">'.e(implode(' › ', $chain)).' › </span>'.e($title).'</span>';
                                @endphp
                                <div x-show="! editing">
                                    <x-bs-admin::list.name :data-guide="$quickEdit ? 'row-title-qe' : 'row-title'" :href="$quickEdit ? route('admin.entries.edit', ['ulid' => $entry->ulid]) : ($trashed ? null : $path)" :external="! $quickEdit"
                                        :quick-edit="$quickEdit ? __('entries.quick_edit_named', ['title' => $title]) : null" :grip="$grip">{!! $nameHtml !!}</x-bs-admin::list.name>
                                </div>
                                @if ($quickEdit)
                                    <input type="text" x-show="editing" x-cloak x-model="value" class="list-in" x-ref="ti" data-t="{{ $t?->title ?? '' }}"
                                        aria-label="{{ __('entries.quick_edit_named', ['title' => $title]) }}"
                                        x-init="$watch('editing', on => on && $nextTick(() => { $el.focus(); $el.select() }))"
                                        @keydown.enter.prevent="cancel = false; $el.blur()"
                                        @keydown.escape="cancel = true; value = orig; $el.blur()"
                                        @blur="if (! editing) { return } editing = false; if (! cancel) { const v = value; if (v.trim() === '') { value = orig } $wire.saveTitle(@js($entry->ulid), v) }">
                                @endif
                                @if (in_array('desc', $cols, true) && filled($t?->excerpt))
                                    <p class="list-desc" x-show="vDesc" data-guide="row-desc">{{ $t->excerpt }}</p>
                                @endif

                                <x-bs-admin::list.meta>
                                    <span class="list-m" x-show="vPath || slugEd">
                                        @if ($isHome)
                                            <span class="inline-flex items-center gap-1 font-mono text-caption" data-guide="row-path-home" title="{{ __('entries.home_locked') }}">{{ $path ?? '/' }} <x-bs-admin::icon name="ri-lock" class="size-3" /></span>
                                        @elseif ($quickEdit)
                                            {{-- «Sửa đường dẫn» in the ⋮ (while the path column is off) opens this same field (slugEd on the row) --}}
                                            <span wire:key="slug-{{ $entry->ulid }}-{{ $slugLeaf }}" x-data="{ editing: false, value: @js($slugLeaf ?? ''), orig: @js($slugLeaf ?? ''), cancel: false, open() { this.value = this.orig; this.editing = true; this.cancel = false; this.$nextTick(() => { this.$refs.slugInput.focus(); this.$refs.slugInput.select() }) } }"
                                                x-init="$watch('slugEd', (on) => on && open())">
                                                <button type="button" x-show="! editing" data-guide="row-path-edit"
                                                    @click="open()"
                                                    title="{{ $path }} · {{ __('entries.edit_hint') }}"
                                                    class="list-path font-mono text-caption text-text-muted [overflow-wrap:anywhere] transition-colors hover:bg-primary-soft hover:text-primary-text">{{ $path ?? '—' }}</button>
                                                <span x-show="editing" x-cloak class="inline-flex w-full items-center gap-0.5 font-mono text-caption">
                                                    <span class="whitespace-nowrap text-placeholder">{{ $slugPrefix }}</span>
                                                    <input type="text" x-ref="slugInput" x-model="value" aria-label="{{ __('entries.col_path') }}"
                                                        @keydown.enter.prevent="cancel = false; $el.blur()"
                                                        @keydown.escape="cancel = true; value = orig; $el.blur()"
                                                        @blur="editing = false; slugEd = false; if (! cancel) { $wire.saveSlug(@js($entry->ulid), value) }"
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
                                    @if (isset($rowStep[$entry->ulid]))
                                        <span class="list-m" data-for="step" x-show="vStep">{{ $rowStep[$entry->ulid]['text'] }}</span>
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
                                        <span class="list-m" data-for="views" data-guide="row-views" x-show="vViews">{{ __('entries.views_count', ['count' => \Illuminate\Support\Number::format($entry->view_count, locale: app()->getLocale())]) }}</span>
                                    @endif
                                    @if ($creator)
                                        <span class="list-m" data-for="au">{{ $creator }}</span>
                                    @endif
                                    @if ($purgeText)
                                        <span class="list-m" data-for="when" data-guide="row-purge">{{ $purgeText }}</span>
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
                        @if ($isPost && $governed)
                            <x-bs-admin::list.cell col="step">
                                @if (isset($rowStep[$entry->ulid]))
                                    <span class="list-ell">{{ $rowStep[$entry->ulid]['text'] }}</span>
                                    <span class="list-step-next">{{ $rowStep[$entry->ulid]['next'] }}</span>
                                @endif
                            </x-bs-admin::list.cell>
                        @endif

                        <x-bs-admin::list.cell col="st">
                            <div class="list-stw" data-guide="row-status">
                                @if ($governed && ! $trashed && array_key_exists($entry->ulid, $rowMoves))
                                    {{-- a section with an approval workflow: the engine's moves this member may make from the row's stage
                                         (a move that asks for a reason opens the reason dialog; the first approval asks first, and so does a move that takes a live post off the website) --}}
                                    @if ($rowMoves[$entry->ulid] !== [])
                                        <div x-data="{ open: false }" @click.outside="open = false" @keydown.escape="if (open) { open = false; $refs.pill.focus() }" class="relative inline-block">
                                            <x-bs-admin::status-pill button x-ref="pill" :status="$status" x-on:click="open = ! open" x-bind:aria-expanded="open.toString()" aria-haspopup="true" title="{{ __('entries.status_menu_label') }}">{{ __('entries.status_'.$status) }}<span class="sr-only"> · {{ __('entries.status_menu_label') }}</span></x-bs-admin::status-pill>
                                            <div x-show="open" x-cloak x-transition
                                                class="absolute left-0 z-30 mt-1 w-64 max-w-[calc(100vw-2rem)] rounded-lg border border-border bg-surface p-1 shadow-lg">
                                                @foreach ($rowMoves[$entry->ulid] as $move)
                                                    @php
                                                        $run = '$wire.applyTransition('.$Js::from($entry->ulid).', '.$Js::from($move['key']).')';
                                                        $moveCall = match (true) {
                                                            $move['note'] => '$dispatch(\'entries-wf-note\', '.$Js::from(['ulid' => $entry->ulid, 'key' => $move['key'], 'title' => __('entries.wf_note_title', ['label' => $move['label']])]).')',
                                                            $move['first'] => '$bsConfirm({ icon: \'question\', danger: false, title: '.$Js::from(__('entries.wf_ap_title', ['title' => $title])).', text: '.$Js::from(__('entries.wf_ap_text')).', confirm: '.$Js::from(__('entries.wf_ap_ok')).', cancel: '.$Js::from(__('entries.wf_ap_cancel')).' }).then(ok => ok && '.$run.')',
                                                            $move['off'] => '$bsConfirm({ icon: \'warning\', title: '.$Js::from(__('entries.wf_off_title', ['title' => $title])).', text: '.$Js::from(__('entries.wf_off_text')).', confirm: '.$Js::from(__('entries.wf_off_ok')).', cancel: '.$Js::from(__('entries.wf_off_cancel')).' }).then(ok => ok && '.$run.')',
                                                            default => $run,
                                                        };
                                                        $moveFlag = match (true) {
                                                            $move['first'] => ' data-first',
                                                            $move['off'] => ' data-off',
                                                            $move['note'] => ' data-note',
                                                            default => '',
                                                        };
                                                    @endphp
                                                    <button type="button" data-wf="{{ $move['key'] }}" data-row="{{ $entry->ulid }}"{!! $moveFlag !!} x-on:click="open = false; {{ $moveCall }}" class="list-mi rounded">
                                                        <x-bs-admin::icon :name="$move['icon']" class="size-4 shrink-0" /><span class="min-w-0 [overflow-wrap:anywhere]">{{ $move['label'] }}</span>
                                                    </button>
                                                @endforeach
                                            </div>
                                        </div>
                                    @else
                                        <x-bs-admin::status-pill :status="$status">{{ __('entries.status_'.$status) }}</x-bs-admin::status-pill>
                                    @endif
                                @elseif ($may['send'] && ! $trashed)
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
                                    {{-- the status menu as board 05 draws it: «Đổi trạng thái» over the statuses in one order, Đã đăng ·
                                         Hẹn giờ đăng · Bản nháp · Gỡ khỏi website (a live row only, what it does on a small second line),
                                         each with its colour dot; the row's own status is never listed. A scheduled row offers «Hủy hẹn giờ»
                                         in the place of «Hẹn giờ đăng». The live Home page stays published: a draft or «Gỡ khỏi website»
                                         would leave «/» answering 404 (TransitionEntryStatus refuses it too), so those two stay listed,
                                         greyed, with the reason; it is never scheduled (it would be off the website until its time), so
                                         «Hẹn giờ đăng» is not offered there --}}
                                    @php
                                        $homeLive = $isHome && $status === 'published';
                                        $stOpts = array_values(array_filter(['published', 'scheduled', 'draft', 'archived'], fn (string $opt): bool => match ($opt) {
                                            'scheduled' => $isScheduled || ! $homeLive,
                                            'archived' => $status === 'published',
                                            default => $opt !== $status,
                                        }));
                                    @endphp
                                    <div x-data="{ open: false, up: false, close(back) { this.open = false; if (back) { this.$refs.pill.focus() } } }" x-on:click.outside="open = false" x-on:keydown.escape="if (open) { $event.stopPropagation(); close(true) }" class="relative inline-block">
                                        <x-bs-admin::status-pill button x-ref="pill" :status="$status" aria-haspopup="menu" x-bind:aria-expanded="open.toString()" title="{{ __('entries.status_menu_label') }}"
                                            x-on:click="up = window.innerHeight - $el.getBoundingClientRect().bottom < 280; open = ! open; open && $nextTick(() => $focus.within($refs.menu).first())">{{ __('entries.status_'.$status) }}<span class="sr-only"> · {{ __('entries.status_menu_label') }}</span></x-bs-admin::status-pill>
                                        <x-bs-admin::list.menu class="list-stm absolute left-0 z-30 mt-1 w-64 max-w-[calc(100vw-2rem)] rounded-lg border border-border bg-surface p-1 shadow-lg" x-bind:class="up && 'bottom-full mb-1 mt-0'" :label="__('entries.status_menu_label')">
                                            {{-- the menu's own name says the same, so a screen reader hears it once --}}
                                            <div class="list-lmh" aria-hidden="true">{{ __('entries.status_menu_label') }}</div>
                                            @foreach ($stOpts as $opt)
                                                @if ($opt === 'scheduled' && $isScheduled)
                                                    {{-- «Hủy hẹn giờ», asked first: back to a draft, never published at that time --}}
                                                    <button type="button" role="menuitem" data-unsched x-on:click="$bsConfirm({ icon: 'warning', title: {{ $Js::from(__('entries.unsched_title')) }}, text: {{ $Js::from(__('entries.unsched_text', ['noun' => __('entries.noun_'.$entry->type), 'time' => $when?->format('H:i'), 'date' => $when?->format('d/m/Y'), 'step' => __('entries.status_draft')])) }}, confirm: {{ $Js::from(__('entries.unsched')) }}, cancel: {{ $Js::from(__('entries.unsched_keep')) }} }).then(ok => { window.bsListFocus?.expect({{ $Js::from($entry->ulid) }}, 'st', ! ok); ok && $wire.unschedule({{ $Js::from($entry->ulid) }}) })" class="list-mi rounded">
                                                        <x-bs-admin::status-pill dot status="scheduled" /><span class="min-w-0 [overflow-wrap:anywhere]">{{ __('entries.unsched') }}</span>
                                                    </button>
                                                @elseif ($opt === 'scheduled')
                                                    {{-- «Hẹn giờ đăng»: the dialog (one for the list) opens on tomorrow 08:00 of the website's clock --}}
                                                    <button type="button" role="menuitem" data-sched x-on:click="$dispatch('entries-sched', {{ $Js::from(['ulid' => $entry->ulid, 'for' => __('entries.sched_for', ['title' => $title]), 'hint' => __('entries.sched_hint', ['noun' => __('entries.noun_'.$entry->type)]), 'value' => $schedDefault]) }})" class="list-mi rounded">
                                                        <x-bs-admin::status-pill dot status="scheduled" /><span class="min-w-0 [overflow-wrap:anywhere]">{{ __('entries.sched_title') }}</span>
                                                    </button>
                                                @else
                                                    @php
                                                        $optLabel = $opt === 'archived' ? __('entries.status_menu_archived') : __('entries.status_'.$opt);
                                                        $optNote = $homeLive ? __('entries.home_trash_why') : ($opt === 'archived' ? __('entries.status_menu_archived_hint') : null);
                                                    @endphp
                                                    @if ($homeLive)
                                                        <button type="button" role="menuitem" aria-disabled="true" data-home-locked="{{ $opt }}" x-on:click="$bsToast({{ $lockToast(__('entries.home_trash_why')) }})" class="list-mi rounded">
                                                    @else
                                                        <button type="button" role="menuitem" @if ($opt === 'archived') data-archive @endif x-on:click="$wire.changeStatus(@js($entry->ulid), @js($opt))" class="list-mi rounded">
                                                    @endif
                                                        <x-bs-admin::status-pill dot :status="$opt" />
                                                        <span class="flex min-w-0 flex-col items-start gap-0.5 [overflow-wrap:anywhere]">
                                                            <span>{{ $optLabel }}</span>
                                                            @if ($optNote)<span class="text-caption text-text-muted">{{ $optNote }}</span>@endif
                                                        </span>
                                                    </button>
                                                @endif
                                            @endforeach
                                        </x-bs-admin::list.menu>
                                    </div>
                                @else
                                    <x-bs-admin::status-pill :status="$status">{{ __('entries.status_'.$status) }}</x-bs-admin::status-pill>
                                @endif
                            </div>
                            @if ($purgeText)
                                <div class="list-st-meta">
                                    <x-bs-admin::icon name="ri-delete-bin" class="size-3 shrink-0" />
                                    <span>{{ $purgeText }}</span>
                                </div>
                            @elseif ($out !== null)
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
                                {{-- no recorded creator: a plain «—», no initials disc (board 05) --}}
                                @if ($creator)
                                    <span class="list-avi" aria-hidden="true" style="background: {{ $c['bg'] }}; color: {{ $c['fg'] }};">{{ $initials }}</span>
                                @endif
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
                                    @if ($viewHref)
                                        <x-bs-admin::button size="sm" variant="soft" color="secondary" :href="$viewHref" :navigate="false" target="_blank" rel="noopener" data-guide="row-view"
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
                                        @if ($may['child'] !== null || $may['childWhy'] !== null)
                                            <x-bs-admin::list.menu-item icon="ri-add" data-child :why="$may['childWhy'] ?? $outWhy" x-on:click="$bsToast({{ $lockToast($may['childWhy'] ?? $out) }})">{{ __('entries.row_add_child') }}</x-bs-admin::list.menu-item>
                                        @endif
                                        @if ($canCreate)
                                            <x-bs-admin::list.menu-item icon="ri-file-copy" :why="$outWhy" x-on:click="$bsToast({{ $lockToast($out) }})">{{ __('entries.action_duplicate') }}</x-bs-admin::list.menu-item>
                                        @endif
                                        @if (! $isPost && ! $isHome)
                                            <x-bs-admin::list.menu-item icon="ri-arrow-up" :why="$outWhy" x-on:click="$bsToast({{ $lockToast($out) }})">{{ __('entries.row_move_up') }}</x-bs-admin::list.menu-item>
                                            <x-bs-admin::list.menu-item icon="ri-arrow-down" :why="$outWhy" x-on:click="$bsToast({{ $lockToast($out) }})">{{ __('entries.row_move_down') }}</x-bs-admin::list.menu-item>
                                            <x-bs-admin::list.menu-item icon="ri-drag-move-2" :why="$outWhy" x-on:click="$bsToast({{ $lockToast($out) }})">{{ __('entries.row_move_to') }}</x-bs-admin::list.menu-item>
                                        @endif
                                        @if ($may['deleteRole'])
                                            <x-bs-admin::list.menu-sep />
                                            <x-bs-admin::list.menu-item icon="ri-delete-bin" danger :why="$outWhy" x-on:click="$bsToast({{ $lockToast($out) }})">{{ __('entries.action_trash') }}</x-bs-admin::list.menu-item>
                                        @endif
                                    </x-slot:menu>
                                @else
                                    @if ($viewHref)
                                        {{-- both open in a new tab and say so: a published record on the website, any other as a preview. The button sits in its own span (list-x) so the table can hide it when the name column needs the room; the ⋮ then offers «Xem» --}}
                                        <span class="list-x" data-x="xv">
                                            <x-bs-admin::button size="sm" variant="soft" color="secondary" :href="$viewHref" :navigate="false" target="_blank" rel="noopener"
                                                :title="$isLive ? __('entries.opens_new_tab') : __('entries.action_preview')" data-guide="row-view" :aria-label="__('entries.view_named', ['title' => $title]).' ('.__('entries.opens_new_tab').')'">
                                                <x-bs-admin::icon :name="$isLive ? 'ri-external-link' : 'ri-eye'" class="size-4" /> {{ __('admin.view') }}
                                            </x-bs-admin::button>
                                        </span>
                                    @else
                                        <span class="list-x list-xe" data-x="xv" aria-hidden="true"></span>
                                    @endif
                                    {{-- «Sửa» only on a row this member may edit: the editor would refuse the others --}}
                                    @if ($quickEdit)
                                        <x-bs-admin::button size="sm" variant="soft" color="primary" data-guide="row-edit" :href="route('admin.entries.edit', ['ulid' => $entry->ulid])"
                                            :aria-label="__('entries.edit_named', ['title' => $title])">
                                            <x-bs-admin::icon name="ri-pencil" class="size-4" /> {{ __('entries.edit_action') }}
                                        </x-bs-admin::button>
                                    @endif
                                    {{-- «Hiện thêm nút khi đủ chỗ»: drawn only while the member has it on; the page script shows the ones that fit (data-acts) --}}
                                    @if ($xbtn)
                                        @if ($may['duplicate'])
                                            <span class="list-x" data-x="xd">
                                                <x-bs-admin::button size="sm" variant="soft" color="secondary" wire:click="duplicate('{{ $entry->ulid }}')"
                                                    :aria-label="__('entries.row_btn_named', ['action' => __('entries.action_duplicate'), 'title' => $title])">
                                                    <x-bs-admin::icon name="ri-file-copy" class="size-4" /> {{ __('entries.action_duplicate') }}
                                                </x-bs-admin::button>
                                            </span>
                                        @else
                                            <span class="list-x list-xe" data-x="xd" aria-hidden="true"></span>
                                        @endif
                                        @if ($trashLock !== null)
                                            {{-- locked (the Home page; a colleague's record for a member who may remove only their own): the reason in a bubble on tap, hover or focus, taking no room --}}
                                            <span class="list-x" data-x="xt">
                                                <x-bs-admin::list.lock-tip :why="$trashLock">
                                                    <x-bs-admin::button size="sm" variant="outline" color="danger" aria-disabled="true" data-locked class="aria-disabled:cursor-not-allowed aria-disabled:opacity-60"
                                                        :aria-label="__('entries.row_btn_named', ['action' => __('entries.action_trash'), 'title' => $title]).' — '.$trashLock">
                                                        <x-bs-admin::icon name="ri-delete-bin" class="size-4" /> {{ __('entries.row_trash') }}
                                                    </x-bs-admin::button>
                                                </x-bs-admin::list.lock-tip>
                                            </span>
                                        @elseif ($may['delete'])
                                            <span class="list-x" data-x="xt">
                                                <x-bs-admin::button size="sm" variant="outline" color="danger" data-trash wire:click="trash('{{ $entry->ulid }}')"
                                                    :aria-label="__('entries.row_btn_named', ['action' => __('entries.action_trash'), 'title' => $title])">
                                                    <x-bs-admin::icon name="ri-delete-bin" class="size-4" /> {{ __('entries.row_trash') }}
                                                </x-bs-admin::button>
                                            </span>
                                        @else
                                            <span class="list-x list-xe" data-x="xt" aria-hidden="true"></span>
                                        @endif
                                    @endif
                                    {{-- «Xem trước» (not published) stays first in ⋮ even while the row shows «Xem» (no data-xm); «Xem trên website» leaves it then --}}
                                    {{-- as the design's ⋮: Xem trên website (published) or Xem trước · Sao chép đường dẫn | Sửa nhanh tiêu đề ·
                                         Sửa đường dẫn (only while the path column is off) · Nhân bản | Chuyển vào Thùng rác (locked on the Home page) --}}
                                    <x-slot:menu>
                                        @if ($viewHref)
                                            <x-bs-admin::list.menu-item :icon="$isLive ? 'ri-external-link' : 'ri-eye'" :data-xm="$isLive ? 'xv' : null" :href="$viewHref" target="_blank" rel="noopener" :aria-label="($isLive ? __('entries.view_on_site') : __('entries.action_preview')).': '.$title.' ('.__('entries.opens_new_tab').')'">{{ $isLive ? __('entries.view_on_site') : __('entries.action_preview') }}</x-bs-admin::list.menu-item>
                                        @endif
                                        @if ($path)
                                            <x-bs-admin::list.menu-item icon="ri-link" data-copy x-on:click="(navigator.clipboard ? navigator.clipboard.writeText({{ $Js::from($publicOrigin.$path) }}) : Promise.reject()).then(() => $bsToast({ text: {{ $Js::from(__('entries.link_copied', ['url' => $publicOrigin.$path])) }} }), () => $bsToast({ text: {{ $Js::from(__('admin.domains_copy_failed', ['text' => $publicOrigin.$path])) }}, stay: true }))">{{ __('entries.copy_link') }}</x-bs-admin::list.menu-item>
                                        @endif
                                        @if (($viewHref || $path) && ($quickEdit || $may['duplicate'] || $may['child'] !== null || $may['childWhy'] !== null))
                                            <x-bs-admin::list.menu-sep />
                                        @endif
                                        @if ($quickEdit)
                                            <x-bs-admin::list.menu-item icon="ri-pencil" x-on:click="$dispatch('list-quick-edit')">{{ __('entries.quick_edit_title') }}</x-bs-admin::list.menu-item>
                                            @if (! $isHome && $path)
                                                <x-bs-admin::list.menu-item icon="ri-link" data-path-edit x-show="! vPath" x-on:click="$dispatch('list-quick-slug')">{{ __('entries.edit_path') }}</x-bs-admin::list.menu-item>
                                            @endif
                                        @endif
                                        {{-- «Thêm trang con»: the editor with this page as the parent (never on the Home page or a page on the last level) --}}
                                        @if ($may['child'] !== null)
                                            <x-bs-admin::list.menu-item icon="ri-add" data-child :href="$may['child']">{{ __('entries.row_add_child') }}</x-bs-admin::list.menu-item>
                                        @elseif ($may['childWhy'] !== null)
                                            <x-bs-admin::list.menu-item icon="ri-add" data-child :why="$may['childWhy']" x-on:click="$bsToast({{ $lockToast($may['childWhy']) }})">{{ __('entries.row_add_child') }}</x-bs-admin::list.menu-item>
                                        @endif
                                        @if ($may['duplicate'])
                                            <x-bs-admin::list.menu-item icon="ri-file-copy" data-xm="xd" wire:click="duplicate('{{ $entry->ulid }}')">{{ __('entries.action_duplicate') }}</x-bs-admin::list.menu-item>
                                        @endif
                                        {{-- moves in the page tree: one place up or down only while the list shows the tree in website order
                                             (the reason under the first of the two otherwise); «Chuyển đến…» in every view, never on the Home page --}}
                                        @if ($may['move'])
                                            @if ($treeMode)
                                                <x-bs-admin::list.menu-item icon="ri-arrow-up" data-move="up" wire:click="movePageStep('{{ $entry->ulid }}', 'up')">{{ __('entries.row_move_up') }}</x-bs-admin::list.menu-item>
                                                <x-bs-admin::list.menu-item icon="ri-arrow-down" data-move="down" wire:click="movePageStep('{{ $entry->ulid }}', 'down')">{{ __('entries.row_move_down') }}</x-bs-admin::list.menu-item>
                                            @else
                                                <x-bs-admin::list.menu-item icon="ri-arrow-up" data-move="up" :why="__('entries.move_off_why')" x-on:click="$bsToast({{ $lockToast(__('entries.move_off_why')) }})">{{ __('entries.row_move_up') }}</x-bs-admin::list.menu-item>
                                                <x-bs-admin::list.menu-item icon="ri-arrow-down" data-move="down" off x-on:click="$bsToast({{ $lockToast(__('entries.move_off_why')) }})">{{ __('entries.row_move_down') }}</x-bs-admin::list.menu-item>
                                            @endif
                                            @if (! $isHome)
                                                <x-bs-admin::list.menu-item icon="ri-drag-move-2" data-move="to" x-on:click="$dispatch('entries-mv', { ulid: {{ $Js::from($entry->ulid) }}, name: {{ $Js::from($title) }} })">{{ __('entries.row_move_to') }}</x-bs-admin::list.menu-item>
                                            @endif
                                        @endif
                                        @if ($trashLock !== null)
                                            <x-bs-admin::list.menu-sep data-xm="xt" />
                                            {{-- locked, with the reason under it (the server refuses it too) --}}
                                            <x-bs-admin::list.menu-item icon="ri-delete-bin" danger data-xm="xt" :why="$trashLock" x-on:click="$bsToast({{ $lockToast($trashLock) }})">{{ __('entries.action_trash') }}</x-bs-admin::list.menu-item>
                                        @elseif ($may['delete'])
                                            <x-bs-admin::list.menu-sep data-xm="xt" />
                                            {{-- at once, with «Hoàn tác» on the message; the server asks first for a page with sub-pages --}}
                                            <x-bs-admin::list.menu-item icon="ri-delete-bin" danger data-xm="xt" data-trash wire:click="trash('{{ $entry->ulid }}')">{{ __('entries.action_trash') }}</x-bs-admin::list.menu-item>
                                        @endif
                                    </x-slot:menu>
                                @endif
                            </x-bs-admin::list.actions>
                        </x-bs-admin::list.cell>
                    </x-bs-admin::list.row>
                @empty
                    {{-- No row on this view: the Trash is empty, nothing matches the search or filters («Bỏ lọc», which
                         also goes back to «Tất cả»), or the status group has nothing --}}
                    <x-bs-admin::list.row>
                        <x-bs-admin::list.cell col="full" aria-colspan="{{ ($isPost ? 6 : 5) + ($isPost && $governed ? 1 : 0) }}">
                            <x-bs-admin::list.state icon="ri-search-eye" :kind="$narrowing ? 'nomatch' : 'none'" data-guide="list-none">
                                {{ $tab === 'trash' ? __('entries.state_trash_empty') : __($narrowing ? 'entries.state_no_match_'.$type : 'entries.state_group_empty_'.$type) }}
                                @if ($narrowing)
                                    <x-slot:action>
                                        <x-bs-admin::button variant="outline" color="neutral" wire:click="clearAllFilters">
                                            <x-bs-admin::icon name="ri-filter-off" class="size-4" /> {{ __('admin.list.clear_filters') }}
                                        </x-bs-admin::button>
                                    </x-slot:action>
                                @endif
                            </x-bs-admin::list.state>
                        </x-bs-admin::list.cell>
                    </x-bs-admin::list.row>
                @endforelse
            </x-bs-admin::list>
            </div>

            <div class="mt-4 flex flex-wrap items-center justify-between gap-3 @max-[56.25rem]/lt:order-5 min-[600px]:px-4">
                <span class="text-caption text-text-muted" x-data="{ failed: false }" x-show="! failed" x-on:bs-list-failed.window="failed = $event.detail">
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
                    <nav aria-label="{{ __('admin.pagination.nav') }}" data-guide="pager" class="flex flex-wrap items-center gap-2">
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
    @endif

    @if ($governed)
        {{-- A workflow move that asks for a reason («Trả lại», «Gỡ bài» …): the shared form dialog. The reason is
             required here and again by the server and the engine; the author is told it word for word. --}}
        <div x-data="{ wfNote: null, note: '', bad: false,
                ask(d) { this.wfNote = d; this.note = ''; this.bad = false; $nextTick(() => document.getElementById('entries-wf-note')?.focus()); },
                send() { if (! this.note.trim()) { this.bad = true; return; } const d = this.wfNote; this.wfNote = null; $wire.applyTransition(d.ulid, d.key, this.note); } }"
            x-on:entries-wf-note.window="ask($event.detail)">
            <x-bs-admin::modal state="wfNote" close="wfNote = null" :close-label="__('admin.close')">
                <x-slot:heading><span x-text="wfNote ? wfNote.title : ''"></span></x-slot:heading>
                <p class="m-0 text-small text-text-muted">{{ __('entries.wf_note_hint') }}</p>
                <div class="flex flex-col gap-1.5">
                    <label for="entries-wf-note" class="text-small font-semibold text-text">{{ __('admin.wfb.note_label') }} <span class="text-danger-text" aria-hidden="true">*</span></label>
                    <textarea id="entries-wf-note" x-model="note" x-on:input="bad = false" rows="4" maxlength="2000" required
                        aria-describedby="entries-wf-note-err" x-bind:aria-invalid="bad.toString()"
                        class="field w-full rounded border bg-surface px-3 py-2 text-text"
                        x-bind:class="bad ? 'border-danger-text' : 'border-border-input'"
                        placeholder="{{ __('entries.wf_note_placeholder') }}"></textarea>
                    <x-bs-admin::field-error id="entries-wf-note-err" x-cloak x-show="bad">{{ __('admin.wfb.note_missing') }}</x-bs-admin::field-error>
                </div>
                <x-slot:footer>
                    <x-bs-admin::button color="light" x-on:click="wfNote = null">{{ __('admin.cancel') }}</x-bs-admin::button>
                    <x-bs-admin::button color="primary" x-on:click="send()"><x-bs-admin::icon name="ri-send-plane" class="size-4" /> {{ __('entries.wf_note_ok') }}</x-bs-admin::button>
                </x-slot:footer>
            </x-bs-admin::modal>
        </div>
    @endif

    {{-- «Hẹn giờ đăng» of one row (from its status menu): the website's own clock. A missing or past time is the
         field's error, kept under the field until it is put right (checked when the field is left and on save,
         Enter saves); the server judges the time again and answers with the same error. --}}
    @if ($canPublish)
        <div x-data="{ sched: null, value: '', err: '', busy: false,
                open(d) { this.sched = d; this.value = d.value; this.err = ''; $nextTick(() => document.getElementById('entries-sched-at')?.focus()); },
                check() { if (! this.value.trim()) { return {{ Js::from(__('entries.sched_err_empty')) }} } return this.value <= {{ Js::from($siteNow) }}.slice(0, 16) ? {{ Js::from(__('entries.sched_err_past')) }} : '' },
                save() { if (this.busy) { return } this.err = this.check(); if (this.err) { document.getElementById('entries-sched-at')?.focus(); return } const d = this.sched; this.busy = true;
                    $wire.scheduleEntry(d.ulid, this.value).then((e) => { this.busy = false; if (e) { this.err = e; document.getElementById('entries-sched-at')?.focus(); return } this.sched = null; window.bsListFocus?.expect(d.ulid, 'st', true) }, () => { this.busy = false }) } }"
            x-on:entries-sched.window="open($event.detail)">
            <x-bs-admin::modal state="sched" close="sched = null" :close-label="__('admin.close')" :title="__('entries.sched_title')">
                <p class="m-0 text-small text-text-muted [overflow-wrap:anywhere]" x-text="sched ? sched.for : ''"></p>
                <div class="flex flex-col gap-1.5">
                    <label for="entries-sched-at" class="text-small font-semibold text-text">{{ __('entries.sched_label') }}</label>
                    <input id="entries-sched-at" type="datetime-local" x-model="value" x-on:blur="err = check()" x-on:input="err && (err = check())" x-on:keydown.enter.prevent="save()"
                        aria-describedby="entries-sched-err entries-sched-hint" x-bind:aria-invalid="(err !== '').toString()"
                        class="field w-full rounded border bg-surface px-3 text-text" x-bind:class="err ? 'border-danger-text' : 'border-border-input'">
                    <x-bs-admin::field-error id="entries-sched-err" x-cloak x-show="err" role="alert"><span x-text="err"></span></x-bs-admin::field-error>
                    <p id="entries-sched-hint" class="m-0 text-caption text-text-muted" x-text="sched ? sched.hint : ''"></p>
                </div>
                <x-slot:footer>
                    <x-bs-admin::button color="light" x-on:click="sched = null">{{ __('admin.cancel') }}</x-bs-admin::button>
                    <x-bs-admin::button color="primary" data-sched-save x-on:click="save()"><x-bs-admin::icon name="ri-calendar-schedule" class="size-4" /> {{ __('entries.sched_title') }}</x-bs-admin::button>
                </x-slot:footer>
            </x-bs-admin::modal>
        </div>
    @endif

    {{-- The page tree (pages only, outside the Trash): the drag by a row's grip and «Chuyển đến…» (Alpine.data 'bsPageMove',
         resources/js/admin-page-tree.js). The places come from the server (moveTargets), each saying what the move makes of it
         or why it is locked; the server judges the move again. --}}
    @if (! $isPost && $tab !== 'trash')
        @php
            $treeTexts = [
                'dnd' => [
                    'start' => __('admin.tree.drag_start', ['noun' => __('entries.tree_noun')]),
                    'child' => __('admin.tree.will_child', ['noun' => __('entries.tree_noun')]),
                    'before' => __('admin.tree.will_before'),
                    'after' => __('admin.tree.will_after'),
                    'self' => __('admin.tree.no_self', ['noun' => __('entries.tree_noun')]),
                ],
                'gone' => __('entries.move_gone'),
                'home' => __('entries.move_refused_parent_home'),
                'depthPage' => __('entries.move_depth_page'),
                'depthL1' => __('entries.move_depth_branch_l1'),
                'depthTop' => __('entries.move_depth_branch_top'),
                'kept' => __('admin.tree.kept'),
                'pick' => __('entries.mv_pick'),
                'goTop' => __('entries.mv_go_top'),
                'goIn' => __('entries.mv_go_in'),
                'pickFirst' => __('entries.mv_pick_first'),
                'hint' => __('entries.mv_hint'),
                'hintFlat' => __('entries.mv_hint_flat'),
                'lock' => $lockMark,
                'info' => \Illuminate\Support\Facades\Blade::render('<x-bs-admin::icon name="ri-information" class="size-4" />'),
                'close' => __('admin.close'),
            ];
        @endphp
        <div x-data="bsPageMove({{ Js::from($treeTexts) }})" x-on:entries-mv.window="open($event.detail)">
            <x-bs-admin::sr-live x-ref="live" />
            <x-bs-admin::modal state="mv" close="close()" :close-label="__('admin.close')">
                <x-slot:heading><span class="[overflow-wrap:anywhere]" x-text="mv ? {{ Js::from(__('entries.mv_title')) }}.replace(':name', () => mv.name) : ''"></span></x-slot:heading>
                <div class="flex flex-col gap-3">
                    <x-bs-admin::search :label="__('entries.mv_search')" :placeholder="__('entries.mv_search_ph')" id="entries-mv-q" x-bind:value="mv ? mv.q : ''" x-on:input="if (mv) { mv.q = $event.target.value }" />
                    <p x-show="mv && mv.opts" class="m-0 text-small text-text-muted" x-text="hint"></p>
                    <div role="radiogroup" aria-label="{{ __('entries.mv_group') }}" class="flex flex-col gap-1.5">
                        <p x-show="mv && ! mv.opts" class="m-0 text-small text-text-muted">{{ __('entries.mv_loading') }}</p>
                        <template x-for="o in shown" :key="o.k || 'top'">
                            <label class="flex min-h-hit cursor-pointer items-start gap-3 rounded border border-border px-3 py-2 has-[:checked]:border-primary-text has-[:disabled]:cursor-not-allowed has-[:disabled]:opacity-75"
                                x-bind:style="'margin-left:' + (Math.max(0, o.lv - 1) * 20) + 'px'">
                                <input type="radio" name="entries-mv" class="mt-1" x-bind:disabled="o.here || !! o.why" x-bind:checked="mv && mv.to === o.k" x-on:change="pick(o)" />
                                <span class="flex min-w-0 flex-col"><span class="font-semibold text-text-strong [overflow-wrap:anywhere]" x-text="o.name"></span><span class="text-caption text-text-muted [overflow-wrap:anywhere]" x-text="o.label"></span></span>
                            </label>
                        </template>
                        <p x-show="mv && mv.opts && shown.length === 0" class="m-0 text-small text-text-muted" x-text="mv ? {{ Js::from(__('entries.mv_none')) }}.replace(':q', () => mv.q) : ''"></p>
                        <p x-show="mv && mv.opts && mv.more" x-cloak class="m-0 text-small text-text-muted" data-mv-more>… {{ __('entries.mv_more', ['n' => $num(\App\Modules\Cms\Support\PageRules::WALK_SIZE)]) }}</p>
                    </div>
                </div>
                <x-slot:footer>
                    <x-bs-admin::button color="light" x-on:click="close()">{{ __('admin.cancel') }}</x-bs-admin::button>
                    <x-bs-admin::button color="primary" x-on:click="go()" x-bind:aria-disabled="chosen ? null : 'true'"><x-bs-admin::icon name="ri-drag-move-2" class="size-4" /> <span x-text="btn">{{ __('entries.mv_pick') }}</span></x-bs-admin::button>
                </x-slot:footer>
            </x-bs-admin::modal>
        </div>
    @endif

    {{-- A move to the Trash or in the page tree, and its «Hoàn tác» (10 seconds); it rises above the bulk bar while that bar sticks to the bottom. --}}
    <x-bs-admin::toast :message="$toastMsg" :tone="$toastTone" :seq="$toastSeq" dismiss="closeToast" :undo="$trashUndo !== null ? 'undoTrash' : ($moveUndo !== null ? 'undoMovePage' : null)" :raised="count($selected) > 0 && $bulk !== []" />
</div>
