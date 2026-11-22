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
    // A locked control's click: its reason in a toast with the lock mark, kept until the member closes it.
    $lockMark = \Illuminate\Support\Facades\Blade::render('<x-bs-admin::icon name="ri-lock" class="size-4" />');
    $lockToast = fn (string $why): Js => Js::from(['text' => $why, 'icon' => $lockMark, 'stay' => true, 'close' => __('admin.close')]);
    // why no new role can be made now: support signed in for the member, platform staff, or the plan
    $createWhy = $writeLock ?? $planWhy;
    $checkbox = 'size-[min(1.5rem,calc(1.25rem+var(--user-text-boost)))] shrink-0';
@endphp
<div class="flex flex-col gap-4">
    @if ($viewed === null && $editor === null)
        <header class="flex flex-col gap-1">
            <x-bs-admin::title-row :title="__('admin.roles.title')">
                <x-slot:help>
                    <x-bs-admin::guide :guide="$guide" />
                </x-slot:help>
                @if (! $failed)
                    @if ($createWhy !== null)
                        <x-bs-admin::button color="primary" class="shrink-0 aria-disabled:cursor-not-allowed aria-disabled:opacity-60" data-guide="add" aria-disabled="true" :title="$createWhy"
                            :aria-label="__('admin.roles.create').' — '.$createWhy" x-on:click="$bsToast({{ $lockToast($createWhy) }})"
                            :label="__('admin.roles.create')" :short="__('admin.roles.create_short')">
                            <x-bs-admin::icon name="ri-lock" class="size-4" />
                        </x-bs-admin::button>
                    @else
                        {{-- «Tạo nhóm ▾»: a blank role, or one that starts from a role the member may give --}}
                        <div class="relative shrink-0" x-data="{ open: false, up: false, close(back) { this.open = false; if (back) { this.$refs.add.focus() } } }"
                            x-on:click.outside="open = false" x-on:keydown.escape.prevent.stop="close(true)">
                            <x-bs-admin::button color="primary" x-ref="add" data-guide="add" title="{{ __('admin.roles.create_tip') }}"
                                aria-haspopup="menu" aria-expanded="false" ::aria-expanded="open.toString()"
                                x-on:click="up = window.innerHeight - $el.getBoundingClientRect().bottom < 300; open = ! open; open && $nextTick(() => window.bsMenu.move($refs.menu, 'first'))">
                                <x-bs-admin::icon name="ri-add" class="size-4" /><span class="max-md:sr-only">{{ __('admin.roles.create') }}</span><span class="md:hidden" aria-hidden="true">{{ __('admin.roles.create_short') }}</span>
                                <x-bs-admin::icon name="ri-arrow-down-s" class="size-4" />
                            </x-bs-admin::button>
                            <x-bs-admin::list.menu class="list-menu" :label="__('admin.roles.create')">
                                <div class="list-lmh" aria-hidden="true">{{ __('admin.roles.create') }}</div>
                                <x-bs-admin::list.menu-item icon="ri-file-add" wire:click="newRole">{{ __('admin.roles.create_blank') }}</x-bs-admin::list.menu-item>
                                @if ($sources !== [])
                                    <x-bs-admin::list.menu-sep />
                                    <div class="list-lmh" role="presentation">{{ __('admin.roles.create_from_head') }}</div>
                                    @foreach ($sources as $s)
                                        <x-bs-admin::list.menu-item :icon="$s['system'] ? 'ri-shield-user' : 'ri-user-settings'" wire:click="copyRole({{ Js::from($s['key']) }})">{{ __('admin.roles.create_from', ['role' => $s['name']]) }}</x-bs-admin::list.menu-item>
                                    @endforeach
                                @endif
                            </x-bs-admin::list.menu>
                        </div>
                    @endif
                @endif
            </x-bs-admin::title-row>
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

    @if ($editor !== null)
        {{-- The editor of a role of the site's own, or a new role (design board «Nhóm quyền», «Tạo nhóm» / «Sửa nhóm») --}}
        <header class="grid grid-cols-1 gap-1 sm:grid-cols-[minmax(0,1fr)_auto] sm:gap-x-4">
            <div class="sm:col-start-1">
                <button type="button" wire:click="backToList"
                    class="inline-flex min-h-control items-center gap-1 border-0 bg-transparent p-0 text-body text-primary-text hover:underline any-pointer-coarse:min-h-hit">
                    <x-bs-admin::icon name="ri-arrow-left-s" class="size-4.5" /> {{ __('admin.roles.back') }}
                </button>
            </div>
            <x-bs-admin::title-row class="sm:col-start-1" :title="$editor['title']" title-id="roles-edit-title" />
            <p class="m-0 max-w-[72ch] text-body text-text-muted [text-wrap:pretty] sm:col-start-1">{{ $editor['desc'] }}</p>
            <div class="mt-2 sm:col-start-2 sm:row-span-3 sm:row-start-1 sm:mt-0 sm:self-start">
                <x-bs-admin::button variant="outline" color="secondary" id="roles-edit-explain" wire:click="explainEditor">
                    <x-bs-admin::icon name="ri-file-list-3" class="size-4" /> {{ __('admin.members.x_invite_item') }}
                </x-bs-admin::button>
            </div>
        </header>

        <section aria-labelledby="roles-info-h" class="@container/inf min-w-0 rounded-md border border-border bg-surface shadow-card">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-border px-4 py-3">
                <h2 id="roles-info-h" class="m-0 text-[calc(1rem+var(--user-text-boost))] font-semibold leading-snug text-text-strong">{{ __('admin.roles.info_title') }}</h2>
                @if ($editor['from'] !== null)
                    <x-bs-admin::badge color="warning" soft pill class="[overflow-wrap:anywhere]"><x-bs-admin::icon name="ri-git-branch" class="size-3.5" />{{ __('admin.roles.from', ['role' => $editor['from']]) }}</x-bs-admin::badge>
                @endif
            </div>
            <div class="grid grid-cols-1 gap-4 p-4 @min-[48rem]/inf:grid-cols-2">
                <div class="min-w-0">
                    <label for="roles-name" class="mb-1.5 flex items-baseline gap-1 text-body font-semibold text-text-strong">
                        {{ __('admin.roles.name_label') }} <span class="text-danger-text" aria-hidden="true">*</span><span class="sr-only">{{ __('admin.members.required') }}</span>
                        <span class="ms-auto text-caption font-normal text-text-muted" aria-hidden="true" x-data x-text="[...($wire.name ?? '')].length + '/60'">{{ mb_strlen($name) }}/60</span>
                    </label>
                    <input id="roles-name" type="text" maxlength="60" autocomplete="off" placeholder="{{ __('admin.roles.name_ph') }}"
                        wire:model.live.debounce.500ms="name" wire:blur="checkName" aria-describedby="roles-name-d" @if ($nameError !== '') aria-invalid="true" @endif
                        class="field block w-full rounded border border-border-input bg-surface px-3 text-text placeholder:text-placeholder transition-colors focus:border-primary-text aria-invalid:border-danger-text">
                    @if ($nameError !== '')
                        <x-bs-admin::field-error id="roles-name-d" class="mt-1.5">{{ $nameError }}</x-bs-admin::field-error>
                    @else
                        <p id="roles-name-d" class="m-0 mt-1.5 text-small text-text-muted">{{ __('admin.roles.name_hint') }}</p>
                    @endif
                </div>
                <fieldset class="m-0 min-w-0 border-0 p-0" aria-describedby="roles-level-d">
                    <legend class="mb-1.5 p-0 text-body font-semibold text-text-strong">{{ __('admin.roles.level_label') }}</legend>
                    <div class="flex flex-col gap-1.5">
                        @foreach ($editor['levels'] as $opt)
                            <label wire:key="lv-{{ $opt['level'] }}" class="flex min-h-control cursor-pointer items-center gap-2.5 rounded border border-border-strong px-3 py-1 has-checked:border-primary-text has-checked:bg-primary-soft any-pointer-coarse:min-h-hit">
                                <input type="radio" name="roles-level" value="{{ $opt['level'] }}" @checked($opt['level'] === $level) wire:click="pickLevel({{ $opt['level'] }})"
                                    class="m-0 size-4.5 shrink-0 accent-primary">
                                <span class="min-w-0 [overflow-wrap:anywhere]">{{ $opt['label'] }}</span>
                            </label>
                        @endforeach
                    </div>
                    <p id="roles-level-d" class="m-0 mt-1.5 text-small text-text-muted">{{ __('admin.roles.level_hint', ['role' => $editor['me']]) }}</p>
                </fieldset>
            </div>
        </section>
    @elseif ($viewed !== null)
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
            <x-bs-admin::alert color="info" icon="ri-lock-2" role="note" class="[overflow-wrap:anywhere]">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                    <span class="min-w-0 flex-[1_1_15rem]">{{ $viewed['note'] }}</span>
                    @if ($viewed['copyable'])
                        @if ($createWhy !== null)
                            <x-bs-admin::button variant="outline" color="secondary" class="aria-disabled:cursor-not-allowed aria-disabled:opacity-60" aria-disabled="true" aria-describedby="roles-dup-why"
                                x-on:click="$bsToast({{ $lockToast($createWhy) }})">
                                <x-bs-admin::icon name="ri-file-copy" class="size-4" /> {{ __('admin.roles.copy_custom') }}
                            </x-bs-admin::button>
                        @else
                            <x-bs-admin::button variant="outline" color="secondary" wire:click="copyRole({{ Js::from($viewed['key']) }})">
                                <x-bs-admin::icon name="ri-file-copy" class="size-4" /> {{ __('admin.roles.copy_custom') }}
                            </x-bs-admin::button>
                        @endif
                    @endif
                </div>
            </x-bs-admin::alert>
            @if ($viewed['copyable'] && $createWhy !== null && $createWhy !== $viewed['note'])
                <p id="roles-dup-why" class="m-0 -mt-2 flex items-start gap-2 text-small text-text-muted" role="note"><x-bs-admin::icon name="ri-lock-2" class="mt-0.5 size-4 shrink-0" /><span>{{ $createWhy }}</span></p>
            @elseif ($viewed['copyable'] && $createWhy !== null)
                <span id="roles-dup-why" hidden>{{ $createWhy }}</span>
            @endif
        @endif
    @endif

    @if ($editor !== null || $viewed !== null)
        @php $searching = trim($permSearch) !== ''; $areas = $editor['areas'] ?? $viewed['areas']; @endphp
        <section aria-labelledby="roles-perm-h" class="@container/pm min-w-0 rounded-md border border-border bg-surface shadow-card">
            <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2 border-b border-border px-4 py-3">
                <h2 id="roles-perm-h" class="m-0 text-[calc(1rem+var(--user-text-boost))] font-semibold leading-snug text-text-strong">{{ __('admin.roles.perm_title') }}</h2>
                <div class="min-w-0 flex-[1_1_13.75rem] text-body sm:max-w-[21.25rem]">
                    <x-bs-admin::search id="roles-perm-search" :label="__('admin.roles.perm_search_label')" :placeholder="__('admin.roles.perm_search_hint')"
                        :clear-label="__('admin.members.search_clear')" clear-action="clearPermSearch" :clear-on="$searching"
                        maxlength="100" wire:model.live.debounce.300ms="permSearch" />
                </div>
            </div>
            <div class="flex min-w-0 flex-col gap-4 p-4">
                <p class="m-0 text-small text-text-muted">{{ __('admin.roles.perm_help_a') }} <x-bs-admin::icon name="ri-lock-2-fill" class="inline size-4 align-[-0.125em]" /><span class="sr-only">{{ __('admin.roles.perm_help_lock') }}</span> {{ __('admin.roles.perm_help_b') }}</p>
                @foreach ($areas as $area)
                    @php $count = $editor !== null ? $area['count'] : $area['held'].'/'.$area['of']; @endphp
                    <div wire:key="area-{{ $area['key'] }}-{{ $searching ? 'q' : 'all' }}" role="group" aria-label="{{ $area['name'] }}"
                        x-data="{ unfolded: {{ Js::from($searching || $area['key'] === 'entries') }} }" x-bind:data-open="unfolded"
                        class="min-w-0 rounded-md border border-border bg-surface">
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-t-md border-b border-border bg-surface-muted px-3 py-2"
                            x-bind:class="unfolded ? '' : '@max-[48rem]/pm:rounded-b-md @max-[48rem]/pm:border-b-0'">
                            <span class="flex min-w-0 flex-1 items-baseline gap-1.5 font-semibold text-text-strong @max-[48rem]/pm:hidden">
                                {{ $area['name'] }} <span class="text-caption font-normal text-text-muted">{{ $count }}</span>
                            </span>
                            <button type="button" x-on:click="unfolded = ! unfolded" x-bind:aria-expanded="unfolded.toString()" aria-expanded="{{ $searching || $area['key'] === 'entries' ? 'true' : 'false' }}"
                                class="hidden min-h-hit flex-1 items-center gap-2 border-0 bg-transparent p-0 text-start font-semibold text-text-strong @max-[48rem]/pm:inline-flex">
                                <x-bs-admin::icon name="ri-arrow-right-s" class="size-5 shrink-0 transition-transform motion-reduce:transition-none" x-bind:class="unfolded && 'rotate-90'" />
                                <span class="min-w-0 [overflow-wrap:anywhere]">{{ $area['name'] }}</span>
                                <span class="ms-auto text-caption font-normal text-text-muted">{{ $count }}</span>
                            </button>
                            @if ($editor !== null && $area['all'])
                                <button type="button" role="checkbox" aria-checked="{{ $area['allOn'] ? 'true' : 'false' }}" aria-label="{{ __('admin.roles.all_row_aria', ['area' => $area['name']]) }}"
                                    wire:click="toggleArea({{ Js::from($area['key']) }})"
                                    class="inline-flex min-h-control items-center gap-2 border-0 bg-transparent p-0 text-small whitespace-nowrap text-text any-pointer-coarse:min-h-hit @max-[48rem]/pm:min-h-hit">
                                    <x-bs-admin::icon :name="$area['allOn'] ? 'ri-checkbox-fill' : 'ri-checkbox-blank'" @class(['size-[min(1.25rem,calc(1.125rem+var(--user-text-boost)))] shrink-0', 'text-primary-text' => $area['allOn'], 'text-text-muted' => ! $area['allOn']]) />
                                    {{ __('admin.roles.all_row') }}
                                </button>
                            @endif
                        </div>
                        <div x-bind:class="unfolded ? '' : '@max-[48rem]/pm:hidden'">
                            @if ($area['off'] !== '')
                                <p class="m-0 flex items-start gap-2 border-b border-border px-3 py-2 text-small text-text-muted">
                                    <x-bs-admin::icon name="ri-lock-2" class="mt-0.5 size-4 shrink-0" /><span>{{ $area['off'] }}</span>
                                </p>
                            @endif
                            @if (($area['note'] ?? '') !== '')
                                <p class="m-0 flex items-start gap-1.5 border-b border-border bg-warning-soft px-3 py-2 text-small text-warning-text" role="status">
                                    <x-bs-admin::icon name="ri-information" class="mt-0.5 size-4 shrink-0" /><span>{{ $area['note'] }}</span>
                                </p>
                            @endif
                            <div class="grid grid-cols-[repeat(auto-fill,minmax(12.5rem,1fr))] @max-[48rem]/pm:grid-cols-1">
                                @foreach ($area['cells'] as $cell)
                                    @php $cid = 'pc-'.str_replace('.', '-', $cell['key']); @endphp
                                    <div wire:key="{{ $cid }}" class="flex min-w-0 flex-col gap-1 border-e border-b border-border px-3 py-2.5 @max-[48rem]/pm:border-e-0">
                                        @if ($editor === null)
                                            <div class="flex min-w-0 items-start gap-2.5">
                                                <x-bs-admin::icon :name="$cell['on'] ? 'ri-checkbox-fill' : 'ri-checkbox-blank'"
                                                    @class([$checkbox, 'text-primary-text' => $cell['on'], 'text-text-muted' => ! $cell['on']]) />
                                                <span class="min-w-0">
                                                    <span class="font-semibold text-text-strong">{{ $cell['label'] }}</span><span class="sr-only">{{ $cell['on'] ? __('admin.roles.ro_has') : __('admin.roles.ro_hasnt') }}</span>
                                                    <span class="block text-small leading-[1.45] text-text-muted">{{ $cell['desc'] }}</span>
                                                </span>
                                            </div>
                                        @elseif ($cell['state'] === 'free')
                                            {{-- a tick; below a 768 list the whole row is a switch, as the board draws it on a phone --}}
                                            <button type="button" role="checkbox" aria-checked="{{ $cell['on'] ? 'true' : 'false' }}" aria-labelledby="{{ $cid }}-l" aria-describedby="{{ $cid }}-d"
                                                wire:click="toggle({{ Js::from($cell['key']) }})"
                                                class="flex w-full min-w-0 cursor-pointer items-start gap-2.5 border-0 bg-transparent p-0 text-start text-text @max-[48rem]/pm:switch-row @max-[48rem]/pm:min-h-hit @max-[48rem]/pm:flex-row-reverse @max-[48rem]/pm:justify-between">
                                                <x-bs-admin::icon :name="$cell['on'] ? 'ri-checkbox-fill' : 'ri-checkbox-blank'"
                                                    @class([$checkbox, '@max-[48rem]/pm:hidden', 'text-primary-text' => $cell['on'], 'text-text-muted' => ! $cell['on']]) />
                                                <span class="min-w-0 flex-1">
                                                    <span id="{{ $cid }}-l" class="font-semibold text-text-strong">{{ $cell['label'] }}</span>
                                                    <span id="{{ $cid }}-d" class="block text-small leading-[1.45] text-text-muted">{{ $cell['desc'] }}</span>
                                                </span>
                                            </button>
                                        @else
                                            {{-- the member cannot change it (they do not hold it, or the plan switched its area off): a press says why --}}
                                            <div x-data="{ why: false }" class="flex min-w-0 flex-col gap-1">
                                                <button type="button" x-on:click="why = ! why" aria-expanded="false" x-bind:aria-expanded="why.toString()" aria-label="{{ $cell['aria'] }}"
                                                    class="flex w-full min-w-0 cursor-pointer items-start gap-2.5 border-0 bg-transparent p-0 text-start text-text">
                                                    <x-bs-admin::icon :name="$cell['on'] ? 'ri-checkbox-fill' : 'ri-checkbox-blank'" @class([$checkbox, 'text-text-muted']) />
                                                    <span class="min-w-0 flex-1">
                                                        <span class="font-semibold text-text-strong">{{ $cell['label'] }} <x-bs-admin::icon name="ri-lock-2-fill" class="inline size-[min(1.5rem,calc(0.75rem+var(--user-text-boost)))] align-[-0.125em] text-text-muted" /></span>
                                                        <span class="block text-small leading-[1.45] text-text-muted">{{ $cell['desc'] }}</span>
                                                    </span>
                                                </button>
                                                <p x-show="why" x-cloak class="m-0 rounded bg-surface-muted px-2 py-1.5 text-small text-text" role="note">{{ $cell['why'] }}</p>
                                            </div>
                                        @endif
                                        @if ($editor !== null && $cell['changed'])
                                            <span class="flex flex-wrap gap-1"><x-bs-admin::badge color="info" soft pill><x-bs-admin::icon name="ri-edit-circle" class="size-3.5" />{{ __('admin.roles.changed') }}</x-bs-admin::badge></span>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endforeach
                @if ($searching && $areas === [])
                    <p class="m-0 text-small text-text-muted">{{ __('admin.roles.perm_nomatch', ['q' => trim($permSearch)]) }}
                        <button type="button" wire:click="clearPermSearch" class="inline-flex min-h-control items-center border-0 bg-transparent p-0 text-primary-text underline any-pointer-coarse:min-h-hit">{{ __('admin.list.clear_filters') }}</button>
                    </p>
                @endif
            </div>
        </section>
    @endif

    @if ($editor !== null)
        @php $pv = $editor['pv']; @endphp
        <section aria-labelledby="roles-pv-h" class="min-w-0 rounded-md border border-border bg-surface shadow-card">
            <div class="border-b border-border px-4 py-3">
                <h2 id="roles-pv-h" class="m-0 text-[calc(1rem+var(--user-text-boost))] font-semibold leading-snug text-text-strong">{{ __('admin.roles.pv_title') }}</h2>
            </div>
            <div class="flex min-w-0 flex-col gap-2 p-4 text-body [overflow-wrap:anywhere]" role="status" aria-live="polite">
                @if ($pv['none'])
                    <p class="m-0 text-small text-text-muted">{{ __('admin.roles.pv_none', ['base' => $pv['base']]) }}</p>
                @endif
                @if ($pv['add'] !== '')
                    <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1"><x-bs-admin::badge color="success" soft pill><x-bs-admin::icon name="ri-add-circle" class="size-3.5" />{{ __('admin.roles.pv_add') }}</x-bs-admin::badge><b class="min-w-0 font-semibold text-text-strong">{{ $pv['add'] }}</b></div>
                @endif
                @if ($pv['rem'] !== '')
                    <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1"><x-bs-admin::badge color="danger" soft pill><x-bs-admin::icon name="ri-indeterminate-circle" class="size-3.5" />{{ __('admin.roles.pv_rem') }}</x-bs-admin::badge><b class="min-w-0 font-semibold text-text-strong">{{ $pv['rem'] }}</b></div>
                @endif
                @if ($pv['keep'] !== '')
                    <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1"><x-bs-admin::badge color="primary" soft pill><x-bs-admin::icon name="ri-lock-2" class="size-3.5" />{{ __('admin.roles.pv_keep') }}</x-bs-admin::badge><span class="min-w-0">{{ __('admin.roles.pv_keep_text', ['list' => $pv['keep']]) }}</span></div>
                @endif
                @if ($pv['drop'] !== '')
                    <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1"><x-bs-admin::badge color="warning" soft pill><x-bs-admin::icon name="ri-error-warning" class="size-3.5" />{{ __('admin.roles.pv_drop') }}</x-bs-admin::badge><span class="min-w-0">{{ __('admin.roles.pv_drop_text', ['list' => $pv['drop']]) }}</span></div>
                @endif
                <p class="m-0 flex items-start gap-2"><x-bs-admin::icon name="ri-team" class="mt-0.5 size-4 shrink-0 text-text-muted" /><span>{{ $pv['affect'] }}</span></p>
                <p class="m-0 flex items-start gap-2 text-small text-text-muted"><x-bs-admin::icon name="ri-history" class="mt-0.5 size-4 shrink-0" /><span>{{ __('admin.roles.pv_audit') }}</span></p>
            </div>
        </section>

        <div class="sticky bottom-0 z-10 flex flex-wrap items-center justify-end gap-x-3 gap-y-2 rounded-md border border-border bg-surface px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] shadow-[0_-1px_2px_rgb(56_65_74/0.1)]"
            role="region" aria-label="{{ __('admin.roles.savebar') }}">
            <span class="min-w-0 flex-[1_1_13.75rem] text-small text-text-muted" role="status">
                @if ($editor['dirty'] > 0)
                    <span class="inline-flex items-center gap-1.5 font-semibold text-warning-text"><x-bs-admin::icon name="ri-error-warning" class="size-4 shrink-0" />{{ trans_choice('admin.roles.changes', $editor['dirty'], ['n' => $num($editor['dirty'])]) }}</span>
                @else
                    {{ __('admin.roles.no_changes') }}
                @endif
            </span>
            <x-bs-admin::button variant="outline" color="secondary" class="max-[29.99rem]:flex-1" wire:click="backToList">{{ __('admin.cancel') }}</x-bs-admin::button>
            <x-bs-admin::button color="primary" id="roles-save" class="max-[29.99rem]:flex-1 aria-disabled:cursor-not-allowed aria-disabled:opacity-60" wire:click="save" wire:loading.attr="aria-busy" wire:target="save"
                :aria-disabled="$editor['saveOff'] ? 'true' : null">
                <x-bs-admin::icon name="ri-save-3" class="size-4" /> {{ $editor['new'] ? __('admin.roles.save_new') : __('admin.roles.save') }}
            </x-bs-admin::button>
        </div>

        <x-bs-admin::unsaved-dialog :count="$editor['dirty']" :label="$editor['title']" save="save" discard="discardDraft"
            :save-label="$editor['new'] ? __('admin.roles.leave_save_new') : __('admin.roles.leave_save')" />
    @elseif ($viewed !== null)
        {{-- the role's page ends with its permissions --}}
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
            <div role="group" aria-label="{{ __('admin.roles.kinds_label') }}" class="flex flex-wrap gap-2" data-guide="chips">
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
                    @php $id = $rid($r['key']); $first = $loop->first; @endphp
                    <x-bs-admin::list.row wire:key="role-{{ $id }}" id="role-{{ $id }}">
                        <x-bs-admin::list.cell col="main">
                            <span class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                                <x-bs-admin::list.name :act="'openRole('.Js::from($r['key']).')'" :data-guide="$first ? 'name' : null">{{ $r['name'] }}</x-bs-admin::list.name>
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
                                <a href="{{ route('admin.users.index', ['nhom' => [$r['key']]]) }}" wire:navigate @if ($first) data-guide="members" @endif
                                    aria-label="{{ __('admin.roles.members_aria', ['members' => $r['membersText'], 'role' => $r['name']]) }}"
                                    class="inline-flex min-h-control items-center text-primary-text hover:underline any-pointer-coarse:min-h-hit">{{ trans_choice('admin.members.x_members', $r['members'], ['n' => $num($r['members'])]) }}</a>
                                @if ($r['invites'] > 0)
                                    <span class="text-caption text-text-muted [overflow-wrap:anywhere]">{{ trans_choice('admin.roles.invites_waiting', $r['invites'], ['n' => $num($r['invites'])]) }}</span>
                                @endif
                            </span>
                        </x-bs-admin::list.cell>
                        <x-bs-admin::list.cell col="f3">
                            {{ $r['typical'] }}
                            <button type="button" id="xd-{{ $id }}" wire:click="openExplain({{ Js::from($r['key']) }}, 'xd-{{ $id }}')" @if ($first) data-guide="explain" @endif
                                aria-label="{{ __('admin.roles.explain_aria', ['role' => $r['name']]) }}"
                                class="inline-flex min-h-control items-center border-0 bg-transparent px-2 py-0 align-middle whitespace-nowrap text-primary-text hover:underline any-pointer-coarse:min-h-hit">{{ __('admin.roles.explain_more') }}</button>
                        </x-bs-admin::list.cell>
                        <x-bs-admin::list.cell col="act">
                            <x-bs-admin::list.actions :name="$r['name']">
                                @if ($r['editable'])
                                    <x-bs-admin::button size="sm" variant="soft" color="primary" data-row-btn
                                        :aria-label="__('admin.roles.edit_aria', ['role' => $r['name']])" wire:click="openRole({{ Js::from($r['key']) }})">
                                        <x-bs-admin::icon name="ri-pencil" class="size-4" /> {{ __('admin.roles.edit') }}
                                    </x-bs-admin::button>
                                @else
                                    <x-bs-admin::button size="sm" variant="soft" color="primary" data-row-btn
                                        :aria-label="__('admin.roles.view_aria', ['role' => $r['name']])" wire:click="openRole({{ Js::from($r['key']) }})">
                                        <x-bs-admin::icon name="ri-eye" class="size-4" /> {{ __('admin.roles.view') }}
                                    </x-bs-admin::button>
                                @endif
                                <x-slot:menu>
                                    <x-bs-admin::list.menu-item icon="ri-file-list-3" wire:click="openExplain({{ Js::from($r['key']) }}, 'xd-{{ $id }}')">{{ __('admin.members.x_invite_item') }}</x-bs-admin::list.menu-item>
                                    @if ($r['copyable'])
                                        @if ($createWhy !== null)
                                            <x-bs-admin::list.menu-item icon="ri-file-copy" :why="$createWhy">{{ __('admin.roles.copy') }}</x-bs-admin::list.menu-item>
                                        @else
                                            <x-bs-admin::list.menu-item icon="ri-file-copy" wire:click="copyRole({{ Js::from($r['key']) }})">{{ __('admin.roles.copy') }}</x-bs-admin::list.menu-item>
                                        @endif
                                    @endif
                                    @if ($r['deletable'])
                                        <x-bs-admin::list.menu-sep />
                                        @if ($writeLock !== null)
                                            <x-bs-admin::list.menu-item icon="ri-delete-bin" danger :why="$writeLock">{{ __('admin.roles.delete') }}</x-bs-admin::list.menu-item>
                                        @else
                                            <x-bs-admin::list.menu-item icon="ri-delete-bin" danger wire:click="askDelete({{ Js::from($r['key']) }})">{{ __('admin.roles.delete') }}</x-bs-admin::list.menu-item>
                                        @endif
                                    @endif
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
                @if ($planOn)
                    <div class="mt-4 flex justify-center">
                        @if ($writeLock !== null)
                            <x-bs-admin::button color="primary" class="aria-disabled:cursor-not-allowed aria-disabled:opacity-60" aria-disabled="true" :title="$writeLock" x-on:click="$bsToast({{ $lockToast($writeLock) }})">
                                <x-bs-admin::icon name="ri-lock" class="size-4" /> {{ __('admin.roles.create') }}
                            </x-bs-admin::button>
                        @else
                            <x-bs-admin::button color="primary" wire:click="newRole"><x-bs-admin::icon name="ri-add" class="size-4" /> {{ __('admin.roles.create') }}</x-bs-admin::button>
                        @endif
                    </div>
                @endif
            </x-bs-admin::card>
        @endif
    @endif

    @if ($explain !== null)
        <x-bs-admin::drawer :title="__('admin.members.x_title', ['role' => $explain['name']])" :subtitle="$explain['meta']"
            close="closeExplain" :return-to="$explainFrom !== '' ? $explainFrom : null" :close-label="__('admin.members.x_close')">
            <x-bs-admin::role-explain :areas="$explain['areas']" id-prefix="roles-x" />
            <x-slot:footer>
                @if ($explain['editable'])
                    <x-bs-admin::button variant="outline" color="secondary" wire:click="openRole({{ Js::from($explain['key']) }})">
                        <x-bs-admin::icon name="ri-pencil" class="size-4" /> {{ __('admin.roles.explain_edit') }}
                    </x-bs-admin::button>
                @endif
                <x-bs-admin::button color="primary" x-on:click="hide()">{{ __('admin.close') }}</x-bs-admin::button>
            </x-slot:footer>
        </x-bs-admin::drawer>
    @endif

    @if ($dialog !== null)
        <x-bs-admin::confirm-dialog model="asking" :title="$dialog['title']" :icon="$dialog['icon']" :danger="$dialog['danger']" :ok="$dialog['ok']"
            confirm="confirmAsk" cancel="cancelAsk" :cancel-label="$dialog['cancel']" :ok-icon="$dialog['icon']">
            @foreach ($dialog['lines'] as $line)
                <x-bs-admin::confirm-line icon="ri-information">{{ $line }}</x-bs-admin::confirm-line>
            @endforeach
        </x-bs-admin::confirm-dialog>
    @endif

    <x-bs-admin::toast :message="$toast" :tone="$toastTone" dismiss="dismissToast" :seq="$toastSeq" :undo="$toastUndo ? 'undoDelete' : null"
        :action="$toastReload ? 'reloadList' : null" :action-label="$toastReload ? __('admin.members.reload') : null" />
</div>
