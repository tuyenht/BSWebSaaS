@php
    // The "Lời mời" view of the members screen (board 22): the invitations of one state, newest mail
    // first. Included by users/index, whose variables it reads. Every action is a Livewire call that
    // checks again on the server (ManagesInvitations); what is drawn here only says why a button waits.
    use App\Modules\Admin\Support\Members\InvitationDirectory;
    use App\Modules\Rbac\Actions\InviteMember;
    use App\Modules\Rbac\Support\RoleLabel;
    use App\Modules\Tenancy\Context\SiteTime;
    use Illuminate\Support\Js;

    $siteTime = app(SiteTime::class);
    $at = fn ($t): string => $siteTime->forDisplay($t)?->format('d/m/Y H:i') ?? '';
    $grantNames = $assignable->pluck('name')->all();
    $now = now();
    $invFiltering = $searching || $groups !== [];
@endphp

@if (! $canInvite)
    <p class="m-0 mb-3 flex items-start gap-1.5 px-4 text-small text-text-muted max-[599.98px]:px-0">
        <x-bs-admin::icon name="ri-lock-2" class="mt-0.5 size-4 shrink-0" /><span>{{ __('admin.members.inv_noperm', ['role' => $myRole]) }}</span>
    </p>
@elseif (! $locked)
    <p class="m-0 mb-3 flex items-start gap-1.5 px-4 text-small text-text-muted max-[599.98px]:px-0">
        <x-bs-admin::icon name="ri-information" class="mt-0.5 size-4 shrink-0 text-info-text" /><span>{{ __('admin.members.inv_resend_note', ['days' => InviteMember::VALID_DAYS]) }}</span>
    </p>
@endif
<p class="m-0 mb-3 flex items-start gap-1.5 px-4 text-small text-text-muted max-[599.98px]:px-0">
    <x-bs-admin::icon name="ri-mail" class="mt-0.5 size-4 shrink-0" /><span>{{ __('admin.members.inv_quota_line', ['n' => $num($sent), 'max' => $limit]) }}</span>
</p>

<div wire:loading.attr="aria-busy" class="min-w-0 transition-opacity @max-[56.25rem]/lt:order-3 max-[599.98px]:rounded-md max-[599.98px]:border max-[599.98px]:border-border max-[599.98px]:bg-surface max-[599.98px]:[&_.list-g]:border-t-0"
     wire:loading.class="opacity-60" wire:target="search,groups,per,older,newer,clearFilters,clearGroups,removeGroup,clearSearch,tab,state">
    @if ($failed)
        <div class="flex flex-col items-start gap-3 px-4 py-5" role="alert">
            <h2 class="m-0 text-[calc(1rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('admin.members.error_title') }}</h2>
            <p class="m-0 max-w-[62ch]">{{ __('admin.members.error_body') }}</p>
            <x-bs-admin::button variant="outline" color="secondary" wire:click="$refresh"><x-bs-admin::icon name="ri-refresh" class="size-4" />{{ __('admin.members.retry') }}</x-bs-admin::button>
        </div>
    @else
        <x-bs-admin::list kind="facts" data-cols="invites" :label="__('admin.members.inv_list_label')">
            @if ($invRows->isNotEmpty())
                <x-bs-admin::list.head>
                    <x-bs-admin::list.cell head col="main">{{ __('admin.members.col_email') }}</x-bs-admin::list.cell>
                    <x-bs-admin::list.cell head col="f1">{{ __('admin.members.col_roles') }}</x-bs-admin::list.cell>
                    <x-bs-admin::list.cell head col="f2">{{ __('admin.members.col_sent') }}</x-bs-admin::list.cell>
                    <x-bs-admin::list.cell head col="f3">{{ __('admin.members.col_exp_'.$state) }}</x-bs-admin::list.cell>
                    <x-bs-admin::list.cell head col="act"><span class="sr-only">{{ __('admin.actions') }}</span></x-bs-admin::list.cell>
                </x-bs-admin::list.head>
            @endif

            @forelse ($invRows as $inv)
                @php
                    $k = InvitationDirectory::stateOf($inv);
                    $live = in_array($k, ['pending', 'expired'], true);
                    $by = $inv->invited_by === $me->id ? __('admin.members.inv_by_you')
                        : ($inv->inviter !== null && in_array($inv->invited_by, $stayed, true) ? $inv->inviter->name : __('admin.members.inv_by_left'));
                    $askName = $inv->invited_by === $me->id || $by === __('admin.members.inv_by_left') ? __('admin.members.owner_word') : $by;
                    $roleNames = $inv->roles->sortByDesc('level')->map(fn ($r): string => RoleLabel::for($r))->values()->all();
                    $limits = $invScopes[$inv->id] ?? [];
                    $endAt = match ($k) { 'accepted' => $inv->accepted_at, 'revoked' => $inv->revoked_at, default => $inv->expires_at };
                    $hoursLeft = max(1, (int) round($now->diffInMinutes($inv->expires_at, false) / 60));
                    $soon = $k === 'pending' && $inv->expires_at->lt($now->copy()->addDays(2));
                    $soonText = $hoursLeft < 24 ? __('admin.members.inv_soon_hours', ['n' => $hoursLeft]) : __('admin.members.inv_soon_days', ['n' => intdiv($hoursLeft, 24)]);
                    $grant = $inv->roles->every(fn ($r): bool => in_array($r->name, $grantNames, true));
                    $coolLeft = $live ? max(0, InviteMember::RESEND_COOLDOWN_SECONDS - (int) $inv->sent_at->diffInSeconds($now)) : 0;
                    $why = null;
                    if ($canInvite && $live) {
                        $why = $locked ? $lockedWhy
                            : (! $grant ? __('admin.members.inv_why_level', ['name' => $askName])
                            : ($full ? __('admin.members.inv_why_full', ['max' => $limit, 'time' => $reopenAt ?? '—']) : null));
                    } elseif ($canInvite && $k === 'revoked' && $locked) {
                        $why = $lockedWhy;
                    }
                    $meta = array_filter([
                        implode(', ', $roleNames),
                        __('admin.members.inv_by', ['name' => $by]),
                        __('admin.members.inv_meta_sent', ['at' => $at($inv->sent_at)]),
                        __('admin.members.inv_meta_'.$k, ['at' => $at($endAt)]).($soon ? ' ('.mb_strtolower($soonText).')' : ''),
                    ]);
                    $hasActs = ($canInvite && ($live || $k === 'revoked')) || $k === 'accepted';
                @endphp
                <x-bs-admin::list.row wire:key="inv-{{ $inv->ulid }}" id="inv-{{ $inv->ulid }}">
                    <x-bs-admin::list.cell col="main">
                        <x-bs-admin::list.name><span class="list-sub-em">{!! $email($inv->email) !!}</span></x-bs-admin::list.name>
                        <p class="list-sub m-0">{{ __('admin.members.inv_by', ['name' => $by]) }}</p>
                        @if ($inv->message !== null && $inv->message !== '')
                            <p class="list-sub m-0 truncate" title="{{ $inv->message }}">{{ __('admin.members.inv_msg_line', ['msg' => $inv->message]) }}</p>
                        @endif
                        <p class="list-line m-0">
                            <x-bs-admin::status-pill :status="'invite-'.$k">{{ __('admin.members.ist_'.$k) }}</x-bs-admin::status-pill>
                            @foreach ($meta as $part)
                                <span>{{ $part }}</span>
                            @endforeach
                        </p>
                        @if ($limits !== [])
                            <p class="m-0 mt-1 hidden items-start gap-1 text-small text-text-muted @max-[37.5rem]/tv:flex">
                                <x-bs-admin::icon name="ri-article" class="mt-0.5 size-4 shrink-0" /><span class="min-w-0 [overflow-wrap:anywhere]">{{ __('admin.members.scope_posts', ['names' => implode(', ', $limits)]) }}</span>
                            </p>
                        @endif
                        @if ($why !== null)
                            <p class="m-0 mt-1 hidden items-start gap-1.5 text-small text-text-muted @max-[37.5rem]/tv:flex">
                                <x-bs-admin::icon name="ri-lock-2" class="mt-0.5 size-4 shrink-0" /><span>{{ $why }}</span>
                            </p>
                        @endif
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
                        <x-bs-admin::status-pill :status="'invite-'.$k">{{ __('admin.members.ist_'.$k) }}</x-bs-admin::status-pill>
                    </x-bs-admin::list.cell>
                    <x-bs-admin::list.cell col="f2">{{ $at($inv->sent_at) }}</x-bs-admin::list.cell>
                    <x-bs-admin::list.cell col="f3">
                        <span class="flex flex-col items-start gap-1">
                            <span>{{ $at($endAt) }}</span>
                            @if ($soon)
                                <x-bs-admin::badge color="warning" soft pill><x-bs-admin::icon name="ri-time" class="size-3.5" />{{ $soonText }}</x-bs-admin::badge>
                            @endif
                        </span>
                    </x-bs-admin::list.cell>
                    <x-bs-admin::list.cell col="act">
                        @if ($hasActs)
                            <x-bs-admin::list.actions :name="$inv->email">
                                @if ($canInvite && $live)
                                    <span class="contents" x-data="{ left: {{ $coolLeft }}, t: null }"
                                          x-init="if (left > 0) t = setInterval(() => { left = Math.max(0, left - 1); if (! left) clearInterval(t) }, 1000)">
                                        <x-bs-admin::button size="sm" variant="soft" color="primary" data-row-btn id="inv-resend-{{ $inv->ulid }}"
                                            aria-disabled="{{ $why !== null ? 'true' : 'false' }}" ::aria-disabled="{{ $why !== null ? 'true' : 'left > 0' }}" class="aria-disabled:opacity-60"
                                            :aria-label="__('admin.members.inv_resend_aria', ['email' => $inv->email])"
                                            wire:click="resendInvitation({{ Js::from($inv->ulid) }})">
                                            <x-bs-admin::icon name="ri-mail-send" class="size-4" />
                                            <span x-text="left > 0 ? {{ Js::from(__('admin.members.inv_resend_wait', ['n' => '#'])) }}.replace('#', left) : {{ Js::from(__('admin.members.inv_resend')) }}">{{ $coolLeft > 0 ? __('admin.members.inv_resend_wait', ['n' => $coolLeft]) : __('admin.members.inv_resend') }}</span>
                                        </x-bs-admin::button>
                                    </span>
                                @elseif ($canInvite && $k === 'revoked')
                                    <x-bs-admin::button size="sm" variant="soft" color="primary" data-row-btn id="inv-reinvite-{{ $inv->ulid }}"
                                        aria-disabled="{{ $locked || $full ? 'true' : 'false' }}" class="aria-disabled:opacity-60"
                                        :aria-label="__('admin.members.inv_reinvite_aria', ['email' => $inv->email])"
                                        wire:click="reinvite({{ Js::from($inv->ulid) }})">
                                        <x-bs-admin::icon name="ri-user-add" class="size-4" /> {{ __('admin.members.inv_reinvite') }}
                                    </x-bs-admin::button>
                                @elseif ($k === 'accepted')
                                    <x-bs-admin::button size="sm" variant="soft" color="primary" data-row-btn
                                        :aria-label="__('admin.members.inv_view_aria', ['email' => $inv->email])"
                                        wire:click="viewMember({{ Js::from($inv->ulid) }})">
                                        <x-bs-admin::icon name="ri-user" class="size-4" /> {{ __('admin.members.inv_view') }}
                                    </x-bs-admin::button>
                                @endif
                                <x-slot:menu>
                                    @if ($canInvite && $live)
                                        <x-bs-admin::list.menu-item icon="ri-mail-send" :why="$why" wire:click="resendInvitation({{ Js::from($inv->ulid) }})">{{ __('admin.members.inv_resend') }}</x-bs-admin::list.menu-item>
                                        <x-bs-admin::list.menu-sep />
                                        <x-bs-admin::list.menu-item icon="ri-mail-close" danger :why="$locked ? $lockedWhy : (! $grant ? __('admin.members.inv_why_level', ['name' => $askName]) : null)"
                                            wire:click="askRevoke({{ Js::from($inv->ulid) }})">{{ __('admin.members.inv_revoke') }}</x-bs-admin::list.menu-item>
                                    @elseif ($canInvite && $k === 'revoked')
                                        <x-bs-admin::list.menu-item icon="ri-user-add" :why="$locked ? $lockedWhy : ($full ? __('admin.members.inv_full', ['max' => $limit]) : null)"
                                            wire:click="reinvite({{ Js::from($inv->ulid) }})">{{ __('admin.members.inv_reinvite') }}</x-bs-admin::list.menu-item>
                                    @elseif ($k === 'accepted')
                                        <x-bs-admin::list.menu-item icon="ri-user" wire:click="viewMember({{ Js::from($inv->ulid) }})">{{ __('admin.members.inv_view') }}</x-bs-admin::list.menu-item>
                                    @endif
                                </x-slot:menu>
                            </x-bs-admin::list.actions>
                        @endif
                        @if ($why !== null)
                            <p class="m-0 mt-1 flex items-start gap-1.5 text-small text-text-muted @max-[37.5rem]/tv:sr-only">
                                <x-bs-admin::icon name="ri-lock-2" class="mt-0.5 size-4 shrink-0" /><span>{{ $why }}</span>
                            </p>
                        @endif
                    </x-bs-admin::list.cell>
                </x-bs-admin::list.row>
            @empty
                <x-bs-admin::list.row>
                    <x-bs-admin::list.cell col="full">
                        @if ($invFiltering)
                            <div class="flex flex-col items-start gap-3 px-4 py-6">
                                <p class="m-0 text-body text-text-muted">{{ __('admin.members.inv_nomatch') }}</p>
                                <x-bs-admin::button variant="outline" color="primary" wire:click="clearFilters">{{ __('admin.list.clear_filters') }}</x-bs-admin::button>
                            </div>
                        @elseif (array_sum($invCounts) === 0)
                            <div class="flex flex-col items-center gap-2 px-4 py-6 text-center">
                                <h2 class="m-0 text-[calc(1.125rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('admin.members.inv_empty_title') }}</h2>
                                <p class="m-0 max-w-[62ch] text-body text-text-muted">{{ __('admin.members.inv_empty_text', ['days' => InviteMember::VALID_DAYS]) }}</p>
                                @if ($canInvite)
                                    <x-bs-admin::button color="primary" class="mt-2 aria-disabled:opacity-60" aria-disabled="{{ $inviteWhy !== null ? 'true' : 'false' }}" :title="$inviteWhy" wire:click="openInvite">
                                        <x-bs-admin::icon name="ri-user-add" class="size-4" /> {{ __('admin.members.invite') }}
                                    </x-bs-admin::button>
                                @endif
                            </div>
                        @else
                            <p class="m-0 px-4 py-6 text-center text-body text-text-muted">{{ __('admin.members.inv_none_'.$state) }}</p>
                        @endif
                    </x-bs-admin::list.cell>
                </x-bs-admin::list.row>
            @endforelse
        </x-bs-admin::list>

        @if ($invRows->isNotEmpty() && ($older !== null || $newer !== null || $listTotal > \App\Modules\Admin\Support\Members\MemberDirectory::PER_PAGE[0]))
            <x-bs-admin::list.pager :older="$older" :newer="$newer" :sizes="\App\Modules\Admin\Support\Members\MemberDirectory::PER_PAGE" model="per"
                :label="__('admin.members.inv_pager_label')" :per-label="__('admin.members.per_page')"
                :prev="__('admin.members.prev')" :next="__('admin.members.next')" />
        @endif
    @endif
</div>
