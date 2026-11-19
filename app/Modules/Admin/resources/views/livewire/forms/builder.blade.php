@php
    use Illuminate\Support\Js;
    use Illuminate\Support\Number;

    $num = fn (int $n): string => Number::format($n, locale: app()->getLocale());
    $leadsText = fn (int $n): ?string => $n === 0 ? null
        : ($n > $cap ? __('form_builder.leads_over', ['n' => $num($cap)]) : trans_choice('form_builder.leads_n', $n, ['n' => $num($n)]));
    $mailText = fn (array $emails): ?string => $emails === [] ? null
        : (count($emails) > 1 ? __('form_builder.mail_more', ['first' => $emails[0], 'n' => count($emails) - 1]) : $emails[0]);

    $groups = [];
    foreach (\App\Modules\Admin\Livewire\Forms\FormList::TABS as $key) {
        $groups[] = ['key' => $key, 'label' => __('form_builder.tab_'.$key), 'count' => $tabCounts[$key] ?? 0];
    }
    $groupLabel = $tab === 'all' ? null : __('form_builder.tab_'.$tab);
    $sections = [
        ['key' => 'status', 'label' => __('form_builder.filter_status'), 'icon' => 'ri-flag', 'model' => 'tab', 'value' => $tab, 'default' => 'all',
            'sheet' => true, 'class' => 'min-[900px]:hidden',
            'options' => array_map(fn (array $g): array => ['value' => $g['key'], 'label' => $g['label'], 'count' => $g['count']], $groups)],
        ['key' => 'leads', 'label' => __('form_builder.filter_leads'), 'icon' => 'ri-mail', 'model' => 'has', 'multiple' => true, 'value' => $has,
            'options' => [
                ['value' => 'yes', 'label' => __('form_builder.has_yes'), 'count' => $hasCounts['yes']],
                ['value' => 'no', 'label' => __('form_builder.has_no'), 'count' => $hasCounts['no']],
            ]],
    ];
    $q = trim($search);
    $chips = [];
    if ($q !== '') {
        $chips[] = ['label' => __('form_builder.chip_search', ['q' => $q]), 'remove' => 'clearSearch'];
    }
    foreach ($has as $value) {
        $chips[] = ['label' => __('form_builder.has_'.$value), 'icon' => 'ri-mail', 'remove' => 'removeHas('.Js::from($value).')',
            'aria' => __('form_builder.chip_remove', ['label' => __('form_builder.has_'.$value)])];
    }
    $narrowed = $q !== '' || $has !== [];
    $filtering = $tab !== 'all' || $narrowed;
    $matched = count($rows);
    $summary = __('form_builder.match_of', ['n' => $num($matched), 'm' => $num($total)]);
@endphp
<div class="flex flex-col gap-4" x-data="{ pick: false }">
    <header class="flex flex-col gap-1">
        <x-bs-admin::title-row :title="__('form_builder.title')">
            <x-bs-admin::button color="primary" class="shrink-0" x-on:click="pick = true" aria-haspopup="dialog"
                :label="__('form_builder.add')" :short="__('admin.list.add_short')">
                <x-bs-admin::icon name="ri-add" class="size-4" />
            </x-bs-admin::button>
        </x-bs-admin::title-row>
        <p class="m-0 max-w-[72ch] text-body text-text-muted [text-wrap:pretty]">
            <span class="max-[599.98px]:hidden">{{ __('form_builder.desc') }}</span><span class="min-[600px]:hidden">{{ __('form_builder.desc_short') }}</span>
            @can('forms.view')
                <a href="{{ route('admin.forms.index') }}" wire:navigate class="hit-area inline-flex items-center gap-1 font-medium text-primary-text underline-offset-2 hover:underline">
                    <x-bs-admin::icon name="ri-mail" class="size-4" />{{ __('admin.contacts') }}
                </a>
            @endcan
        </p>
    </header>

    @if ($failed)
        <x-bs-admin::card flush role="alert" data-state="error" class="min-w-0 p-6 text-center">
            <x-bs-admin::icon name="ri-wifi-off" class="mx-auto size-8 text-text-muted" />
            <h2 class="m-0 mt-2 text-[calc(1.125rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('form_builder.error_title') }}</h2>
            <p class="m-0 mt-1 text-body text-text-muted">{{ __('form_builder.error_text') }}</p>
            <x-bs-admin::button color="primary" class="mt-4" wire:click="$refresh">
                <x-bs-admin::icon name="ri-refresh" class="size-4" /> {{ __('form_builder.retry') }}
            </x-bs-admin::button>
        </x-bs-admin::card>
    @else
        <section aria-label="{{ __('form_builder.list_label') }}" class="@container/bm min-w-0 min-[600px]:rounded-md min-[600px]:border min-[600px]:border-border min-[600px]:bg-surface min-[600px]:shadow-card">
            <x-bs-admin::group-row :groups="$groups" :active="$tab" model="tab" :label="__('form_builder.groups_label')" />

            <div class="flex flex-wrap items-center gap-3 pb-3 min-[600px]:px-4 min-[600px]:pt-4 min-[900px]:pt-3">
                <div class="min-w-0 flex-[1_1_12rem] text-body min-[900px]:max-w-[30em]">
                    <x-bs-admin::search id="forms-search" :label="__('form_builder.search_label')" :placeholder="__('form_builder.search_placeholder')"
                        maxlength="100" wire:model.live.debounce.300ms="search" />
                </div>
                <x-bs-admin::filter-panel class="shrink-0 flex-nowrap min-[900px]:ms-auto" :sections="$sections" :label="__('admin.list.filter')"
                    :active="count($has)" :active-narrow="count($has) + ($tab !== 'all' ? 1 : 0)"
                    :results="__('form_builder.filter_see', ['n' => $num($matched)])" clear="clearFilters" />
            </div>

            <x-bs-admin::filter-status class="-mx-3 -mt-1 mb-2 min-[600px]:mx-1 min-[900px]:hidden" :group="$groupLabel"
                :summary="$filtering ? $summary : null" :chips="$chips" :clear="$filtering ? 'clearFilters' : null" />
            @if ($narrowed)
                <x-bs-admin::filter-status class="-mt-1 mb-2 min-[600px]:mx-1 max-[899.98px]:hidden" :summary="$summary" :chips="$chips" clear="clearFilters" />
            @endif

            <div class="min-w-0 max-[599.98px]:rounded-md max-[599.98px]:border max-[599.98px]:border-border max-[599.98px]:bg-surface max-[599.98px]:[&_.list-g]:border-t-0">
                <x-bs-admin::list kind="facts" data-cols="forms" :label="__('form_builder.list_label')" busy="search,tab,has,clearFilters,clearSearch,removeHas">
                    @if ($rows !== [])
                        <x-bs-admin::list.head>
                            <x-bs-admin::list.cell head col="main">{{ __('form_builder.col_name') }}</x-bs-admin::list.cell>
                            <x-bs-admin::list.cell head col="f1">{{ __('form_builder.col_leads') }}</x-bs-admin::list.cell>
                            <x-bs-admin::list.cell head col="f2">{{ __('form_builder.col_mail') }}</x-bs-admin::list.cell>
                            <x-bs-admin::list.cell head col="act">{{ __('admin.actions') }}</x-bs-admin::list.cell>
                        </x-bs-admin::list.head>
                    @endif

                    @forelse ($rows as $row)
                        @php
                            $href = route('admin.form-builder.edit', $row['ulid']);
                            $leads = $leadsText($row['leads']);
                            $mail = $mailText($row['emails']);
                            $leadsHref = route('admin.forms.index', ['form' => [$row['ulid']]]);
                        @endphp
                        <x-bs-admin::list.row wire:key="bm-{{ $row['ulid'] }}">
                            <x-bs-admin::list.cell col="main">
                                <div class="flex min-w-0 flex-wrap items-center gap-x-2.5 gap-y-1">
                                    <x-bs-admin::list.name :href="$href">{{ $row['name'] }}</x-bs-admin::list.name>
                                    @if ($row['system'])
                                        <span class="inline-flex min-h-5.5 max-w-full items-center gap-1 rounded-full bg-surface-hover px-2 py-0.5 text-caption font-semibold leading-snug text-text [overflow-wrap:anywhere]"
                                              title="{{ __('form_builder.system_tip') }}">
                                            <x-bs-admin::icon name="ri-shield-star" class="size-3.5 shrink-0" />{{ __('form_builder.system') }}<span class="sr-only"> — {{ __('form_builder.system_tip') }}</span>
                                        </span>
                                    @endif
                                </div>
                                <p class="list-line m-0">
                                    <span>{{ $leads ?? trans_choice('form_builder.leads_n', 0, ['n' => 0]) }}</span>
                                    <span @class(['text-warning-text' => $mail === null])>{{ $mail ?? __('form_builder.mail_none') }}</span>
                                </p>
                                <button type="button" role="switch" class="switch-row -ms-1 w-auto font-medium text-text" aria-checked="{{ $row['active'] ? 'true' : 'false' }}"
                                    aria-label="{{ __('form_builder.switch_aria', ['name' => $row['name']]) }}"
                                    wire:click="setActive({{ Js::from($row['ulid']) }}, {{ $row['active'] ? 'false' : 'true' }})" wire:loading.attr="disabled" wire:target="setActive">
                                    {{ $row['active'] ? __('form_builder.on') : __('form_builder.off') }}
                                </button>
                            </x-bs-admin::list.cell>
                            <x-bs-admin::list.cell col="f1">
                                @if ($leads !== null && auth()->user()?->can('forms.view'))
                                    <a href="{{ $leadsHref }}" wire:navigate class="hit-area inline-flex min-h-hit items-center font-medium tabular-nums text-primary-text underline-offset-2 hover:underline">{{ $leads }}</a>
                                @else
                                    <span class="tabular-nums">{{ $leads ?? __('form_builder.leads_none') }}</span>
                                @endif
                            </x-bs-admin::list.cell>
                            <x-bs-admin::list.cell col="f2">
                                <span @class(['[overflow-wrap:anywhere]', 'text-warning-text' => $mail === null, 'text-text' => $mail !== null])>{{ $mail ?? __('form_builder.mail_none') }}</span>
                            </x-bs-admin::list.cell>
                            <x-bs-admin::list.cell col="act">
                                <x-bs-admin::list.actions :name="$row['name']" :title="__('form_builder.more_aria', ['name' => $row['name']])">
                                    <x-bs-admin::button size="sm" variant="soft" color="primary" :href="$href" data-row-btn
                                        :aria-label="__('form_builder.edit_aria', ['name' => $row['name']])">
                                        <x-bs-admin::icon name="ri-pencil" class="size-4" /> {{ __('form_builder.edit') }}
                                    </x-bs-admin::button>
                                    <x-slot:menu>
                                        <p class="list-mh m-0" aria-hidden="true">{{ $row['name'] }}</p>
                                        <x-bs-admin::list.menu-item icon="ri-pencil" :href="$href" wire:navigate>{{ __('form_builder.edit') }}</x-bs-admin::list.menu-item>
                                    </x-slot:menu>
                                </x-bs-admin::list.actions>
                            </x-bs-admin::list.cell>
                        </x-bs-admin::list.row>
                    @empty
                        <x-bs-admin::list.row>
                            <x-bs-admin::list.cell col="full">
                                <div class="flex flex-col items-start gap-3 px-4 py-6">
                                    @if ($any)
                                        <h2 class="m-0 text-[calc(1rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('form_builder.nomatch') }}</h2>
                                        <x-bs-admin::button variant="outline" color="primary" wire:click="clearFilters">{{ __('admin.list.clear_filters') }}</x-bs-admin::button>
                                    @else
                                        <h2 class="m-0 text-[calc(1rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('form_builder.empty_title') }}</h2>
                                        <p class="m-0 max-w-[62ch] text-body text-text-muted">{{ __('form_builder.empty_text') }}</p>
                                    @endif
                                </div>
                            </x-bs-admin::list.cell>
                        </x-bs-admin::list.row>
                    @endforelse
                </x-bs-admin::list>
            </div>
        </section>
    @endif

    {{-- «Thêm biểu mẫu»: what the form is for; each starting point opens the editor with its fields. --}}
    <x-bs-admin::modal state="pick" close="pick = false" size="wide" :title="__('form_builder.pick_title')" :subtitle="__('form_builder.pick_text')"
        :close-label="__('form_builder.pick_close')">
        <div class="grid gap-2 min-[600px]:grid-cols-2">
            @foreach ($purposes as $p)
                <a href="{{ $p['href'] }}" wire:navigate @class([
                    'flex min-h-hit items-start gap-3 rounded-md border border-border p-3 text-start text-text transition-colors hover:border-primary-text hover:bg-surface-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-text',
                    'border-dashed min-[600px]:col-span-2' => $p['key'] === 'custom',
                ])>
                    <x-bs-admin::icon :name="$p['icon']" class="mt-0.5 size-6 shrink-0 text-primary-text" />
                    <span class="flex min-w-0 flex-col gap-0.5 [overflow-wrap:anywhere]">
                        <b class="font-semibold text-text-strong">{{ $p['label'] }}</b>
                        <span class="text-small text-text-muted">{{ $p['desc'] }}</span>
                        <span class="text-caption font-medium text-text-muted">{{ __('form_builder.pick_count', ['n' => $p['n']]) }}</span>
                    </span>
                </a>
            @endforeach
        </div>
        <x-slot:footer>
            <x-bs-admin::button variant="outline" color="secondary" x-on:click="pick = false">{{ __('form_builder.cancel') }}</x-bs-admin::button>
        </x-slot:footer>
    </x-bs-admin::modal>

    <x-bs-admin::toast :message="$toastMsg" :tone="$toastTone" :seq="$toastSeq" dismiss="closeToast" :undo="$undoOp !== null ? 'undoLast' : null" />
</div>
