<x-bs-admin::settings-shell active="langs" :unsaved="$changes">
    @php
        $enabledN = count($this->enabled);
        $publicN = count($this->public);
        $glyph = 'size-[min(1.5rem,calc(0.9375rem+var(--user-text-boost)))]';
    @endphp

    {{-- What the three columns mean, with the plan's limit and today's counts. --}}
    <div class="flex items-start gap-2.5 rounded-lg border border-primary/20 bg-primary/5 px-3.5 py-3 text-[calc(0.78125rem+var(--user-text-boost))] leading-[1.55] text-text" data-lng-legend>
        <x-bs-admin::icon name="ri-information" class="mt-px shrink-0 text-primary-text {{ $glyph }}" />
        <span class="min-w-0"><b>{{ __('admin.lng.plan', ['max' => $max, 'on' => $enabledN, 'pub' => $publicN]) }}</b>
            <b>{{ __('admin.lng.col_on') }}</b> {{ __('admin.lng.legend_on') }} · <b>{{ __('admin.lng.col_pub') }}</b> {{ __('admin.lng.legend_pub') }} · <b>{{ __('admin.lng.col_def') }}</b> {{ __('admin.lng.legend_def') }}</span>
    </div>

    {{-- Every language the plan allows is on: the ones left are locked, and this says where to get more. --}}
    @if ($limited)
        <div data-lng-limit x-data @lng-limit.window="$nextTick(() => $el.querySelector('a, button')?.focus())" @class([
            'flex flex-wrap items-center gap-x-3.5 gap-y-2.5 rounded-md border px-3.5 py-3',
            'border-warning-base/45 bg-warning-soft' => $upsell,
            'border-border bg-surface-muted' => ! $upsell,
        ])>
            <x-bs-admin::icon name="ri-lock-2" @class(['shrink-0 size-[min(1.5rem,calc(1.125rem+var(--user-text-boost)))]', 'text-warning-text' => $upsell, 'text-text-muted' => ! $upsell]) />
            <span class="min-w-0 flex-[1_1_260px] text-[calc(0.8125rem+var(--user-text-boost))] leading-[1.55] text-text">
                @if ($upsell)
                    <b class="text-warning-text">{{ __('admin.lng.locked_plan') }}</b> {{ __('admin.lng.limit_up', ['n' => $enabledN, 'max' => $max]) }}
                @else
                    {{ __('admin.lng.limit_off', ['max' => $max]) }}
                @endif
                @if ($over)
                    {{ __('admin.lng.limit_over') }}
                @endif
            </span>
            @if ($supportMail !== '')
                <x-bs-admin::button variant="outline" color="neutral" :href="'mailto:'.$supportMail" :navigate="false">
                    <x-bs-admin::icon name="ri-customer-service-2" class="size-4" /> {{ __('admin.lng.support') }}
                </x-bs-admin::button>
            @endif
        </div>
    @endif

    <div class="@container overflow-hidden rounded-[9px] border border-border bg-surface">
        <div class="grid grid-cols-[minmax(150px,1fr)_repeat(3,96px)] items-center gap-x-2 gap-y-2.5 border-b border-border bg-surface-hover px-3.5 py-[11px] text-[calc(0.75rem+var(--user-text-boost))] font-bold tracking-[0.04em] text-text-muted uppercase @max-[32.5rem]:hidden" aria-hidden="true">
            <span class="min-w-0 truncate">{{ __('admin.lng.col_lang') }}</span>
            <span class="whitespace-nowrap text-center" title="{{ __('admin.lng.col_on_tip') }}">{{ __('admin.lng.col_on') }}</span>
            <span class="whitespace-nowrap text-center" title="{{ __('admin.lng.col_pub_tip') }}">{{ __('admin.lng.col_pub') }}</span>
            <span class="whitespace-nowrap text-center" title="{{ __('admin.lng.col_def_tip') }}">{{ __('admin.lng.col_def') }}</span>
        </div>

        <ul class="m-0 list-none p-0" aria-label="{{ __('admin.lng.title') }}">
            @foreach ($rows as $r)
                @php
                    $sub = match (true) {
                        $r['def'] => __('admin.lng.sub_def'),
                        $r['lock'] => $upsell ? __('admin.lng.sub_lock_up', ['max' => $max]) : __('admin.lng.sub_lock_off'),
                        $r['on'] && $r['pub'] => __('admin.lng.sub_pub'),
                        $r['on'] => __('admin.lng.sub_hidden', ['code' => $r['code']]),
                        default => __('admin.lng.sub_off'),
                    };
                    $lockAria = $upsell ? __('admin.lng.lock_aria_up', ['name' => $r['name'], 'max' => $max]) : __('admin.lng.lock_aria_off', ['name' => $r['name']]);
                    $onTip = $r['def'] ? __('admin.lng.on_tip_def') : __('admin.lng.on_tip', ['name' => $r['name']]);
                    $pubTip = $r['def'] ? __('admin.lng.pub_tip_def') : ($r['on'] ? __('admin.lng.pub_tip', ['name' => $r['name']]) : __('admin.lng.pub_tip_with', ['name' => $r['name']]));
                    $starTip = $r['def'] ? __('admin.lng.star_def') : __('admin.lng.star', ['name' => $r['name']]);
                @endphp
                <li wire:key="lng-{{ $r['code'] }}" data-lng-row="{{ $r['code'] }}" @class([
                    'grid grid-cols-[minmax(150px,1fr)_repeat(3,96px)] items-center gap-x-2 gap-y-2.5 border-b border-border px-3.5 py-[13px] last:border-b-0 hover:bg-primary/8 @max-[32.5rem]:grid-cols-3!',
                    'bg-primary/4' => $r['def'],
                ])>
                    <div class="flex min-w-0 flex-col gap-0.5 @max-[32.5rem]:col-span-3">
                        <span class="flex min-w-0 flex-wrap items-center gap-2">
                            <span @class(['min-w-0 text-[calc(0.84375rem+var(--user-text-boost))] [overflow-wrap:anywhere]', 'font-semibold text-text-strong' => $r['on'], 'font-medium text-text-muted' => ! $r['on']])>{{ $r['name'] }}</span>
                            <span @class(['inline-flex h-[19px] min-w-[26px] shrink-0 items-center justify-center rounded-[3px] px-[5px] text-[calc(0.75rem+var(--user-text-boost))] font-bold', 'bg-primary/12 text-primary-text' => $r['on'], 'bg-surface-hover text-text-muted' => ! $r['on']])>{{ strtoupper($r['code']) }}</span>
                            @if ($r['def'])
                                <span title="{{ __('admin.lng.star_def') }}" class="inline-flex text-warning-text"><x-bs-admin::icon name="ri-star-fill" class="size-[min(1.5rem,calc(0.75rem+var(--user-text-boost)))]" /></span>
                            @endif
                        </span>
                        <span class="text-[calc(0.75rem+var(--user-text-boost))] text-text-muted [overflow-wrap:anywhere]">{{ $sub }}</span>
                        @if ($r['on'] && ! $r['pub'] && ! $r['def'] && isset($previews[$r['code']]))
                            <span class="mt-1 flex flex-wrap items-center gap-2">
                                <x-bs-admin::button variant="outline" color="neutral" size="sm" :href="$previews[$r['code']]" :navigate="false" target="_blank" rel="noopener"
                                                    title="{{ __('admin.lng.preview_tip', ['name' => $r['name']]) }}">
                                    <x-bs-admin::icon name="ri-eye" class="size-4" /> {{ __('admin.lng.preview') }}
                                </x-bs-admin::button>
                            </span>
                        @endif
                    </div>

                    {{-- Đang soạn --}}
                    <span class="flex items-center justify-center @max-[32.5rem]:min-h-11 @max-[32.5rem]:flex-col @max-[32.5rem]:gap-1.5">
                        <span class="hidden text-[calc(0.75rem+var(--user-text-boost))] font-semibold uppercase tracking-[0.02em] text-text-muted @max-[32.5rem]:block" aria-hidden="true">{{ __('admin.lng.col_on') }}</span>
                        @if ($r['def'])
                            <span title="{{ $onTip }}" class="inline-flex items-center gap-1 whitespace-nowrap text-[calc(0.75rem+var(--user-text-boost))] font-semibold text-success-text"><x-bs-admin::icon name="ri-checkbox-circle" class="size-3.5" />{{ __('admin.lng.always_on') }}</span>
                        @elseif ($r['lock'])
                            <button type="button" wire:click="toggleEnabled('{{ $r['code'] }}')" title="{{ $lockAria }}" aria-label="{{ $lockAria }}"
                                    class="hit-area inline-flex min-h-9 items-center justify-center gap-1 whitespace-nowrap rounded border border-border-input bg-surface px-2.5 text-[calc(0.75rem+var(--user-text-boost))] font-semibold text-text-muted hover:text-text">
                                <x-bs-admin::icon name="ri-lock-2" class="size-3.5" />{{ __('admin.lng.lock') }}
                            </button>
                        @else
                            <button type="button" class="switch hit-area" role="switch" aria-checked="{{ $r['on'] ? 'true' : 'false' }}"
                                    wire:click="toggleEnabled('{{ $r['code'] }}')" title="{{ $onTip }}" aria-label="{{ $onTip }}"></button>
                        @endif
                    </span>

                    {{-- Khách thấy --}}
                    <span class="flex items-center justify-center @max-[32.5rem]:min-h-11 @max-[32.5rem]:flex-col @max-[32.5rem]:gap-1.5">
                        <span class="hidden text-[calc(0.75rem+var(--user-text-boost))] font-semibold uppercase tracking-[0.02em] text-text-muted @max-[32.5rem]:block" aria-hidden="true">{{ __('admin.lng.col_pub') }}</span>
                        @if ($r['def'])
                            <span title="{{ $pubTip }}" class="inline-flex items-center gap-1 whitespace-nowrap text-[calc(0.75rem+var(--user-text-boost))] font-semibold text-success-text"><x-bs-admin::icon name="ri-eye" class="size-3.5" />{{ __('admin.lng.always_pub') }}</span>
                        @elseif ($r['lock'])
                            <span class="text-[calc(0.75rem+var(--user-text-boost))] text-text-muted"><span aria-hidden="true">—</span><span class="sr-only">{{ $lockAria }}</span></span>
                        @else
                            <button type="button" class="switch hit-area" role="switch" aria-checked="{{ $r['pub'] ? 'true' : 'false' }}"
                                    wire:click="togglePublic('{{ $r['code'] }}')" title="{{ $pubTip }}" aria-label="{{ $pubTip }}"></button>
                        @endif
                    </span>

                    {{-- Mặc định --}}
                    <div class="flex flex-col items-center justify-center @max-[32.5rem]:min-h-11 @max-[32.5rem]:gap-1.5">
                        <span class="hidden text-[calc(0.75rem+var(--user-text-boost))] font-semibold uppercase tracking-[0.02em] text-text-muted @max-[32.5rem]:block" aria-hidden="true">{{ __('admin.lng.col_def') }}</span>
                        <button type="button" wire:click="chooseDefault('{{ $r['code'] }}')" title="{{ $starTip }}" aria-label="{{ $starTip }}"
                                @if ($r['def']) aria-disabled="true" @endif
                                @class([
                                    'inline-flex size-11 items-center justify-center rounded-lg text-[calc(1.0625rem+var(--user-text-boost))]',
                                    'cursor-default bg-warning-base/16 text-warning-text' => $r['def'],
                                    'text-text-muted hover:bg-surface-hover hover:text-text' => ! $r['def'],
                                ])>
                            <x-bs-admin::icon :name="$r['def'] ? 'ri-star-fill' : 'ri-star'" class="size-[min(1.5rem,calc(1.0625rem+var(--user-text-boost)))]" />
                        </button>
                    </div>
                </li>
            @endforeach
        </ul>

        <div class="flex items-center gap-2 px-4 py-3 text-[calc(0.75rem+var(--user-text-boost))] text-text-muted">
            <x-bs-admin::icon name="ri-information" class="shrink-0 size-[min(1.5rem,calc(0.875rem+var(--user-text-boost)))]" />{{ __('admin.lng.foot_off') }}
        </div>
    </div>

    {{-- Before turning a public language off, opening one that is off, or changing the default. --}}
    <x-bs-admin::confirm-dialog model="asking" :title="$askTitle" icon="ri-global" :ok="$askOk" ok-icon="ri-check" confirm="confirmAsk" cancel="cancelAsk">
        <x-bs-admin::confirm-line icon="ri-information">{{ $askText }}</x-bs-admin::confirm-line>
    </x-bs-admin::confirm-dialog>

    <x-bs-admin::toast :message="$toast" :tone="$toastTone" dismiss="dismissToast" :seq="$toastSeq" />
</x-bs-admin::settings-shell>
