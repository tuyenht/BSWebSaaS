@php
    use App\Modules\Admin\Support\Customers\PlanChangePreview;
    use App\Modules\Tenancy\Registry\FeatureRegistry;
    use Carbon\CarbonImmutable;
    use Illuminate\Support\Js;
    use Illuminate\Support\Number;

    $num = fn (int $n): string => Number::format($n, locale: app()->getLocale());
    $joined = $siteTime->forDisplay($tenant->created_at)?->locale(app()->getLocale())->isoFormat('L') ?? '—';
    $planLabel = $plan === null ? __('admin.customers_no_plan') : $plan->name;
    $siteStatus = fn (string $s): string => in_array($s, ['active', 'suspended'], true) ? __('admin.customers_status_'.$s) : __('admin.customers_site_status_'.$s);
    // every website counts toward sites.max, as ProvisionSite counts them
    $liveCount = $sites->count();
    $sitesMax = (int) ($values['sites.max'] ?? 0);
    $card = 'min-w-0 rounded-md border border-border bg-surface shadow-card';
    $cardHead = 'flex flex-wrap items-center gap-2 border-b border-border px-4 py-3';
    $h2 = 'm-0 flex-auto text-[calc(1rem+var(--user-text-boost))] font-semibold text-text-strong';
    $why = 'm-0 flex items-start gap-1.5 text-small text-text-muted';
    $noPlan = __('admin.customers_no_plan');
    $historyText = function (array $h) use ($noPlan): string {
        $what = $h['kind'] === 'plan_changed'
            ? __('admin.customers_history_plan_changed', ['from' => $h['from'] ?? $noPlan, 'to' => $h['to'] ?? $noPlan])
            : __('admin.customers_history_other');

        return $h['reason'] === null ? $what : __('admin.customers_history_with_reason', ['what' => $what, 'reason' => $h['reason']]);
    };
    $historyAt = fn (array $h): string => $siteTime->forDisplay(CarbonImmutable::parse($h['at']))?->locale(app()->getLocale())->isoFormat('L LT') ?? '—';
@endphp
<div class="@container/kh flex flex-col gap-4">
    <div>
        <a href="{{ route('platform.tenants.index') }}" wire:navigate
           class="inline-flex min-h-hit items-center gap-1 rounded pe-2 text-body font-medium text-primary-text hover:underline">
            <x-bs-admin::icon name="ri-arrow-left-s" class="size-5" />{{ __('admin.customers_back') }}
        </a>
    </div>

    @if ($roleStrip !== null)
        <p role="note" class="m-0 flex items-start gap-2 rounded border border-border bg-surface-muted px-3.5 py-2.5 text-body text-text [overflow-wrap:anywhere]">
            <x-bs-admin::icon name="ri-shield-user" class="mt-0.5 size-5 shrink-0 text-text-muted" />
            <span>{{ $roleStrip }}</span>
        </p>
    @endif

    <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-3">
        <div class="min-w-0 flex-[1_1_20rem]">
            <h1 class="m-0 text-[calc(1.25rem+var(--user-text-boost))] font-semibold leading-snug text-text-strong [overflow-wrap:anywhere]">{{ $tenant->name }}</h1>
            <div class="mt-1.5 flex flex-wrap gap-1.5">
                <x-bs-admin::status-pill :status="$tenant->status">{{ __('admin.customers_status_'.$tenant->status) }}</x-bs-admin::status-pill>
                <span class="inline-flex min-h-7 items-center gap-1.5 rounded-full bg-primary/7 px-2 py-0.5 text-small font-semibold text-primary-text">
                    <x-bs-admin::icon name="ri-price-tag-3" class="size-[calc(0.875rem+var(--user-text-boost))] shrink-0" />
                    {{ $plan === null ? __('admin.customers_no_plan') : __('admin.customers_plan_tag', ['plan' => $plan->name]) }}
                </span>
            </div>
            @if ($suspensionReason !== '')
                <p class="{{ $why }} mt-1.5">
                    <x-bs-admin::icon name="ri-pause-circle" class="mt-0.5 size-4 shrink-0" />
                    <span class="[overflow-wrap:anywhere]">{{ __('admin.customers_suspended_why', ['why' => $suspensionReason]) }}</span>
                </p>
            @endif
        </div>
        <div class="flex shrink-0 flex-wrap gap-2">
            @if ($canChangePlan)
                <x-bs-admin::button variant="outline" color="primary" x-on:click="$dispatch('open-change-plan', { ulid: {{ Js::from($tenant->ulid) }} })">
                    <x-bs-admin::icon name="ri-exchange" class="size-4" />{{ __('admin.customers_dlg_submit') }}
                </x-bs-admin::button>
            @else
                <x-bs-admin::button variant="outline" color="secondary" aria-disabled="true" aria-describedby="kh-plan-why" class="cursor-not-allowed opacity-70">
                    <x-bs-admin::icon name="ri-lock" class="size-4" />{{ __('admin.customers_dlg_submit') }}
                </x-bs-admin::button>
            @endif
        </div>
    </div>
    @if (! $canChangePlan)
        <p id="kh-plan-why" class="{{ $why }} -mt-2">
            <x-bs-admin::icon name="ri-lock" class="mt-0.5 size-4 shrink-0" /><span>{{ $lockedReason }}</span>
        </p>
    @endif

    <div class="grid grid-cols-1 gap-4 @min-[56.25rem]/kh:grid-cols-2">
        <div class="flex min-w-0 flex-col gap-4">
            <section class="{{ $card }}" aria-labelledby="kh-s1">
                <div class="{{ $cardHead }}"><h2 id="kh-s1" class="{{ $h2 }}">{{ __('admin.customers_info_title') }}</h2></div>
                <dl class="m-0 grid grid-cols-1 gap-x-4 gap-y-1 p-4 text-body @min-[30rem]/kh:grid-cols-[minmax(0,9.375rem)_minmax(0,1fr)] @min-[30rem]/kh:gap-y-2">
                    <dt class="text-text-muted">{{ __('admin.customers_info_owner') }}</dt>
                    <dd class="m-0 mb-2 text-text-strong [overflow-wrap:anywhere] @min-[30rem]/kh:mb-0">{{ $owner?->name ?: __('admin.customers_none') }}</dd>
                    <dt class="text-text-muted">{{ __('admin.customers_info_email') }}</dt>
                    <dd class="m-0 mb-2 [overflow-wrap:anywhere] @min-[30rem]/kh:mb-0">
                        @if ($owner?->email)
                            <a href="mailto:{{ $owner->email }}" class="inline-flex min-h-hit items-center text-primary-text underline">{{ $owner->email }}</a>
                        @else
                            <span class="text-text-strong">{{ __('admin.customers_none') }}</span>
                        @endif
                    </dd>
                    <dt class="text-text-muted">{{ __('admin.customers_info_joined') }}</dt>
                    <dd class="m-0 mb-2 text-text-strong @min-[30rem]/kh:mb-0">{{ $joined }}</dd>
                    <dt class="text-text-muted">{{ __('admin.customers_info_code') }}</dt>
                    <dd class="m-0 text-text-strong [overflow-wrap:anywhere]">{{ $tenant->customer_code }}</dd>
                </dl>
            </section>

            <section class="{{ $card }}" aria-labelledby="kh-s2">
                <div class="{{ $cardHead }}"><h2 id="kh-s2" class="{{ $h2 }}">{{ __('admin.customers_plan_card_title') }}</h2><span class="text-small text-text-muted">{{ $planLabel }}</span></div>
                <div class="flex flex-col gap-3 p-4">
                    @if ($plan !== null && ! $plan->is_active)
                        <x-bs-admin::alert color="warning" icon="ri-error-warning">{{ __('admin.customers_plan_legacy_note', ['plan' => $plan->name]) }}</x-bs-admin::alert>
                    @endif
                    <div>
                        @foreach (FeatureRegistry::all() as $key => $feature)
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-border py-1.5 text-body last:border-b-0">
                                <span class="min-w-0 flex-[1_1_12.5rem] text-text-strong">{{ $feature['name'] }}</span>
                                <span class="shrink-0 whitespace-nowrap text-text">{{ PlanChangePreview::format($key, $values[$key] ?? $feature['default']) }}</span>
                            </div>
                        @endforeach
                    </div>
                    <p class="{{ $why }}"><x-bs-admin::icon name="ri-information" class="mt-0.5 size-4 shrink-0" /><span>{{ __('admin.customers_plan_scope_note') }}</span></p>
                </div>
            </section>
        </div>

        <div class="flex min-w-0 flex-col gap-4">
            <section class="{{ $card }}" aria-labelledby="kh-s3">
                <div class="{{ $cardHead }}">
                    <h2 id="kh-s3" class="{{ $h2 }}">{{ __('admin.customers_sites_title') }}</h2>
                    <span class="text-small text-text-muted">{{ __('admin.customers_sites_usage', ['used' => $num($liveCount), 'max' => $num($sitesMax)]) }}</span>
                </div>
                <div class="px-4 py-1">
                    @forelse ($sites as $site)
                        @php $primary = $site->domains->firstWhere('is_primary', true) ?? $site->domains->first(); @endphp
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-border py-2.5 last:border-b-0" wire:key="kh-site-{{ $site->ulid }}">
                            <div class="min-w-0 flex-[1_1_10rem]">
                                <div class="font-semibold text-text-strong [overflow-wrap:anywhere]">{{ $site->name }}</div>
                                @if ($primary !== null)
                                    <div class="text-small text-text-muted [overflow-wrap:anywhere]">{{ $primary->hostname }}</div>
                                @endif
                            </div>
                            <x-bs-admin::status-pill :status="$site->status">{{ $siteStatus($site->status) }}</x-bs-admin::status-pill>
                            @if ($canOpenSites)
                                <x-bs-admin::button size="sm" variant="soft" color="primary" :href="route('platform.sites.show', $site->ulid)" :aria-label="__('admin.view').' '.$site->name">
                                    {{ __('admin.view') }}
                                </x-bs-admin::button>
                            @endif
                        </div>
                    @empty
                        <p class="my-3 text-body text-text">{{ __('admin.customers_sites_empty') }}</p>
                    @endforelse
                    @if (! $canOpenSites && $sites->isNotEmpty())
                        <p class="{{ $why }} my-2"><x-bs-admin::icon name="ri-lock" class="mt-0.5 size-4 shrink-0" /><span>{{ __('admin.customers_sites_locked') }}</span></p>
                    @endif
                </div>
            </section>

            {{-- newest first; "older" adds the next rows below the oldest one shown --}}
            <section class="{{ $card }}" aria-labelledby="kh-s4">
                <div class="{{ $cardHead }}"><h2 id="kh-s4" class="{{ $h2 }}">{{ __('admin.customers_history_title') }}</h2></div>
                <div class="flex flex-col gap-2 px-4 py-2">
                    @if ($history === [])
                        <p class="my-2 text-body text-text">{{ __('admin.customers_history_empty') }}</p>
                    @else
                        <ul class="m-0 list-none p-0">
                            @foreach ($history as $h)
                                <li class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-2.5 gap-y-0.5 border-b border-border py-2 text-body last:border-b-0" wire:key="kh-h-{{ $loop->index }}">
                                    <x-bs-admin::icon name="ri-history" class="row-span-2 mt-0.5 size-[calc(1rem+var(--user-text-boost))] shrink-0 text-text-muted" />
                                    <span class="text-text-strong [overflow-wrap:anywhere]">{{ $historyText($h) }}</span>
                                    <span class="text-small text-text-muted [overflow-wrap:anywhere]">{{ $historyAt($h) }} · {{ $h['actor'] ?? __('admin.customers_history_system') }}</span>
                                </li>
                            @endforeach
                        </ul>
                        @if ($historyOlder !== null)
                            <div class="pb-2">
                                <x-bs-admin::button size="sm" variant="outline" color="secondary" wire:click="olderHistory({{ Js::from($historyOlder) }})"
                                    wire:loading.attr="disabled" wire:target="olderHistory">
                                    <x-bs-admin::icon name="ri-arrow-down-s" class="size-4" wire:loading.remove wire:target="olderHistory" />
                                    <x-bs-admin::icon name="ri-loader-4" class="size-4 animate-spin motion-reduce:animate-none" wire:loading wire:target="olderHistory" />
                                    {{ __('admin.customers_history_older') }}
                                </x-bs-admin::button>
                            </div>
                        @endif
                    @endif
                </div>
            </section>
        </div>
    </div>

    <livewire:admin.platform.tenants.change-plan-dialog />
</div>
