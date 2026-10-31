@php
    use App\Modules\Admin\Livewire\Platform\Audit\Index;
    use App\Modules\Admin\Support\AuditCatalog;
    use Illuminate\Support\Js;

    $time = fn ($at): string => $siteTime->forDisplay($at)?->format('d/m/Y H:i') ?? '—';
    $day = fn (string $date): string => $date === '' ? '…' : implode('/', array_reverse(explode('-', $date)));
    $whenLabels = [
        '' => __('admin.audit.when_all'), 'today' => __('admin.audit.when_today'),
        '7' => __('admin.audit.when_days', ['n' => 7]), '30' => __('admin.audit.when_days', ['n' => 30]), '90' => __('admin.audit.when_days', ['n' => 90]),
    ];
    $whenText = $when === Index::RANGE ? __('admin.audit.range', ['from' => $day($from), 'to' => $day($to)]) : ($when !== '' ? $whenLabels[$when] : '');
    $groupNames = [];
    foreach (array_keys(AuditCatalog::GROUPS) as $g) {
        $groupNames[$g] = $catalog->groupLabel($g);
    }
    $siteLabels = array_column($siteChoices, 'label', 'value');
    $personLabels = array_column($peopleChoices, 'label', 'value');

    $sections = [
        ['key' => 'groups', 'label' => __('admin.audit.f_groups'), 'icon' => 'ri-list-check-2', 'model' => 'groups', 'multiple' => true, 'value' => $groups,
            'options' => array_map(fn (string $g): array => ['value' => $g, 'label' => $groupNames[$g]], array_keys($groupNames))],
        ['key' => 'sites', 'label' => __('admin.audit.f_sites'), 'icon' => 'ri-global', 'model' => 'sites', 'multiple' => true, 'value' => $sites, 'options' => $siteChoices,
            'search' => ['model' => 'siteQuery', 'label' => __('admin.audit.site_search'), 'placeholder' => __('admin.audit.site_search_hint')]],
        ['key' => 'people', 'label' => __('admin.audit.f_people'), 'icon' => 'ri-user-3', 'model' => 'people', 'multiple' => true, 'value' => $people, 'options' => $peopleChoices],
        ['key' => 'when', 'label' => __('admin.audit.f_when'), 'icon' => 'ri-calendar-schedule', 'model' => 'when', 'value' => $when, 'default' => '',
            'options' => array_map(fn (string $v): array => ['value' => $v, 'label' => $whenLabels[$v]], Index::WHEN),
            'range' => ['from' => 'from', 'to' => 'to', 'from_label' => __('admin.audit.from'), 'to_label' => __('admin.audit.to')]]
            + ($when === Index::RANGE ? ['on' => true, 'summary' => $whenText, 'suffix' => ': '.$whenText] : []),
    ];
    $active = count($groups) + count($sites) + count($people) + ($when !== '' ? 1 : 0);

    // remove calls are fixed method names with Js::from() arguments, never raw input (filter-status contract)
    $chips = [];
    if (trim($q) !== '') {
        $chips[] = ['label' => __('admin.audit.chip_search', ['q' => trim($q)]), 'remove' => 'clearSearch'];
    }
    if ($whenText !== '') {
        $chips[] = ['label' => __('admin.audit.chip_when', ['when' => $whenText]), 'remove' => 'clearWhen'];
    }
    foreach ($people as $p) {
        $chips[] = ['label' => __('admin.audit.chip_person', ['name' => $personLabels[$p] ?? '—']), 'remove' => 'removePerson('.Js::from($p).')'];
    }
    foreach ($sites as $s) {
        $chips[] = ['label' => __('admin.audit.chip_site', ['name' => $siteLabels[$s] ?? '—']), 'remove' => 'removeSite('.Js::from($s).')'];
    }
    foreach ($groups as $g) {
        $chips[] = ['label' => $groupNames[$g], 'remove' => 'removeGroup('.Js::from($g).')'];
    }
    if ($support) {
        $chips[] = ['label' => __('admin.audit.support_only'), 'remove' => 'clearSupport'];
    }
    if ($session !== '') {
        $chips[] = ['label' => __('admin.audit.session_only'), 'remove' => 'clearSession'];
    }

    $site = fn ($row): string => $row->site_id === null ? __('admin.audit.no_site') : ($siteNames[$row->site_id] ?? '—');
    $cell = 'min-w-0 shrink-0 [overflow-wrap:anywhere]';
@endphp
<div class="flex flex-col gap-4">
    <div>
        <h1 class="m-0 text-[calc(1.25rem+var(--user-text-boost))] font-semibold leading-snug text-text-strong">{{ __('admin.platform_audit') }}</h1>
        <p class="m-0 mt-1 max-w-[72ch] text-body text-text-muted [text-wrap:pretty]">{{ __('admin.audit.intro') }}</p>
    </div>

    <div class="flex flex-wrap items-center gap-3">
        <div class="min-w-0 max-w-[26rem] flex-[1_1_16rem]">
            <x-bs-admin::search :label="__('admin.audit.search_label')" :placeholder="__('admin.audit.search_hint')" wire:model.live.debounce.400ms="q" />
        </div>
        <x-bs-admin::filter-panel :sections="$sections" :active="$active" :results="__('admin.audit.show_results')" clear="clearFilters" />
        <button type="button" wire:click="$toggle('support')" aria-pressed="{{ $support ? 'true' : 'false' }}"
                class="hit-area inline-flex min-h-control shrink-0 items-center gap-1.5 rounded border px-3 text-body transition-colors pointer-coarse:min-h-hit {{ $support ? 'border-primary-text bg-primary/10 font-semibold text-primary-text' : 'border-border-input bg-surface text-text-strong hover:bg-surface-hover' }}">
            <x-bs-admin::icon name="ri-user-shared" class="size-4 shrink-0" />
            <span>{{ __('admin.audit.support_only') }}</span>
        </button>
    </div>

    <x-bs-admin::filter-status class="-mx-3 -mt-2" :chips="$chips" :clear="$chips === [] ? null : 'clearFilters'" />

    <div class="@container min-w-0 rounded-md border border-border bg-surface shadow-card">
        <div aria-hidden="true" class="hidden items-center gap-3 rounded-t-md border-b border-border bg-surface-muted px-4 py-2 text-small font-semibold text-text-muted @min-[48rem]:flex">
            <span class="w-33 shrink-0">{{ __('admin.audit.col_when') }}</span>
            <span class="min-w-50 flex-1">{{ __('admin.audit.col_what') }}</span>
            <span class="w-57.5 shrink-0 @max-[73.75rem]:w-50">{{ __('admin.audit.col_who') }}</span>
            <span class="w-42.5 shrink-0">{{ __('admin.audit.col_site') }}</span>
            <span class="hidden w-37.5 shrink-0 @min-[73.8125rem]:block">{{ __('admin.audit.col_device') }}</span>
            <span class="w-5 shrink-0"></span>
        </div>

        <p wire:loading.delay class="sr-only" role="status">{{ __('admin.audit.loading') }}</p>

        @if ($failed !== null)
            <div class="flex flex-col items-start gap-3 px-4 py-5" role="alert">
                <h2 class="m-0 text-[calc(1rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('admin.audit.error_title') }}</h2>
                <p class="m-0 max-w-[62ch]">{{ __($failed === 'timeout' ? 'admin.audit.timeout_text' : 'admin.audit.error_text') }}</p>
                <x-bs-admin::button wire:click="$refresh"><x-bs-admin::icon name="ri-refresh" class="size-4" />{{ __('admin.audit.retry') }}</x-bs-admin::button>
            </div>
        @elseif ($rows->isEmpty() && ! $filtering)
            <div class="flex flex-col items-start gap-3 px-4 py-5">
                <h2 class="m-0 text-[calc(1rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('admin.audit.empty_title') }}</h2>
                <p class="m-0 max-w-[62ch]">{{ __('admin.audit.empty_text') }}</p>
            </div>
        @elseif ($rows->isEmpty())
            <div class="flex flex-col items-start gap-3 px-4 py-5">
                <h2 class="m-0 text-[calc(1rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('admin.audit.nomatch_title') }}</h2>
                <p class="m-0 max-w-[62ch]">{{ __('admin.audit.nomatch_text') }}</p>
                <x-bs-admin::button variant="outline" color="secondary" wire:click="clearFilters">{{ __('admin.list.clear_filters') }}</x-bs-admin::button>
            </div>
        @else
            <ul class="m-0 list-none p-0" wire:loading.delay.class="opacity-60">
                @foreach ($rows as $row)
                    @php
                        $what = $catalog->label($row->action);
                        $who = $entries->who($row);
                        $target = $entries->target($row);
                    @endphp
                    <li wire:key="audit-{{ $row->ulid }}" class="border-b border-border last:border-b-0">
                        <button type="button" id="audit-row-{{ $row->ulid }}" wire:click="openDetail({{ Js::from($row->ulid) }})"
                                class="flex w-full min-w-0 items-center gap-3 px-4 py-2.5 text-start text-body text-text transition-colors hover:bg-surface-hover @max-[47.9375rem]:flex-wrap @max-[47.9375rem]:gap-x-2 @max-[47.9375rem]:gap-y-0.5 @max-[47.9375rem]:p-3">
                            <span class="w-33 shrink-0 tabular-nums @max-[47.9375rem]:order-2 @max-[47.9375rem]:w-auto @max-[47.9375rem]:text-small @max-[47.9375rem]:text-text-muted">{{ $time($row->created_at) }}</span>
                            <span class="min-w-50 flex-1 @max-[47.9375rem]:order-1 @max-[47.9375rem]:min-w-0">
                                <span class="block font-semibold leading-snug text-text-strong [overflow-wrap:anywhere]">{{ $what }}</span>
                                @if ($target !== '')
                                    <span class="block text-small text-text-muted [overflow-wrap:anywhere] @max-[47.9375rem]:hidden">{{ $target }}</span>
                                @endif
                            </span>
                            <span class="contents @max-[47.9375rem]:order-3 @max-[47.9375rem]:flex @max-[47.9375rem]:basis-full @max-[47.9375rem]:flex-wrap @max-[47.9375rem]:items-center @max-[47.9375rem]:gap-x-1.5 @max-[47.9375rem]:text-small @max-[47.9375rem]:text-text-muted">
                                <span class="{{ $cell }} w-57.5 @max-[73.75rem]:w-50 @max-[47.9375rem]:w-auto @max-[47.9375rem]:max-w-full @max-[47.9375rem]:shrink">
                                    <span class="inline-flex max-w-full flex-wrap items-center gap-x-1.5 gap-y-1">
                                        @if ($entries->isSupport($row))
                                            <x-bs-admin::badge color="warning" soft pill><x-bs-admin::icon name="ri-user-shared" class="size-3.5" />{{ __('admin.audit.support_badge') }}</x-bs-admin::badge>
                                        @endif
                                        <span class="min-w-0">{{ $who }}</span>
                                    </span>
                                </span>
                                <span aria-hidden="true" class="hidden @max-[47.9375rem]:inline">·</span>
                                <span class="{{ $cell }} w-42.5 @max-[47.9375rem]:w-auto">{{ $site($row) }}</span>
                                <span aria-hidden="true" class="hidden @max-[47.9375rem]:inline">·</span>
                                <span class="{{ $cell }} hidden w-37.5 @min-[73.8125rem]:block @max-[47.9375rem]:block @max-[47.9375rem]:w-auto">{{ $row->ip_address ?? '—' }} · {{ $entries->device($row) }}</span>
                            </span>
                            <span class="sr-only">{{ __('admin.audit.open_row') }}</span>
                            <x-bs-admin::icon name="chevron-right" class="size-5 shrink-0 text-text-muted @max-[47.9375rem]:hidden" />
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($failed === null && $rows->isNotEmpty())
            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-t border-border px-4 py-2.5">
                <label class="flex items-center gap-2 text-small">
                    {{ __('admin.audit.per_page') }}
                    <select wire:model.live="per" class="field rounded border border-border-input bg-surface px-2 text-text-strong">
                        @foreach (Index::PER_PAGE as $n)
                            <option value="{{ $n }}">{{ $n }}</option>
                        @endforeach
                    </select>
                </label>
                <nav class="flex gap-2" aria-label="{{ __('admin.audit.pager') }}">
                    <x-bs-admin::button size="sm" variant="outline" color="secondary" :disabled="$newer === null" wire:click="newer({{ Js::from((string) $newer) }})">
                        <x-bs-admin::icon name="chevron-left" class="size-4" />{{ __('admin.audit.prev') }}
                    </x-bs-admin::button>
                    <x-bs-admin::button size="sm" variant="outline" color="secondary" :disabled="$older === null" wire:click="older({{ Js::from((string) $older) }})">
                        {{ __('admin.audit.next') }}<x-bs-admin::icon name="chevron-right" class="size-4" />
                    </x-bs-admin::button>
                </nav>
            </div>
        @endif
    </div>

    @if ($openRow !== null)
        @php
            $group = $catalog->groupOf($openRow->action);
            $staff = $entries->staff($openRow);
            $details = $entries->details($openRow);
            $openTarget = $entries->target($openRow);
            $rowSession = (string) $openRow->getAttribute('support_session_ulid');
        @endphp
        <x-bs-admin::drawer :title="$catalog->label($openRow->action)"
                            :subtitle="$time($openRow->created_at).($group !== null ? ' · '.$catalog->groupLabel($group) : '')"
                            close="closeDetail" :return-to="'audit-row-'.$openRow->ulid" :close-label="__('admin.audit.detail_close')"
                            wire:key="audit-detail-{{ $openRow->ulid }}">
            @if ($staff !== null)
                <div role="note" class="flex items-start gap-2 rounded bg-warning-soft px-3.5 py-3 text-warning-text">
                    <x-bs-admin::icon name="ri-user-shared" class="mt-0.5 size-5 shrink-0" />
                    <span class="min-w-0 [overflow-wrap:anywhere]">{{ __('admin.audit.support_note', ['staff' => $staff, 'brand' => (string) config('admin.brand.name'), 'member' => $entries->member($openRow)]) }}@if ($reason !== null) {{ __('admin.audit.support_reason', ['reason' => $reason]) }}@endif</span>
                </div>
            @endif
            <dl class="m-0 grid grid-cols-[minmax(0,8.125rem)_minmax(0,1fr)] gap-x-4 gap-y-2 @max-[25rem]:grid-cols-1 @max-[25rem]:gap-y-0.5">
                @foreach ([
                    'd_who' => $entries->who($openRow),
                    'd_site' => $site($openRow),
                    'd_target' => $openTarget !== '' ? $openTarget : '—',
                    'd_ip' => $openRow->ip_address ?? '—',
                    'd_device' => $entries->device($openRow),
                ] as $key => $value)
                    <dt class="text-text-muted @max-[25rem]:mt-2">{{ __('admin.audit.'.$key) }}</dt>
                    <dd class="m-0 text-text-strong [overflow-wrap:anywhere]">{{ $value }}</dd>
                @endforeach
            </dl>
            @if ($details !== [])
                <div>
                    <h3 class="m-0 mb-1.5 text-small font-semibold text-text-strong">{{ __('admin.audit.d_detail') }}</h3>
                    <dl class="m-0 grid grid-cols-[minmax(0,8.125rem)_minmax(0,1fr)] gap-x-4 gap-y-2 @max-[25rem]:grid-cols-1 @max-[25rem]:gap-y-0.5">
                        @foreach ($details as $detail)
                            <dt class="text-text-muted @max-[25rem]:mt-2">{{ $detail['label'] }}</dt>
                            <dd class="m-0 text-text-strong [overflow-wrap:anywhere]">{{ $detail['value'] }}</dd>
                        @endforeach
                    </dl>
                </div>
            @endif
            <x-slot:footer>
                @if ($rowSession !== '' && $rowSession !== $session)
                    <x-bs-admin::button variant="outline" color="secondary" wire:click="showSession">
                        <x-bs-admin::icon name="ri-filter-3" class="size-4" />{{ __('admin.audit.d_session') }}
                    </x-bs-admin::button>
                @endif
                <x-bs-admin::button @click="hide()">{{ __('admin.audit.close') }}</x-bs-admin::button>
            </x-slot:footer>
        </x-bs-admin::drawer>
    @endif
</div>
