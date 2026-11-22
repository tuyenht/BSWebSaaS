@php
    use Illuminate\Support\HtmlString;
    use Illuminate\Support\Js;
    use Illuminate\Support\Number;

    $num = fn (int $n): string => Number::format($n, locale: app()->getLocale());
    // a stable id per role for the buttons the side panel gives the focus back to (a name may hold any character)
    $rid = fn (string $key): string => 'r'.substr(md5($key), 0, 10);
    $chips = [
        ['key' => 'all', 'label' => __('admin.roles.k_all'), 'count' => $total],
        ['key' => 'sys', 'label' => __('admin.roles.k_sys'), 'count' => $total - $custom],
        ['key' => 'cust', 'label' => __('admin.roles.k_cust'), 'count' => $custom],
    ];
    $q = trim($search);
    // "Tìm thấy N trong M nhóm" with N in bold, as the board draws it (both numbers are formatted integers)
    $found = new HtmlString(__('admin.roles.found', ['n' => '<strong class="font-semibold text-text-strong">'.e($num(count($rows))).'</strong>', 'm' => e($num($total))]));
@endphp
<div class="flex flex-col gap-4">
    @if ($viewed === null)
        <header class="flex flex-col gap-1">
            <x-bs-admin::title-row :title="__('admin.roles.title')" />
            <p class="m-0 max-w-[72ch] text-body text-text-muted [text-wrap:pretty]">{{ __('admin.roles.desc') }}</p>
        </header>
    @endif

    @if ($support)
        <x-bs-admin::alert color="warning" icon="ri-user-shared" role="note" class="[overflow-wrap:anywhere]">
            <b class="text-text-strong">{{ __('admin.members.support_title') }}</b> {{ __('admin.roles.support_text') }}
        </x-bs-admin::alert>
    @elseif ($lockedWhy !== null)
        <x-bs-admin::alert color="warning" icon="ri-shield-user" role="note" class="[overflow-wrap:anywhere]">{{ $lockedWhy }}</x-bs-admin::alert>
    @endif

    @if ($viewed !== null)
        {{-- One role read-only: what it may do, area by area (design board «Nhóm quyền», «chỉ xem») --}}
        {{-- the button sits at the top right from 640, under the description on a phone, as the board draws it --}}
        <header class="grid grid-cols-1 gap-1 sm:grid-cols-[minmax(0,1fr)_auto] sm:gap-x-4">
            <div class="sm:col-start-1">
                <button type="button" wire:click="backToList"
                    class="inline-flex min-h-control items-center gap-1 border-0 bg-transparent p-0 text-body text-primary-text hover:underline any-pointer-coarse:min-h-hit">
                    <x-bs-admin::icon name="ri-arrow-left-s" class="size-4.5" /> {{ __('admin.roles.back') }}
                </button>
            </div>
            <x-bs-admin::title-row class="sm:col-start-1" :title="__('admin.roles.ro_title', ['role' => $viewed['name']])" title-id="roles-view-title" />
            <p class="m-0 max-w-[72ch] text-body text-text-muted [text-wrap:pretty] sm:col-start-1">{{ $viewed['desc'] }}</p>
            <div class="mt-2 sm:col-start-2 sm:row-span-3 sm:row-start-1 sm:mt-0 sm:self-start">
                <x-bs-admin::button variant="outline" color="secondary" id="roles-view-explain"
                    wire:click="openExplain({{ Js::from($viewed['key']) }}, 'roles-view-explain')">
                    <x-bs-admin::icon name="ri-file-list-3" class="size-4" /> {{ __('admin.members.x_invite_item') }}
                </x-bs-admin::button>
            </div>
        </header>

        @if ($viewed['note'] !== null)
            <x-bs-admin::alert color="info" icon="ri-lock-2" role="note" class="[overflow-wrap:anywhere]">{{ $viewed['note'] }}</x-bs-admin::alert>
        @endif

        <section aria-labelledby="roles-perm-h" class="@container/pm min-w-0 rounded-md border border-border bg-surface shadow-card">
            <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2 border-b border-border px-4 py-3">
                <h2 id="roles-perm-h" class="m-0 text-[calc(1rem+var(--user-text-boost))] font-semibold leading-snug text-text-strong">{{ __('admin.roles.perm_title') }}</h2>
                <div class="min-w-0 flex-[1_1_13.75rem] text-body sm:max-w-[21.25rem]">
                    <x-bs-admin::search id="roles-perm-search" :label="__('admin.roles.perm_search_label')" :placeholder="__('admin.roles.perm_search_hint')"
                        :clear-label="__('admin.members.search_clear')" clear-action="clearPermSearch" :clear-on="trim($permSearch) !== ''"
                        maxlength="100" wire:model.live.debounce.300ms="permSearch" />
                </div>
            </div>
            <div class="flex min-w-0 flex-col gap-4 p-4">
                <p class="m-0 text-small text-text-muted">{{ __('admin.roles.perm_help_a') }} <x-bs-admin::icon name="ri-lock-2-fill" class="inline size-4 align-[-0.125em]" /><span class="sr-only">{{ __('admin.roles.perm_help_lock') }}</span> {{ __('admin.roles.perm_help_b') }}</p>
                @php $searching = trim($permSearch) !== ''; @endphp
                @foreach ($viewed['areas'] as $area)
                    <div wire:key="area-{{ $area['key'] }}-{{ $searching ? 'q' : 'all' }}" role="group" aria-label="{{ $area['name'] }}"
                        x-data="{ unfolded: {{ Js::from($searching || $area['key'] === 'entries') }} }" x-bind:data-open="unfolded"
                        class="min-w-0 rounded-md border border-border bg-surface">
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-t-md border-b border-border bg-surface-muted px-3 py-2"
                            x-bind:class="unfolded ? '' : '@max-[48rem]/pm:rounded-b-md @max-[48rem]/pm:border-b-0'">
                            <span class="flex min-w-0 flex-1 items-baseline gap-1.5 font-semibold text-text-strong @max-[48rem]/pm:hidden">
                                {{ $area['name'] }} <span class="text-caption font-normal text-text-muted">{{ $area['held'] }}/{{ $area['of'] }}</span>
                            </span>
                            <button type="button" x-on:click="unfolded = ! unfolded" x-bind:aria-expanded="unfolded.toString()" aria-expanded="{{ $searching || $area['key'] === 'entries' ? 'true' : 'false' }}"
                                class="hidden min-h-hit flex-1 items-center gap-2 border-0 bg-transparent p-0 text-start font-semibold text-text-strong @max-[48rem]/pm:inline-flex">
                                <x-bs-admin::icon name="ri-arrow-right-s" class="size-5 shrink-0 transition-transform motion-reduce:transition-none" x-bind:class="unfolded && 'rotate-90'" />
                                <span class="min-w-0 [overflow-wrap:anywhere]">{{ $area['name'] }}</span>
                                <span class="ms-auto text-caption font-normal text-text-muted">{{ $area['held'] }}/{{ $area['of'] }}</span>
                            </button>
                        </div>
                        <div x-bind:class="unfolded ? '' : '@max-[48rem]/pm:hidden'">
                            @if ($area['off'] !== '')
                                <p class="m-0 flex items-start gap-2 border-b border-border px-3 py-2 text-small text-text-muted">
                                    <x-bs-admin::icon name="ri-lock-2" class="mt-0.5 size-4 shrink-0" /><span>{{ $area['off'] }}</span>
                                </p>
                            @endif
                            <div class="grid grid-cols-[repeat(auto-fill,minmax(12.5rem,1fr))] @max-[48rem]/pm:grid-cols-1">
                                @foreach ($area['cells'] as $cell)
                                    <div class="flex min-w-0 items-start gap-2.5 border-e border-b border-border px-3 py-2.5">
                                        <x-bs-admin::icon :name="$cell['on'] ? 'ri-checkbox-fill' : 'ri-checkbox-blank'"
                                            @class(['size-[min(1.5rem,calc(1.25rem+var(--user-text-boost)))] shrink-0', 'text-primary-text' => $cell['on'], 'text-text-muted' => ! $cell['on']]) />
                                        <span class="min-w-0">
                                            <span class="font-semibold text-text-strong">{{ $cell['label'] }}</span><span class="sr-only">{{ $cell['on'] ? __('admin.roles.ro_has') : __('admin.roles.ro_hasnt') }}</span>
                                            <span class="block text-small leading-[1.45] text-text-muted">{{ $cell['desc'] }}</span>
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endforeach
                @if ($searching && $viewed['areas'] === [])
                    <p class="m-0 text-small text-text-muted">{{ __('admin.roles.perm_nomatch', ['q' => trim($permSearch)]) }}
                        <button type="button" wire:click="clearPermSearch" class="inline-flex min-h-control items-center border-0 bg-transparent p-0 text-primary-text underline any-pointer-coarse:min-h-hit">{{ __('admin.list.clear_filters') }}</button>
                    </p>
                @endif
            </div>
        </section>
    @elseif ($failed)
        <x-bs-admin::alert color="danger" icon="ri-error-warning">
            <div class="flex flex-wrap items-start gap-x-3 gap-y-2">
                <p class="m-0 min-w-0 flex-[1_1_15rem] text-text"><b class="text-text-strong">{{ __('admin.roles.error_title') }}</b> {{ __('admin.roles.error_text') }}</p>
                <x-bs-admin::button variant="outline" color="secondary" wire:click="$refresh"><x-bs-admin::icon name="ri-refresh" class="size-4" /> {{ __('admin.list.retry') }}</x-bs-admin::button>
            </div>
        </x-bs-admin::alert>
    @else
        @if (! $planOn)
            <x-bs-admin::alert color="info" icon="ri-lock-2" role="note" class="[overflow-wrap:anywhere]">
                <span class="text-text">
                    @if ($upsell)
                        <b class="text-text-strong">{{ __('admin.roles.plan_up_title') }}</b> {{ __('admin.roles.plan_up_text') }}
                    @else
                        <b class="text-text-strong">{{ __('admin.roles.plan_off_title') }}</b> {{ __('admin.roles.plan_off_text') }}
                    @endif
                    @if ($custom > 0) {{ __('admin.roles.plan_kept') }}@endif
                </span>
            </x-bs-admin::alert>
        @endif

        <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
            <div class="min-w-0 flex-[1_1_16.25rem] text-body sm:max-w-[26.25rem]">
                <x-bs-admin::search id="roles-search" :label="__('admin.roles.search_label')" :placeholder="__('admin.roles.search_hint')"
                    :clear-label="__('admin.members.search_clear')" clear-action="clearFilters" :clear-on="$q !== ''"
                    maxlength="100" wire:model.live.debounce.300ms="search" />
            </div>
            <div role="group" aria-label="{{ __('admin.roles.kinds_label') }}" class="flex flex-wrap gap-2">
                @foreach ($chips as $chip)
                    <button type="button" wire:click="setKind({{ Js::from($chip['key']) }})" aria-pressed="{{ $kind === $chip['key'] ? 'true' : 'false' }}"
                        class="inline-flex min-h-9 max-w-full items-center gap-1.5 rounded-full border border-border-input bg-surface px-3 text-small whitespace-nowrap text-text hover:bg-surface-hover aria-pressed:border-primary aria-pressed:bg-primary aria-pressed:font-semibold aria-pressed:text-on-primary any-pointer-coarse:min-h-hit">
                        {{ $chip['label'] }} <b class="font-semibold">{{ $num($chip['count']) }}</b>
                    </button>
                @endforeach
            </div>
        </div>

        {{-- the board's status line: "Tìm thấy N trong M nhóm · Bỏ lọc", the found count in bold, no condition chips --}}
        <x-bs-admin::filter-status class="-mx-3 -my-2" alone
            :summary="$filtering ? $found : null"
            :clear="$filtering ? 'clearFilters' : null" />

        <div class="min-w-0 rounded-md border border-border bg-surface shadow-card">
            <x-bs-admin::list kind="facts" data-cols="roles" :label="__('admin.roles.list_label')" busy="search,setKind,clearFilters,$refresh">
                @if ($rows !== [])
                    <x-bs-admin::list.head>
                        <x-bs-admin::list.cell head col="main">{{ __('admin.roles.col_name') }}</x-bs-admin::list.cell>
                        <x-bs-admin::list.cell head col="f1">{{ __('admin.roles.col_pos') }}</x-bs-admin::list.cell>
                        <x-bs-admin::list.cell head col="f2">{{ __('admin.roles.col_members') }}</x-bs-admin::list.cell>
                        <x-bs-admin::list.cell head col="f3">{{ __('admin.roles.col_typical') }}</x-bs-admin::list.cell>
                        <x-bs-admin::list.cell head col="act"><span class="sr-only">{{ __('admin.actions') }}</span></x-bs-admin::list.cell>
                    </x-bs-admin::list.head>
                @endif

                @foreach ($rows as $r)
                    @php $id = $rid($r['key']); @endphp
                    <x-bs-admin::list.row wire:key="role-{{ $id }}" id="role-{{ $id }}">
                        <x-bs-admin::list.cell col="main">
                            <span class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                                <x-bs-admin::list.name :act="'openRole('.Js::from($r['key']).')'">{{ $r['name'] }}</x-bs-admin::list.name>
                                @if ($r['system'])
                                    <x-bs-admin::badge color="primary" soft pill><x-bs-admin::icon name="ri-lock-2" class="size-3.5" />{{ __('admin.roles.chip_sys') }}</x-bs-admin::badge>
                                @else
                                    <x-bs-admin::badge color="warning" soft pill><x-bs-admin::icon name="ri-user-settings" class="size-3.5" />{{ __('admin.roles.chip_cust') }}</x-bs-admin::badge>
                                @endif
                            </span>
                            @if ($r['from'] !== null)
                                <p class="m-0 text-small text-text-muted [overflow-wrap:anywhere]">{{ __('admin.roles.from', ['role' => $r['from']]) }}</p>
                            @endif
                            @if ($r['desc'] !== null)
                                <p class="m-0 text-small text-text-muted [text-wrap:pretty]">{{ $r['desc'] }}</p>
                            @endif
                            <p class="list-line m-0"><span>{{ $r['pos'] }}</span><span>{{ $r['membersText'] }}</span></p>
                        </x-bs-admin::list.cell>
                        <x-bs-admin::list.cell col="f1">{{ $r['pos'] }}</x-bs-admin::list.cell>
                        <x-bs-admin::list.cell col="f2">
                            <span class="flex flex-col items-start gap-0.5">
                                <a href="{{ route('admin.users.index', ['nhom' => [$r['key']]]) }}" wire:navigate
                                    aria-label="{{ __('admin.roles.members_aria', ['members' => $r['membersText'], 'role' => $r['name']]) }}"
                                    class="inline-flex min-h-control items-center text-primary-text hover:underline any-pointer-coarse:min-h-hit">{{ trans_choice('admin.members.x_members', $r['members'], ['n' => $num($r['members'])]) }}</a>
                                @if ($r['invites'] > 0)
                                    <span class="text-caption text-text-muted [overflow-wrap:anywhere]">{{ trans_choice('admin.roles.invites_waiting', $r['invites'], ['n' => $num($r['invites'])]) }}</span>
                                @endif
                            </span>
                        </x-bs-admin::list.cell>
                        <x-bs-admin::list.cell col="f3">
                            {{ $r['typical'] }}
                            <button type="button" id="xd-{{ $id }}" wire:click="openExplain({{ Js::from($r['key']) }}, 'xd-{{ $id }}')"
                                aria-label="{{ __('admin.roles.explain_aria', ['role' => $r['name']]) }}"
                                class="inline-flex min-h-control items-center border-0 bg-transparent px-2 py-0 align-middle whitespace-nowrap text-primary-text hover:underline any-pointer-coarse:min-h-hit">{{ __('admin.roles.explain_more') }}</button>
                        </x-bs-admin::list.cell>
                        <x-bs-admin::list.cell col="act">
                            <x-bs-admin::list.actions :name="$r['name']">
                                <x-bs-admin::button size="sm" variant="soft" color="primary" data-row-btn
                                    :aria-label="__('admin.roles.view_aria', ['role' => $r['name']])" wire:click="openRole({{ Js::from($r['key']) }})">
                                    <x-bs-admin::icon name="ri-eye" class="size-4" /> {{ __('admin.roles.view') }}
                                </x-bs-admin::button>
                                <x-slot:menu>
                                    <x-bs-admin::list.menu-item icon="ri-file-list-3" wire:click="openExplain({{ Js::from($r['key']) }}, 'xd-{{ $id }}')">{{ __('admin.members.x_invite_item') }}</x-bs-admin::list.menu-item>
                                </x-slot:menu>
                            </x-bs-admin::list.actions>
                        </x-bs-admin::list.cell>
                    </x-bs-admin::list.row>
                @endforeach

                @if ($rows === [] && $q !== '')
                    <x-bs-admin::list.row>
                        <x-bs-admin::list.cell col="full">
                            <div class="flex flex-col items-center gap-3 px-4 py-6 text-center">
                                <p class="m-0 text-body text-text-muted [overflow-wrap:anywhere]">{{ __('admin.roles.nomatch', ['q' => $q]) }}</p>
                                <x-bs-admin::button variant="outline" color="secondary" wire:click="clearFilters">{{ __('admin.list.clear_filters') }}</x-bs-admin::button>
                            </div>
                        </x-bs-admin::list.cell>
                    </x-bs-admin::list.row>
                @endif
            </x-bs-admin::list>
        </div>

        @if ($custom === 0 && $kind !== 'sys' && $q === '')
            <x-bs-admin::card flush data-state="empty-custom" class="min-w-0 p-6 text-center">
                <h2 class="m-0 text-[calc(1rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('admin.roles.empty_title') }}</h2>
                <p class="m-0 mx-auto mt-1 max-w-[62ch] text-body text-text-muted">{{ __('admin.roles.empty_text') }}</p>
            </x-bs-admin::card>
        @endif
    @endif

    @if ($explain !== null)
        <x-bs-admin::drawer :title="__('admin.members.x_title', ['role' => $explain['name']])" :subtitle="$explain['meta']"
            close="closeExplain" :return-to="$explainFrom !== '' ? $explainFrom : null" :close-label="__('admin.members.x_close')">
            <x-bs-admin::role-explain :areas="$explain['areas']" id-prefix="roles-x" />
            <x-slot:footer>
                <x-bs-admin::button color="primary" x-on:click="hide()">{{ __('admin.close') }}</x-bs-admin::button>
            </x-slot:footer>
        </x-bs-admin::drawer>
    @endif

    <x-bs-admin::toast :message="$toast" :tone="$toastTone" dismiss="dismissToast" :seq="$toastSeq"
        :action="$toastReload ? 'reloadList' : null" :action-label="$toastReload ? __('admin.members.reload') : null" />
</div>
