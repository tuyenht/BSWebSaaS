@php
    use App\Modules\Admin\Livewire\Platform\Tenants\Index;
    use App\Modules\Admin\Support\Customers\CustomerDirectory;
    use Illuminate\Support\Js;
    use Illuminate\Support\Number;

    $num = fn (int $n): string => Number::format($n, locale: app()->getLocale());
    $plansById = $planList->keyBy('id');
    $planName = function (?int $id) use ($plansById): string {
        $plan = $id === null ? null : $plansById->get($id);
        if ($plan === null) {
            return __('admin.customers_no_plan');
        }

        return $plan->is_active ? $plan->name : __('admin.customers_plan_legacy', ['plan' => $plan->name]);
    };
    $joined = fn ($at): string => $siteTime->forDisplay($at)?->locale(app()->getLocale())->isoFormat('L') ?? '—';
    // An email may break after "@" and "." only, never inside a word
    $email = fn (string $e): string => str_replace(['@', '.'], ['@<wbr>', '.<wbr>'], e($e));

    $groups = [];
    foreach (Index::TABS as $key) {
        $groups[] = ['key' => $key, 'label' => __('admin.customers_'.($key === 'all' ? 'group_all' : 'status_'.$key)), 'count' => $counts[$key] ?? 0];
    }
    $groupLabel = $tab === 'all' ? null : (collect($groups)->firstWhere('key', $tab)['label'] ?? null);

    $colLabels = ['plan' => __('admin.customers_col_plan'), 'sites' => __('admin.customers_col_sites'), 'joined' => __('admin.customers_col_joined')];
    $show = fn (string $k): bool => in_array($k, $cols, true);
    $planOptions = $planList->map(fn ($p): array => [
        'value' => $p->key,
        'label' => $p->is_active ? $p->name : __('admin.customers_plan_legacy', ['plan' => $p->name]),
        'count' => $planCounts[$p->id] ?? 0,
    ])->values()->all();

    $sections = [
        ['key' => 'status', 'label' => __('admin.customers_filter_status'), 'icon' => 'ri-flag', 'model' => 'tab', 'value' => $tab, 'default' => 'all',
            'sheet' => true, 'class' => 'min-[900px]:hidden',
            'options' => array_map(fn (array $g): array => ['value' => $g['key'], 'label' => $g['label'], 'count' => $g['count']], $groups)],
    ];
    if ($planOptions !== []) {
        $sections[] = ['key' => 'plan', 'label' => __('admin.customers_filter_plan'), 'icon' => 'ri-price-tag-3', 'model' => 'plans', 'multiple' => true, 'value' => $plans, 'options' => $planOptions];
    }
    // a view choice, not a filter: there exactly while the rows are cards
    $sections[] = ['key' => 'card', 'label' => __('admin.customers_filter_on_card'), 'model' => 'cols', 'multiple' => true, 'value' => $cols, 'sheet' => true,
        'class' => '@min-[37.5rem]/kh:hidden', 'on' => false,
        'options' => array_map(fn (string $k, string $l): array => ['value' => $k, 'label' => $l], array_keys($colLabels), $colLabels)];

    $chosenPlans = array_values(array_filter($plans, fn (string $k): bool => $planList->contains('key', $k)));
    $q = trim($search);
    $narrowed = $q !== '' || $chosenPlans !== [];
    // remove calls are fixed method names with Js::from() arguments, never raw input (filter-status contract)
    $chips = [];
    if ($q !== '') {
        $chips[] = ['label' => __('admin.customers_filter_search_chip', ['q' => $q]), 'remove' => 'clearSearch'];
    }
    foreach ($chosenPlans as $key) {
        $p = $planList->firstWhere('key', $key);
        $chips[] = ['label' => __('admin.customers_filter_plan_chip', ['plan' => $p->name]), 'remove' => 'removePlan('.Js::from($key).')'];
    }
    $matched = $counts[$tab] ?? 0;
    $summary = $narrowed && $baseCounts !== null
        ? __('admin.customers_filter_summary_of', ['n' => $num($matched), 'm' => $num($baseCounts[$tab] ?? 0)])
        : __('admin.customers_filter_summary', ['n' => $num($matched)]);
    $filtering = $tab !== 'all' || $narrowed;
    $hiddenCols = count(Index::COLS) - count($cols);
@endphp
<div class="flex flex-col gap-4">
    <header class="flex flex-col gap-1">
        <h1 class="m-0 text-[calc(1.25rem+var(--user-text-boost))] font-semibold leading-snug text-text-strong [overflow-wrap:anywhere]">{{ __('admin.platform_tenants') }}</h1>
        <p class="m-0 max-w-[72ch] text-body text-text-muted [text-wrap:pretty]">{{ __('admin.customers_desc') }}</p>
    </header>

    @if ($roleStrip !== null)
        <p role="note" class="m-0 flex items-start gap-2 rounded border border-border bg-surface-muted px-3.5 py-2.5 text-body text-text [overflow-wrap:anywhere]">
            <x-bs-admin::icon name="ri-shield-user" class="mt-0.5 size-5 shrink-0 text-text-muted" />
            <span>{{ $roleStrip }}</span>
        </p>
    @endif

    {{-- The list card: groups (from 900) · search + tools · the active-filter line · the rows · the pager. Below 600
         the card frame moves onto the rows, so the tools stand on the page and the first record comes up sooner. --}}
    <div class="@container/kh min-w-0 min-[600px]:rounded-md min-[600px]:border min-[600px]:border-border min-[600px]:bg-surface min-[600px]:shadow-card">
        <x-bs-admin::group-row :groups="$groups" :active="$tab" model="tab" :label="__('admin.customers_groups_label')" />

        <div class="flex flex-wrap items-center gap-3 pb-3 min-[600px]:px-4 min-[600px]:pt-4 min-[900px]:pt-3">
            <div class="min-w-0 flex-[1_1_12rem] text-body min-[900px]:max-w-[30em]">
                <x-bs-admin::search id="customers-search" :label="__('admin.customers_search_label')" :placeholder="__('admin.customers_search_placeholder')"
                    maxlength="{{ CustomerDirectory::SEARCH_MAX }}" wire:model.live.debounce.300ms="search" />
            </div>
            <x-bs-admin::filter-panel class="shrink-0 flex-nowrap min-[900px]:ms-auto" :sections="$sections" :label="__('admin.list.filter')"
                :active="count($chosenPlans)" :active-narrow="count($chosenPlans) + ($tab !== 'all' ? 1 : 0)"
                :results="__('admin.customers_filter_see', ['n' => $num($matched)])" clear="clearFilters" />
            {{-- Cột (from 900): Khách hàng and Trạng thái always show. Opening focuses the first choice; Esc puts focus back. --}}
            <div class="relative hidden shrink-0 min-[900px]:block"
                 x-data="{
                     open: false,
                     show() { this.open = true; this.whenShown(() => this.$refs.colsPanel.querySelector('input:not([disabled])')?.focus()); },
                     hide(back = true) { if (! this.open) return; this.open = false; if (back) this.$refs.cols.focus(); },
                     whenShown(fn, tries = 20) { this.$refs.colsPanel.getClientRects().length || ! tries ? fn() : setTimeout(() => this.whenShown(fn, tries - 1), 16); },
                 }"
                 @click.outside="hide(false)" @keydown.escape.prevent.stop="hide()"
                 @focusout="if ($event.relatedTarget && ! $root.contains($event.relatedTarget)) hide(false)">
                <button type="button" x-ref="cols" @click="open ? hide() : show()" :aria-expanded="open ? 'true' : 'false'" aria-controls="customers-cols"
                    class="hit-area inline-flex min-h-control items-center gap-1.5 rounded-full border border-transparent bg-primary/7 px-3.5 text-body font-medium text-text transition-colors hover:bg-primary/12">
                    <x-bs-admin::icon name="ri-layout-column" class="size-4 text-text-muted" />
                    {{ $hiddenCols > 0 ? __('admin.customers_columns_hidden', ['n' => $hiddenCols]) : __('admin.customers_columns') }}
                    <x-bs-admin::icon name="chevron-down" class="size-4 text-text-muted transition-transform" ::class="open && 'rotate-180'" />
                </button>
                <div x-show="open" x-cloak x-transition x-ref="colsPanel" id="customers-cols" role="group" aria-label="{{ __('admin.customers_columns_title') }}"
                    class="absolute end-0 top-full z-60 mt-1.5 w-60 rounded-md border border-border bg-surface p-1.5 shadow-lg">
                    <p class="m-0 px-2 pb-1 pt-1.5 text-caption font-semibold text-text-muted">{{ __('admin.customers_columns_required') }}</p>
                    @foreach ([__('admin.customers_col_customer'), __('admin.customers_col_status')] as $locked)
                        <label class="flex min-h-menu-row cursor-not-allowed items-center gap-2.5 rounded px-2 text-body text-text-muted any-pointer-coarse:min-h-menu-row-touch">
                            <input type="checkbox" checked disabled class="size-4 rounded border-border-strong">
                            {{ $locked }}
                        </label>
                    @endforeach
                    <p class="m-0 px-2 pb-1 pt-2 text-caption font-semibold text-text-muted">{{ __('admin.customers_columns_optional') }}</p>
                    @foreach ($colLabels as $key => $label)
                        <label class="flex min-h-menu-row cursor-pointer items-center gap-2.5 rounded px-2 text-body text-text hover:bg-surface-hover any-pointer-coarse:min-h-menu-row-touch">
                            <input type="checkbox" value="{{ $key }}" wire:model.live="cols" class="size-4 shrink-0 accent-primary-text">
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Below 900: "Tạm ngưng · N trong M khách hàng · Bỏ lọc" + chips. From 900 the groups say the status, so the
             line only lists the search and the plans. --}}
        <x-bs-admin::filter-status class="-mx-3 -mt-1 mb-2 min-[600px]:mx-1 min-[900px]:hidden" :group="$groupLabel"
            :summary="$filtering ? $summary : null" :chips="$chips" :clear="$filtering ? 'clearFilters' : null" />
        @if ($narrowed)
            <x-bs-admin::filter-status class="-mt-1 mb-2 min-[600px]:mx-1 max-[899.98px]:hidden" :summary="__('admin.customers_filtering')"
                :chips="$chips" clear="clearFilters" />
        @endif

        <p wire:loading.delay class="sr-only" role="status">{{ __('admin.customers_loading') }}</p>

        <div class="min-w-0 transition-opacity max-[599.98px]:rounded-md max-[599.98px]:border max-[599.98px]:border-border max-[599.98px]:bg-surface max-[599.98px]:[&_.list-g]:border-t-0"
             wire:loading.class="opacity-60" wire:target="search,tab,plans,per,older,newer,clearFilters,clearPlans,removePlan,clearSearch"
             x-data="{ online: navigator.onLine }" x-on:online.window="online = true" x-on:offline.window="online = false">
            @if ($failed)
                <div class="flex flex-col items-start gap-3 px-4 py-5" role="alert">
                    <h2 class="m-0 text-[calc(1rem+var(--user-text-boost))] font-semibold text-text-strong">
                        <span x-show="online">{{ __('admin.customers_error_title') }}</span>
                        <span x-show="! online" x-cloak>{{ __('admin.customers_offline_title') }}</span>
                    </h2>
                    <p class="m-0 max-w-[62ch]">{{ __('admin.customers_error_body') }}</p>
                    <x-bs-admin::button wire:click="$refresh"><x-bs-admin::icon name="ri-refresh" class="size-4" />{{ __('admin.customers_retry') }}</x-bs-admin::button>
                </div>
            @else
                <x-bs-admin::list kind="facts" :label="__('admin.customers_list_label')">
                    @if ($rows->isNotEmpty())
                        <x-bs-admin::list.head>
                            <x-bs-admin::list.cell head col="main">{{ __('admin.customers_col_customer') }}</x-bs-admin::list.cell>
                            @if ($show('plan'))<x-bs-admin::list.cell head col="f1">{{ __('admin.customers_col_plan') }}</x-bs-admin::list.cell>@endif
                            @if ($show('sites'))<x-bs-admin::list.cell head col="f2">{{ __('admin.customers_col_sites') }}</x-bs-admin::list.cell>@endif
                            @if ($show('joined'))<x-bs-admin::list.cell head col="f3">{{ __('admin.customers_col_joined') }}</x-bs-admin::list.cell>@endif
                            <x-bs-admin::list.cell head col="st">{{ __('admin.customers_col_status') }}</x-bs-admin::list.cell>
                            <x-bs-admin::list.cell head col="act">{{ __('admin.actions') }}</x-bs-admin::list.cell>
                        </x-bs-admin::list.head>
                    @endif

                    @forelse ($rows as $t)
                        @php
                            $href = route('platform.tenants.show', $t->ulid);
                            $statusLabel = __('admin.customers_status_'.$t->status);
                            $sitesText = __('admin.customers_sites_n', ['n' => $num((int) $t->sites_count)]);
                            $owner = trim((string) $t->owner_name);
                            $ownerEmail = trim((string) $t->owner_email);
                        @endphp
                        <x-bs-admin::list.row wire:key="kh-{{ $t->ulid }}">
                            <x-bs-admin::list.cell col="main">
                                <x-bs-admin::list.name :href="$href">{{ $t->name }}</x-bs-admin::list.name>
                                @if ($owner !== '' || $ownerEmail !== '')
                                    <p class="list-sub m-0">{{ $owner }}@if ($owner !== '' && $ownerEmail !== '') · @endif<span class="list-sub-em">{!! $email($ownerEmail) !!}</span></p>
                                @endif
                                <p class="list-line m-0">
                                    <x-bs-admin::status-pill :status="$t->status">{{ $statusLabel }}</x-bs-admin::status-pill>
                                    @if ($show('plan'))<span>{{ $planName($t->plan_id) }}</span>@endif
                                    @if ($show('sites'))<span>{{ $sitesText }}</span>@endif
                                    @if ($show('joined'))<span>{{ $joined($t->created_at) }}</span>@endif
                                </p>
                            </x-bs-admin::list.cell>
                            @if ($show('plan'))<x-bs-admin::list.cell col="f1">{{ $planName($t->plan_id) }}</x-bs-admin::list.cell>@endif
                            @if ($show('sites'))<x-bs-admin::list.cell col="f2">{{ $sitesText }}</x-bs-admin::list.cell>@endif
                            @if ($show('joined'))<x-bs-admin::list.cell col="f3">{{ $joined($t->created_at) }}</x-bs-admin::list.cell>@endif
                            <x-bs-admin::list.cell col="st">
                                <x-bs-admin::status-pill :status="$t->status">{{ $statusLabel }}</x-bs-admin::status-pill>
                            </x-bs-admin::list.cell>
                            <x-bs-admin::list.cell col="act">
                                <x-bs-admin::list.actions :name="$t->name">
                                    <x-bs-admin::button size="sm" variant="soft" color="primary" :href="$href" data-row-btn
                                        :aria-label="__('admin.view').' '.$t->name">
                                        <x-bs-admin::icon name="ri-eye" class="size-4" /> {{ __('admin.view') }}
                                    </x-bs-admin::button>
                                    <x-slot:menu>
                                        <p class="list-mh m-0" aria-hidden="true">{{ __('admin.customers_row_menu_title', ['name' => $t->name]) }}</p>
                                        <x-bs-admin::list.menu-item icon="ri-eye" :href="$href" wire:navigate>{{ __('admin.view') }}</x-bs-admin::list.menu-item>
                                        @if ($canChangePlan)
                                            <x-bs-admin::list.menu-item icon="ri-exchange" x-on:click="$dispatch('open-change-plan', { ulid: {{ Js::from($t->ulid) }} })">
                                                {{ __('admin.customers_action_change_plan') }}
                                            </x-bs-admin::list.menu-item>
                                        @else
                                            {{-- locked, never hidden: the item says why, inside itself (a menu holds only items) --}}
                                            <x-bs-admin::list.menu-item icon="ri-lock" aria-disabled="true" class="list-mi-locked">
                                                {{ __('admin.customers_action_change_plan') }}<span class="list-mwhy">{{ $lockedReason }}</span>
                                            </x-bs-admin::list.menu-item>
                                        @endif
                                    </x-slot:menu>
                                </x-bs-admin::list.actions>
                            </x-bs-admin::list.cell>
                        </x-bs-admin::list.row>
                    @empty
                        <x-bs-admin::list.row>
                            <x-bs-admin::list.cell col="full">
                                <div class="flex flex-col items-start gap-3 px-4 py-6">
                                    @if ($filtering)
                                        <h2 class="m-0 text-[calc(1rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('admin.customers_nomatch_title') }}</h2>
                                        <p class="m-0 max-w-[62ch] text-body text-text-muted">{{ __('admin.customers_nomatch_body') }}</p>
                                        <x-bs-admin::button variant="outline" color="primary" wire:click="clearFilters">{{ __('admin.list.clear_filters') }}</x-bs-admin::button>
                                    @else
                                        <h2 class="m-0 text-[calc(1rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('admin.customers_empty_title') }}</h2>
                                        <p class="m-0 max-w-[62ch] text-body text-text-muted">{{ __('admin.customers_empty_body') }}</p>
                                    @endif
                                </div>
                            </x-bs-admin::list.cell>
                        </x-bs-admin::list.row>
                    @endforelse
                </x-bs-admin::list>

                @if ($rows->isNotEmpty())
                    <x-bs-admin::list.pager :older="$older" :newer="$newer" :sizes="CustomerDirectory::PER_PAGE" model="per"
                        :label="__('admin.customers_pager_label')" :per-label="__('admin.customers_per_page')"
                        :prev="__('admin.customers_prev')" :next="__('admin.customers_next')" />
                @endif
            @endif
        </div>
    </div>

    <livewire:admin.platform.tenants.change-plan-dialog />
</div>
