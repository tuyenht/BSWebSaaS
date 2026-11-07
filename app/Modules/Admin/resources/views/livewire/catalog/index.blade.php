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
    $cardLabels = ['sku' => __('catalog.card_opt_sku'), 'cat' => __('catalog.card_opt_cat')];
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
                 size() { const w = this.$refs.rows?.clientWidth ?? 0, rem = parseFloat(getComputedStyle(document.documentElement).fontSize) || 16; this.mode = w < 37.5 * rem ? 'card' : (w < 56.25 * rem ? 'line' : 'cols'); },
                 show(k) { return this.mode === 'line' || (this.mode === 'cols' ? this.$wire.cols : this.$wire.card).includes(k); },
                 get vSku() { return this.mode !== 'card' || this.$wire.card.includes('sku'); },
                 get vCat() { return this.show('cat'); },
             }"
             x-init="size(); new ResizeObserver(() => size()).observe($el)">
            <div class="flex flex-col gap-3 pb-3 min-[600px]:px-4 min-[600px]:pt-4 min-[900px]:pt-3 @min-[56.25rem]/lt:flex-row @min-[56.25rem]/lt:flex-wrap @min-[56.25rem]/lt:items-center">
                <div class="min-w-0 text-body @min-[56.25rem]/lt:min-w-[19em] @min-[56.25rem]/lt:max-w-[30em] @min-[56.25rem]/lt:flex-[999_1_19em] @min-[56.25rem]/lt:[&:has(+[data-below='1'])]:max-w-none">
                    <x-bs-admin::search id="catalog-search" :label="__('catalog.search_label')" :placeholder="__('catalog.search_hint')"
                        :clear-label="__('catalog.search_clear')" clear-action="$set('search', '')" :clear-on="trim($search) !== ''" wire:model.live.debounce.300ms="search" />
                </div>
                <x-bs-admin::tool-row class="@min-[56.25rem]/lt:min-w-0 @min-[56.25rem]/lt:flex-[1_1_auto] @min-[56.25rem]/lt:flex-wrap @min-[56.25rem]/lt:gap-3">
                    <x-bs-admin::filter-panel class="ms-auto flex-nowrap @min-[56.25rem]/lt:ms-0 @min-[56.25rem]/lt:min-w-0 @min-[56.25rem]/lt:shrink @min-[56.25rem]/lt:flex-wrap" :sections="$sections"
                        :active="$filterCount" :active-narrow="$filterCountNarrow" clear="clearAllFilters" :results="__('catalog.show_n', ['n' => $fmt($matchCount), 'unit' => $noun])" />
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
            <x-bs-admin::list :label="__('catalog.list_label')">

                <x-bs-admin::list.head>
                    <x-bs-admin::list.cell head col="main" :sort="$ariaSort('name')">
                        <x-bs-admin::list.sort :active="$sortOn === 'name'" dir="asc" wire:click="sortBy('name')">{{ __('catalog.col_product') }}</x-bs-admin::list.sort>
                    </x-bs-admin::list.cell>
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
                        $categoryName = $product->primaryCategory()?->translations->firstWhere('locale', $locale)?->name;
                        $trashed = $product->trashed();
                        $when = $siteTime->forDisplay($product->updated_at);
                        $whenText = $when ? __('catalog.updated_at', ['date' => $when->locale(app()->getLocale())->isoFormat('D MMM, Y')]) : null;
                    @endphp
                    <x-bs-admin::list.row wire:key="product-{{ $product->ulid }}">
                        <x-bs-admin::list.cell col="main">
                            <div class="flex items-start gap-3">
                                @if ($thumbnail !== null)
                                    <img src="{{ $urls->url($thumbnail->storage_key, 96) }}" alt="" loading="lazy" class="size-12 shrink-0 rounded border border-border object-cover">
                                @else
                                    <span class="flex size-12 shrink-0 items-center justify-center rounded border border-border bg-surface-muted text-placeholder" aria-hidden="true">
                                        <x-bs-admin::icon name="image" class="size-5" />
                                    </span>
                                @endif
                                <div class="min-w-0 flex-1">
                                    <x-bs-admin::list.name :href="$canManage && ! $trashed ? route('admin.catalog.edit', ['ulid' => $product->ulid]) : null">{{ $name }}</x-bs-admin::list.name>
                                    <x-bs-admin::list.meta>
                                        @if ($product->sku !== null)
                                            <span class="list-m" data-for="sku" x-show="vSku">{{ __('catalog.sku_meta', ['sku' => $product->sku]) }}</span>
                                        @endif
                                        @if ($categoryName !== null)
                                            <span class="list-m" data-for="cat" x-show="vCat">{{ $categoryName }}</span>
                                        @endif
                                        @if ($whenText)
                                            <span class="list-m" data-for="when">{{ $whenText }}</span>
                                        @endif
                                    </x-bs-admin::list.meta>
                                </div>
                            </div>
                        </x-bs-admin::list.cell>

                        <x-bs-admin::list.cell col="st">
                            <div class="list-stw">
                                <x-bs-admin::status-pill :status="$product->status">{{ $statusText($product->status) }}</x-bs-admin::status-pill>
                            </div>
                            @if ($whenText)
                                <div class="list-st-meta" title="{{ $when->locale(app()->getLocale())->isoFormat('LLL') }}">
                                    <x-bs-admin::icon name="ri-history" class="size-3 shrink-0" />
                                    <span>{{ $whenText }}</span>
                                </div>
                            @endif
                        </x-bs-admin::list.cell>

                        <x-bs-admin::list.cell col="act">
                            @if ($canManage)
                                <x-bs-admin::list.actions :name="$name">
                                    @if ($trashed)
                                        <x-bs-admin::button size="sm" variant="soft" color="primary" wire:click="restore('{{ $product->ulid }}')">
                                            <x-bs-admin::icon name="ri-arrow-go-back" class="size-4" /> {{ __('catalog.restore_action') }}
                                        </x-bs-admin::button>
                                        <x-slot:menu>
                                            <x-bs-admin::list.menu-item icon="ri-delete-bin" danger
                                                x-on:click="$bsConfirm({ icon: 'trash-danger', text: {{ Js::from(__('catalog.force_delete_confirm')) }}, confirm: {{ Js::from(__('catalog.force_delete_action')) }}, cancel: {{ Js::from(__('admin.cancel')) }} }).then(ok => ok && $wire.forceDelete({{ Js::from($product->ulid) }}))">{{ __('catalog.force_delete_action') }}</x-bs-admin::list.menu-item>
                                        </x-slot:menu>
                                    @else
                                        <x-bs-admin::button size="sm" variant="soft" color="primary" :href="route('admin.catalog.edit', ['ulid' => $product->ulid])"
                                            :aria-label="__('catalog.edit_named', ['name' => $name])">
                                            <x-bs-admin::icon name="ri-pencil" class="size-4" /> {{ __('catalog.edit_action') }}
                                        </x-bs-admin::button>
                                        <x-slot:menu>
                                            <x-bs-admin::list.menu-item icon="ri-delete-bin" danger
                                                x-on:click="$bsConfirm({ icon: 'trash', text: {{ Js::from(__('catalog.trash_confirm')) }}, confirm: {{ Js::from(__('catalog.trash_action')) }}, cancel: {{ Js::from(__('admin.cancel')) }} }).then(ok => ok && $wire.trash({{ Js::from($product->ulid) }}))">{{ __('catalog.trash_action') }}</x-bs-admin::list.menu-item>
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
</div>
