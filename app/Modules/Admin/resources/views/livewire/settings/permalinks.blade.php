@php
    // «Đường dẫn» (board «Khung quản trị», section «Đường dẫn», embedded component c-duong-dan): the frame
    // draws the section's name, note and save bar; the page holds the board's info line, the preview of
    // the addresses that change, then one card per content section with its options (name, «Khuyên dùng»,
    // an example address, when to use it) and, for «Có tiền tố», the prefix field. Two states the board
    // does not draw: a change still being applied (the cards show what is coming, read-only) and counts
    // past 10.000 («10.000+»). Addresses are code-like text: monospace, wrapped anywhere, never clipped.
    $glyph = 'size-[calc(1rem+var(--user-text-boost))] shrink-0';
    $cardIcon = 'size-[min(1.5rem,calc(1.125rem+var(--user-text-boost)))] shrink-0';
@endphp
<div>
    @if (! $allowed)
        {{-- Not this member's to open: the page keeps its own title (the frame lists only what the member may open). --}}
        <div class="flex flex-col gap-4">
            <header class="flex flex-col gap-1">
                <nav aria-label="{{ __('admin.breadcrumb') }}" class="flex flex-wrap items-center gap-1 text-small text-text-muted">
                    <span>{{ __('admin.domains_crumb_settings') }}</span>
                    <x-bs-admin::icon name="ri-arrow-right-s" :class="$glyph" />
                    <span>{{ __('admin.set.g.content') }}</span>
                    <x-bs-admin::icon name="ri-arrow-right-s" :class="$glyph" />
                    <span aria-current="page" class="text-text-strong">{{ __('admin.pl.title') }}</span>
                </nav>
                <x-bs-admin::title-row :title="__('admin.pl.title')">
                </x-bs-admin::title-row>
                <p class="m-0 max-w-[72ch] text-body text-text-muted [text-wrap:pretty]">{{ __('admin.set.desc.permalinks') }}</p>
            </header>
            <div class="flex flex-col items-start gap-2 rounded-md border border-border bg-surface p-5 shadow-card">
                <x-bs-admin::icon name="ri-forbid" class="size-6 text-text-muted" />
                <h2 class="m-0 text-[calc(1rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('admin.pl.noperm_title') }}</h2>
                <p class="m-0 text-text-muted">{{ __('admin.pl.noperm_body', ['role' => $role !== '' ? $role : __('admin.domains_noperm_role_none')]) }}</p>
            </div>
        </div>
    @else
    <x-bs-admin::settings-shell active="permalinks" card="none" :unsaved="$changes" :invalid="$invalid" first-error="pl-first-error">
    <div class="@container/pl flex flex-col gap-3.5" x-on:pl-first-error.window="$nextTick(() => { const f = $el.querySelector('[aria-invalid=true]'); f && f.focus() })">
        @if ($failed)
            <div class="flex flex-col items-start gap-2 rounded-md border border-border bg-surface p-5 shadow-card" wire:loading.remove wire:target="retry">
                <x-bs-admin::icon name="ri-wifi-off" class="size-6 text-danger-text" />
                <h2 class="m-0 text-[calc(1rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('admin.pl.error_title') }}</h2>
                <p class="m-0 text-text-muted">{{ __('admin.pl.error_body') }}</p>
                <x-bs-admin::button variant="outline" color="primary" wire:click="retry">
                    <x-bs-admin::icon name="ri-refresh" :class="$glyph" />
                    <span>{{ __('admin.pl.retry') }}</span>
                </x-bs-admin::button>
            </div>
            <div wire:loading.flex wire:target="retry" aria-busy="true" aria-label="{{ __('admin.pl.loading') }}" class="flex-col gap-3">
                <div class="h-11 rounded bg-surface-hover"></div>
                <div class="flex flex-col gap-2.5 rounded-md border border-border bg-surface p-4">
                    <div class="h-4.5 w-2/5 rounded bg-surface-hover"></div>
                    <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,13.75rem),1fr))] gap-2"><div class="h-24 rounded bg-surface-hover"></div><div class="h-24 rounded bg-surface-hover"></div><div class="h-24 rounded bg-surface-hover"></div></div>
                </div>
            </div>
        @elseif ($locked)
            <div class="flex flex-col items-start gap-2 rounded-md border border-border bg-surface p-5 shadow-card">
                @if ($upsell)
                    <x-bs-admin::badge color="warning" soft><x-bs-admin::icon name="ri-lock-2" class="size-3.5" />{{ __('admin.pl.lock_chip') }}</x-bs-admin::badge>
                @else
                    <x-bs-admin::icon name="ri-lock-2" class="size-6 text-text-muted" />
                @endif
                <h2 class="m-0 text-[calc(1rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('admin.pl.lock_title') }}</h2>
                <p class="m-0 text-text-muted">{{ $upsell ? __('admin.pl.lock_up') : __('admin.pl.lock_off') }}</p>
                @if ($upsell && $supportMail !== '')
                    <x-bs-admin::button variant="outline" color="neutral" :href="'mailto:'.$supportMail" :navigate="false">
                        <x-bs-admin::icon name="ri-vip-crown" :class="$glyph" />
                        <span>{{ __('admin.pl.lock_upgrade') }}</span>
                    </x-bs-admin::button>
                @endif
            </div>
        @else
            <div class="flex items-start gap-2.5 rounded-md border border-border bg-surface px-3.5 py-2.75 text-small leading-relaxed">
                <x-bs-admin::icon name="ri-information" class="mt-px size-[min(1.5rem,calc(1rem+var(--user-text-boost)))] shrink-0 text-primary-text" />
                <span class="min-w-0">{!! __('admin.pl.info', ['primary' => '<b>'.e(__('admin.pl.info_primary')).'</b>']) !!}</span>
            </div>

            @if ($pending)
                {{-- a change still being applied (not on the board): the run moves the addresses, then the page refreshes itself --}}
                <div wire:poll.15s="retry">
                    <x-bs-admin::alert color="info" icon="ri-loader-4" role="status">
                        <span class="block font-semibold">{{ __('admin.pl.pending_title') }}</span>
                        <span class="block">{{ __('admin.pl.pending_body') }}</span>
                    </x-bs-admin::alert>
                </div>
            @endif

            @if ($live && $preview['changed'] > 0)
                <section aria-labelledby="pl-pv-h" class="overflow-hidden rounded-md border border-primary-text/30 bg-surface">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-border bg-primary-soft/40 px-4 py-3">
                        <x-bs-admin::icon name="ri-eye" :class="$cardIcon.' text-primary-text'" />
                        <h2 id="pl-pv-h" class="m-0 min-w-0 flex-[1_1_13.75rem] text-[calc(0.9375rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('admin.pl.pv_title') }}</h2>
                        <div class="flex flex-wrap gap-1.5">
                            <x-bs-admin::badge color="primary" soft><x-bs-admin::icon name="ri-links" class="size-3.5" />{{ __('admin.pl.pv_changed', ['n' => $num($preview['changed'], $preview['capped'])]) }}</x-bs-admin::badge>
                            <x-bs-admin::badge color="success" soft><x-bs-admin::icon name="ri-route" class="size-3.5" />{{ __('admin.pl.pv_redirects', ['n' => $num($preview['changed'], $preview['capped'])]) }}</x-bs-admin::badge>
                            @if ($preview['dups'] > 0)
                                <x-bs-admin::badge color="warning" soft><x-bs-admin::icon name="ri-error-warning" class="size-3.5" />{{ __('admin.pl.pv_dups', ['n' => $num($preview['dups'])]) }}</x-bs-admin::badge>
                            @endif
                        </div>
                    </div>
                    <div class="flex flex-col gap-3 px-4 pt-3 pb-3.5">
                        @foreach ($preview['list'] as $d)
                            <div wire:key="pl-pv-{{ $d['key'] }}">
                                <div class="mb-0.5 flex flex-wrap items-baseline gap-x-2.5 gap-y-1">
                                    <span class="text-[calc(0.84375rem+var(--user-text-boost))] font-semibold text-text-strong">{{ $d['name'] }}</span>
                                    <span class="text-caption text-text-muted">{{ __('admin.pl.pv_summary', ['n' => $num($d['estimate'], $d['capped']), 'from' => __('admin.pl.style_'.$d['from']), 'to' => __('admin.pl.style_'.$d['to'])]) }}</span>
                                </div>
                                @foreach ($d['rows'] as $r)
                                    <div class="grid grid-cols-[auto_minmax(0,1fr)] items-center gap-x-2.5 gap-y-0.5 border-t border-border py-2 @[37.5rem]/pl:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)]">
                                        <span class="col-span-full font-mono text-[calc(0.78125rem+var(--user-text-boost))] leading-normal text-text-muted [overflow-wrap:anywhere] @[37.5rem]/pl:col-span-1" dir="ltr">{{ $r['old'] }}</span>
                                        <span class="inline-flex text-text-muted"><x-bs-admin::icon name="ri-arrow-right" class="size-4" /><span class="sr-only">{{ __('admin.pl.pv_to') }}</span></span>
                                        <span class="flex min-w-0 flex-col gap-0.5">
                                            <span class="font-mono text-[calc(0.78125rem+var(--user-text-boost))] leading-normal font-semibold text-primary-text [overflow-wrap:anywhere]" dir="ltr">{{ $r['new'] }}</span>
                                            @if ($r['dup'] !== null)
                                                <span class="flex items-start gap-1 text-caption leading-snug text-warning-text">
                                                    <x-bs-admin::icon name="ri-error-warning" class="mt-0.5 size-3.5 shrink-0" />
                                                    <span>{{ __('admin.pl.pv_dup', ['holder' => $r['holder'], 'url' => $r['dup']]) }}</span>
                                                </span>
                                            @endif
                                        </span>
                                    </div>
                                @endforeach
                                @if ($d['more'] > 0)
                                    <div class="border-t border-border pt-1.5 text-caption text-text-muted">{{ __('admin.pl.pv_more', ['n' => $num($d['more'], $d['capped']), 'name' => mb_strtolower($d['name'])]) }}</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </section>
            @elseif (! $live && $changes > 0)
                <x-bs-admin::alert color="info" icon="ri-information" role="status">{{ __('admin.pl.not_live') }}</x-bs-admin::alert>
            @endif

            @foreach ($cards as $t)
                @php
                    $k = $t['key'];
                    $hid = 'pl-h-'.$k;
                    $err = $t['problem'];
                    $errText = match ($err['code'] ?? null) {
                        'base_empty' => __('admin.pl.err_empty', ['ex' => $t['example_base']]),
                        'base_format' => __('admin.pl.err_format', ['ex' => $t['example_base']]),
                        'base_taken' => __('admin.pl.err_taken', ['other' => $names[$err['other'] ?? ''] ?? '']),
                        'base_reserved' => __('admin.pl.err_reserved'),
                        'pattern' => __('admin.pl.err_pattern'),
                        default => '',
                    };
                    $readOnly = $pending;
                @endphp
                <section aria-labelledby="{{ $hid }}" wire:key="pl-card-{{ $k }}" class="overflow-hidden rounded-md border border-border bg-surface shadow-card">
                    <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1.5 border-b border-border px-4 py-3">
                        <x-bs-admin::icon :name="$t['icon']" :class="$cardIcon.' text-primary-text'" />
                        <h2 id="{{ $hid }}" class="m-0 min-w-0 flex-[1_1_10rem] text-[calc(0.9375rem+var(--user-text-boost))] font-semibold text-text-strong">{{ $t['name'] }}</h2>
                        <span class="text-caption text-text-muted">{{ __('admin.pl.count', ['n' => $num($t['count'], $t['capped']), 'style' => __('admin.pl.style_'.$t['pattern'])]) }}</span>
                        @if ($t['staged'])
                            <x-bs-admin::badge color="info" soft><x-bs-admin::icon name="ri-loader-4" class="size-3.5" />{{ __('admin.pl.chip_pending') }}</x-bs-admin::badge>
                        @elseif ($t['changed'])
                            <x-bs-admin::badge color="warning" soft><x-bs-admin::icon name="ri-edit-2" class="size-3.5" />{{ __('admin.pl.chip_unsaved') }}</x-bs-admin::badge>
                        @endif
                    </div>
                    <div role="radiogroup" aria-labelledby="{{ $hid }}" class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,13.75rem),1fr))] gap-2 px-4 py-3">
                        @foreach ($t['options'] as $o)
                            @php $sel = $t['draft']['pattern'] === $o['style']; @endphp
                            <label wire:key="pl-opt-{{ $k }}-{{ $o['style'] }}" @class([
                                'group flex min-w-0 flex-col items-start gap-1.5 rounded-md px-3 py-2.75 text-left transition-colors has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary-text any-pointer-coarse:min-h-hit',
                                'border-2 border-primary-text bg-primary-soft/40' => $sel,
                                'border border-border-input bg-surface px-3.25 py-3' => ! $sel,
                                'cursor-pointer hover:border-primary-text' => ! $readOnly,
                                'cursor-not-allowed opacity-75' => $readOnly && ! $sel,
                            ])>
                                <input type="radio" name="pl-{{ $k }}" value="{{ $o['style'] }}" wire:model.live="draft.{{ $k }}.pattern" class="sr-only" @checked($sel) @disabled($readOnly)>
                                <span class="flex flex-wrap items-center gap-1.5">
                                    @if ($sel)
                                        <x-bs-admin::icon name="ri-checkbox-circle-fill" :class="$cardIcon.' text-primary-text'" />
                                    @else
                                        <x-bs-admin::icon name="ri-checkbox-blank-circle" :class="$cardIcon.' text-text-muted'" />
                                    @endif
                                    <span class="text-[calc(0.875rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('admin.pl.style_'.$o['style']) }}</span>
                                    @if ($o['style'] === 'flat')
                                        <x-bs-admin::badge color="success" soft>{{ __('admin.pl.rec') }}</x-bs-admin::badge>
                                    @endif
                                </span>
                                <span class="font-mono text-[calc(0.78125rem+var(--user-text-boost))] leading-normal text-text-strong [overflow-wrap:anywhere]" dir="ltr">{{ $host }}{{ $o['example'] }}</span>
                                <span class="text-[calc(0.78125rem+var(--user-text-boost))] leading-normal text-text-muted">{{ __('admin.pl.when_'.$o['style']) }}</span>
                            </label>
                        @endforeach
                    </div>
                    @if ($t['draft']['pattern'] === 'base')
                        @php $pid = 'pl-p-'.$k; $pdid = 'pl-pd-'.$k; @endphp
                        <div class="flex flex-wrap items-start gap-x-4 gap-y-2 px-4 pb-3.5">
                            <div class="min-w-0 flex-[0_1_17.5rem]">
                                <label for="{{ $pid }}" class="mb-1.5 block text-body font-medium text-text-strong">{{ __('admin.pl.prefix') }} <span class="text-danger-text" aria-hidden="true">*</span><span class="sr-only">{{ __('admin.pl.required') }}</span></label>
                                <div class="flex items-center gap-1.5">
                                    <span class="font-mono text-small text-text-muted" aria-hidden="true">/</span>
                                    <input id="{{ $pid }}" type="text" wire:model.blur="draft.{{ $k }}.base" placeholder="{{ $t['example_base'] }}" spellcheck="false" autocapitalize="off" autocomplete="off" dir="ltr"
                                        aria-describedby="{{ $pdid }}" @if ($errText !== '') aria-invalid="true" @endif @disabled($readOnly)
                                        class="field min-w-0 flex-1 rounded border border-border-input bg-surface px-3 font-mono text-text-strong transition-colors focus:border-primary-text aria-invalid:border-danger-text disabled:cursor-not-allowed disabled:bg-surface-muted">
                                    <span class="font-mono text-small text-text-muted" aria-hidden="true">/…</span>
                                </div>
                                @if ($errText !== '')
                                    <x-bs-admin::field-error id="{{ $pdid }}">{{ $errText }}</x-bs-admin::field-error>
                                @else
                                    <p id="{{ $pdid }}" class="m-0 mt-1 text-small text-text-muted">{{ __('admin.pl.prefix_hint') }}</p>
                                @endif
                            </div>
                        </div>
                    @endif
                </section>
            @endforeach

            @if ($savedAt !== '' && $changes === 0 && ! $pending)
                <p class="m-0 flex items-center gap-1.5 text-caption text-success-text">
                    <x-bs-admin::icon name="ri-checkbox-circle" class="size-4" />{{ __('admin.pl.saved_at', ['time' => $savedAt]) }}
                </p>
            @endif
        @endif
    </div>

    {{-- Before addresses visitors know change: how many, the redirects the website gets, the clashes, Google's delay. --}}
    <x-bs-admin::confirm-dialog model="confirming" :title="__('admin.pl.cf_title', ['n' => $changes])" icon="ri-links" warning
        :ok="__('admin.pl.cf_ok', ['n' => $num($preview['changed'], $preview['capped'])])" ok-icon="ri-check" confirm="confirmSave" cancel="cancelConfirm">
        <x-bs-admin::confirm-line icon="ri-links">{{ __('admin.pl.cf_changed', ['n' => $num($preview['changed'], $preview['capped'])]) }}</x-bs-admin::confirm-line>
        <x-bs-admin::confirm-line icon="ri-route">{{ __('admin.pl.cf_redirects', ['n' => $num($preview['changed'], $preview['capped'])]) }}</x-bs-admin::confirm-line>
        @if ($preview['dups'] > 0)
            <x-bs-admin::confirm-line icon="ri-error-warning">{{ __('admin.pl.cf_dups', ['n' => $num($preview['dups'])]) }}</x-bs-admin::confirm-line>
        @endif
        <x-bs-admin::confirm-line icon="ri-time">{{ __('admin.pl.cf_google') }}</x-bs-admin::confirm-line>
    </x-bs-admin::confirm-dialog>

    <x-bs-admin::toast :message="$toast" :tone="$toastTone" dismiss="dismissToast" :seq="$toastSeq" />
    </x-bs-admin::settings-shell>
    @endif
</div>
