@php
    use App\Modules\Admin\Support\FirstHints;
    use App\Modules\Admin\Support\Members\MemberDirectory;
    use App\Modules\Rbac\Registry\PermissionRegistry;
    use App\Modules\Rbac\Support\RoleLabel;
    use Illuminate\Support\Js;
    use Illuminate\Support\Str;
    use Illuminate\Support\Number;

    $num = fn (int $n): string => Number::format($n, locale: app()->getLocale());
    // An email may break after "@" and "." only, never inside a word
    $email = fn (string $e): string => str_replace(['@', '.'], ['@<wbr>', '.<wbr>'], e($e));
    $first = fn (string $name): string => Str::afterLast(trim($name), ' ');
    $myRole = RoleLabel::topOf($me) ?? __('admin.members.your_role');

    // What a role lets its holder do, in a few words (the most telling permissions first).
    $typical = ['settings.manage', 'users.manage', 'entries.publish', 'entries.approve', 'entries.edit.any', 'catalog.manage', 'entries.review', 'entries.create', 'entries.edit.own', 'media.upload', 'forms.manage', 'entries.submit', 'entries.view', 'media.view', 'forms.view', 'catalog.view'];
    $permLabel = fn (string $p): string => __('admin.members.perm.'.$p);
    $describe = function ($role) use ($typical, $permLabel): string {
        $perms = $role->permissions->pluck('name')->all();
        if (count(array_intersect(PermissionRegistry::TENANT_PERMISSIONS, $perms)) >= count(PermissionRegistry::TENANT_PERMISSIONS)) {
            return __('admin.members.does_all');
        }
        $top = array_slice(array_values(array_intersect($typical, $perms)), 0, 3);
        $rest = count($perms) - count($top);
        if ($top === []) {
            return __('admin.members.does_nothing');
        }
        $list = implode(', ', array_map($permLabel, $top));

        return $rest > 0 ? __('admin.members.does_some_more', ['list' => $list, 'n' => $rest]) : __('admin.members.does_some', ['list' => $list]);
    };

    // The role filter: every role someone on this site holds (and any chosen one), highest first.
    $groupOptions = $siteRoles->filter(fn ($r): bool => ($roleCounts[$r->name] ?? 0) > 0 || in_array($r->name, $groups, true))
        ->map(fn ($r): array => ['value' => $r->name, 'label' => RoleLabel::for($r), 'count' => $roleCounts[$r->name] ?? 0])->values()->all();
    $sections = $groupOptions === [] ? [] : [[
        'key' => 'roles', 'label' => __('admin.members.f_roles'), 'icon' => 'ri-shield-user', 'model' => 'groups', 'multiple' => true,
        'value' => $groups, 'options' => $groupOptions, 'reset' => 'clearGroups',
    ]];
    $q = trim($search);
    $filtering = $searching || $groups !== [];
    $chips = [];
    if ($searching) {
        $chips[] = ['label' => __('admin.members.chip_search', ['q' => $q]), 'remove' => 'clearSearch'];
    }
    foreach ($groups as $g) {
        $r = $siteRoles->firstWhere('name', $g);
        if ($r !== null) {
            $chips[] = ['label' => RoleLabel::for($r), 'icon' => 'ri-shield-user', 'remove' => 'removeGroup('.Js::from($g).')',
                'aria' => __('admin.members.chip_role_aria', ['role' => RoleLabel::for($r)])];
        }
    }
    $unit = __('admin.members.unit');
    $hasMe = $rows->contains(fn ($u): bool => $u->id === $me->id);
    $locked = $support;
@endphp
<div class="flex flex-col gap-4">
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div class="flex min-w-0 flex-[1_1_20rem] flex-col gap-1">
            <h1 class="m-0 text-[calc(1.25rem+var(--user-text-boost))] font-semibold leading-snug text-text-strong [overflow-wrap:anywhere]">{{ __('admin.members.title') }}</h1>
            <p class="m-0 max-w-[72ch] text-body text-text-muted [text-wrap:pretty]">{{ __('admin.members.desc') }}</p>
        </div>
        @if ($canInvite)
            <x-bs-admin::button color="primary" id="members-invite" aria-disabled="{{ $locked ? 'true' : 'false' }}" class="aria-disabled:opacity-60"
                :title="$locked ? __('admin.support.locked') : null" wire:click="openInvite">
                <x-bs-admin::icon name="ri-user-add" class="size-4" /> {{ __('admin.members.invite') }}
            </x-bs-admin::button>
        @endif
    </header>

    @if ($support)
        <div role="note" class="flex items-start gap-2 rounded bg-warning-soft px-3.5 py-3 text-warning-text">
            <x-bs-admin::icon name="ri-user-shared" class="mt-0.5 size-5 shrink-0" />
            <span class="min-w-0 [overflow-wrap:anywhere]"><b>{{ __('admin.members.support_title') }}</b> {{ __('admin.members.support_text') }}</span>
        </div>
    @endif

    <div class="@container/tv min-w-0 min-[600px]:rounded-md min-[600px]:border min-[600px]:border-border min-[600px]:bg-surface min-[600px]:shadow-card">
        <div class="flex flex-wrap items-center gap-3 pb-3 min-[600px]:px-4 min-[600px]:pt-4">
            <div class="min-w-0 flex-[1_1_12rem] text-body min-[900px]:max-w-[30em]">
                <x-bs-admin::search id="members-search" :label="__('admin.members.search_label')" :placeholder="__('admin.members.search_hint')"
                    :clear-label="__('admin.members.search_clear')" clear-action="clearSearch" :clear-on="$q !== ''"
                    maxlength="{{ MemberDirectory::SEARCH_MAX }}" wire:model.live.debounce.300ms="search" />
            </div>
            @if ($sections !== [])
                <x-bs-admin::filter-panel class="shrink-0 flex-nowrap min-[900px]:ms-auto" :sections="$sections" :label="__('admin.list.filter')"
                    :active="count($groups)" :results="__('admin.members.f_see', ['n' => $num($matched), 'unit' => $unit])" clear="clearGroups" />
            @endif
        </div>

        <x-bs-admin::filter-status class="-mx-3 -mt-1 mb-2 min-[600px]:mx-1"
            :summary="$filtering ? __('admin.members.match_of', ['n' => $num($matched), 'm' => $num($total), 'unit' => $unit]) : null"
            :chips="$chips" :clear="$filtering ? 'clearFilters' : null" />

        @if ($hasMe && $youHint)
            <div role="note" class="mb-3 flex flex-wrap items-center gap-x-3 gap-y-2 rounded-md border border-border bg-surface-muted px-3 py-2 text-small text-text min-[600px]:mx-4">
                <x-bs-admin::icon name="ri-information" class="size-4 shrink-0 text-info-text" />
                <span class="min-w-0 flex-[1_1_16rem] [overflow-wrap:anywhere]">{{ __('admin.members.you_hint') }}</span>
                <x-bs-admin::hint-ok :key="FirstHints::MEMBERS_YOU" />
            </div>
        @endif

        <div wire:loading.attr="aria-busy" class="min-w-0 transition-opacity max-[599.98px]:rounded-md max-[599.98px]:border max-[599.98px]:border-border max-[599.98px]:bg-surface max-[599.98px]:[&_.list-g]:border-t-0"
             wire:loading.class="opacity-60" wire:target="search,groups,per,older,newer,clearFilters,clearGroups,removeGroup,clearSearch">
            @if ($failed)
                <div class="flex flex-col items-start gap-3 px-4 py-5" role="alert">
                    <h2 class="m-0 text-[calc(1rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('admin.members.error_title') }}</h2>
                    <p class="m-0 max-w-[62ch]">{{ __('admin.members.error_body') }}</p>
                    <x-bs-admin::button variant="outline" color="secondary" wire:click="$refresh"><x-bs-admin::icon name="ri-refresh" class="size-4" />{{ __('admin.members.retry') }}</x-bs-admin::button>
                </div>
            @else
                <x-bs-admin::list kind="facts" data-cols="people" :label="__('admin.members.list_label')">
                    @if ($rows->isNotEmpty())
                        <x-bs-admin::list.head>
                            <x-bs-admin::list.cell head col="main">{{ __('admin.members.col_name') }}</x-bs-admin::list.cell>
                            <x-bs-admin::list.cell head col="f1">{{ __('admin.members.col_roles') }}</x-bs-admin::list.cell>
                            <x-bs-admin::list.cell head col="st">{{ __('admin.members.col_status') }}</x-bs-admin::list.cell>
                            <x-bs-admin::list.cell head col="act"><span class="sr-only">{{ __('admin.actions') }}</span></x-bs-admin::list.cell>
                        </x-bs-admin::list.head>
                    @endif

                    @forelse ($rows as $member)
                        @php
                            $you = $member->id === $me->id;
                            $top = (int) $member->roles->max('level');
                            $can = ! $you && $top < $ceiling;
                            $why = $you ? __('admin.members.why_you') : __('admin.members.why_level');
                            $roleNames = $member->roles->sortByDesc('level')->map(fn ($r): string => RoleLabel::for($r))->values()->all();
                            $limits = $scopes[$member->id] ?? [];
                            $statusLabel = __('admin.members.status_active');
                        @endphp
                        <x-bs-admin::list.row wire:key="member-{{ $member->ulid }}" id="member-{{ $member->ulid }}">
                            <x-bs-admin::list.cell col="main">
                                <div class="flex min-w-0 items-start gap-2.5">
                                    <x-bs-admin::avatar :name="$member->name" size="md" class="mt-1.5 hidden shrink-0 min-[600px]:inline-flex" />
                                    <div class="min-w-0 flex-1">
                                        <x-bs-admin::list.name>{{ $member->name }}@if ($you) <x-bs-admin::badge color="primary" soft pill class="ms-1 align-middle">{{ __('admin.members.you') }}</x-bs-admin::badge>@endif</x-bs-admin::list.name>
                                        <p class="list-sub m-0"><span class="list-sub-em">{!! $email($member->email) !!}</span></p>
                                        <p class="list-line m-0">
                                            <span class="list-sub-em">{!! $email($member->email) !!}</span>
                                            <x-bs-admin::status-pill status="active">{{ $statusLabel }}</x-bs-admin::status-pill>
                                            <span>{{ implode(', ', $roleNames) }}</span>
                                        </p>
                                        @if (! $can)
                                            <p class="m-0 mt-1 hidden items-start gap-1.5 text-small text-text-muted @max-[37.5rem]/tv:flex">
                                                <x-bs-admin::icon name="ri-lock-2" class="mt-0.5 size-4 shrink-0" /><span>{{ $why }}</span>
                                            </p>
                                        @endif
                                    </div>
                                </div>
                            </x-bs-admin::list.cell>
                            <x-bs-admin::list.cell col="f1">
                                <span class="flex flex-wrap gap-1">
                                    @foreach ($roleNames as $label)
                                        <span class="inline-flex max-w-full rounded bg-primary/8 px-2 py-0.5 text-caption font-medium text-primary-text [overflow-wrap:anywhere]">{{ $label }}</span>
                                    @endforeach
                                </span>
                                @if ($limits !== [])
                                    <span class="mt-1 flex items-start gap-1 text-small text-text-muted">
                                        <x-bs-admin::icon name="ri-article" class="mt-0.5 size-4 shrink-0" />
                                        <span class="min-w-0 [overflow-wrap:anywhere]">{{ __('admin.members.scope_posts', ['names' => implode(', ', $limits)]) }}</span>
                                    </span>
                                @endif
                            </x-bs-admin::list.cell>
                            <x-bs-admin::list.cell col="st">
                                <x-bs-admin::status-pill status="active">{{ $statusLabel }}</x-bs-admin::status-pill>
                            </x-bs-admin::list.cell>
                            <x-bs-admin::list.cell col="act">
                                @if ($can)
                                    <x-bs-admin::list.actions :name="$member->name">
                                        <x-bs-admin::button size="sm" variant="soft" color="primary" data-row-btn id="member-edit-{{ $member->ulid }}"
                                            aria-disabled="{{ $locked ? 'true' : 'false' }}" class="aria-disabled:opacity-60"
                                            :aria-label="__('admin.members.edit_aria', ['name' => $member->name])"
                                            wire:click="openPanel({{ Js::from($member->ulid) }})">
                                            <x-bs-admin::icon name="ri-shield-user" class="size-4" /> {{ __('admin.members.edit') }}
                                        </x-bs-admin::button>
                                        <x-slot:menu>
                                            <x-bs-admin::list.menu-item icon="ri-shield-user" :why="$locked ? __('admin.support.locked') : null" wire:click="openPanel({{ Js::from($member->ulid) }})">{{ __('admin.members.edit') }}</x-bs-admin::list.menu-item>
                                            <x-bs-admin::list.menu-sep />
                                            <x-bs-admin::list.menu-item icon="ri-user-unfollow" danger :why="$locked ? __('admin.support.locked') : null" wire:click="askRemove({{ Js::from($member->ulid) }})">{{ __('admin.members.remove') }}</x-bs-admin::list.menu-item>
                                        </x-slot:menu>
                                    </x-bs-admin::list.actions>
                                @else
                                    <p class="m-0 flex items-start gap-1.5 text-small text-text-muted" title="{{ $why }}">
                                        <x-bs-admin::icon name="ri-lock-2" class="mt-0.5 size-4 shrink-0" /><span class="@max-[37.5rem]/tv:sr-only">{{ $why }}</span>
                                    </p>
                                @endif
                            </x-bs-admin::list.cell>
                        </x-bs-admin::list.row>
                    @empty
                        @if ($filtering)
                            <x-bs-admin::list.row>
                                <x-bs-admin::list.cell col="full">
                                    <div class="flex flex-col items-start gap-3 px-4 py-6">
                                        <p class="m-0 text-body text-text-muted">{{ __('admin.members.nomatch') }}</p>
                                        <x-bs-admin::button variant="outline" color="primary" wire:click="clearFilters">{{ __('admin.list.clear_filters') }}</x-bs-admin::button>
                                    </div>
                                </x-bs-admin::list.cell>
                            </x-bs-admin::list.row>
                        @endif
                    @endforelse

                    @if ($total <= 1 && ! $filtering)
                        <x-bs-admin::list.row>
                            <x-bs-admin::list.cell col="full">
                                <div class="flex flex-col items-center gap-2 px-4 py-6 text-center">
                                    <h2 class="m-0 text-[calc(1.125rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('admin.members.only_you_title') }}</h2>
                                    <p class="m-0 max-w-[62ch] text-body text-text-muted">{{ __('admin.members.only_you_text') }}</p>
                                    @if ($canInvite)
                                        <x-bs-admin::button color="primary" class="mt-2 aria-disabled:opacity-60" aria-disabled="{{ $locked ? 'true' : 'false' }}" wire:click="openInvite">
                                            <x-bs-admin::icon name="ri-user-add" class="size-4" /> {{ __('admin.members.invite') }}
                                        </x-bs-admin::button>
                                    @endif
                                </div>
                            </x-bs-admin::list.cell>
                        </x-bs-admin::list.row>
                    @endif
                </x-bs-admin::list>

                @if ($rows->isNotEmpty() && ($older !== null || $newer !== null || $total > MemberDirectory::PER_PAGE[0]))
                    <x-bs-admin::list.pager :older="$older" :newer="$newer" :sizes="MemberDirectory::PER_PAGE" model="per"
                        :label="__('admin.members.pager_label')" :per-label="__('admin.members.per_page')"
                        :prev="__('admin.members.prev')" :next="__('admin.members.next')" />
                @endif
            @endif
        </div>
    </div>

    {{-- The roles panel of one member: only the roles you may grant can be ticked; a role they hold that you could not
         grant stays, shown ticked and locked, so saving never takes it away by accident. --}}
    @if ($panelMember !== null)
        @php
            $heldNames = $panelBefore->pluck('name')->all();
            $choices = $assignable->concat($panelBefore->reject(fn ($r): bool => $assignable->contains('id', $r->id)))->unique('id')->sortByDesc('level')->values();
            $grantable = $assignable->pluck('name')->all();
            $permsOf = fn (array $names) => $choices->whereIn('name', $names)->flatMap(fn ($r) => $r->permissions->pluck('name'))->unique()->values()->all();
            $was = $permsOf($heldNames);
            $will = $permsOf($panelRoles);
            $known = array_flip(PermissionRegistry::TENANT_PERMISSIONS);
            $added = array_values(array_filter(array_diff($will, $was), fn ($p): bool => isset($known[$p])));
            $removed = array_values(array_filter(array_diff($was, $will), fn ($p): bool => isset($known[$p])));
        @endphp
        <x-bs-admin::drawer :title="__('admin.members.panel_title', ['name' => $panelMember->name])" :subtitle="$panelMember->email"
            close="closePanel" :return-to="'member-edit-'.$panelMember->ulid" :close-label="__('admin.members.panel_close')" busy="saveRoles"
            wire:key="members-panel-{{ $panelMember->ulid }}">
            <div class="flex flex-col gap-4">
                <p class="m-0 text-small text-text-muted">{{ __('admin.members.panel_hint', ['role' => $myRole]) }}</p>
                <fieldset class="m-0 flex min-w-0 flex-col gap-2 border-0 p-0">
                    <legend class="sr-only">{{ __('admin.members.col_roles') }}</legend>
                    @foreach ($choices as $role)
                        @php $fixed = ! in_array($role->name, $grantable, true); @endphp
                        <label class="flex min-h-hit cursor-pointer items-start gap-3 rounded-md border border-border px-3 py-2.5 has-checked:border-primary has-checked:bg-primary/5 has-disabled:cursor-not-allowed">
                            <input type="checkbox" value="{{ $role->name }}" wire:model.live="panelRoles" @disabled($fixed) class="mt-1 size-4.5 shrink-0 accent-primary-text">
                            <span class="flex min-w-0 flex-col">
                                <span class="text-body font-medium text-text-strong [overflow-wrap:anywhere]">{{ RoleLabel::for($role) }}</span>
                                <span class="text-small text-text-muted">{{ $fixed ? __('admin.members.role_fixed') : $describe($role) }}</span>
                            </span>
                        </label>
                    @endforeach
                </fieldset>
                @if ($panelError !== '')
                    <p class="m-0 flex items-start gap-1.5 text-small text-danger-text" role="alert"><x-bs-admin::icon name="ri-error-warning" class="mt-0.5 size-4 shrink-0" /><span>{{ $panelError }}</span></p>
                @endif
                <div class="flex flex-col gap-2 rounded-md bg-surface-muted px-3.5 py-3 text-small">
                    <b class="text-text-strong">{{ __('admin.members.pv_title', ['name' => $first($panelMember->name)]) }}</b>
                    @if ($added === [] && $removed === [])
                        <span class="text-text-muted">{{ __('admin.members.pv_none') }}</span>
                    @endif
                    @if ($added !== [])
                        <span class="flex flex-wrap items-start gap-2"><x-bs-admin::badge color="success" soft pill><x-bs-admin::icon name="ri-add-circle" class="size-3.5" />{{ __('admin.members.pv_add') }}</x-bs-admin::badge><b class="min-w-0 flex-1 text-text">{{ implode(', ', array_map($permLabel, $added)) }}</b></span>
                    @endif
                    @if ($removed !== [])
                        <span class="flex flex-wrap items-start gap-2"><x-bs-admin::badge color="danger" soft pill><x-bs-admin::icon name="ri-indeterminate-circle" class="size-3.5" />{{ __('admin.members.pv_remove') }}</x-bs-admin::badge><b class="min-w-0 flex-1 text-text">{{ implode(', ', array_map($permLabel, $removed)) }}</b></span>
                    @endif
                    <span class="text-text-muted">{{ __('admin.members.pv_logged') }}</span>
                </div>
            </div>
            <x-slot:footer>
                <x-bs-admin::button variant="outline" color="secondary" wire:click="closePanel">{{ __('admin.cancel') }}</x-bs-admin::button>
                <x-bs-admin::button color="primary" aria-disabled="{{ $locked ? 'true' : 'false' }}" class="aria-disabled:opacity-60" wire:click="saveRoles">
                    <x-bs-admin::icon name="ri-save-3" class="size-4" /> {{ __('admin.members.save_roles') }}
                </x-bs-admin::button>
            </x-slot:footer>
        </x-bs-admin::drawer>
    @endif

    @if ($removeMember !== null)
        <x-bs-admin::confirm-dialog model="confirmOpen" :title="__('admin.members.rm_title', ['name' => $removeMember->name])" icon="ri-user-unfollow" danger
            :ok="__('admin.members.rm_ok')" confirm="confirmRemove" cancel="cancelRemove">
            <x-bs-admin::confirm-line icon="ri-login-box">{{ __('admin.members.rm_l1', ['name' => $first($removeMember->name)]) }}</x-bs-admin::confirm-line>
            <x-bs-admin::confirm-line icon="ri-article">{{ __('admin.members.rm_l2') }}</x-bs-admin::confirm-line>
            <x-bs-admin::confirm-line icon="ri-delete-bin">{{ __('admin.members.rm_l3') }}</x-bs-admin::confirm-line>
        </x-bs-admin::confirm-dialog>
    @endif

    @if ($canInvite)
        @php
            [$validAddr, $badAddr] = \App\Modules\Admin\Livewire\Users\Index::addresses($inviteEmail);
            $okN = count(array_filter($inviteResults, fn (array $r): bool => $r['ok']));
        @endphp
        <x-bs-admin::modal model="inviteOpen" size="lg" :title="__('admin.members.invite')" :subtitle="__('admin.members.inv_sub', ['days' => \App\Modules\Rbac\Actions\InviteMember::VALID_DAYS])"
            :close-label="__('admin.members.inv_close')">
            @if ($inviteResults === [])
                <div class="flex flex-col gap-4">
                    <div class="flex flex-col gap-1.5">
                        <label for="members-inv-email" class="text-body font-medium text-text-strong">{{ __('admin.members.inv_email') }} <span class="text-danger-text" aria-hidden="true">*</span><span class="sr-only">{{ __('admin.members.required') }}</span></label>
                        <textarea id="members-inv-email" rows="2" inputmode="email" autocomplete="off" spellcheck="false" wire:model.blur="inviteEmail"
                            placeholder="ha.vu@anvinh.vn" aria-describedby="members-inv-email-h" @error('inviteEmail') aria-invalid="true" @enderror
                            class="field w-full rounded border border-border-input bg-surface px-3 py-2 text-text-strong"></textarea>
                        @error('inviteEmail')<p class="m-0 flex items-start gap-1.5 text-small text-danger-text" role="alert"><x-bs-admin::icon name="ri-error-warning" class="mt-0.5 size-4 shrink-0" /><span>{{ $message }}</span></p>@enderror
                        <p id="members-inv-email-h" class="m-0 text-small text-text-muted">{{ __('admin.members.inv_email_hint') }}</p>
                        @if ($validAddr !== [] && $badAddr === [])
                            <p class="m-0"><x-bs-admin::badge color="primary" soft pill><x-bs-admin::icon name="ri-mail" class="size-3.5" />{{ count($validAddr) === 1 ? __('admin.members.inv_addr_one') : __('admin.members.inv_addr_n', ['n' => count($validAddr)]) }}</x-bs-admin::badge></p>
                        @endif
                    </div>
                    <fieldset class="m-0 flex min-w-0 flex-col gap-2 border-0 p-0" aria-describedby="members-inv-roles-h">
                        <legend class="mb-1 text-body font-medium text-text-strong">{{ __('admin.members.col_roles') }} <span class="text-danger-text" aria-hidden="true">*</span><span class="sr-only">{{ __('admin.members.required') }}</span></legend>
                        <p id="members-inv-roles-h" class="m-0 text-small text-text-muted">{{ __('admin.members.inv_roles_hint', ['role' => $myRole]) }}</p>
                        @foreach ($assignable as $role)
                            <label class="flex min-h-hit cursor-pointer items-start gap-3 rounded-md border border-border px-3 py-2.5 has-checked:border-primary has-checked:bg-primary/5">
                                <input type="checkbox" value="{{ $role->name }}" wire:model="inviteRoles" class="mt-1 size-4.5 shrink-0 accent-primary-text">
                                <span class="flex min-w-0 flex-col">
                                    <span class="text-body font-medium text-text-strong [overflow-wrap:anywhere]">{{ RoleLabel::for($role) }}</span>
                                    <span class="text-small text-text-muted">{{ $describe($role) }}</span>
                                </span>
                            </label>
                        @endforeach
                        @error('inviteRoles')<p class="m-0 flex items-start gap-1.5 text-small text-danger-text" role="alert"><x-bs-admin::icon name="ri-error-warning" class="mt-0.5 size-4 shrink-0" /><span>{{ $message }}</span></p>@enderror
                    </fieldset>
                    <div class="flex flex-col gap-1.5" x-data="{ n: {{ mb_strlen($inviteMessage) }} }">
                        <label for="members-inv-msg" class="flex flex-wrap items-baseline gap-x-2 text-body font-medium text-text-strong">{{ __('admin.members.inv_msg') }} <span class="font-normal text-text-muted">{{ __('admin.members.optional') }}</span><span class="ms-auto text-small font-normal text-text-muted" aria-hidden="true"><span x-text="n">{{ mb_strlen($inviteMessage) }}</span>/500</span></label>
                        <textarea id="members-inv-msg" rows="3" maxlength="500" wire:model="inviteMessage" x-on:input="n = $el.value.length" aria-describedby="members-inv-msg-h"
                            placeholder="{{ __('admin.members.inv_msg_ph') }}" class="field w-full rounded border border-border-input bg-surface px-3 py-2 text-text-strong"></textarea>
                        <p id="members-inv-msg-h" class="m-0 text-small text-text-muted">{{ __('admin.members.inv_msg_hint') }}</p>
                    </div>
                    <p class="m-0 flex items-start gap-1.5 text-small text-text-muted"><x-bs-admin::icon name="ri-time" class="mt-0.5 size-4 shrink-0" /><span>{{ __('admin.members.inv_valid', ['days' => \App\Modules\Rbac\Actions\InviteMember::VALID_DAYS]) }}</span></p>
                    <p class="m-0 flex items-start gap-1.5 text-small text-text-muted"><x-bs-admin::icon name="ri-mail" class="mt-0.5 size-4 shrink-0" /><span>{{ __('admin.members.inv_quota', ['n' => $num($inviteLeft)]) }}</span></p>
                </div>
            @else
                <div class="flex flex-col gap-3">
                    <h3 class="m-0 text-[calc(1rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('admin.members.inv_res_title', ['ok' => $okN, 'n' => count($inviteResults)]) }}</h3>
                    <ul class="m-0 flex list-none flex-col gap-2 p-0">
                        @foreach ($inviteResults as $res)
                            <li class="flex items-start gap-2 text-body">
                                <x-bs-admin::icon :name="$res['ok'] ? 'ri-checkbox-circle' : 'ri-error-warning'" @class(['mt-0.5 size-5 shrink-0', 'text-success-text' => $res['ok'], 'text-danger-text' => ! $res['ok']]) />
                                <span class="min-w-0 [overflow-wrap:anywhere]"><b>{!! $email($res['email']) !!}</b> — {{ $res['text'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <x-slot:footer>
                @if ($inviteResults === [])
                    <x-bs-admin::button variant="outline" color="secondary" wire:click="closeInvite">{{ __('admin.cancel') }}</x-bs-admin::button>
                    <x-bs-admin::button color="primary" wire:click="sendInvites" wire:loading.attr="aria-busy" aria-disabled="{{ $locked ? 'true' : 'false' }}" class="aria-disabled:opacity-60">
                        <x-bs-admin::icon name="ri-send-plane" class="size-4" /> {{ __('admin.members.inv_send') }}
                    </x-bs-admin::button>
                @else
                    <x-bs-admin::button variant="outline" color="secondary" wire:click="inviteAgain"><x-bs-admin::icon name="ri-user-add" class="size-4" /> {{ __('admin.members.inv_again') }}</x-bs-admin::button>
                    <x-bs-admin::button color="primary" wire:click="closeInvite">{{ __('admin.members.inv_done') }}</x-bs-admin::button>
                @endif
            </x-slot:footer>
        </x-bs-admin::modal>
    @endif

    <x-bs-admin::toast :message="$toast" :tone="$toastTone" dismiss="dismissToast" :undo="$undoRoles !== null ? 'undo' : null" :seq="$toastSeq" />
</div>
