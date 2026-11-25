{{-- «Khối dùng chung» (board 28): the explainer, the «Quay lại …» band when the member came from
     another screen, the status groups and region chips (from 900px; below that both live in the Filter
     sheet, with a summary line under the toolbar), the search, then one row per block. Region icons
     and tints come from the region (the board's per-block colours are mock data). --}}
@php
    use App\Modules\Admin\Livewire\Blocks\Library;
    use App\Modules\Admin\Support\Locales;
    use App\Modules\Cms\Models\ContentBlock;
    use Illuminate\Support\Js;
    use Illuminate\Support\Number;

    $num = fn (int $n): string => Number::format($n, locale: app()->getLocale());
    $regionIcon = ['footer' => 'ri-layout-bottom', 'header' => 'ri-layout-top', 'sidebar' => 'ri-layout-right', 'inline' => 'ri-insert-column-right', 'menu' => 'ri-node-tree'];
    $regionTint = ['footer' => 'bg-primary-soft text-primary-text', 'header' => 'bg-success-soft text-success-text', 'sidebar' => 'bg-info-soft text-info-text', 'inline' => 'bg-warning-soft text-warning-text', 'menu' => 'bg-surface-muted text-text-muted'];
    $regionLabel = fn (string $r): string => __('blocks.region.'.$r);

    $groups = [];
    foreach (Library::TABS as $key) {
        $groups[] = ['key' => $key, 'label' => __('blocks.tab_'.$key), 'count' => $tabCounts[$key] ?? 0];
    }
    $regionOptions = [['value' => 'all', 'label' => __('blocks.region_all'), 'count' => $regionCounts['all'] ?? 0]];
    foreach (ContentBlock::REGIONS as $r) {
        $regionOptions[] = ['value' => $r, 'label' => $regionLabel($r), 'count' => $regionCounts[$r] ?? 0];
    }
    $sections = [
        ['key' => 'status', 'label' => __('blocks.filter_status'), 'icon' => 'ri-flag', 'model' => 'tab', 'value' => $tab, 'default' => 'all',
            'sheet' => true, 'class' => 'min-[900px]:hidden',
            'options' => array_map(fn (array $g): array => ['value' => $g['key'], 'label' => $g['label'], 'count' => $g['count']], $groups)],
        ['key' => 'region', 'label' => __('blocks.filter_region'), 'icon' => 'ri-layout-masonry', 'model' => 'region', 'value' => $region, 'default' => 'all',
            'sheet' => true, 'class' => 'min-[900px]:hidden', 'options' => $regionOptions],
    ];
    $narrowActive = ($tab !== 'all' ? 1 : 0) + ($region !== 'all' ? 1 : 0);
    $q = trim($search);
    $chips = $q !== '' ? [['label' => __('blocks.chip_search', ['q' => $q]), 'remove' => 'clearSearch']] : [];
    $line = implode(' · ', array_filter([
        $tab !== 'all' ? __('blocks.tab_'.$tab) : null,
        $region !== 'all' ? $regionLabel($region) : null,
        trans_choice('blocks.n_blocks', $matched, ['n' => $num($matched)]),
    ]));
    $multi = count($locales) > 1;
    // the copy of a reference code is the browser's: a toast says it worked, or how to copy it by hand
    $warn = \Illuminate\Support\Facades\Blade::render('<x-bs-admin::icon name="ri-error-warning" class="size-4" />');
    $copy = fn (string $key): string => 'navigator.clipboard ? navigator.clipboard.writeText('.Js::from($key).').then(() => window.bsToast?.({ text: '.Js::from(__('blocks.toast_copied', ['key' => $key])).' }), () => window.bsToast?.({ text: '.Js::from(__('blocks.toast_copy_failed', ['key' => $key])).', icon: '.Js::from($warn).' })) : window.bsToast?.({ text: '.Js::from(__('blocks.toast_copy_failed', ['key' => $key])).', icon: '.Js::from($warn).' })';
@endphp
<div class="flex flex-col gap-4">
    <header class="flex flex-col gap-1">
        <x-bs-admin::title-row :title="__('blocks.title')">
            <x-bs-admin::button color="primary" class="shrink-0" wire:click="openAdd" aria-haspopup="dialog"
                :label="__('blocks.add')" :short="__('admin.list.add_short')">
                <x-bs-admin::icon name="ri-add" class="size-4" />
            </x-bs-admin::button>
        </x-bs-admin::title-row>
    </header>

    <x-bs-admin::alert color="info" role="note">
        {!! __('blocks.explainer', ['once' => '<b>'.e(__('blocks.explainer_once')).'</b>', 'many' => '<b>'.e(__('blocks.explainer_many')).'</b>', 'langs' => '<b>'.e(__('blocks.explainer_langs')).'</b>']) !!}
    </x-bs-admin::alert>

    @if ($backUrl !== null)
        <div class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-md border border-border bg-surface px-3.5 py-2.5">
            <x-bs-admin::icon name="ri-arrow-left" class="size-5 shrink-0 text-primary-text" />
            <span class="min-w-0 flex-[1_1_14rem] text-small text-text-muted">{{ __('blocks.back_band_'.$back) }}</span>
            <x-bs-admin::button size="sm" variant="soft" color="primary" :href="$backUrl" wire:navigate>
                <x-bs-admin::icon name="ri-arrow-left" class="size-4" /> {{ __('blocks.back_to_'.$back) }}
            </x-bs-admin::button>
        </div>
    @endif

    @if ($failed)
        <x-bs-admin::card flush role="alert" data-state="error" class="min-w-0 p-6 text-center">
            <x-bs-admin::icon name="ri-wifi-off" class="mx-auto size-8 text-text-muted" />
            <h2 class="m-0 mt-2 text-[calc(1.125rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('blocks.error_title') }}</h2>
            <p class="m-0 mt-1 text-body text-text-muted">{{ __('blocks.error_text') }}</p>
            <x-bs-admin::button color="primary" class="mt-4" wire:click="$refresh">
                <x-bs-admin::icon name="ri-refresh" class="size-4" /> {{ __('blocks.retry') }}
            </x-bs-admin::button>
        </x-bs-admin::card>
    @else
        <section aria-label="{{ __('blocks.list_label') }}" class="@container/bl min-w-0 min-[600px]:rounded-md min-[600px]:border min-[600px]:border-border min-[600px]:bg-surface min-[600px]:shadow-card">
            <x-bs-admin::group-row :groups="$groups" :active="$tab" model="tab" :label="__('blocks.groups_label')" />

            <div class="flex flex-wrap items-center gap-3 pb-3 min-[600px]:px-4 min-[600px]:pt-4 min-[900px]:pt-3">
                <div class="min-w-0 flex-[1_1_12rem] text-body min-[900px]:max-w-[30em]">
                    <x-bs-admin::search id="blocks-search" :label="__('blocks.search_label')" :placeholder="__('blocks.search_placeholder')"
                        maxlength="100" wire:model.live.debounce.300ms="search" />
                </div>
                <x-bs-admin::filter-panel class="shrink-0 flex-nowrap min-[900px]:ms-auto" :sections="$sections" :label="__('admin.list.filter')"
                    :active="0" :active-narrow="$narrowActive"
                    :results="__('blocks.filter_see', ['n' => $num($matched)])" clear="clearFilters" clear-closes />
            </div>

            {{-- below 900: what the sheet is set to, in words, with «Bỏ lọc» --}}
            <x-bs-admin::filter-status class="-mx-3 -mt-1 mb-2 min-[600px]:mx-1 min-[900px]:hidden"
                :summary="$narrowActive > 0 || $q !== '' ? $line : null" :chips="$chips" :clear="$narrowActive > 0 || $q !== '' ? 'clearFilters' : null" />

            {{-- the region chips (from 900; below that the region is a section of the Filter sheet) --}}
            <div class="hidden flex-wrap items-center gap-2 pb-3 min-[600px]:px-4 min-[900px]:flex" role="group" aria-label="{{ __('blocks.filter_region') }}">
                @foreach ([['all', 'ri-apps-2'], ...array_map(fn (string $r): array => [$r, $regionIcon[$r]], ContentBlock::REGIONS)] as [$key, $icon])
                    @php $on = $region === $key; @endphp
                    <button type="button" wire:click="$set('region', {{ Js::from($key) }})" aria-pressed="{{ $on ? 'true' : 'false' }}"
                        @class([
                            'hit-area inline-flex min-h-control-row items-center gap-1.5 rounded-full border px-3 text-small font-semibold whitespace-nowrap transition-colors',
                            'border-primary-text bg-primary-soft text-primary-text' => $on,
                            'border-border bg-surface text-text-muted hover:bg-surface-hover hover:text-text' => ! $on,
                        ])>
                        <x-bs-admin::icon :name="$icon" class="size-4 shrink-0" />{{ $key === 'all' ? __('blocks.region_all') : $regionLabel($key) }}
                    </button>
                @endforeach
            </div>
            @if ($region === 'menu')
                <x-bs-admin::alert color="warning" role="note" class="mb-3 min-[600px]:mx-4">{{ __('blocks.region_note_menu') }}</x-bs-admin::alert>
            @endif
            @if ($q !== '')
                <x-bs-admin::filter-status class="-mt-1 mb-2 min-[600px]:mx-1 max-[899.98px]:hidden" :summary="$line" :chips="$chips" clear="clearFilters" />
            @endif

            <div class="min-w-0 max-[599.98px]:rounded-md max-[599.98px]:border max-[599.98px]:border-border max-[599.98px]:bg-surface max-[599.98px]:[&_.list-g]:border-t-0">
                <x-bs-admin::list kind="facts" data-cols="blocks" :label="__('blocks.list_label')" busy="search,tab,region,clearFilters,clearSearch,loadMore">
                    @if ($rows !== [])
                        <x-bs-admin::list.head>
                            <x-bs-admin::list.cell head col="main">{{ __('blocks.col_name') }}</x-bs-admin::list.cell>
                            <x-bs-admin::list.cell head col="f1">{{ __('blocks.col_key') }}</x-bs-admin::list.cell>
                            <x-bs-admin::list.cell head col="f2">{{ __('blocks.col_region') }}</x-bs-admin::list.cell>
                            <x-bs-admin::list.cell head col="f3">{{ __('blocks.col_usage') }}</x-bs-admin::list.cell>
                            <x-bs-admin::list.cell head col="st">{{ __('blocks.col_status') }}</x-bs-admin::list.cell>
                            <x-bs-admin::list.cell head col="act">{{ __('admin.actions') }}</x-bs-admin::list.cell>
                        </x-bs-admin::list.head>
                    @endif

                    @forelse ($rows as $row)
                        @php
                            $ulid = Js::from($row['ulid']);
                            $editHref = route('admin.blocks.edit', ['ulid' => $row['ulid']]);
                        @endphp
                        <x-bs-admin::list.row wire:key="bl-{{ $row['ulid'] }}">
                            <x-bs-admin::list.cell col="main">
                                <div class="flex min-w-0 items-center gap-3">
                                    <span class="inline-flex size-9 shrink-0 items-center justify-center rounded-md {{ $regionTint[$row['region']] ?? '' }}" aria-hidden="true">
                                        <x-bs-admin::icon :name="$regionIcon[$row['region']] ?? 'ri-stack'" class="size-5" />
                                    </span>
                                    <div class="flex min-w-0 flex-col">
                                        <x-bs-admin::list.name :href="$editHref">{{ $row['name'] }}</x-bs-admin::list.name>
                                        <span class="text-caption text-text-muted">{{ __('blocks.updated', ['day' => $row['updated']]) }}</span>
                                    </div>
                                </div>
                                @if ($multi)
                                    <p class="list-line m-0">
                                        <x-bs-admin::list.langs :locales="$locales" :source="$defaultLocale" :name="$row['name']" :has="$row['locales']"
                                            :href="fn (string $lc): string => route('admin.blocks.edit', ['ulid' => $row['ulid'], 'locale' => $lc])" />
                                    </p>
                                @endif
                            </x-bs-admin::list.cell>
                            <x-bs-admin::list.cell col="f1">
                                <span class="inline-flex min-w-0 max-w-full items-center gap-1.5">
                                    <code class="min-w-0 rounded-sm border border-border bg-surface-muted px-2 py-0.5 font-mono text-caption text-text [overflow-wrap:anywhere]">{{ $row['key'] }}</code>
                                    <button type="button" class="hit-area inline-flex size-control-row shrink-0 items-center justify-center rounded text-text-muted transition-colors hover:bg-surface-hover hover:text-text"
                                        x-on:click="{{ $copy($row['key']) }}" title="{{ __('blocks.copy_key') }}" aria-label="{{ __('blocks.copy_key_aria', ['key' => $row['key']]) }}">
                                        <x-bs-admin::icon name="ri-file-copy" class="size-4" />
                                    </button>
                                </span>
                            </x-bs-admin::list.cell>
                            <x-bs-admin::list.cell col="f2"><span class="text-text-muted">{{ $regionLabel($row['region']) }}</span></x-bs-admin::list.cell>
                            <x-bs-admin::list.cell col="f3">
                                <button type="button" wire:click="showUsage({{ $ulid }})" title="{{ __('blocks.usage_tip') }}" aria-haspopup="dialog"
                                    @class([
                                        'hit-area inline-flex min-h-control-row items-center gap-1 font-semibold underline underline-offset-2',
                                        'text-primary-text' => $row['places'] > 0,
                                        'text-text-muted' => $row['places'] === 0,
                                    ])>
                                    <x-bs-admin::icon name="ri-links" class="size-4 shrink-0" />{{ trans_choice('blocks.n_places', $row['places'], ['n' => $num($row['places'])]) }}
                                </button>
                            </x-bs-admin::list.cell>
                            <x-bs-admin::list.cell col="st">
                                <x-bs-admin::status-pill :status="$row['published'] ? 'published' : 'draft'">{{ $row['published'] ? __('blocks.tab_published') : __('blocks.tab_draft') }}</x-bs-admin::status-pill>
                            </x-bs-admin::list.cell>
                            <x-bs-admin::list.cell col="act">
                                <x-bs-admin::list.actions :name="$row['name']" :title="__('blocks.more_aria', ['name' => $row['name']])">
                                    <x-bs-admin::button size="sm" variant="soft" color="primary" wire:click="openEdit({{ $ulid }})" data-row-btn aria-haspopup="dialog"
                                        title="{{ __('blocks.edit_tip') }}" :aria-label="__('blocks.edit_aria', ['name' => $row['name']])">
                                        <x-bs-admin::icon name="ri-edit-2" class="size-4" /> {{ __('blocks.edit') }}
                                    </x-bs-admin::button>
                                    <x-slot:menu>
                                        <p class="list-mh m-0" aria-hidden="true">{{ __('blocks.more_aria', ['name' => $row['name']]) }}</p>
                                        @if ($row['published'])
                                            <x-bs-admin::list.menu-item icon="ri-draft" wire:click="askUnpublish({{ $ulid }})">{{ __('blocks.unpublish') }}</x-bs-admin::list.menu-item>
                                        @else
                                            <x-bs-admin::list.menu-item icon="ri-send-plane" wire:click="publish({{ $ulid }})">{{ __('blocks.publish') }}</x-bs-admin::list.menu-item>
                                        @endif
                                        <x-bs-admin::list.menu-item icon="ri-file-copy" wire:click="duplicate({{ $ulid }})">{{ __('blocks.duplicate') }}</x-bs-admin::list.menu-item>
                                        <x-bs-admin::list.menu-sep />
                                        <x-bs-admin::list.menu-item icon="ri-delete-bin-6" danger wire:click="askDelete({{ $ulid }})">{{ __('blocks.delete') }}</x-bs-admin::list.menu-item>
                                    </x-slot:menu>
                                </x-bs-admin::list.actions>
                            </x-bs-admin::list.cell>
                        </x-bs-admin::list.row>
                    @empty
                        <x-bs-admin::list.row>
                            <x-bs-admin::list.cell col="full">
                                <div class="flex flex-col items-center gap-3 px-4 py-10 text-center">
                                    <span class="inline-flex size-15 items-center justify-center rounded-full bg-bg text-text-muted" aria-hidden="true">
                                        <x-bs-admin::icon name="ri-stack" class="size-7" />
                                    </span>
                                    <h2 class="m-0 text-[calc(1rem+var(--user-text-boost))] font-semibold text-text-strong">{{ $any ? __('blocks.nomatch_title') : __('blocks.empty_title') }}</h2>
                                    <p class="m-0 max-w-[62ch] text-small text-text-muted">{{ $any ? __('blocks.nomatch_text') : __('blocks.empty_text') }}</p>
                                    @if ($any)
                                        <x-bs-admin::button variant="outline" color="primary" wire:click="clearFilters">{{ __('admin.list.clear_filters') }}</x-bs-admin::button>
                                    @else
                                        <x-bs-admin::button color="primary" wire:click="openAdd" aria-haspopup="dialog">
                                            <x-bs-admin::icon name="ri-add" class="size-4" /> {{ __('blocks.add') }}
                                        </x-bs-admin::button>
                                    @endif
                                </div>
                            </x-bs-admin::list.cell>
                        </x-bs-admin::list.row>
                    @endforelse
                </x-bs-admin::list>
                @if ($more)
                    <div class="flex justify-center border-t border-border p-3">
                        <x-bs-admin::button variant="outline" color="secondary" wire:click="loadMore" wire:loading.attr="disabled" wire:target="loadMore">
                            {{ __('blocks.load_more', ['n' => $num(count($rows)), 'm' => $num($matched)]) }}
                        </x-bs-admin::button>
                    </div>
                @endif
            </div>
        </section>
    @endif

    <p class="m-0 flex items-start gap-1.5 px-0.5 text-small leading-relaxed text-text-muted">
        <x-bs-admin::icon name="ri-lock" class="mt-0.5 size-4 shrink-0 text-success-text" />
        <span>{{ __('blocks.footer_note') }}</span>
    </p>

    {{-- «Tạo / Sửa khối dùng chung»: the name and the region; the content is made in the block editor --}}
    <x-bs-admin::modal model="formOpen" :title="$editing === null ? __('blocks.form_title_new') : __('blocks.form_title_edit')" :close-label="__('blocks.close')">
        <form id="block-form" wire:submit="saveBlock" class="flex flex-col gap-4" novalidate>
            <x-bs-admin::input :label="__('blocks.f_name')" wire:model="name" id="block-name" maxlength="{{ ContentBlock::NAME_MAX }}"
                :placeholder="__('blocks.f_name_placeholder')" autocomplete="off" autofocus />
            <div class="flex flex-col gap-1.5">
                <span id="block-region-label" class="text-small font-semibold text-text">{{ __('blocks.f_region') }}</span>
                <div class="flex flex-wrap gap-2" role="group" aria-labelledby="block-region-label">
                    @foreach (ContentBlock::REGIONS as $r)
                        @php $on = $formRegion === $r; @endphp
                        <button type="button" wire:click="$set('formRegion', {{ Js::from($r) }})" aria-pressed="{{ $on ? 'true' : 'false' }}" wire:key="fr-{{ $r }}"
                            @class([
                                'hit-area inline-flex min-h-control-row items-center gap-1.5 rounded-full border px-3 text-small font-semibold transition-colors',
                                'border-primary-text bg-primary-soft text-primary-text' => $on,
                                'border-border bg-surface text-text-muted hover:bg-surface-hover hover:text-text' => ! $on,
                            ])>
                            <x-bs-admin::icon :name="$regionIcon[$r]" class="size-4 shrink-0" />{{ $regionLabel($r) }}
                        </button>
                    @endforeach
                </div>
                @error('formRegion') <p class="m-0 text-small text-danger-text">{{ $message }}</p> @enderror
            </div>
            <p class="m-0 flex items-start gap-1.5 text-small text-text-muted">
                <x-bs-admin::icon name="ri-information" class="mt-0.5 size-4 shrink-0 text-primary-text" />{{ __('blocks.form_note') }}
            </p>
        </form>
        <x-slot:footer>
            <x-bs-admin::button variant="outline" color="secondary" wire:click="closeForm">{{ __('blocks.cancel') }}</x-bs-admin::button>
            @if ($editing !== null)
                <x-bs-admin::button variant="soft" color="primary" wire:click="openContent">
                    <x-bs-admin::icon name="ri-artboard-2" class="size-4" /> {{ __('blocks.open_content') }}
                </x-bs-admin::button>
            @endif
            <x-bs-admin::button color="primary" type="submit" form="block-form" wire:loading.attr="disabled" wire:target="saveBlock">
                <x-bs-admin::icon name="ri-check" class="size-4" /> {{ $editing === null ? __('blocks.create_open') : __('blocks.save') }}
            </x-bs-admin::button>
        </x-slot:footer>
    </x-bs-admin::modal>

    {{-- «Nơi đang dùng khối» --}}
    <x-bs-admin::modal model="usageOpen" :title="__('blocks.usage_title')"
        :subtitle="$usage !== null ? __('blocks.usage_sub', ['name' => $usage['name'], 'places' => trans_choice('blocks.n_places', $usage['total'], ['n' => $num($usage['total'])])]) : null"
        :close-label="__('blocks.close')">
        @if ($usage !== null)
            @if ($usage['places'] === [])
                <p class="m-0 text-body text-text-muted">{{ __('blocks.usage_none') }}</p>
            @else
                <ul class="m-0 flex list-none flex-col p-0">
                    @foreach ($usage['places'] as $i => $place)
                        <li wire:key="use-{{ $i }}" class="border-b border-border last:border-b-0">
                            @if ($place['href'] !== null)
                                <a href="{{ $place['href'] }}" wire:navigate class="flex min-h-hit items-center gap-2.5 px-1 py-2 text-body text-text hover:bg-surface-hover">
                                    <x-bs-admin::icon name="ri-file-text" class="size-4 shrink-0 text-text-muted" />
                                    <span class="min-w-0 flex-1 [overflow-wrap:anywhere]">{{ $place['label'] }}</span>
                                    <x-bs-admin::icon name="ri-arrow-right-up" class="size-4 shrink-0 text-primary-text" />
                                </a>
                            @else
                                <span class="flex min-h-hit items-center gap-2.5 px-1 py-2 text-body text-text">
                                    <x-bs-admin::icon name="ri-file-text" class="size-4 shrink-0 text-text-muted" />
                                    <span class="min-w-0 flex-1 [overflow-wrap:anywhere]">{{ $place['label'] }}</span>
                                </span>
                            @endif
                        </li>
                    @endforeach
                </ul>
                @if ($usage['total'] > count($usage['places']))
                    <p class="m-0 mt-2 text-small text-text-muted">{{ __('blocks.usage_more', ['n' => $num($usage['total'] - count($usage['places']))]) }}</p>
                @endif
            @endif
            <p class="m-0 mt-3 text-small text-text-muted">{{ __('blocks.usage_hint') }}</p>
        @endif
        <x-slot:footer>
            <x-bs-admin::button variant="outline" color="secondary" wire:click="closeUsage">{{ __('blocks.close') }}</x-bs-admin::button>
        </x-slot:footer>
    </x-bs-admin::modal>

    {{-- «Xóa khối dùng chung?» / «Đưa khối về nháp?»: one question for both; no Trash, so it says what uses the block --}}
    @php
        $del = $deleting;
        $delPlaces = $del['places'] ?? 0;
        $toDraft = $asking === 'draft';
        $placesText = fn (): string => '<b class="font-semibold text-text-strong">'.e(trans_choice('blocks.n_places', $delPlaces, ['n' => $num($delPlaces)])).'</b>';
        $nameText = $del === null ? '' : '<b class="font-semibold text-text-strong">'.e($del['name']).'</b>';
    @endphp
    <x-bs-admin::confirm-dialog model="confirmOpen" :danger="! $toDraft" :icon="$toDraft ? 'ri-draft' : 'ri-alert'" ok-icon="ri-delete-bin-6"
        :title="$toDraft ? __('blocks.unpub_title') : __('blocks.del_title')" :ok="$toDraft ? __('blocks.unpub_ok') : __('blocks.del_ok')" :cancel-label="__('blocks.del_stay')"
        :confirm="$toDraft ? 'confirmUnpublish' : 'confirmDelete'" cancel="cancelDelete">
        @if ($del !== null)
            <p class="m-0 [overflow-wrap:anywhere]">
                @if ($toDraft)
                    {!! __('blocks.unpub_used', ['name' => $nameText, 'places' => $placesText()]) !!}
                @elseif ($delPlaces > 0)
                    {!! __('blocks.del_used', ['name' => $nameText, 'places' => $placesText()]) !!}
                @else
                    {!! __('blocks.del_free', ['name' => $nameText]) !!}
                @endif
            </p>
            @if ($del['menu'] && $delPlaces > 0)
                <x-bs-admin::alert color="warning" role="note" class="mt-1">{{ $toDraft ? __('blocks.unpub_menu') : __('blocks.del_menu') }}</x-bs-admin::alert>
            @endif
        @endif
    </x-bs-admin::confirm-dialog>

    <x-bs-admin::toast :message="$toastMsg" :tone="$toastTone" :seq="$toastSeq" dismiss="closeToast" />
</div>
