@php
    use App\Modules\Admin\Livewire\Platform\Support\Index;
    use App\Modules\Admin\Support\SupportSessions;
    use Illuminate\Support\Js;
    use Illuminate\Support\Number;

    $num = fn (int $n): string => Number::format($n, locale: app()->getLocale());

    $groups = [];
    foreach (SupportSessions::TABS as $key) {
        $groups[] = ['key' => $key, 'label' => __('admin.support.list.tab_'.$key), 'count' => $counts[$key] ?? 0];
    }
    $groupLabel = $tab === SupportSessions::TAB_ALL ? null : (collect($groups)->firstWhere('key', $tab)['label'] ?? null);

    $whenLabels = ['' => __('admin.support.list.when_all')];
    foreach (SupportSessions::PERIODS as $d) {
        $whenLabels[(string) $d] = __('admin.support.list.when_days', ['n' => $d]);
    }
    $colLabels = ['staff' => __('admin.support.list.col_staff'), 'reason' => __('admin.support.list.col_reason'), 'time' => __('admin.support.list.col_time')];
    $show = fn (string $k): bool => in_array($k, $cols, true);
    $staffLabels = array_column($staffChoices, 'label', 'value');

    $sections = [
        ['key' => 'status', 'label' => __('admin.support.list.f_status'), 'icon' => 'ri-flag', 'model' => 'tab', 'value' => $tab, 'default' => SupportSessions::TAB_ALL,
            'sheet' => true, 'class' => 'min-[900px]:hidden',
            'options' => array_map(fn (array $g): array => ['value' => $g['key'], 'label' => $g['label'], 'count' => $g['count']], $groups)],
        ['key' => 'staff', 'label' => __('admin.support.list.f_staff'), 'icon' => 'ri-user-star', 'model' => 'staff', 'multiple' => true, 'value' => $staff, 'options' => $staffChoices],
        ['key' => 'days', 'label' => __('admin.support.list.f_when'), 'icon' => 'ri-calendar', 'model' => 'days', 'value' => $days, 'default' => '',
            'options' => array_map(fn (string $v, string $l): array => ['value' => $v, 'label' => $l], array_keys($whenLabels), $whenLabels)],
        // a view choice, not a filter: there exactly while the rows are cards
        ['key' => 'card', 'label' => __('admin.support.list.f_on_card'), 'model' => 'cols', 'multiple' => true, 'value' => $cols, 'sheet' => true,
            'class' => '@min-[37.5rem]/ss:hidden', 'on' => false,
            'options' => array_map(fn (string $k, string $l): array => ['value' => $k, 'label' => $l], array_keys($colLabels), $colLabels)],
    ];
    $active = count($staff) + ($days !== '' ? 1 : 0);

    // remove calls are fixed method names with Js::from() arguments, never raw input (filter-status contract)
    $q = trim($search);
    $chips = [];
    if ($q !== '') {
        $chips[] = ['label' => __('admin.support.list.chip_search', ['q' => $q]), 'remove' => 'clearSearch', 'aria' => __('admin.support.list.chip_search_aria')];
    }
    foreach ($staff as $s) {
        $who = $staffLabels[$s] ?? '—';
        $chips[] = ['label' => __('admin.support.list.chip_staff', ['name' => $who]), 'remove' => 'removeStaff('.Js::from($s).')', 'aria' => __('admin.support.list.chip_staff_aria', ['name' => $who])];
    }
    if ($days !== '') {
        $chips[] = ['label' => $whenLabels[$days], 'remove' => 'clearDays', 'aria' => __('admin.support.list.chip_when_aria')];
    }
    $narrowed = $chips !== [];
    $filtering = $tab !== SupportSessions::TAB_ALL || $narrowed;
    $matched = $counts[$tab] ?? 0;

    $at = fn ($t) => $siteTime->forDisplay($t);
    $whys = ['left', 'expired', 'replaced', 'ended_by', 'another_site', 'staff_lost_right', 'switched_off', 'site_inactive'];
@endphp
<div class="flex flex-col gap-4">
    <header class="flex flex-col gap-1">
        <h1 class="m-0 text-[calc(1.25rem+var(--user-text-boost))] font-semibold leading-snug text-text-strong [overflow-wrap:anywhere]">{{ __('admin.platform_support') }}</h1>
        <p class="m-0 max-w-[72ch] text-body text-text-muted [text-wrap:pretty]">{{ __('admin.support.list.intro') }}</p>
    </header>

    @if (! $allowed)
        <div class="flex flex-col items-start gap-3 rounded-md border border-border bg-surface px-4 py-5 shadow-card">
            <x-bs-admin::icon name="ri-lock" class="size-[calc(1.75rem+var(--user-text-boost))] text-text-muted" />
            <h2 class="m-0 text-[calc(1rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('admin.support.list.noright_title') }}</h2>
            <p class="m-0 max-w-[62ch] text-body">{{ __('admin.support.list.noright_text') }}</p>
        </div>
    @else
        {{-- The list card: groups (from 900) · search + tools · the active-filter line · the rows · the pager. Below 600
             the card frame moves onto the rows, so the tools stand on the page and the first record comes up sooner. --}}
        <div class="@container/ss min-w-0 min-[600px]:rounded-md min-[600px]:border min-[600px]:border-border min-[600px]:bg-surface min-[600px]:shadow-card">
            <x-bs-admin::group-row :groups="$groups" :active="$tab" model="tab" :label="__('admin.support.list.groups')" />

            <div class="flex flex-wrap items-center gap-3 pb-3 min-[600px]:px-4 min-[600px]:pt-4 min-[900px]:pt-3">
                <div class="min-w-0 flex-[1_1_12rem] text-body min-[900px]:max-w-[30em]">
                    <x-bs-admin::search id="support-search" :label="__('admin.support.list.search_label')" :placeholder="__('admin.support.list.search_hint')"
                        maxlength="100" wire:model.live.debounce.300ms="search" />
                </div>
                <x-bs-admin::filter-panel class="shrink-0 flex-nowrap" :sections="$sections" :label="__('admin.list.filter')"
                    :active="$active" :active-narrow="$active + ($tab !== SupportSessions::TAB_ALL ? 1 : 0)"
                    :results="__('admin.support.list.see', ['n' => $num($matched)])" clear="clearFilters" />
                <x-bs-admin::cols-menu class="ms-auto hidden min-[900px]:block" id="support-cols" :options="$colLabels" :selected="$cols"
                    :locked="[__('admin.support.list.col_site'), __('admin.support.list.col_status')]" />
            </div>

            {{-- Below 900: "Đang mở · N phiên · Bỏ lọc" + chips. From 900 the groups say the status, so the line only lists
                 the search, the staff and the period. --}}
            <x-bs-admin::filter-status class="-mx-3 -mt-1 mb-2 min-[600px]:mx-1 min-[900px]:hidden" :group="$groupLabel"
                :summary="$filtering ? __('admin.support.list.summary', ['n' => $num($matched)]) : null" :chips="$chips" :clear="$filtering ? 'clearFilters' : null" />
            @if ($narrowed)
                <x-bs-admin::filter-status class="-mt-1 mb-2 min-[600px]:mx-1 max-[899.98px]:hidden" :summary="__('admin.support.list.filtering', ['n' => $num($matched)])"
                    :chips="$chips" clear="clearFilters" />
            @endif
            @if (! $failed && ($counts['open'] ?? 0) > 0 && $tab !== SupportSessions::TAB_OPEN)
                {{-- below 900 the groups live in the Filter sheet, so the open sessions are named here --}}
                <p class="m-0 mb-2 flex flex-wrap items-center gap-x-1.5 text-body text-text min-[600px]:mx-4 min-[900px]:hidden">
                    <x-bs-admin::icon name="ri-user-shared" class="size-4 shrink-0 text-info-text" />
                    <span>{{ __('admin.support.list.open_note', ['n' => $num($counts['open'])]) }}</span>
                    <span aria-hidden="true">·</span>
                    <button type="button" wire:click="showOpen" class="hit-area inline-flex min-h-control items-center px-1 text-primary-text underline any-pointer-coarse:min-h-hit">{{ __('admin.support.list.open_see') }}</button>
                </p>
            @endif

            <div class="min-w-0 transition-opacity max-[599.98px]:rounded-md max-[599.98px]:border max-[599.98px]:border-border max-[599.98px]:bg-surface max-[599.98px]:[&_.list-g]:border-t-0"
                 wire:loading.class="opacity-60" wire:loading.attr="aria-busy" wire:target="search,tab,staff,days,per,older,newer,clearFilters,clearSearch,clearDays,removeStaff,showOpen,endNow">
                @if ($failed)
                    <x-bs-admin::alert color="danger" icon="ri-error-warning" class="m-4">
                        <span class="flex flex-col items-start gap-3">
                            <h2 class="m-0 text-[calc(1rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('admin.support.list.error_title') }}</h2>
                            <span class="max-w-[62ch]">{{ __('admin.support.list.error_text') }}</span>
                            <x-bs-admin::button wire:click="$refresh"><x-bs-admin::icon name="ri-refresh" class="size-4" />{{ __('admin.support.list.retry') }}</x-bs-admin::button>
                        </span>
                    </x-bs-admin::alert>
                @else
                    <x-bs-admin::list kind="facts" data-cols="support" :label="__('admin.support.list.label')">
                        @if ($rows !== [])
                            <x-bs-admin::list.head>
                                <x-bs-admin::list.cell head col="main">{{ __('admin.support.list.col_site') }}</x-bs-admin::list.cell>
                                @if ($show('staff'))<x-bs-admin::list.cell head col="f1">{{ __('admin.support.list.col_staff') }}</x-bs-admin::list.cell>@endif
                                @if ($show('reason'))<x-bs-admin::list.cell head col="f2">{{ __('admin.support.list.col_reason') }}</x-bs-admin::list.cell>@endif
                                @if ($show('time'))<x-bs-admin::list.cell head col="f3">{{ __('admin.support.list.col_time') }}</x-bs-admin::list.cell>@endif
                                <x-bs-admin::list.cell head col="st">{{ __('admin.support.list.col_status') }}</x-bs-admin::list.cell>
                                <x-bs-admin::list.cell head col="act"><span class="sr-only">{{ __('admin.actions') }}</span></x-bs-admin::list.cell>
                            </x-bs-admin::list.head>
                        @endif

                        @forelse ($rows as $r)
                            @php
                                $open = $r['status'] === 'open';
                                $start = $at($r['started_at']);
                                $end = $at($r['ends_at']);
                                $startText = $start?->format('d/m/Y H:i') ?? '—';
                                $endText = $open || $end === null ? '' : ' – '.($start !== null && $end->isSameDay($start) ? $end->format('H:i') : $end->format('d/m/Y H:i'));
                                $dur = __($open ? 'admin.support.list.minutes' : 'admin.support.list.up_to', ['n' => $r['minutes']]);
                                $site = $r['site_name'] ?? '—';
                                $as = $r['member_roles'] !== []
                                    ? __('admin.support.list.as_member', ['member' => $r['member'], 'roles' => implode(', ', $r['member_roles'])])
                                    : __('admin.support.list.as_member_no_role', ['member' => $r['member']]);
                                $why = in_array($r['why'], $whys, true) ? __('admin.support.list.why_'.$r['why']) : null;
                                $pill = $open ? 'support-open' : 'support-ended';
                                $pillText = __($open ? 'admin.support.list.status_open' : 'admin.support.list.status_ended');
                                $siteHref = $mayViewSites && $r['site_ulid'] !== null ? route('platform.sites.show', $r['site_ulid']) : null;
                                $auditHref = route('platform.audit.index', ['session' => $r['session']]);
                            @endphp
                            <x-bs-admin::list.row wire:key="ss-{{ $r['session'] }}">
                                <x-bs-admin::list.cell col="main">
                                    <x-bs-admin::list.name :href="$siteHref">{{ $site }}</x-bs-admin::list.name>
                                    <p class="list-line m-0">
                                        <x-bs-admin::status-pill :status="$pill">{{ $pillText }}</x-bs-admin::status-pill>
                                        @if ($why !== null)<span>{{ $why }}</span>@endif
                                        @if ($show('staff'))<span>{{ $r['staff'] }}</span>@endif
                                        @if ($show('time'))<span>{{ $start?->format('d/m H:i') ?? '—' }}</span><span>{{ $dur }}</span>@endif
                                    </p>
                                    <p class="list-sub m-0">{{ $as }}</p>
                                    @if ($show('reason'))
                                        <p class="list-rsn m-0">{{ __('admin.support.list.reason_line', ['reason' => $r['reason']]) }}</p>
                                    @endif
                                </x-bs-admin::list.cell>
                                @if ($show('staff'))<x-bs-admin::list.cell col="f1">{{ $r['staff'] }}</x-bs-admin::list.cell>@endif
                                @if ($show('reason'))<x-bs-admin::list.cell col="f2">{{ $r['reason'] }}</x-bs-admin::list.cell>@endif
                                @if ($show('time'))
                                    <x-bs-admin::list.cell col="f3">{{ $startText.$endText }} <span class="list-dur">· {{ $dur }}</span></x-bs-admin::list.cell>
                                @endif
                                <x-bs-admin::list.cell col="st">
                                    <x-bs-admin::status-pill :status="$pill">{{ $pillText }}</x-bs-admin::status-pill>
                                    @if ($why !== null)<span class="list-why">{{ $why }}</span>@endif
                                </x-bs-admin::list.cell>
                                <x-bs-admin::list.cell col="act">
                                    <x-bs-admin::list.actions :name="__('admin.support.list.session_at', ['site' => $site])" :title="__('admin.support.list.menu_title', ['site' => $site])">
                                        <x-slot:menu>
                                            @if ($siteHref !== null)
                                                <x-bs-admin::list.menu-item icon="ri-global" :href="$siteHref" wire:navigate>{{ __('admin.support.list.menu_site') }}</x-bs-admin::list.menu-item>
                                            @else
                                                {{-- locked, never hidden: the item says why, inside itself (a menu holds only items) --}}
                                                <x-bs-admin::list.menu-item icon="ri-lock" aria-disabled="true" class="list-mi-locked">
                                                    {{ __('admin.support.list.menu_site') }}@if (! $mayViewSites)<span class="list-mwhy">{{ __('admin.support.list.locked_site') }}</span>@endif
                                                </x-bs-admin::list.menu-item>
                                            @endif
                                            @if ($mayViewAudit)
                                                <x-bs-admin::list.menu-item icon="ri-file-shield-2" :href="$auditHref" wire:navigate>{{ __('admin.support.list.menu_audit') }}</x-bs-admin::list.menu-item>
                                            @else
                                                <x-bs-admin::list.menu-item icon="ri-lock" aria-disabled="true" class="list-mi-locked">
                                                    {{ __('admin.support.list.menu_audit') }}<span class="list-mwhy">{{ __('admin.support.list.locked_audit') }}</span>
                                                </x-bs-admin::list.menu-item>
                                            @endif
                                            @if ($open)
                                                <x-bs-admin::list.menu-sep />
                                                @if ($r['can_end'])
                                                    <x-bs-admin::list.menu-item icon="ri-logout-box-r" danger aria-haspopup="dialog" wire:click="askEnd({{ Js::from($r['session']) }})">
                                                        {{ __('admin.support.list.menu_end') }}
                                                    </x-bs-admin::list.menu-item>
                                                @else
                                                    <x-bs-admin::list.menu-item icon="ri-lock" aria-disabled="true" class="list-mi-locked">
                                                        {{ __('admin.support.list.menu_end') }}<span class="list-mwhy">{{ __('admin.support.end_not_yours') }}</span>
                                                    </x-bs-admin::list.menu-item>
                                                @endif
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
                                            <h2 class="m-0 text-[calc(1rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('admin.support.list.nomatch_title') }}</h2>
                                            <p class="m-0 max-w-[62ch] text-body text-text-muted">{{ __('admin.support.list.nomatch_text') }}</p>
                                            <x-bs-admin::button variant="outline" color="secondary" wire:click="clearFilters">{{ __('admin.list.clear_filters') }}</x-bs-admin::button>
                                        @else
                                            <h2 class="m-0 text-[calc(1rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('admin.support.list.empty_title') }}</h2>
                                            <p class="m-0 max-w-[62ch] text-body text-text-muted">{{ __('admin.support.list.empty_text') }}</p>
                                            @if ($mayViewSites)
                                                <x-bs-admin::button variant="outline" color="secondary" :href="route('platform.sites.index')" wire:navigate>
                                                    <x-bs-admin::icon name="ri-global" class="size-4" />{{ __('admin.support.list.empty_sites') }}
                                                </x-bs-admin::button>
                                            @endif
                                        @endif
                                    </div>
                                </x-bs-admin::list.cell>
                            </x-bs-admin::list.row>
                        @endforelse
                    </x-bs-admin::list>

                    @if ($rows !== [])
                        <x-bs-admin::list.pager :older="$older" :newer="$newer" :sizes="Index::PER_PAGE" model="per"
                            :label="__('admin.support.list.pager')" :per-label="__('admin.support.list.per_page')"
                            :prev="__('admin.support.list.prev')" :next="__('admin.support.list.next')" />
                    @endif
                @endif
            </div>
        </div>

        <x-bs-admin::confirm-dialog model="endOpen" :title="__('admin.support.list.end_title')" icon="ri-logout-box-r" danger ok-icon="ri-logout-box-r"
            :ok="__('admin.support.list.end_ok')" confirm="endNow" cancel="cancelEnd">
            <p class="m-0 [overflow-wrap:anywhere]">{{ $ending !== '' ? __('admin.support.list.end_text', ['staff' => $endingStaff, 'site' => $endingSite]) : '' }}</p>
        </x-bs-admin::confirm-dialog>
    @endif

    <x-bs-admin::toast :message="$toast" :tone="$toastTone" dismiss="dismissToast" :seq="$toastSeq" />
</div>
