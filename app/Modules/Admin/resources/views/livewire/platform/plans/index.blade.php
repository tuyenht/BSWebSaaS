@php
    use App\Modules\Admin\Livewire\Platform\Plans\Index;
    use App\Modules\Admin\Support\Plans\PlanCatalog;
    use App\Modules\Admin\Support\Plans\PlanFeatureText;
    use Illuminate\Support\Js;

    $num = fn (int $n): string => PlanFeatureText::number($n);
    $glyph = 'size-[1.15em] shrink-0';
    $card = 'min-w-0 rounded-md border border-border bg-surface shadow-card';
    $h2 = 'm-0 text-[calc(1rem+var(--user-text-boost))] font-semibold leading-snug text-text-strong';
    $sub = 'm-0 text-caption text-text-muted [overflow-wrap:anywhere]';
    $statusPill = ['ok' => 'active', 'off' => 'suspended', 'new' => 'draft'];
    $statusText = ['ok' => __('admin.plans_status_ok'), 'off' => __('admin.plans_status_off'), 'new' => __('admin.plans_status_new')];
    $soldCount = count(array_filter($all, fn (array $p): bool => $p['sold']));
    $summary = function (array $plan) use ($features): string {
        $effective = PlanCatalog::effective($plan['grants']);
        $parts = array_filter(array_map(fn (string $k): ?string => PlanFeatureText::short($k, $effective[$k]), $features));

        return implode(' · ', $parts);
    };
    $empty = 'flex flex-col items-center gap-2 border-t border-border px-4 py-8 text-center text-body text-text-muted';
    $h3 = 'm-0 text-[calc(0.9375rem+var(--user-text-boost))] font-semibold text-text-strong';
@endphp
<div class="flex flex-col gap-4">
    @if (! $canPlans && ! $canTypes)
        <div class="{{ $card }}">
            <div class="flex flex-col items-center gap-2 px-4 py-10 text-center text-body text-text-muted">
                <x-bs-admin::icon name="ri-lock" class="size-7 text-text-muted" />
                <h2 class="{{ $h2 }}">{{ __('admin.plans_noperm_title') }}</h2>
                <p class="m-0 max-w-[60ch]">{{ __('admin.plans_noperm_text', ['role' => $group]) }}</p>
            </div>
        </div>
    @else
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex min-w-0 flex-[1_1_20rem] flex-col gap-1">
                <h1 class="m-0 text-[calc(1.25rem+var(--user-text-boost))] font-semibold leading-snug text-text-strong [overflow-wrap:anywhere]">{{ __('admin.platform_plans') }}</h1>
                <p class="m-0 max-w-[72ch] text-body text-text-muted [text-wrap:pretty]">{{ __('admin.plans_desc') }}</p>
            </div>
            @if ($canPlans)
                <x-bs-admin::button color="primary" :href="route('platform.plans.create')" title="{{ __('admin.plans_add_tip') }}">
                    <x-bs-admin::icon name="ri-add" :class="$glyph" /><span>{{ __('admin.plans_add') }}</span>
                </x-bs-admin::button>
            @endif
        </header>
    @endif

    @if ($canPlans && ! $canTypes)
        <p role="note" class="m-0 flex items-start gap-2 rounded border border-border bg-surface-muted px-3.5 py-2.5 text-body text-text [overflow-wrap:anywhere]">
            <x-bs-admin::icon name="ri-shield-user" class="mt-0.5 size-5 shrink-0 text-text-muted" />
            <span>{{ __('admin.plans_role_strip_billing') }}</span>
        </p>
    @endif

    @if ($canPlans)
        {{-- Lời mời nâng gói: one switch for every website on the platform, and what a website sees either way --}}
        <section class="{{ $card }} flex flex-col gap-4 p-4" aria-labelledby="plans-inv-h">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex min-w-0 flex-[1_1_16rem] flex-col gap-1">
                    <h2 id="plans-inv-h" class="{{ $h2 }}">{{ __('admin.plans_inv_title') }}</h2>
                    <p class="{{ $sub }}">{{ __('admin.plans_inv_sub') }}</p>
                </div>
                <div class="flex items-center gap-2.5">
                    <span class="text-body text-text">{{ $invitation ? __('admin.plans_on') : __('admin.plans_off') }}</span>
                    <button type="button" class="switch hit-area" role="switch" aria-checked="{{ $invitation ? 'true' : 'false' }}"
                            aria-label="{{ __('admin.plans_inv_title') }}" wire:click="toggleInvitation" wire:loading.attr="disabled" wire:target="toggleInvitation"></button>
                </div>
            </div>
            @if ($invitation && ! $plansDiffer)
                <x-bs-admin::alert color="warning" icon="ri-error-warning">{{ __('admin.plans_inv_same') }}</x-bs-admin::alert>
            @endif
            <div class="grid gap-3 min-[720px]:grid-cols-2">
                @foreach ([false, true] as $on)
                    @php $now = $invitation === $on; @endphp
                    <figure @class(['m-0 flex flex-col gap-2 rounded-md border p-3', 'border-primary-text bg-primary-soft' => $now, 'border-border' => ! $now])>
                        <figcaption class="flex flex-wrap items-center gap-2 text-caption font-semibold text-text">
                            {{ $on ? __('admin.plans_inv_when_on') : __('admin.plans_inv_when_off') }}
                            @if ($now)
                                <x-bs-admin::badge :soft="true" color="primary"><x-bs-admin::icon name="ri-check" class="size-3.5" />{{ __('admin.plans_inv_now') }}</x-bs-admin::badge>
                            @endif
                        </figcaption>
                        <div class="flex items-start gap-2.5 rounded border border-border bg-surface p-3" aria-hidden="true">
                            <x-bs-admin::icon name="ri-lock" class="mt-0.5 size-5 shrink-0 text-text-muted" />
                            <div class="flex min-w-0 flex-col gap-1 text-caption">
                                <b class="text-text-strong">{{ PlanFeatureText::name('rbac.custom_roles') }}</b>
                                @if ($on)
                                    <span class="text-text-muted">{{ __('admin.plans_inv_mock_on') }}</span>
                                    <span class="inline-flex items-center gap-1 font-semibold text-primary-text"><x-bs-admin::icon name="ri-arrow-right-up" class="size-4" />{{ __('admin.plans_inv_mock_cta') }}</span>
                                @else
                                    <span class="text-text-muted">{{ __('admin.plans_inv_mock_off') }}</span>
                                @endif
                            </div>
                        </div>
                        <p class="{{ $sub }}">{{ $on ? __('admin.plans_inv_note_on') : __('admin.plans_inv_note_off') }}</p>
                    </figure>
                @endforeach
            </div>
        </section>

        {{-- Các gói --}}
        <section class="{{ $card }} @container/pl" aria-labelledby="plans-list-h">
            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 px-4 pt-4">
                <h2 id="plans-list-h" class="{{ $h2 }}">{{ __('admin.plans_list_title') }}</h2>
                @if ($all !== [])
                    <span class="{{ $sub }}">{{ __('admin.plans_list_count', ['n' => $num(count($all)), 'sold' => $num($soldCount)]) }}</span>
                @endif
            </div>
            <div class="flex flex-wrap items-center gap-3 px-4 pt-3">
                <div class="min-w-0 flex-[1_1_14rem] text-body min-[900px]:max-w-[24em]">
                    <x-bs-admin::search id="plans-search" :label="__('admin.plans_search_label')" :placeholder="__('admin.plans_name_ph')"
                        maxlength="{{ Index::SEARCH_MAX }}" wire:model.live.debounce.300ms="search" />
                </div>
                <div class="flex flex-wrap gap-2" role="group" aria-label="{{ __('admin.plans_filter_label') }}">
                    @foreach (Index::FILTERS as $f)
                        <x-bs-admin::button variant="outline" color="secondary" size="sm" wire:click="$set('filter', {{ Js::from($f) }})" aria-pressed="{{ $filter === $f ? 'true' : 'false' }}">
                            {{ __('admin.plans_filter_'.$f) }} <b>{{ $num($counts[$f] ?? 0) }}</b>
                        </x-bs-admin::button>
                    @endforeach
                </div>
            </div>
            <p class="m-0 flex items-start gap-2 px-4 pt-3 text-caption text-text-muted">
                <x-bs-admin::icon name="ri-information" class="mt-0.5 size-4 shrink-0" />
                <span>{{ $filtering ? __('admin.plans_order_hint_filtering') : __('admin.plans_order_hint') }}</span>
            </p>
            @if ($filtering)
                @php
                    $searchChips = trim($search) === '' ? [] : [[
                        'label' => '“'.trim($search).'”', 'icon' => 'ri-search',
                        'remove' => "\$set('search', '')", 'aria' => __('admin.plans_clear_search', ['q' => trim($search)]),
                    ]];
                @endphp
                <x-bs-admin::filter-status class="px-4 pt-2"
                    :group="$filter === 'all' ? null : __('admin.plans_filter_'.$filter)"
                    :summary="__('admin.plans_matched', ['n' => $num($matched), 'm' => $num(count($all))])"
                    :chips="$searchChips" clear="clearFilters" />
            @endif

            <div class="mt-3">
                <div class="hidden grid-cols-[minmax(10rem,1.3fr)_minmax(9rem,0.9fr)_minmax(8rem,0.9fr)_minmax(10rem,2fr)_auto] gap-3 border-y border-border bg-surface-muted px-4 py-2 text-caption font-semibold text-text-muted @min-[52rem]/pl:grid" aria-hidden="true">
                    <span>{{ __('admin.plans_col_plan') }}</span><span>{{ __('admin.plans_col_sold') }}</span><span>{{ __('admin.plans_col_customers') }}</span><span>{{ __('admin.plans_col_features') }}</span><span class="text-end">{{ __('admin.plans_col_actions') }}</span>
                </div>

                <div wire:loading.delay wire:target="search,filter,per,page,previousPage,nextPage,clearFilters" class="w-full">
                    @foreach (range(1, 4) as $i)
                        <div class="flex flex-col gap-2 border-b border-border px-4 py-4" aria-hidden="true">
                            <div class="h-3.5 w-1/2 animate-pulse rounded bg-surface-muted"></div>
                            <div class="h-3.5 w-3/4 animate-pulse rounded bg-surface-muted"></div>
                        </div>
                    @endforeach
                    <p class="sr-only">{{ __('admin.plans_loading') }}</p>
                </div>

                <div wire:loading.remove.delay wire:target="search,filter,per,page,previousPage,nextPage,clearFilters">
                    @if ($failed)
                        <div class="{{ $empty }}" role="alert">
                            <h3 class="{{ $h3 }}">{{ __('admin.plans_failed_title') }}</h3>
                            <p class="m-0">{{ __('admin.plans_failed_text') }}</p>
                            <x-bs-admin::button color="primary" wire:click="$refresh"><x-bs-admin::icon name="ri-refresh" :class="$glyph" /><span>{{ __('admin.plans_retry') }}</span></x-bs-admin::button>
                        </div>
                    @elseif ($all === [])
                        <div class="{{ $empty }}">
                            <h3 class="{{ $h3 }}">{{ __('admin.plans_empty_title') }}</h3>
                            <p class="m-0 max-w-[60ch]">{{ __('admin.plans_empty_text') }}</p>
                            <x-bs-admin::button color="primary" :href="route('platform.plans.create')"><x-bs-admin::icon name="ri-add" :class="$glyph" /><span>{{ __('admin.plans_add') }}</span></x-bs-admin::button>
                        </div>
                    @elseif ($rows === [])
                        <div class="{{ $empty }}">
                            <h3 class="{{ $h3 }}">{{ __('admin.plans_nomatch_title') }}</h3>
                            <p class="m-0">{{ __('admin.plans_nomatch_text') }}</p>
                            <x-bs-admin::button variant="outline" color="secondary" wire:click="clearFilters">{{ __('admin.plans_clear_filters') }}</x-bs-admin::button>
                        </div>
                    @endif

                    @foreach ($rows as $row)
                        @php
                            $at = array_search($row['key'], $order, true);
                            $first = $at === 0;
                            $last = $at === count($order) - 1;
                        @endphp
                        <div wire:key="plan-{{ $row['key'] }}" class="grid grid-cols-[1fr_auto] items-center gap-x-3 gap-y-2 border-b border-border px-4 py-3 @min-[52rem]/pl:grid-cols-[minmax(10rem,1.3fr)_minmax(9rem,0.9fr)_minmax(8rem,0.9fr)_minmax(10rem,2fr)_auto]">
                            <div class="min-w-0">
                                <div class="font-semibold text-text-strong [overflow-wrap:anywhere]">{{ $row['name'] }}</div>
                                <div class="{{ $sub }}">{{ __('admin.plans_position', ['n' => $row['pos']]) }}</div>
                            </div>
                            <div class="col-start-2 row-start-1 flex items-center gap-2.5 justify-self-end @min-[52rem]/pl:col-start-auto @min-[52rem]/pl:justify-self-start">
                                <button type="button" class="switch hit-area" role="switch" aria-checked="{{ $row['sold'] ? 'true' : 'false' }}"
                                        aria-label="{{ __('admin.plans_sold_aria', ['name' => $row['name']]) }}"
                                        wire:click="toggleSold({{ Js::from($row['key']) }})" wire:loading.attr="disabled" wire:target="toggleSold"></button>
                                <x-bs-admin::status-pill :status="$statusPill[$row['status']]">{{ $statusText[$row['status']] }}</x-bs-admin::status-pill>
                            </div>
                            <div class="col-span-2 text-body text-text @min-[52rem]/pl:col-span-1">{{ $row['customers'] > 0 ? __('admin.plans_customers_n', ['n' => $num($row['customers'])]) : __('admin.plans_customers_none') }}</div>
                            <div class="col-span-2 text-caption text-text-muted [overflow-wrap:anywhere] @min-[52rem]/pl:col-span-1">{{ $summary($row) }}</div>
                            <div class="col-span-2 flex items-center justify-end gap-2 @min-[52rem]/pl:col-span-1">
                                <x-bs-admin::button variant="outline" color="secondary" size="sm" :href="route('platform.plans.edit', ['key' => $row['key']])">
                                    <x-bs-admin::icon name="ri-edit" :class="$glyph" /><span>{{ __('admin.plans_edit') }}</span>
                                </x-bs-admin::button>
                                <x-bs-admin::dropdown align="end">
                                    <x-slot:trigger>
                                        <x-bs-admin::button variant="ghost" color="secondary" size="sm" :icon="true" title="{{ __('admin.plans_more') }}" aria-label="{{ __('admin.plans_more_aria', ['name' => $row['name']]) }}">
                                            <x-bs-admin::icon name="ri-more-2-fill" class="size-5" />
                                        </x-bs-admin::button>
                                    </x-slot:trigger>
                                    <p class="m-0 px-3 text-caption font-semibold text-text-muted">{{ __('admin.plans_menu_title', ['name' => $row['name']]) }}</p>
                                    <button type="button" class="gap-2 px-3 text-start text-text hover:bg-surface-hover" wire:click="duplicate({{ Js::from($row['key']) }})" @click="open = false">
                                        <x-bs-admin::icon name="ri-file-copy" :class="$glyph" />{{ __('admin.plans_duplicate') }}
                                    </button>
                                    <button type="button" class="gap-2 border-t border-border px-3 text-start text-text hover:bg-surface-hover aria-disabled:cursor-not-allowed aria-disabled:text-text-muted"
                                            aria-disabled="{{ $first ? 'true' : 'false' }}" @unless ($first) wire:click="move({{ Js::from($row['key']) }}, -1)" @click="open = false" @endunless>
                                        <x-bs-admin::icon name="ri-arrow-up" :class="$glyph" />{{ __('admin.plans_move_up') }}
                                    </button>
                                    @if ($first)<p class="m-0 px-3 pb-1 text-caption text-text-muted">{{ __('admin.plans_move_up_why') }}</p>@endif
                                    <button type="button" class="gap-2 px-3 text-start text-text hover:bg-surface-hover aria-disabled:cursor-not-allowed aria-disabled:text-text-muted"
                                            aria-disabled="{{ $last ? 'true' : 'false' }}" @unless ($last) wire:click="move({{ Js::from($row['key']) }}, 1)" @click="open = false" @endunless>
                                        <x-bs-admin::icon name="ri-arrow-down" :class="$glyph" />{{ __('admin.plans_move_down') }}
                                    </button>
                                    @if ($last)<p class="m-0 px-3 pb-1 text-caption text-text-muted">{{ __('admin.plans_move_down_why') }}</p>@endif
                                    <p class="m-0 max-w-72 border-t border-border px-3 py-2 text-caption text-text-muted">{{ __('admin.plans_menu_foot') }}</p>
                                </x-bs-admin::dropdown>
                            </div>
                        </div>
                    @endforeach

                    @if ($rows !== [])
                        <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                            <label class="flex items-center gap-2 text-caption text-text-muted">
                                {{ __('admin.plans_per_page') }}
                                <select wire:model.live="per" class="field rounded border border-border-input bg-surface px-2 text-text">
                                    @foreach (Index::PER_PAGE as $n)
                                        <option value="{{ $n }}">{{ $n }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <div class="flex items-center gap-2">
                                <x-bs-admin::button variant="outline" color="secondary" size="sm" wire:click="previousPage" :disabled="$page <= 1">
                                    <x-bs-admin::icon name="ri-arrow-left-s" :class="$glyph" /><span>{{ __('admin.plans_prev') }}</span>
                                </x-bs-admin::button>
                                <span class="text-caption text-text-muted">{{ __('admin.plans_page_of', ['n' => $page, 'm' => $pages]) }}</span>
                                <x-bs-admin::button variant="outline" color="secondary" size="sm" wire:click="nextPage" :disabled="$page >= $pages">
                                    <span>{{ __('admin.plans_next') }}</span><x-bs-admin::icon name="ri-arrow-right-s" :class="$glyph" />
                                </x-bs-admin::button>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </section>
    @endif

    @if ($canTypes)
        {{-- Loại website: read only --}}
        <section id="loai-website" class="flex scroll-mt-4 flex-col gap-3" aria-labelledby="plans-types-h">
            <div class="flex flex-col gap-1">
                <h2 id="plans-types-h" class="m-0 text-[calc(1.25rem+var(--user-text-boost))] font-semibold leading-snug text-text-strong">{{ __('admin.plans_types_title') }}</h2>
                <p class="m-0 max-w-[72ch] text-body text-text-muted">{{ __('admin.plans_types_desc') }}</p>
            </div>
            @if ($failed)
                <div class="{{ $card }}"><div class="{{ $empty }} border-t-0" role="alert">
                    <h3 class="{{ $h3 }}">{{ __('admin.plans_types_failed') }}</h3>
                    <p class="m-0">{{ __('admin.plans_types_failed_text') }}</p>
                    <x-bs-admin::button color="primary" wire:click="$refresh"><x-bs-admin::icon name="ri-refresh" :class="$glyph" /><span>{{ __('admin.plans_retry') }}</span></x-bs-admin::button>
                </div></div>
            @elseif ($types === [])
                <div class="{{ $card }}"><div class="{{ $empty }} border-t-0">
                    <h3 class="{{ $h3 }}">{{ __('admin.plans_types_empty') }}</h3>
                    <p class="m-0">{{ __('admin.plans_types_empty_text') }}</p>
                </div></div>
            @endif
            @foreach ($types as $type)
                @php $typeName = $type['labels'][app()->getLocale()] ?? $type['labels']['vi'] ?? $type['key']; @endphp
                <article class="{{ $card }} flex flex-col gap-3 p-4" aria-label="{{ $typeName }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="flex min-w-0 flex-col gap-1">
                            <h3 class="{{ $h3 }}">{{ $typeName }}</h3>
                            <p class="{{ $sub }}">{{ $type['sites'] > 0 ? __('admin.plans_types_sites', ['n' => $num($type['sites'])]) : __('admin.plans_types_no_sites') }}</p>
                        </div>
                        <x-bs-admin::badge :soft="true" color="primary"><x-bs-admin::icon name="ri-eye" class="size-3.5" />{{ __('admin.plans_types_read_only') }}</x-bs-admin::badge>
                    </div>
                    <dl class="m-0 grid gap-3 text-body min-[720px]:grid-cols-[12rem_1fr]">
                        <dt class="font-semibold text-text">{{ __('admin.plans_types_labels') }}</dt>
                        <dd class="m-0">
                            <ul class="m-0 flex list-none flex-col gap-1 p-0">
                                @foreach ($type['labels'] as $code => $text)
                                    <li class="flex flex-wrap gap-2"><span class="min-w-24 text-caption text-text-muted">{{ config('locales.labels.'.$code, strtoupper((string) $code)) }}</span><span lang="{{ $code }}">{{ $text }}</span></li>
                                @endforeach
                            </ul>
                        </dd>
                        <dt class="font-semibold text-text">{{ __('admin.plans_types_modules') }}</dt>
                        <dd class="m-0 flex flex-wrap gap-1.5">
                            @foreach ($type['modules'] as $module)
                                <x-bs-admin::badge :soft="true" color="success"><x-bs-admin::icon name="ri-checkbox-circle" class="size-3.5" />{{ $module }}</x-bs-admin::badge>
                            @endforeach
                        </dd>
                        <dt class="font-semibold text-text">{{ __('admin.plans_types_roles') }}</dt>
                        <dd class="m-0 flex flex-wrap gap-1.5">
                            @foreach ($type['roles'] as $role)
                                <x-bs-admin::badge :soft="true" color="primary"><x-bs-admin::icon name="ri-group" class="size-3.5" />{{ $role }}</x-bs-admin::badge>
                            @endforeach
                        </dd>
                        <dt class="font-semibold text-text">{{ __('admin.plans_types_theme') }}</dt>
                        <dd class="m-0 flex flex-wrap items-center gap-2">
                            <x-bs-admin::badge :soft="true" color="warning"><x-bs-admin::icon name="ri-time" class="size-3.5" />{{ __('admin.plans_types_later') }}</x-bs-admin::badge>
                            <span class="{{ $sub }}">{{ __('admin.plans_types_theme_note') }}</span>
                        </dd>
                    </dl>
                </article>
            @endforeach
        </section>
    @endif

    <x-bs-admin::toast :message="$toast" :tone="$toastTone" dismiss="dismissToast" :seq="$toastSeq" :undo="$undoAction !== null ? 'undo' : null" />
</div>
