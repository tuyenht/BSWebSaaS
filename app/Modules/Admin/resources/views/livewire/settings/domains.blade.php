@php
    use App\Modules\Tenancy\Domains\DomainName;
    use Illuminate\Support\Js;

    // Screen 21 "Tên miền" (design 21-ten-mien). The page is a size container: each name's block
    // lays itself out by the page's width (below 600 its buttons go last and full width; below 720
    // a spelling's role drops under its address; the pointing table becomes one card per row below
    // 600). Names wrap after a dot and are never clipped.
    $display = fn (?string $host): string => DomainName::display((string) $host);
    $host = fn (?string $h): string => str_replace('.', '.<wbr>', e($display($h)));
    $rowTones = [
        'ok' => ['text-success-text', 'ri-lock', 'admin.domains_rowst_ok'],
        'pointed' => ['text-info-text', 'ri-checkbox-circle', 'admin.domains_rowst_pointed'],
        'issuing' => ['text-info-text', 'ri-shield-keyhole', 'admin.domains_rowst_issuing'],
        'wait' => ['text-warning-text', 'ri-time', 'admin.domains_rowst_wait'],
        'nolock' => ['text-danger-text', 'ri-error-warning', 'admin.domains_rowst_nolock'],
        'lost' => ['text-danger-text', 'ri-close-circle', 'admin.domains_rowst_lost'],
        'failing' => ['text-danger-text', 'ri-link-unlink', 'admin.domains_rowst_failing'],
        'optional' => ['text-text-muted', 'ri-indeterminate-circle', 'admin.domains_rowst_optional'],
    ];
    $msgTones = [
        'ok' => ['text-success-text', 'ri-checkbox-circle'],
        'warn' => ['text-warning-text', 'ri-time'],
        'danger' => ['text-danger-text', 'ri-error-warning'],
        'info' => ['text-info-text', 'ri-information'],
        'muted' => ['text-text-muted', 'ri-information'],
    ];
    $glyph = 'size-[calc(1rem+var(--user-text-boost))] shrink-0';
@endphp
<div class="@container" @if ($allowed && ! ($failed ?? false) && $overview->pending()) wire:poll.5s @endif>
    <div class="flex max-w-295 flex-col gap-3.5">
        {{-- Breadcrumb, title, description --}}
        <div class="min-w-0">
            <nav aria-label="{{ __('admin.breadcrumb') }}" class="flex flex-wrap items-center gap-1 text-small text-text-muted">
                @if ($siteUlid !== null)
                    <a href="{{ route('platform.sites.index') }}" wire:navigate class="text-primary-text hover:underline">{{ __('admin.domains_crumb_site') }}</a>
                    <x-bs-admin::icon name="ri-arrow-right-s" :class="$glyph" />
                    <a href="{{ route('platform.sites.show', $siteUlid) }}" wire:navigate class="text-primary-text hover:underline [overflow-wrap:anywhere]">{{ $console ?? '' }}</a>
                @else
                    <span>{{ __('admin.domains_crumb_settings') }}</span>
                    <x-bs-admin::icon name="ri-arrow-right-s" :class="$glyph" />
                    <span>{{ __('admin.domains_crumb_system') }}</span>
                @endif
                <x-bs-admin::icon name="ri-arrow-right-s" :class="$glyph" />
                <span aria-current="page" class="text-text-strong">{{ __('admin.domains_title') }}</span>
            </nav>
            <h1 class="mt-1.5 mb-1 text-[calc(1.25rem+var(--user-text-boost))] font-semibold leading-snug text-text-strong">{{ __('admin.domains_title') }}</h1>
            <p class="m-0 text-body text-text-muted [text-wrap:pretty]">{{ __('admin.domains_desc') }}</p>
        </div>

        @if (! $allowed)
            {{-- No permission to see the names at all --}}
            <div class="flex flex-col items-start gap-2 rounded-md border border-border bg-surface p-5 shadow-card">
                <x-bs-admin::icon name="ri-forbid" class="size-6 text-text-muted" />
                <h2 class="m-0 text-[calc(1rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('admin.domains_noperm_title') }}</h2>
                <p class="m-0 text-text-muted">{{ __('admin.domains_noperm_body', ['role' => $role !== '' ? $role : __('admin.domains_noperm_role_none')]) }}</p>
            </div>
        @elseif ($failed)
            {{-- The list could not be read --}}
            <div role="alert" class="flex flex-col items-start gap-2 rounded-md border border-border bg-surface p-5 shadow-card" wire:loading.remove wire:target="$refresh">
                <x-bs-admin::icon name="ri-wifi-off" class="size-6 text-danger-text" />
                <h2 class="m-0 text-[calc(1rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('admin.domains_load_error_title') }}</h2>
                <p class="m-0 text-text-muted">{{ __('admin.domains_load_error_body') }}</p>
                <x-bs-admin::button variant="outline" color="primary" wire:click="$refresh">
                    <x-bs-admin::icon name="ri-refresh" :class="$glyph" />
                    <span>{{ __('admin.domains_retry') }}</span>
                </x-bs-admin::button>
            </div>
            <div wire:loading.flex wire:target="$refresh" aria-busy="true" aria-label="{{ __('admin.domains_loading_aria') }}"
                 class="flex-col gap-3 rounded-md border border-border bg-surface p-4 shadow-card">
                <div class="h-5 w-2/5 rounded bg-surface-hover"></div>
                <div class="h-16 rounded bg-surface-hover"></div>
                <div class="h-16 rounded bg-surface-hover"></div>
            </div>
        @else
            {{-- Notices: offline, support sign-in, view only, console, a name taken by another site --}}
            <div x-data="{ off: ! navigator.onLine }" x-on:online.window="off = false" x-on:offline.window="off = true" x-show="off" x-cloak>
                <x-bs-admin::alert color="warning" icon="ri-wifi-off">{{ __('admin.domains_offline') }}</x-bs-admin::alert>
            </div>
            @if ($locked)
                <x-bs-admin::alert color="warning" icon="ri-lock-2">{{ __('admin.domains_imp_banner') }}</x-bs-admin::alert>
            @endif
            @if (! $manage)
                <x-bs-admin::alert color="info" icon="ri-eye">{{ $siteUlid !== null ? __('admin.domains_readonly_platform') : __('admin.domains_readonly') }}</x-bs-admin::alert>
            @endif
            @if ($siteUlid !== null)
                <x-bs-admin::alert color="info" icon="ri-building-2">{{ __('admin.domains_console_banner', ['site' => $console]) }}</x-bs-admin::alert>
            @endif
            @if ($taken)
                <x-bs-admin::alert color="danger" icon="ri-shield-cross">
                    <div class="flex flex-wrap items-start gap-2">
                        <span class="min-w-0 flex-[1_1_16rem]">{{ __('admin.domains_claim_taken_alert', ['name' => $taken['name']]) }}</span>
                        <x-bs-admin::button size="sm" variant="outline" color="danger" wire:click="acknowledgeTaken({{ \Illuminate\Support\Js::from($taken['ulid']) }})">{{ __('admin.domains_claim_ack') }}</x-bs-admin::button>
                    </div>
                </x-bs-admin::alert>
            @endif

            <section class="min-w-0 rounded-md border border-border bg-surface shadow-card" aria-labelledby="tm-h-list">
                {{-- Card header: title, how many names the plan allows, add --}}
                @php
                    $full = $limit > 0 && $overview->used >= $limit;
                    $addLocked = $full || $locked;
                @endphp
                <div class="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-border px-4 py-3">
                    <h2 id="tm-h-list" class="m-0 min-w-0 flex-[1_1_13.75rem] text-[calc(1rem+var(--user-text-boost))] font-semibold leading-snug text-text-strong">{{ __('admin.domains_card_title') }}</h2>
                    <span class="text-small text-text-muted">{{ $limit === 0 ? __('admin.domains_none_in_plan') : __('admin.domains_used', ['u' => $overview->used, 'l' => $limit]) }}</span>
                    @if ($manage && $limit > 0)
                        <x-bs-admin::button wire:click="openAdd" class="@max-[37.4999rem]:w-full"
                            :aria-disabled="$addLocked ? 'true' : null" :aria-describedby="$full ? 'tm-lim-why' : null">
                            <x-bs-admin::icon name="ri-add" :class="$glyph" />
                            <span>{{ __('admin.domains_add') }}</span>
                        </x-bs-admin::button>
                    @endif
                </div>
                @if ($full)
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-border bg-surface-muted px-4 py-2.5 text-small">
                        <x-bs-admin::status-pill status="domain-locked">{{ __('admin.domains_limit_chip') }}</x-bs-admin::status-pill>
                        <span id="tm-lim-why" class="min-w-0 flex-[1_1_15rem]">{{ $limitText }}</span>
                    </div>
                @endif
                @if ($limit > 0 && $overview->custom() === [])
                    <div class="flex flex-col items-start gap-1.5 border-b border-border px-4 py-3.5">
                        <span class="font-semibold text-text-strong">{{ __('admin.domains_empty_title') }}</span>
                        <span class="text-small text-text-muted">{{ __('admin.domains_empty_body') }}</span>
                    </div>
                @endif

                {{-- One block per name --}}
                @foreach ($overview->groups as $g)
                    @php
                        $name = $display($g->name);
                        $retry = ($retryLater[$g->name] ?? false) === true;
                        $err = $retry ? 'ratelimit' : ($g->httpsError ?? 'notpointed');
                        $next = null;
                        if (! $g->platform && $manage) {
                            $next = match ($g->state) {
                                'wait', 'claim', 'lost' => ['check', 'ri-radar', __('admin.domains_next_check')],
                                'verified' => ['enableHttps', 'ri-shield-check', __('admin.domains_next_enable')],
                                'https_err' => ['enableHttps', 'ri-refresh', $retry ? __('admin.domains_next_retry_later') : __('admin.domains_next_retry')],
                                'active' => $g->primary ? null : ['askPrimary', 'ri-star', __('admin.domains_next_primary')],
                                'failing', 'expiring' => ['check', 'ri-radar', __('admin.domains_next_recheck')],
                                default => null,
                            };
                        }
                        [$msg, $msg2, $tone, $adv] = match (true) {
                            $g->platform => [__($g->primary ? 'admin.domains_msg_platform_primary' : 'admin.domains_msg_platform'), null, 'muted', null],
                            $g->state === 'wait' => [__('admin.domains_msg_wait'), $g->removeOn ? __('admin.domains_msg_wait_remove_on', ['date' => $g->removeOn->format('d/m/Y')]) : null, 'warn', null],
                            $g->state === 'claim' => [__('admin.domains_msg_claim'), null, 'warn', null],
                            $g->state === 'verified' => [__('admin.domains_msg_verified'), null, 'info', null],
                            $g->state === 'issuing' => [__('admin.domains_msg_issuing'), null, 'info', null],
                            $g->state === 'https_err' => [__('admin.domains_https_err_'.$err), null, 'danger', __('admin.domains_https_err_'.$err.'_adv')],
                            $g->state === 'failing' => [__('admin.domains_msg_failing', ['date' => $g->until?->format('d/m/Y')]), $g->primary ? __('admin.domains_msg_failing_primary') : null, 'danger', null],
                            $g->state === 'expiring' => [__('admin.domains_msg_expiring', ['n' => $g->daysLeft, 'date' => $g->until?->format('d/m/Y')]), __('admin.domains_msg_expiring_fix'), 'danger', null],
                            $g->state === 'lost' => [__('admin.domains_msg_lost'), null, 'danger', null],
                            $g->primary => [__('admin.domains_msg_active_primary', ['host' => $display($g->runs)]), null, 'ok', null],
                            default => [__('admin.domains_msg_active_other', ['host' => $display($overview->primary)]), null, 'muted', null],
                        };
                        $rows = $g->platform
                            ? [[$g->runs, $g->primary ? __('admin.domains_role_running') : __('admin.domains_role_redirect', ['host' => $display($overview->primary)]), 'ok']]
                            : array_values(array_filter([
                                [$g->runs, ($g->primary || $g->state !== 'active') ? __('admin.domains_role_running') : __('admin.domains_role_redirect', ['host' => $display($overview->primary)]), $g->rowState(true)],
                                $g->alt !== null ? [$g->alt, __('admin.domains_role_redirect', ['host' => $display($g->runs)]), $g->rowState(false)] : null,
                            ]));
                        $pill = $g->platform ? 'active' : $g->state;
                    @endphp
                    <div wire:key="dg-{{ $g->name }}" class="flex flex-wrap gap-x-3 gap-y-2 px-4 py-3.5 @max-[37.4999rem]:p-3 {{ $loop->first ? '' : 'border-t border-border' }}">
                        <div class="flex min-w-0 flex-[1_1_0] flex-wrap items-center gap-x-2.5 gap-y-1.5">
                            <span class="min-w-0 text-[calc(0.9375rem+var(--user-text-boost))] font-semibold text-text-strong [overflow-wrap:anywhere]">{{ $name }}</span>
                            @if ($g->primary)
                                <x-bs-admin::status-pill status="domain-primary">{{ __('admin.domains_chip_primary') }}</x-bs-admin::status-pill>
                            @endif
                            @if ($g->platform)
                                <x-bs-admin::status-pill status="domain-platform">{{ __('admin.domains_chip_platform') }}</x-bs-admin::status-pill>
                            @endif
                            <span wire:loading.remove wire:target="check({{ Js::from($g->name) }})" class="contents">
                                <x-bs-admin::status-pill :status="'domain-'.$pill">{{ $g->state === 'expiring' ? __('admin.domains_st_expiring', ['n' => $g->daysLeft]) : __('admin.domains_st_'.$pill) }}</x-bs-admin::status-pill>
                            </span>
                            <span wire:loading wire:target="check({{ Js::from($g->name) }})">
                                <x-bs-admin::status-pill status="domain-verified">{{ __('admin.domains_st_checking') }}</x-bs-admin::status-pill>
                            </span>
                            @if ($g->state === 'active' && isset($recovered[$g->name]))
                                <x-bs-admin::status-pill status="domain-recovered">{{ __('admin.domains_chip_recovered') }}</x-bs-admin::status-pill>
                            @endif
                        </div>

                        @if (! $g->platform)
                            <div class="flex flex-none items-start justify-end gap-2 @max-[37.4999rem]:order-last @max-[37.4999rem]:basis-full">
                                @if ($g->state === 'issuing')
                                    <span role="status" class="inline-flex min-h-control items-center gap-1.5 text-small text-info-text">
                                        <x-bs-admin::icon name="ri-loader-4" :class="$glyph.' animate-spin motion-reduce:animate-none'" />{{ __('admin.domains_busy_enable') }}
                                    </span>
                                @endif
                                <span role="status" wire:loading.inline-flex wire:target="check({{ Js::from($g->name) }})" class="min-h-control items-center gap-1.5 text-small text-info-text">
                                    <x-bs-admin::icon name="ri-loader-4" :class="$glyph.' animate-spin motion-reduce:animate-none'" />{{ __('admin.domains_busy_check') }}
                                </span>
                                @if ($next)
                                    <x-bs-admin::button variant="outline" color="primary" size="sm" class="@max-[37.4999rem]:flex-1" wire:click="{{ $next[0] }}({{ Js::from($g->name) }})"
                                        wire:loading.attr="disabled" wire:target="{{ $next[0] }}({{ Js::from($g->name) }})"
                                        :aria-disabled="$locked ? 'true' : null">
                                        <x-bs-admin::icon :name="$next[1]" :class="$glyph" />
                                        <span>{{ $next[2] }}</span>
                                    </x-bs-admin::button>
                                @endif
                                <x-bs-admin::list.actions :name="$name" :title="__('admin.domains_menu_title', ['name' => $name])">
                                    <x-slot:menu>
                                        <x-bs-admin::list.menu-item icon="ri-table" wire:click="togglePanel({{ Js::from($g->name) }})">{{ ($open[$g->name] ?? false) ? __('admin.domains_menu_hide_table') : __('admin.domains_menu_show_table') }}</x-bs-admin::list.menu-item>
                                        @if ($manage)
                                            @php $lockWhy = $locked ? __('admin.domains_locked') : null; @endphp
                                            @if ($g->state === 'active' && $g->altOptional)
                                                <x-bs-admin::list.menu-item icon="ri-radar" :why="$lockWhy" wire:click="check({{ Js::from($g->name) }})">{{ __('admin.domains_next_recheck') }}</x-bs-admin::list.menu-item>
                                            @endif
                                            @if (! $g->primary && $g->state !== 'active')
                                                <x-bs-admin::list.menu-item icon="ri-star" :why="$lockWhy ?? __('admin.domains_menu_primary_need_https')" wire:click="askPrimary({{ Js::from($g->name) }})">{{ __('admin.domains_next_primary') }}</x-bs-admin::list.menu-item>
                                            @endif
                                            @if ($g->alt !== null)
                                                <x-bs-admin::list.menu-item icon="ri-arrow-left-right" :why="$lockWhy" wire:click="switchSpelling({{ Js::from($g->name) }})">{{ $g->www() ? __('admin.domains_menu_www_off') : __('admin.domains_menu_www_on', ['name' => $display($g->name)]) }}</x-bs-admin::list.menu-item>
                                            @endif
                                            <x-bs-admin::list.menu-sep />
                                            <x-bs-admin::list.menu-item icon="ri-delete-bin" danger :why="$lockWhy" wire:click="askRemove({{ Js::from($g->name) }})">{{ __('admin.domains_menu_remove') }}</x-bs-admin::list.menu-item>
                                        @endif
                                    </x-slot:menu>
                                </x-bs-admin::list.actions>
                            </div>
                        @endif

                        {{-- One line per spelling: address, what it does, its own state --}}
                        <div class="flex min-w-0 basis-full flex-col gap-1">
                            @foreach ($rows as [$rowHost, $rowRole, $rowState])
                                @php [$rowInk, $rowIcon, $rowLabel] = $rowTones[$rowState]; @endphp
                                <div class="grid grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)_minmax(0,1.2fr)] items-center gap-x-3 gap-y-0.5 rounded bg-surface-muted px-2.5 py-1.5 text-small @max-[44.9999rem]:grid-cols-[minmax(0,1fr)_auto]">
                                    <span class="font-mono text-text-strong [overflow-wrap:break-word]">{!! $host($rowHost) !!}</span>
                                    <span class="text-text-muted [overflow-wrap:anywhere] @max-[44.9999rem]:order-last @max-[44.9999rem]:col-span-2">{{ $rowRole }}</span>
                                    <span class="flex min-w-0 items-center gap-1.5 [overflow-wrap:anywhere] {{ $rowInk }} @max-[44.9999rem]:justify-end @max-[44.9999rem]:text-right">
                                        <x-bs-admin::icon :name="$rowIcon" :class="$glyph" /><span>{{ __($rowLabel) }}</span>
                                    </span>
                                </div>
                            @endforeach
                        </div>

                        {{-- What this state means and what to do --}}
                        @php [$msgInk, $msgIcon] = $msgTones[$tone]; @endphp
                        <div class="flex min-w-0 basis-full items-start gap-2 text-small leading-[1.55] {{ $msgInk }}">
                            <x-bs-admin::icon :name="$msgIcon" :class="$glyph.' mt-0.5'" />
                            <div class="flex min-w-0 flex-col gap-1">
                                <span>{{ $msg }}</span>
                                @if ($msg2)
                                    <span class="font-semibold">{{ $msg2 }}</span>
                                @endif
                                @if ($adv)
                                    <details class="text-small">
                                        <summary class="hit-area flex min-h-control-row cursor-pointer list-none items-center gap-1.5 font-semibold text-primary-text any-pointer-coarse:min-h-hit [&::-webkit-details-marker]:hidden">
                                            <x-bs-admin::icon name="ri-arrow-right-s" :class="$glyph.' transition-transform in-open:rotate-90 motion-reduce:transition-none'" />
                                            {{ __('admin.domains_advanced_details') }}
                                        </summary>
                                        <p class="m-0 mt-1 text-text">{{ $adv }}</p>
                                    </details>
                                @endif
                            </div>
                        </div>

                        @if ($g->state === 'claim')
                            @include('admin::livewire.settings.domains.proof', ['group' => $g, 'proof' => $proof($g), 'dialog' => false])
                        @endif
                        @if ($open[$g->name] ?? false)
                            @include('admin::livewire.settings.domains.pointing', ['group' => $g, 'records' => $records($g), 'apex' => $apex[$g->name] ?? null, 'dialog' => false])
                        @endif
                    </div>
                @endforeach
            </section>

            @if ($manage)
                {{-- Add a name: 1 type it, 2 how to point it (or prove it is yours) --}}
                <x-bs-admin::modal model="addOpen" size="wide" :title="__('admin.domains_dlg_title')" :subtitle="__('admin.domains_dlg_step', ['n' => $addStep])" :close-label="__('admin.domains_dlg_close')">
                    @if ($addStep === 1)
                        <div>
                            <label for="tm-in" class="mb-1.5 block text-body font-medium text-text">{{ __('admin.domains_dlg_name_label') }} <span class="text-danger-text" aria-hidden="true">*</span></label>
                            <input id="tm-in" type="text" autofocus wire:model.live.debounce.300ms="addName" x-on:blur="$wire.checkName()" wire:keydown.enter.prevent="submitAdd"
                                   placeholder="{{ __('admin.domains_dlg_name_placeholder') }}" inputmode="url" autocapitalize="off" spellcheck="false" autocomplete="off" required
                                   aria-describedby="tm-in-d" @error('addName') aria-invalid="true" @enderror
                                   class="field block w-full rounded border border-border-input bg-surface px-3 text-[calc(1rem+var(--user-text-boost))] text-text placeholder:text-placeholder focus:border-primary-text aria-invalid:border-danger-text">
                            @error('addName')
                                <p id="tm-in-d" class="m-0 mt-1.5 flex items-start gap-1.5 text-small text-danger-text"><x-bs-admin::icon name="ri-error-warning" :class="$glyph.' mt-0.5'" /><span>{{ $message }}</span></p>
                            @else
                                <p id="tm-in-d" class="m-0 mt-1.5 text-small text-text-muted">{{ __('admin.domains_dlg_name_hint') }}</p>
                            @enderror
                        </div>
                        @php $preview = $addPreview; $n = $preview['name'] !== '' ? $preview['name'] : __('admin.domains_dlg_name_placeholder'); @endphp
                        <div class="flex items-start gap-3 rounded-md border border-border px-3 py-2.5">
                            <div class="min-w-0 flex-auto">
                                <span id="tm-www-l" class="block font-semibold text-text-strong">{{ __('admin.domains_dlg_www_label') }} · {{ $addWww ? __('admin.domains_dlg_www_on') : __('admin.domains_dlg_www_off') }}</span>
                                <span class="block text-small text-text-muted">{{ __($addWww ? 'admin.domains_dlg_www_hint_on' : 'admin.domains_dlg_www_hint_off', ['n' => $n]) }}</span>
                            </div>
                            <button type="button" role="switch" aria-checked="{{ $addWww ? 'true' : 'false' }}" aria-labelledby="tm-www-l" wire:click="toggleWww"
                                    class="hit-area relative h-6 w-11 shrink-0 cursor-pointer rounded-full transition-colors motion-reduce:transition-none {{ $addWww ? 'bg-primary' : 'bg-border-input' }}">
                                <span class="absolute top-0.5 size-5 rounded-full bg-white shadow {{ $addWww ? 'right-0.5' : 'left-0.5' }}"></span>
                            </button>
                        </div>
                        @if ($preview['name'] !== '')
                            <x-bs-admin::alert color="info" class="text-small">{{ __('admin.domains_dlg_preview', ['run' => $preview['runs'], 'alt' => $preview['alt']]).($preview['typedWww'] && $addWww ? ' '.__('admin.domains_dlg_preview_www_note') : '') }}</x-bs-admin::alert>
                        @endif
                    @elseif ($added)
                        <x-bs-admin::alert color="success" icon="ri-checkbox-circle" class="text-small" tabindex="-1" autofocus>
                            {{ $added->state === 'claim' ? __('admin.domains_dlg_added_claim', ['name' => $display($added->name)]) : __('admin.domains_dlg_added', ['name' => $display($added->name)]) }}
                        </x-bs-admin::alert>
                        @if ($added->state === 'claim')
                            @include('admin::livewire.settings.domains.proof', ['group' => $added, 'proof' => $proof($added), 'dialog' => true])
                        @else
                            @include('admin::livewire.settings.domains.pointing', ['group' => $added, 'records' => $records($added), 'apex' => null, 'dialog' => true])
                        @endif
                        <span class="text-small text-text-muted">{{ __('admin.domains_dlg_check_foot') }}</span>
                    @endif

                    <x-slot:footer>
                        @if ($addStep === 1)
                            <x-bs-admin::button variant="outline" color="secondary" wire:click="closeAdd">{{ __('admin.cancel') }}</x-bs-admin::button>
                            <x-bs-admin::button wire:click="submitAdd" wire:loading.attr="disabled" wire:target="submitAdd">
                                <span>{{ __('admin.domains_dlg_continue') }}</span>
                                <x-bs-admin::icon name="ri-arrow-right" :class="$glyph" />
                            </x-bs-admin::button>
                        @else
                            <x-bs-admin::button variant="outline" color="secondary" wire:click="closeAdd">{{ __('admin.domains_dlg_later') }}</x-bs-admin::button>
                            <x-bs-admin::button wire:click="checkFromAdd">
                                <x-bs-admin::icon name="ri-radar" :class="$glyph" />
                                <span>{{ __('admin.domains_dlg_check_now') }}</span>
                            </x-bs-admin::button>
                        @endif
                    </x-slot:footer>
                </x-bs-admin::modal>

                {{-- Questions before a change --}}
                @php
                    $cg = $confirmGroup;
                    $cfTitle = $cfOk = '';
                    $cfIcon = 'ri-star';
                    if ($cg && $confirm === 'primary') {
                        $cfTitle = __('admin.domains_cf_primary_title', ['host' => $display($cg->runs)]);
                        $cfOk = __('admin.domains_cf_primary_ok');
                    } elseif ($cg && $confirm === 'spelling') {
                        $cfTitle = __('admin.domains_cf_www_title');
                        $cfOk = __('admin.domains_cf_www_ok', ['host' => $display($cg->alt)]);
                        $cfIcon = 'ri-arrow-left-right';
                    } elseif ($cg && $confirm === 'remove') {
                        $cfTitle = __('admin.domains_cf_remove_title', ['name' => $display($cg->name)]);
                        $cfOk = __('admin.domains_cf_remove_ok');
                        $cfIcon = 'ri-delete-bin';
                    }
                @endphp
                <x-bs-admin::confirm-dialog model="confirmOpen" :title="$cfTitle" :icon="$cfIcon" :danger="$confirm === 'remove'" :ok="$cfOk" confirm="confirmOk" cancel="cancelConfirm">
                    @if ($cg && $confirm === 'primary')
                        <x-bs-admin::confirm-line icon="ri-global">{{ __('admin.domains_cf_primary_l1', ['old' => $display($overview->primary), 'host' => $display($cg->runs)]) }}</x-bs-admin::confirm-line>
                        <x-bs-admin::confirm-line icon="ri-route">{{ __('admin.domains_cf_primary_l2', ['old' => $display($overview->primary), 'host' => $display($cg->runs)]) }}</x-bs-admin::confirm-line>
                        <x-bs-admin::confirm-line icon="ri-time">{{ __('admin.domains_cf_primary_l3') }}</x-bs-admin::confirm-line>
                    @elseif ($cg && $confirm === 'spelling')
                        <x-bs-admin::confirm-line icon="ri-global">{{ __('admin.domains_cf_www_l1', ['from' => $display($cg->runs), 'to' => $display($cg->alt)]) }}</x-bs-admin::confirm-line>
                        <x-bs-admin::confirm-line icon="ri-route">{{ __('admin.domains_cf_www_l2', ['from' => $display($cg->runs)]) }}</x-bs-admin::confirm-line>
                    @elseif ($cg && $confirm === 'remove')
                        @php $rec = $records($cg); @endphp
                        <x-bs-admin::confirm-line icon="ri-close-circle">{{ $cg->alt !== null ? __('admin.domains_cf_remove_l1', ['run' => $display($cg->runs), 'alt' => $display($cg->alt)]) : __('admin.domains_cf_remove_l1_one', ['run' => $display($cg->runs)]) }}</x-bs-admin::confirm-line>
                        @if ($cg->primary)
                            <x-bs-admin::confirm-line icon="ri-star">{{ __('admin.domains_cf_remove_l1_primary', ['platform' => $display($platformHost), 'run' => $display($cg->runs)]) }}</x-bs-admin::confirm-line>
                        @endif
                        <x-bs-admin::confirm-line icon="ri-list-check">{{ __('admin.domains_cf_remove_l2', ['n' => count($rec), 'labels' => collect($rec)->map(fn (array $r): string => '“'.$r['label'].'”')->join(' '.__('admin.domains_and').' ')]) }}</x-bs-admin::confirm-line>
                    @endif
                </x-bs-admin::confirm-dialog>
            @endif
        @endif
    </div>

    <x-bs-admin::toast :message="$toast" :tone="$toastTone" dismiss="dismissToast" :undo="$toastUndo ? 'undo' : null" />
</div>
