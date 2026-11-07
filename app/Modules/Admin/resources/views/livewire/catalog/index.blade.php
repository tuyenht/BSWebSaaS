@php
    use Illuminate\Support\Js;
    use Illuminate\Support\Number;

    // a list stops counting past the cap: such a count reads "hơn 10.000"
    $over = __('catalog.count_over');
    $fmt = fn (int|string $n): string => is_string($n) ? $n : ($n > $cap ? $over : Number::format($n, locale: app()->getLocale()));
    $num = fn (int $n): string => Number::format($n, locale: app()->getLocale());
    $countOf = fn (int $n): int|string => $n > $cap ? $over : $n;
    $noun = __('catalog.noun');

    // Status groups, as in the design; Thùng rác only for who may manage products.
    $groupIcons = ['all' => 'ri-list-check', 'published' => 'ri-global', 'draft' => 'ri-draft', 'archived' => 'ri-archive'];
    $groups = [];
    foreach ($groupIcons as $key => $icon) {
        $groups[] = ['key' => $key, 'label' => __('catalog.tab_'.$key), 'icon' => $icon, 'count' => $countOf($counts[$key] ?? 0)];
    }
    if ($canManage) {
        $groups[] = ['key' => 'trash', 'label' => __('catalog.trash_tab'), 'icon' => 'ri-delete-bin', 'count' => $countOf($counts['trash'] ?? 0), 'trash' => true];
    }
    $groupLabel = $tab === 'all' ? null : (collect($groups)->firstWhere('key', $tab)['label'] ?? null);

    // The Filter sheet below 900px holds the status groups (their row shows from 900px).
    $sections = [
        ['key' => 'status', 'label' => __('catalog.status_label'), 'icon' => 'ri-flag', 'model' => 'tab', 'value' => $tab, 'default' => 'all',
            'sheet' => true, 'class' => 'min-[900px]:hidden',
            'options' => array_map(fn (array $g): array => ['value' => $g['key'], 'label' => $g['label'], 'count' => $g['count'], 'divider' => $g['trash'] ?? false], $groups)],
    ];
    // The filters, in the design's order: category, translations, labels, stock, sale. A long one shows its first
    // choices with "Xem thêm", and past fifteen choices has its own search box. A filter nothing can be chosen
    // in is left out unless it is on.
    $filterDefs = [
        'cat' => ['label' => __('catalog.f_cat'), 'icon' => 'ri-folder-3', 'model' => 'catF', 'value' => $catF],
        'trans' => ['label' => __('entries.f_trans'), 'icon' => 'ri-translate-2', 'model' => 'transF', 'value' => $transF],
        'tag' => ['label' => __('catalog.f_tag'), 'icon' => 'ri-price-tag-3', 'model' => 'tagF', 'value' => $tagF],
        'stock' => ['label' => __('catalog.f_stock'), 'icon' => 'ri-stack', 'model' => 'stockF', 'value' => $stockF],
        'sale' => ['label' => __('catalog.f_sale'), 'icon' => 'ri-price-tag-2', 'model' => 'saleF', 'value' => $saleF],
    ];
    foreach ($filterDefs as $key => $def) {
        $options = $facets[$key] ?? [];
        if ($options === []) {
            continue;
        }
        $fit = \App\Modules\Admin\Support\LongFilterSection::fit($options, $moreF[$key] ?? false, $qF[$key] ?? '');
        $what = mb_strtolower($def['label']);
        $sections[] = $def + [
            'key' => $key, 'multiple' => true, 'reset' => 'resetFilterGroup('.Js::from($key).')',
            'options' => $fit['options'],
            'search' => $fit['searchable'] ? ['model' => 'qF.'.$key, 'label' => __('admin.list.search_in', ['what' => $what]), 'placeholder' => __('admin.list.search_in', ['what' => $what])] : null,
            'more' => $fit['hidden'] > 0 ? ['label' => __('admin.list.show_more', ['n' => $fit['hidden'], 'what' => $what]), 'call' => 'showMore('.Js::from($key).')'] : null,
        ];
    }
    // a view choice, not a filter: only while the rows are cards (the list narrower than 37.5rem)
    $cardLabels = ['sku' => __('catalog.card_opt_sku'), 'cat' => __('catalog.card_opt_cat'), 'slug' => __('catalog.card_opt_slug'), 'tr' => __('catalog.card_opt_tr')];
    $sections[] = ['key' => 'card', 'label' => __('entries.f_card'), 'model' => 'card', 'multiple' => true, 'value' => $card, 'sheet' => true, 'class' => '@min-[37.5rem]/lt:hidden', 'on' => false,
        'options' => array_map(fn (string $k): array => ['value' => $k, 'label' => $cardLabels[$k]], $cardOptions)];

    $trashSort = $tab === 'trash';
    $sortLabel = fn (string $key): string => $key === 'updated' && $trashSort ? __('catalog.sort_updated_trash') : __('catalog.sort_'.$key);
    $sortIcons = ['updated' => 'ri-time', 'name' => 'ri-sort-alphabet-asc', 'price_asc' => 'ri-sort-number-asc', 'price_desc' => 'ri-sort-number-desc', 'stock_asc' => 'ri-stack'];
    $sortOptions = [];
    foreach ($sorts as $key) {
        $sortOptions[] = ['key' => $key, 'label' => $sortLabel($key), 'icon' => $sortIcons[$key]];
    }

    // The active-filter line: "Group · N trong M sản phẩm · Bỏ lọc", then a chip per condition (and the sort, when changed).
    $filtering = $tab !== 'all' || $filterCount > 0 || trim($search) !== '';
    $chips = [];
    if (trim($search) !== '') {
        $chips[] = ['label' => __('catalog.chip_search', ['q' => trim($search)]), 'remove' => '$set(\'search\', \'\')'];
    }
    foreach ($filterChips as $chip) {
        $chips[] = $chip;
    }
    if (! $sortIsDefault) {
        $chips[] = ['label' => $sortLabel($sortOn), 'icon' => 'ri-arrow-up-down', 'remove' => 'resetSort',
            'aria' => __('catalog.chip_sort_aria', ['name' => $sortLabel($sortOn), 'default' => $sortLabel('updated')])];
    }
    $ariaSort = fn (string $key): string => $sortOn !== $key ? 'none' : (\App\Modules\Admin\Support\Catalog\ProductListQuery::SORTS[$key] === 'asc' ? 'ascending' : 'descending');
    $title = __('catalog.title');
    $statusText = fn (string $status): string => __('catalog.tab_'.$status);
    // what the status menu offers from each state (the ones the row is not already in)
    $statusMoves = ['published' => ['archived', 'draft'], 'draft' => ['published', 'archived'], 'archived' => ['published', 'draft']];

    // Row cells. A price keeps its "₫" on the number's line (a no-break space); a time is the site's own clock.
    $money = fn (int $n): string => number_format($n, 0, ',', '.')."\u{00A0}₫";
    $clock = fn (?\DateTimeInterface $at): ?string => $at === null ? null : $siteTime->forDisplay($at)?->format('d/m/Y H:i');
    $nowAt = $siteTime->now();
    // the card's short form of the last edit: today, yesterday, this year without the year, otherwise the day
    $editedShort = function (\DateTimeInterface $at) use ($siteTime, $nowAt): string {
        $t = $siteTime->forDisplay($at);
        if ($t === null) {
            return '';
        }
        $days = (int) round($t->startOfDay()->diffInDays($nowAt->startOfDay()));

        return match (true) {
            $days === 0 => __('catalog.edited_today', ['time' => $t->format('H:i')]),
            $days === 1 => __('catalog.edited_yesterday', ['time' => $t->format('H:i')]),
            $t->year === $nowAt->year => __('catalog.edited_year', ['day' => $t->format('d/m'), 'time' => $t->format('H:i')]),
            default => __('catalog.edited_other', ['date' => $t->format('d/m/Y')]),
        };
    };
    $hiddenAlways = $selling ? [] : ['price', 'stock'];
    // what the table hides at first paint (the saved columns, and price and stock on a brochure website); Alpine keeps it in step
    $hideNow = implode(' ', array_values(array_unique([...array_diff(['cat', 'price', 'stock', 'badge'], $cols), ...$hiddenAlways])));
    $priceNext = match ($sortOn) { 'price_asc' => 'price_desc', 'price_desc' => 'updated', default => 'price_asc' };
    $ariaPrice = $sortOn === 'price_asc' ? 'ascending' : ($sortOn === 'price_desc' ? 'descending' : 'none');
@endphp
<div class="catalog-index flex flex-col gap-3">

    <header class="flex flex-col gap-1">
        <nav aria-label="{{ __('admin.breadcrumb') }}" class="hidden text-caption text-text-muted min-[600px]:block">
            <div class="flex items-center gap-1.5">
                <a href="{{ route('admin.dashboard') }}" wire:navigate class="hit-area inline-flex items-center gap-1 transition-colors hover:text-primary-text">
                    <x-bs-admin::icon name="home" class="size-3.5" /> {{ __('admin.breadcrumb_home') }}
                </a>
                <x-bs-admin::icon name="chevron-right" class="size-3.5 text-placeholder" />
                <span class="font-medium">{{ $title }}</span>
            </div>
        </nav>
        <div class="flex items-center gap-3">
            <h1 class="m-0 min-w-0 flex-1 text-[calc(1.25rem+var(--user-text-boost))] font-semibold leading-snug text-text-strong [overflow-wrap:anywhere]">{{ $title }}</h1>
            @if ($canManage)
                <x-bs-admin::button color="primary" class="shrink-0" :href="route('admin.catalog.create')">
                    <x-bs-admin::icon name="ri-add" class="size-4" /> {{ __('catalog.create_new') }}
                </x-bs-admin::button>
            @endif
        </div>
        <p class="m-0 text-body text-text-muted [overflow-wrap:anywhere]">{{ __('catalog.desc') }}</p>
    </header>

    @if ($failed)
        <x-bs-admin::card flush role="alert" data-state="error" class="min-w-0 p-6 text-center">
            <x-bs-admin::icon name="ri-wifi-off" class="mx-auto size-8 text-text-muted" />
            <h2 class="m-0 mt-2 text-[calc(1.125rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('catalog.error_title') }}</h2>
            <p class="m-0 mt-1 text-body text-text-muted">{{ __('catalog.error_text') }}</p>
            <x-bs-admin::button color="primary" class="mt-4" wire:click="$refresh">
                <x-bs-admin::icon name="ri-refresh" class="size-4" /> {{ __('catalog.error_retry') }}
            </x-bs-admin::button>
        </x-bs-admin::card>
    @elseif ($counts['all'] === 0 && (! $canManage || $counts['trash'] === 0))
        <x-bs-admin::card flush role="status" data-state="empty" class="min-w-0 p-6 text-center">
            <x-bs-admin::icon name="ri-box-3" class="mx-auto size-8 text-text-muted" />
            <h2 class="m-0 mt-2 text-[calc(1.125rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('catalog.empty_title') }}</h2>
            <p class="m-0 mt-1 text-body text-text-muted">{{ $canManage ? __('catalog.empty_text_manage') : __('catalog.empty_text_view') }}</p>
            @if ($canManage)
                <x-bs-admin::button color="primary" class="mt-4" :href="route('admin.catalog.create')">
                    <x-bs-admin::icon name="ri-add" class="size-4" /> {{ __('catalog.empty_add_first') }}
                </x-bs-admin::button>
            @endif
        </x-bs-admin::card>
    @else
    <div class="min-w-0 min-[600px]:rounded-md min-[600px]:border min-[600px]:border-border min-[600px]:bg-surface min-[600px]:pb-4 min-[600px]:shadow-card">
        <x-bs-admin::group-row :groups="$groups" :active="$tab" model="tab" :label="__('catalog.groups_label')" />

        {{-- What shows on each product follows the list's own width: below 600 the member's card choice, 600–899
             every detail, from 900 their column choice (both kept in their own preferences). --}}
        <div class="@container/lt min-w-0"
             x-data="{
                 mode: 'cols',
                 xv: false,
                 lowH: false,
                 fold: '',
                 seen: '',
                 ro: null,
                 lowMq: null,
                 onLow: null,
                 destroy() { this.ro?.disconnect(); this.lowMq?.removeEventListener('change', this.onLow); },
                 size(force = false) { const w = this.$refs.rows?.clientWidth ?? 0, rem = parseFloat(getComputedStyle(document.documentElement).fontSize) || 16, boost = window.bsListFold.boostPx(), low = window.matchMedia('(max-height:500px)').matches, key = w + ':' + boost + ':' + low; if (! force && key === this.seen) { return; } this.seen = key; this.lowH = low; this.mode = w < 37.5 * rem ? 'card' : (w < 56.25 * rem ? 'line' : 'cols'); this.foldCols(rem, boost); this.fitXv(rem, boost); },
                 {{-- the row's "Xem" button shows from 600 on: always on a card line, and in the table only while the name column keeps 340px (+6px per pixel of text boost) after the button takes its room, measured as if the button were not there so showing it cannot flip the decision; a short landscape screen has none --}}
                 fitXv(rem, boost) { const main = this.$refs.rows?.querySelector('.list-rows .list-hd .list-c-main'); let back = 0; this.$refs.rows?.querySelectorAll('[data-row-view]').forEach((b) => { if (b.getClientRects().length) { back = Math.max(back, b.getBoundingClientRect().width + (parseFloat(getComputedStyle(b.parentElement).columnGap) || 0)); } }); this.xv = this.mode === 'cols' && !! main && main.getBoundingClientRect().width + back - 6 * rem >= 340 + 6 * boost; },
                 get xvVis() { return ! this.lowH && (this.mode === 'line' || (this.mode === 'cols' && this.xv)); },
                 {{-- as the table narrows or the text grows, the label, category, stock and price columns fold into the line under the name (in that order) until the name keeps the shared minimum; one pass: clear the fold, read every width, write the fold once --}}
                 foldCols(rem, boost) {
                     const list = this.$refs.rows?.querySelector('.list-rows'), head = list?.querySelector('.list-hd'), main = head?.querySelector('.list-c-main');
                     if (! list || ! main) { return; }
                     let out = [];
                     if (this.mode === 'cols') {
                         list.removeAttribute('data-fold');
                         const cols = ['badge', 'cat', 'stock', 'price'].map((k) => [k, head.querySelector('.list-c-' + k)?.getBoundingClientRect().width ?? 0]);
                         out = window.bsListFold.nameFold(main.getBoundingClientRect().width - 3.75 * rem, cols, boost);
                     }
                     this.fold = out.join(' ');
                     this.fold === '' ? list.removeAttribute('data-fold') : list.setAttribute('data-fold', this.fold);
                 },
                 show(k) { return this.mode === 'line' || (this.mode === 'cols' ? this.$wire.cols : this.$wire.card).includes(k); },
                 get vSku() { return this.mode !== 'card' || this.$wire.card.includes('sku'); },
                 get vCat() { return this.show('cat'); },
                 get vSlug() { return this.mode !== 'card' || this.$wire.card.includes('slug'); },
                 get vLangs() { return this.mode !== 'card' || this.$wire.card.includes('tr'); },
                 get vPrice() { return this.$wire.cols.includes('price'); },
                 get vQty() { return this.$wire.cols.includes('stock'); },
                 get vBadge() { return this.$wire.cols.includes('badge'); },
                 hidden() { const off = {{ Js::from($hiddenAlways) }}; return (this.mode === 'cols' ? ['cat', 'price', 'stock', 'badge'].filter(k => ! this.$wire.cols.includes(k)) : []).concat(off).join(' '); },
                 copy(url) { (navigator.clipboard ? navigator.clipboard.writeText(url) : Promise.reject()).then(() => window.bsToast({ text: {{ Js::from(__('catalog.copied')) }}.replace(':url', url) }), () => {}); },
             }"
             x-init="size(true); ro = new ResizeObserver(() => size()); ro.observe($el); lowMq = window.matchMedia('(max-height:500px)'); onLow = () => size(true); lowMq.addEventListener('change', onLow); $watch('$wire.cols', () => $nextTick(() => size(true))); $wire.$hook('commit', ({ succeed }) => succeed(() => $nextTick(() => size(true))))">
            <div class="flex flex-col gap-3 pb-3 min-[600px]:px-4 min-[600px]:pt-4 min-[900px]:pt-3 @min-[56.25rem]/lt:flex-row @min-[56.25rem]/lt:flex-wrap @min-[56.25rem]/lt:items-center">
                <div class="min-w-0 text-body @min-[56.25rem]/lt:min-w-[19em] @min-[56.25rem]/lt:max-w-[30em] @min-[56.25rem]/lt:flex-[999_1_19em] @min-[56.25rem]/lt:[&:has(+[data-below='1'])]:max-w-none">
                    <x-bs-admin::search id="catalog-search" :label="__('catalog.search_label')" :placeholder="__('catalog.search_hint')"
                        :clear-label="__('catalog.search_clear')" clear-action="$set('search', '')" :clear-on="trim($search) !== ''" wire:model.live.debounce.300ms="search" />
                </div>
                <x-bs-admin::tool-row class="@min-[56.25rem]/lt:min-w-0 @min-[56.25rem]/lt:flex-[1_1_auto] @min-[56.25rem]/lt:flex-wrap @min-[56.25rem]/lt:gap-3">
                    <x-bs-admin::filter-panel class="ms-auto flex-nowrap @min-[56.25rem]/lt:ms-0 @min-[56.25rem]/lt:min-w-0 @min-[56.25rem]/lt:shrink @min-[56.25rem]/lt:flex-wrap" :sections="$sections"
                        :active="$filterCount" :active-narrow="$filterCountNarrow" clear="clearAllFilters" :clear-closes="true" :results="__('catalog.show_n', ['n' => $fmt($matchCount), 'unit' => $noun])" />
                    <x-bs-admin::sort-menu class="@min-[56.25rem]/lt:ms-auto" :options="$sortOptions" :active="$sortOn" :lit="! $sortIsDefault" set="setSort" />
                    <x-bs-admin::cols-menu class="hidden @min-[56.25rem]/lt:block" id="catalog-cols" :selected="$cols"
                        :options="collect($colOptions)->mapWithKeys(fn (string $k): array => [$k => __('catalog.col_opt_'.$k)])->all()"
                        :locked="[__('catalog.col_product'), __('catalog.status_label'), __('admin.actions')]" />
                </x-bs-admin::tool-row>
            </div>

            <x-bs-admin::filter-status class="-mx-3 -mt-1 mb-2 min-[600px]:mx-1" :group="$groupLabel"
                :summary="$filtering ? __('catalog.match_of', ['n' => $fmt($matchCount), 'm' => $fmt($counts[$tab] ?? 0), 'unit' => $noun]) : null"
                :chips="$chips" :clear="$filtering ? 'clearAllFilters' : null" />

            <div x-ref="rows" class="min-w-0 max-[599.98px]:rounded-md max-[599.98px]:border max-[599.98px]:border-border max-[599.98px]:bg-surface max-[599.98px]:[&_.list-g]:border-t-0">
            <x-bs-admin::list :label="__('catalog.list_label')" kind="catalog" :data-hide="$hideNow" x-bind:data-hide="hidden()" x-bind:data-fold="fold">

                <x-bs-admin::list.head>
                    <x-bs-admin::list.cell head col="main" :sort="$ariaSort('name')">
                        <x-bs-admin::list.sort :active="$sortOn === 'name'" dir="asc" wire:click="sortBy('name')">{{ __('catalog.col_product') }}</x-bs-admin::list.sort>
                    </x-bs-admin::list.cell>
                    <x-bs-admin::list.cell head col="cat">{{ __('catalog.col_opt_cat') }}</x-bs-admin::list.cell>
                    @if ($selling)
                        <x-bs-admin::list.cell head col="price" :sort="$ariaPrice">
                            <x-bs-admin::list.sort :active="in_array($sortOn, ['price_asc', 'price_desc'], true)" :dir="$sortOn === 'price_desc' ? 'desc' : 'asc'" wire:click="setSort('{{ $priceNext }}')">{{ __('catalog.col_price') }}</x-bs-admin::list.sort>
                        </x-bs-admin::list.cell>
                        <x-bs-admin::list.cell head col="stock" :sort="$ariaSort('stock_asc')">
                            <x-bs-admin::list.sort :active="$sortOn === 'stock_asc'" dir="asc" wire:click="sortBy('stock_asc')">{{ __('catalog.col_opt_stock') }}</x-bs-admin::list.sort>
                        </x-bs-admin::list.cell>
                    @endif
                    <x-bs-admin::list.cell head col="badge">{{ __('catalog.col_opt_badge') }}</x-bs-admin::list.cell>
                    <x-bs-admin::list.cell head col="st" :sort="$ariaSort('updated')">
                        <x-bs-admin::list.sort :active="$sortOn === 'updated'" dir="desc" wire:click="sortBy('updated')">{{ $trashSort ? __('catalog.trash_tab') : __('catalog.status_label') }}</x-bs-admin::list.sort>
                    </x-bs-admin::list.cell>
                    <x-bs-admin::list.cell head col="act">{{ __('admin.actions') }}</x-bs-admin::list.cell>
                </x-bs-admin::list.head>

                @forelse ($rows as $product)
                    @php
                        $t = $product->translations->firstWhere('locale', $locale);
                        $name = $t?->name ?? __('catalog.untitled');
                        $thumbnail = $product->images->first()?->media;
                        $primary = $product->primaryCategory();
                        $categoryName = $primary?->translations->firstWhere('locale', $locale)?->name;
                        $categoryPath = $primary === null ? null : ($tree?->pathOf($primary->id) ?? $categoryName);
                        $trashed = $product->trashed();
                        $langHas = $product->translations->pluck('locale')->all();
                        // the editor opens the default language only: every other chip says so; a viewer or a trashed row, why not
                        $langWhy = fn (string $lc): string => $trashed ? __('entries.lc_why_trashed') : (! $canManage ? __('entries.lc_why_view_only', ['noun' => $noun]) : __('catalog.lc_why_default_only'));
                        $imageCount = $num((int) $product->getAttribute('images_n'));
                        // the address PathBuilder composes for this product (the site's permalink preset), in the default language
                        $path = $t?->slug ? $paths->publicUrl($paths->forProduct($primary, $t->slug, $locale), $locale) : null;
                        $fullUrl = $path === null ? null : $publicHost.$path;
                        // the quick edits: name and address of the default language, a live row of a member who manages products
                        $quickEdit = $canManage && ! $trashed && $t !== null;
                        $slugLeaf = $t?->slug;
                        $slugPrefix = ($path !== null && $slugLeaf) ? \Illuminate\Support\Str::beforeLast($path, $slugLeaf) : '/';
                        // the row is drawn anew when its name or address changes, so the open-edit state never holds the old text
                        $rowKey = 'product-'.$product->ulid.'-'.substr(md5($name.'|'.($slugLeaf ?? '')), 0, 8);
                        $rowX = $quickEdit ? strtr(<<<'JS'
{
                                ulid: @ULID@,
                                editing: false, value: @NAME@, orig: @NAME@, err: '',
                                slugEditing: false, slugValue: @SLUG@, slugOrig: @SLUG@, slugErr: '',
                                cancel: false, byKey: false, busy: false, from: '.list-qe',
                                init() {
                                    this.$el.addEventListener('list-quick-edit', () => { this.from = '.list-qe'; this.editing = true; this.cancel = false; this.err = '' })
                                    this.$el.addEventListener('list-slug-edit', () => { this.from = '.list-more [aria-haspopup]'; this.slugEditing = true; this.cancel = false; this.slugErr = ''; this.$nextTick(() => { this.$refs.slugInput.focus(); this.$refs.slugInput.select() }) })
                                },
                                back() { if (this.byKey) { const ulid = this.ulid, from = this.from; this.$nextTick(() => { const row = document.querySelector('[data-row-ulid=' + JSON.stringify(ulid) + ']'); const shown = (el) => el && el.getClientRects().length > 0; const to = [from, '.list-name', '.list-qe'].map((q) => row?.querySelector(q)).find(shown); to?.focus() }) } this.byKey = false },
                                async saveName() {
                                    if (this.busy) { return }
                                    if (this.cancel || this.value.trim() === this.orig) { this.value = this.orig; this.err = ''; this.editing = false; this.cancel = false; this.back(); return }
                                    this.busy = true
                                    const r = await $wire.saveName(this.ulid, this.value)
                                    this.busy = false
                                    if (r.ok) { this.orig = this.value = r.name; this.err = ''; this.editing = false; this.back(); return }
                                    if (r.keep) { this.err = r.error; this.$nextTick(() => this.$refs.nameInput.focus()); return }
                                    this.value = r.name || this.orig; this.err = ''; this.editing = false; this.back()
                                },
                                async saveSlug() {
                                    if (this.busy) { return }
                                    if (this.cancel || this.slugValue.trim() === this.slugOrig) { this.slugValue = this.slugOrig; this.slugErr = ''; this.slugEditing = false; this.cancel = false; this.back(); return }
                                    this.busy = true
                                    const r = await $wire.saveSlug(this.ulid, this.slugValue)
                                    this.busy = false
                                    if (r.ok) { this.slugOrig = this.slugValue = r.slug; this.slugErr = ''; this.slugEditing = false; this.back(); return }
                                    if (r.keep) { this.slugErr = r.error; if (r.slug) { this.slugValue = r.slug } this.$nextTick(() => this.$refs.slugInput.focus()); return }
                                    this.slugValue = this.slugOrig; this.slugErr = ''; this.slugEditing = false; this.back()
                                }
                            }
JS, ['@ULID@' => (string) Js::from($product->ulid), '@NAME@' => (string) Js::from($name), '@SLUG@' => (string) Js::from($slugLeaf ?? '')]) : '{}';
                        // price: the sale state comes from SQL (on_sale), the old price shows struck through beside it
                        $onSale = (bool) $product->getAttribute('on_sale');
                        $noPrice = $product->price === null;
                        $priceText = $noPrice ? __('catalog.price_ask') : $money($onSale ? (int) $product->sale_price : (int) $product->price);
                        $oldText = $onSale ? $money((int) $product->price) : null;
                        $saleEnd = $clock($product->sale_ends_at);
                        $priceTip = $onSale
                            ? ($saleEnd !== null ? __('catalog.price_sale_tip', ['date' => $saleEnd, 'price' => $oldText]) : __('catalog.price_sale_tip_open', ['price' => $oldText]))
                            : ($noPrice ? __('catalog.price_ask_tip') : null);
                        $stockKind = $product->stock === null ? 'none' : ($product->stock === 0 ? 'out' : ($product->stock <= 5 ? 'low' : 'in'));
                        $stockText = $stockKind === 'none' ? __('catalog.stock_none') : ($stockKind === 'out' ? __('catalog.stock_out') : __('catalog.stock_left', ['n' => $num((int) $product->stock)]));
                        $stockIcon = ['out' => 'ri-close-circle', 'low' => 'ri-error-warning', 'in' => 'ri-checkbox-circle', 'none' => 'ri-subtract'][$stockKind];
                        $tags = array_values(array_filter([
                            $product->is_featured ? ['k' => 'featured', 'l' => __('catalog.tag_featured'), 'icon' => 'ri-star'] : null,
                            $product->is_bestseller ? ['k' => 'best', 'l' => __('catalog.tag_best'), 'icon' => 'ri-fire'] : null,
                        ]));
                        // the last edit (a trashed row: the day it went to the Trash)
                        $stamp = $trashed ? $product->deleted_at : $product->updated_at;
                        $stampText = $clock($stamp);
                        $whenText = $stampText === null ? null : ($trashed ? __('catalog.trashed_at', ['date' => $stampText]) : __('catalog.updated_at', ['date' => $stampText]));
                        $whenTitle = $stampText === null ? null : ($trashed ? __('catalog.trashed_at_title', ['date' => $stampText]) : __('catalog.updated_full', ['date' => $stampText]));
                        $whenCard = $trashed || $stamp === null ? $whenText : $editedShort($stamp);
                    @endphp
                    <x-bs-admin::list.row wire:key="{{ $rowKey }}" data-row-ulid="{{ $product->ulid }}" x-data="{{ $rowX }}">
                        <x-bs-admin::list.cell col="main">
                            <div class="flex items-start gap-3">
                                @if ($thumbnail !== null)
                                    <img src="{{ $urls->url($thumbnail->storage_key, 96) }}" alt="" title="{{ __('catalog.thumb_n', ['n' => $imageCount]) }}" loading="lazy" class="size-12 shrink-0 rounded border border-border object-cover">
                                @else
                                    <span class="flex size-12 shrink-0 items-center justify-center rounded border border-border bg-surface-muted text-placeholder" title="{{ __('catalog.thumb_none') }}" aria-hidden="true">
                                        <x-bs-admin::icon name="image" class="size-5" />
                                    </span>
                                @endif
                                <div class="min-w-0 flex-1">
                                    <div @if ($quickEdit) x-show="! editing" @endif>
                                    <x-bs-admin::list.name :href="$canManage && ! $trashed ? route('admin.catalog.edit', ['ulid' => $product->ulid]) : null" :act="$canManage && $trashed ? 'trashedNote' : null"
                                            :quick-edit="$quickEdit ? __('catalog.quick_edit_named', ['name' => $name]) : null">{{ $name }}</x-bs-admin::list.name>
                                    </div>
                                    @if ($quickEdit)
                                        <input type="text" x-ref="nameInput" x-show="editing" x-cloak x-model="value" class="list-in" maxlength="1000"
                                            aria-label="{{ __('catalog.name_input_label') }}" x-bind:aria-invalid="err !== ''"
                                            x-effect="if (editing) { $nextTick(() => { $refs.nameInput.focus(); $refs.nameInput.select() }) }"
                                            @keydown.enter.prevent="cancel = false; byKey = true; $el.blur()"
                                            @keydown.escape.prevent.stop="cancel = true; byKey = true; $el.blur()"
                                            @blur="saveName()">
                                        <p x-show="editing && err !== ''" x-cloak role="alert" class="mt-1 flex items-start gap-1 text-caption text-danger-text"><x-bs-admin::icon name="ri-error-warning" class="mt-0.5 size-3.5 shrink-0" /><span x-text="err"></span></p>
                                    @endif
                                    <x-bs-admin::list.meta>
                                        <span class="list-m" data-for="sku" x-show="vSku" title="{{ __('catalog.sku_title') }}">
                                            @if ($product->sku !== null){{ __('catalog.sku_meta', ['sku' => $product->sku]) }}@else<span class="text-text-muted">{{ __('catalog.sku_none') }}</span>@endif
                                        </span>
                                        @if ($path !== null)
                                            <span class="list-m inline-flex items-center gap-1" data-for="slug" x-show="vSlug{{ $quickEdit ? ' && ! slugEditing' : '' }}">
                                                @if ($quickEdit)
                                                    <button type="button" class="list-path list-slug-btn font-mono [overflow-wrap:anywhere]" title="{{ __('catalog.slug_edit_hint', ['url' => $fullUrl]) }}"
                                                        x-on:click="from = '.list-slug-btn'; slugEditing = true; cancel = false; slugErr = ''; $nextTick(() => { $refs.slugInput.focus(); $refs.slugInput.select() })">{{ $path }}</button>
                                                @else
                                                    <span class="font-mono [overflow-wrap:anywhere]" title="{{ $path }}">{{ $path }}</span>
                                                @endif
                                                @unless ($trashed)
                                                    <button type="button" class="list-cpy" title="{{ __('catalog.copy_slug') }}" aria-label="{{ __('catalog.copy_slug_aria', ['url' => $fullUrl]) }}"
                                                        x-on:click="copy({{ Js::from($fullUrl) }})">
                                                        <x-bs-admin::icon name="ri-file-copy" class="size-[calc(1rem+var(--user-text-boost))]" />
                                                    </button>
                                                @endunless
                                            </span>
                                        @endif
                                        @if ($quickEdit && $path !== null)
                                            <span class="list-m w-full" x-show="slugEditing" x-cloak>
                                                <span class="inline-flex w-full items-center gap-0.5 font-mono text-caption">
                                                    <span class="whitespace-nowrap text-placeholder">{{ $slugPrefix }}</span>
                                                    <input type="text" x-ref="slugInput" x-model="slugValue" maxlength="1000" aria-label="{{ __('catalog.slug_input_label') }}" x-bind:aria-invalid="slugErr !== ''"
                                                        @keydown.enter.prevent="cancel = false; byKey = true; $el.blur()"
                                                        @keydown.escape.prevent.stop="cancel = true; byKey = true; $el.blur()"
                                                        @blur="saveSlug()"
                                                        class="min-h-tap-cell min-w-0 flex-1 rounded border border-primary-text bg-surface px-1">
                                                </span>
                                                <span x-show="slugErr !== ''" role="alert" class="mt-1 flex items-start gap-1 font-sans text-caption text-danger-text"><x-bs-admin::icon name="ri-error-warning" class="mt-0.5 size-3.5 shrink-0" /><span x-text="slugErr"></span></span>
                                            </span>
                                        @endif
                                        <x-bs-admin::list.langs :locales="$locales" :source="$locale" :name="$name" show="vLangs"
                                            :has="$langHas" :only="[$locale]" :note="! $trashed"
                                            :href="$canManage && ! $trashed ? fn (string $lc): string => route('admin.catalog.edit', ['ulid' => $product->ulid]) : null"
                                            :why="$langWhy" />
                                        @if ($selling)
                                            <span class="list-m" data-for="price" x-show="vPrice">{{ $priceText }}@if ($oldText !== null) <span class="list-old">{{ __('catalog.price_old_card', ['price' => $oldText]) }}</span>@endif</span>
                                            <span class="list-m" data-for="stock" x-show="vQty">{{ $stockText }}</span>
                                        @endif
                                        <span class="list-m" data-for="cat" x-show="vCat">{{ $categoryName ?? __('catalog.cat_none') }}</span>
                                        @if ($tags !== [])
                                            <span class="list-m" data-for="badge" x-show="vBadge">{{ collect($tags)->pluck('l')->implode(', ') }}</span>
                                        @endif
                                        @if ($whenCard)
                                            <span class="list-m" data-for="when">{{ $whenCard }}</span>
                                        @endif
                                        <x-bs-admin::list.langs part="note" :locales="$locales" :source="$locale" :name="$name" :has="$langHas" :note="! $trashed" />
                                    </x-bs-admin::list.meta>
                                </div>
                            </div>
                            {{-- below 400 the language chips and the missing-language note move under the picture, full width --}}
                            @if (count($locales) > 1)
                                <div class="list-langs2">
                                    <x-bs-admin::list.langs :locales="$locales" :source="$locale" :name="$name" show="vLangs"
                                        :has="$langHas" :only="[$locale]" :note="! $trashed"
                                        :href="$canManage && ! $trashed ? fn (string $lc): string => route('admin.catalog.edit', ['ulid' => $product->ulid]) : null"
                                        :why="$langWhy" />
                                </div>
                            @endif
                        </x-bs-admin::list.cell>

                        <x-bs-admin::list.cell col="cat">
                            <span class="list-ell" title="{{ $categoryPath ?? '' }}">{{ $categoryName ?? __('catalog.cat_none') }}</span>
                        </x-bs-admin::list.cell>
                        @if ($selling)
                            <x-bs-admin::list.cell col="price" :title="$priceTip">
                                <span class="sp-pr" data-k="{{ $noPrice ? 'ask' : 'v' }}">{{ $priceText }}</span>
                                @if ($oldText !== null)<span class="sp-old">{{ $oldText }}</span>@endif
                            </x-bs-admin::list.cell>
                            <x-bs-admin::list.cell col="stock">
                                <span class="sp-stk" data-k="{{ $stockKind }}"><x-bs-admin::icon :name="$stockIcon" class="size-[calc(1rem+var(--user-text-boost))] shrink-0" />{{ $stockText }}</span>
                            </x-bs-admin::list.cell>
                        @endif
                        <x-bs-admin::list.cell col="badge">
                            <span class="sp-tags">
                                @forelse ($tags as $tag)
                                    <span class="sp-tag" data-k="{{ $tag['k'] }}"><x-bs-admin::icon :name="$tag['icon']" class="size-[calc(0.75rem+var(--user-text-boost))] shrink-0" />{{ $tag['l'] }}</span>
                                @empty
                                    <span class="sp-none">—</span>
                                @endforelse
                            </span>
                        </x-bs-admin::list.cell>

                        <x-bs-admin::list.cell col="st">
                            <div class="list-stw">
                                @if ($canManage && ! $trashed)
                                    <div x-data="{ open: false, up: false, close(back) { this.open = false; if (back) { this.$refs.pill.focus() } } }" x-on:click.outside="open = false" x-on:keydown.escape="if (open) { $event.stopPropagation(); close(true) }" class="relative inline-block">
                                        <x-bs-admin::status-pill button x-ref="pill" :status="$product->status" aria-haspopup="menu" x-bind:aria-expanded="open.toString()" aria-label="{{ __('catalog.status_pill_name', ['status' => $statusText($product->status)]) }}" title="{{ __('catalog.status_menu_label') }}"
                                            x-on:click="up = window.innerHeight - $el.getBoundingClientRect().bottom < 240; open = ! open; open && $nextTick(() => $focus.within($refs.menu).first())">{{ $statusText($product->status) }}</x-bs-admin::status-pill>
                                        <x-bs-admin::list.menu class="absolute left-0 z-30 mt-1 w-64 rounded-lg border border-border bg-surface p-1 shadow-lg" x-bind:class="up && 'bottom-full mb-1 mt-0'" :label="__('catalog.status_menu_label')">
                                            @foreach ($statusMoves[$product->status] as $move)
                                                <button type="button" role="menuitem" x-on:click="$wire.setStatus({{ Js::from($product->ulid) }}, {{ Js::from($move) }})" class="list-mi rounded">
                                                    <span class="flex min-w-0 flex-col text-left">
                                                        <span class="font-medium">{{ __('catalog.move_'.$move) }}</span>
                                                        <span class="text-caption text-text-muted">{{ __('catalog.move_'.$move.'_hint') }}</span>
                                                    </span>
                                                </button>
                                            @endforeach
                                        </x-bs-admin::list.menu>
                                    </div>
                                @else
                                    <x-bs-admin::status-pill :status="$product->status">{{ $statusText($product->status) }}</x-bs-admin::status-pill>
                                @endif
                            </div>
                            @if ($whenText)
                                <div class="list-st-meta" title="{{ $whenTitle }}">
                                    <x-bs-admin::icon name="ri-history" class="size-3 shrink-0" />
                                    <span>{{ $whenText }}</span>
                                </div>
                            @endif
                        </x-bs-admin::list.cell>

                        <x-bs-admin::list.cell col="act">
                            @php
                                $live = ! $trashed && $product->status === 'published' && $fullUrl !== null;
                                $shownUrl = $fullUrl === null ? '' : (string) preg_replace('#^https?://#', '', $fullUrl);
                            @endphp
                            @if ($canManage || $live)
                                <x-bs-admin::list.actions :name="$name">
                                    @if ($canManage && $trashed)
                                        <x-bs-admin::button size="sm" variant="soft" color="primary" wire:click="restore('{{ $product->ulid }}')">
                                            <x-bs-admin::icon name="ri-arrow-go-back" class="size-4" /> {{ __('catalog.restore_action') }}
                                        </x-bs-admin::button>
                                        <x-slot:menu>
                                            <x-bs-admin::list.menu-item icon="ri-delete-bin" danger
                                                x-on:click="$bsConfirm({ icon: 'trash-danger', text: {{ Js::from(__('catalog.force_delete_confirm')) }}, confirm: {{ Js::from(__('catalog.force_delete_action')) }}, cancel: {{ Js::from(__('admin.cancel')) }} }).then(ok => ok && $wire.forceDelete({{ Js::from($product->ulid) }}))">{{ __('catalog.force_delete_action') }}</x-bs-admin::list.menu-item>
                                        </x-slot:menu>
                                    @elseif ($canManage)
                                        @if ($live)
                                            <x-bs-admin::button size="sm" variant="soft" color="primary" :href="$fullUrl" :navigate="false" target="_blank" rel="noopener" x-show="xvVis" x-cloak
                                                :aria-label="__('catalog.view_named', ['name' => $name])" :title="__('catalog.view_named', ['name' => $name])" data-row-view>
                                                <x-bs-admin::icon name="ri-external-link" class="size-4" /> {{ __('catalog.view_action') }}
                                            </x-bs-admin::button>
                                        @endif
                                        <x-bs-admin::button size="sm" variant="soft" color="primary" :href="route('admin.catalog.edit', ['ulid' => $product->ulid])"
                                            :aria-label="__('catalog.edit_named', ['name' => $name])">
                                            <x-bs-admin::icon name="ri-pencil" class="size-4" /> {{ __('catalog.edit_action') }}
                                        </x-bs-admin::button>
                                        <x-slot:menu>
                                            @if ($live)
                                                <x-bs-admin::list.menu-item icon="ri-external-link" :href="$fullUrl" target="_blank" rel="noopener" x-show="! xvVis">{{ __('catalog.view_on_site') }}</x-bs-admin::list.menu-item>
                                                <span class="list-mwhy" x-show="! xvVis">{{ $shownUrl }}</span>
                                            @endif
                                            @if ($quickEdit && $path !== null)
                                                <x-bs-admin::list.menu-item icon="ri-link" x-on:click="$dispatch('list-slug-edit')">{{ __('catalog.slug_edit_action') }}</x-bs-admin::list.menu-item>
                                            @endif
                                            @if ($fullUrl !== null)
                                                <x-bs-admin::list.menu-item icon="ri-file-copy" x-show="mode === 'card'" x-on:click="copy({{ Js::from($fullUrl) }})">{{ __('catalog.copy_path') }}</x-bs-admin::list.menu-item>
                                                <x-bs-admin::list.menu-sep x-show="mode === 'card'{{ $live ? ' || ! xvVis' : '' }}" />
                                            @endif
                                            <x-bs-admin::list.menu-item icon="ri-star" wire:click="toggleFlag('{{ $product->ulid }}', 'featured', {{ $product->is_featured ? 'false' : 'true' }})">{{ __($product->is_featured ? 'catalog.flag_remove_featured' : 'catalog.flag_add_featured') }}</x-bs-admin::list.menu-item>
                                            <x-bs-admin::list.menu-item icon="ri-fire" wire:click="toggleFlag('{{ $product->ulid }}', 'best', {{ $product->is_bestseller ? 'false' : 'true' }})">{{ __($product->is_bestseller ? 'catalog.flag_remove_best' : 'catalog.flag_add_best') }}</x-bs-admin::list.menu-item>
                                            <x-bs-admin::list.menu-sep />
                                            <x-bs-admin::list.menu-item icon="ri-delete-bin" danger
                                                wire:click="trash('{{ $product->ulid }}')">{{ __('catalog.menu_trash') }}</x-bs-admin::list.menu-item>
                                            <span class="list-mwhy">{{ __('catalog.menu_trash_hint') }}</span>
                                        </x-slot:menu>
                                    @else
                                        <x-slot:menu>
                                            <x-bs-admin::list.menu-item icon="ri-external-link" :href="$fullUrl" target="_blank" rel="noopener">{{ __('catalog.view_on_site') }}</x-bs-admin::list.menu-item>
                                            <span class="list-mwhy">{{ $shownUrl }}</span>
                                        </x-slot:menu>
                                    @endif
                                </x-bs-admin::list.actions>
                            @endif
                        </x-bs-admin::list.cell>
                    </x-bs-admin::list.row>
                @empty
                    <x-bs-admin::list.row>
                        <x-bs-admin::list.cell col="full" aria-colspan="3">
                            @if ($filtering && $tab !== 'trash')
                                {{ __('catalog.empty_filtered') }}
                            @elseif ($tab === 'trash')
                                {{ __('catalog.trash_empty') }}
                            @else
                                {{ __('catalog.empty_group') }}
                            @endif
                        </x-bs-admin::list.cell>
                    </x-bs-admin::list.row>
                @endforelse
            </x-bs-admin::list>
            </div>

            <div class="mt-4 flex flex-wrap items-center justify-between gap-3 min-[600px]:px-4">
                <span class="text-caption text-text-muted">
                    {{ __('catalog.footer_showing', ['first' => $num($pager['from']), 'last' => $num($pager['to']), 'total' => $fmt($pager['total'])]) }}
                </span>
                <div class="flex flex-wrap items-center gap-4">
                    <div role="group" aria-label="{{ __('entries.rows_per_page_label') }}" class="flex items-center gap-2 text-caption text-text-muted">
                        <span class="mr-1">{{ __('entries.rows_per_page') }}</span>
                        @foreach ([10, 20, 50] as $size)
                            <button type="button" wire:click="setPerPage({{ $size }})" aria-pressed="{{ $perPage === $size ? 'true' : 'false' }}"
                                @class(['pager-link border', 'border-primary bg-primary-base/8 font-semibold text-primary-text' => $perPage === $size, 'border-border-strong bg-surface font-medium text-text hover:bg-surface-hover' => $perPage !== $size])>
                                {{ $size }}
                            </button>
                        @endforeach
                    </div>
                    @php
                        // an arrow turns to a page number, or, past the numbered pages, to the row the page follows
                        $pgCurrent = $pager['current'];
                        $turn = fn (?array $to): ?string => $to === null ? null : (isset($to['token']) ? 'turnPage('.Js::from($to['token']).')' : 'gotoPage('.(int) $to['page'].')');
                        $pgPrev = $turn($pager['prev']);
                        $pgNext = $turn($pager['next']);
                    @endphp
                    <nav aria-label="Pagination" class="flex flex-wrap items-center gap-2">
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
    <x-bs-admin::toast :message="$toastMsg" :tone="$toastTone" :seq="$toastSeq" dismiss="closeToast" :undo="$undoOp !== null ? 'undoLast' : null" />
</div>
