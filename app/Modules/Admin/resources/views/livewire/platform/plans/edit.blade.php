@php
    use App\Modules\Admin\Livewire\Platform\Plans\Edit;
    use App\Modules\Admin\Support\Plans\PlanFeatureText;
    use App\Modules\Tenancy\Registry\FeatureRegistry;
    use Illuminate\Support\Js;

    $glyph = 'size-[1.15em] shrink-0';
    $card = 'min-w-0 rounded-md border border-border bg-surface shadow-card';
    $h3 = 'm-0 text-[calc(0.9375rem+var(--user-text-boost))] font-semibold leading-snug text-text-strong';
    $sub = 'm-0 text-caption text-text-muted [overflow-wrap:anywhere]';
    $label = 'text-body font-semibold text-text';
    $input = 'w-full min-w-0 rounded border border-border-input bg-surface px-3 text-text placeholder:text-placeholder aria-invalid:border-danger-text';
    $err = 'm-0 flex items-start gap-1.5 text-caption font-semibold text-danger-text';
    $isNew = $planKey === null;
    $statusPill = ['ok' => 'active', 'off' => 'suspended', 'new' => 'draft'];
    $statusText = ['ok' => __('admin.plans_status_ok'), 'off' => __('admin.plans_status_off'), 'new' => __('admin.plans_status_new')];
    // 'changed': the plan was changed elsewhere since this page read it; the list's own refusal says so
    $errorText = match ($error) { '' => '', 'changed' => __('admin.plans_toast_refused'), default => __('admin.plans_err_'.$error) };
    $title = $isNew ? __('admin.plans_add') : __('admin.plans_edit_title', ['name' => $base['name']]);
    $leave = __('admin.plans_leave_body', ['n' => $changes, 'name' => $isNew ? __('admin.plans_leave_new') : __('admin.plans_leave_plan', ['name' => $base['name']])]);
@endphp
<div class="flex flex-col gap-4">
    @if (! $canPlans)
        <div class="{{ $card }}">
            <div class="flex flex-col items-center gap-2 px-4 py-10 text-center text-body text-text-muted">
                <x-bs-admin::icon name="ri-lock" class="size-7 text-text-muted" />
                <h2 class="m-0 text-[calc(1rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('admin.plans_noperm_title') }}</h2>
                <p class="m-0 max-w-[60ch]">{{ __('admin.plans_noperm_text', ['role' => $group]) }}</p>
            </div>
        </div>
    @else
        <div>
            <x-bs-admin::button variant="ghost" color="secondary" size="sm" :href="route('platform.plans.index')">
                <x-bs-admin::icon name="ri-arrow-left" :class="$glyph" /><span>{{ __('admin.plans_back') }}</span>
            </x-bs-admin::button>
        </div>
        <header class="flex flex-col gap-1">
            <div class="flex flex-wrap items-center gap-2.5">
                <h1 class="m-0 text-[calc(1.25rem+var(--user-text-boost))] font-semibold leading-snug text-text-strong [overflow-wrap:anywhere]">{{ $title }}</h1>
                <x-bs-admin::status-pill :status="$statusPill[$status]">{{ $statusText[$status] }}</x-bs-admin::status-pill>
            </div>
            <p class="m-0 max-w-[72ch] text-body text-text-muted">
                @if ($isNew)
                    {{ __('admin.plans_edit_desc_new') }}
                @elseif ($customers > 0)
                    {{ __('admin.plans_edit_desc_customers', ['n' => PlanFeatureText::number($customers)]) }}
                @else
                    {{ __('admin.plans_edit_desc_none') }}
                @endif
            </p>
        </header>

        @if ($errorText !== '')
            <x-bs-admin::alert color="danger" icon="ri-error-warning" tabindex="-1">{{ $errorText }}</x-bs-admin::alert>
        @endif

        <div class="grid items-start gap-4 min-[1100px]:grid-cols-[minmax(0,1fr)_22rem]">
            <div class="flex min-w-0 flex-col gap-4">
                <section class="{{ $card }}" aria-labelledby="plan-info-h">
                    <div class="border-b border-border px-4 py-3"><h2 id="plan-info-h" class="{{ $h3 }}">{{ __('admin.plans_info_title') }}</h2></div>
                    <div class="flex flex-col gap-4 p-4">
                        <div class="flex flex-col gap-1.5">
                            <label for="plan-name" class="{{ $label }}">{{ __('admin.plans_name') }} <span class="text-danger-text" aria-hidden="true">*</span><span class="sr-only">({{ __('admin.plans_required') }})</span></label>
                            <input id="plan-name" type="text" class="field {{ $input }}" wire:model.blur="name" maxlength="60" placeholder="{{ __('admin.plans_name_ph') }}"
                                   aria-invalid="{{ $nameError ? 'true' : 'false' }}" @if ($nameError) aria-describedby="plan-name-err" @endif>
                            @if ($nameError)
                                <p id="plan-name-err" class="{{ $err }}"><x-bs-admin::icon name="ri-error-warning" :class="$glyph" />{{ __('admin.plans_name_err') }}</p>
                            @endif
                            <p class="{{ $sub }}">{{ __('admin.plans_name_hint') }}</p>
                        </div>
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div class="flex min-w-0 flex-[1_1_14rem] flex-col gap-0.5">
                                <b id="plan-sold-l" class="text-body text-text">{{ __('admin.plans_col_sold') }}</b>
                                <span class="{{ $sub }}">{{ $sold ? __('admin.plans_sold_hint_on') : __('admin.plans_sold_hint_off') }}</span>
                            </div>
                            <div class="flex items-center gap-2.5">
                                <span class="text-body text-text">{{ $statusText[$status] }}</span>
                                <button type="button" class="switch hit-area" role="switch" aria-checked="{{ $sold ? 'true' : 'false' }}" aria-labelledby="plan-sold-l" wire:click="toggleSold"></button>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="{{ $card }}" aria-labelledby="plan-feat-h">
                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 border-b border-border px-4 py-3">
                        <h2 id="plan-feat-h" class="{{ $h3 }}">{{ __('admin.plans_features_title') }}</h2>
                        <span class="{{ $sub }}">{{ __('admin.plans_features_sub') }}</span>
                    </div>
                    @foreach (FeatureRegistry::all() as $key => $feature)
                        @php
                            $fid = 'plan-f-'.str_replace(['.', '_'], '-', $key);
                            $field = Edit::field($key);
                            $value = $features[$field] ?? '';
                            $isLimit = PlanFeatureText::isLimit($key);
                            $wide = PlanFeatureText::wholeCustomer($key);
                            $default = FeatureRegistry::default($key);
                        @endphp
                        <div wire:key="{{ $fid }}" class="grid gap-3 border-b border-border px-4 py-3 last:border-b-0 min-[720px]:grid-cols-[minmax(0,1fr)_auto]">
                            <div class="min-w-0">
                                <div id="{{ $fid }}-l" class="flex flex-wrap items-center gap-2 font-semibold text-text-strong">
                                    {{ PlanFeatureText::name($key) }}
                                    <span class="inline-flex items-center gap-1 text-caption font-normal text-text-muted">
                                        <x-bs-admin::icon :name="$wide ? 'ri-building-2' : 'ri-window'" class="size-3.5" />{{ $wide ? __('admin.plans_scope_customer') : __('admin.plans_scope_site') }}
                                    </span>
                                </div>
                                <p class="{{ $sub }}">{{ PlanFeatureText::benefit($key) }}</p>
                            </div>
                            <div class="flex flex-col items-start gap-1.5 min-[720px]:items-end">
                                @if ($isLimit)
                                    <div class="flex items-center gap-2">
                                        <input type="text" inputmode="numeric" class="field {{ $input }} max-w-40" aria-labelledby="{{ $fid }}-l"
                                               wire:model.live.debounce.400ms="features.{{ $field }}" placeholder="{{ PlanFeatureText::number((int) $default) }}"
                                               aria-invalid="{{ isset($numErrors[$key]) ? 'true' : 'false' }}" @if (isset($numErrors[$key])) aria-describedby="{{ $fid }}-e" @endif>
                                        <span class="whitespace-nowrap text-body text-text-muted">{{ PlanFeatureText::unit($key) }}</span>
                                    </div>
                                @else
                                    <div class="inline-flex flex-wrap rounded border border-border-input" role="radiogroup" aria-labelledby="{{ $fid }}-l">
                                        @foreach (['' => __('admin.plans_default'), 'on' => __('admin.plans_yes'), 'off' => __('admin.plans_no')] as $choice => $choiceLabel)
                                            <button type="button" role="radio" aria-checked="{{ $value === $choice ? 'true' : 'false' }}"
                                                    class="hit-area min-h-control px-3 text-body text-text not-first:border-s not-first:border-border-input aria-checked:bg-primary-soft aria-checked:font-semibold aria-checked:text-primary-text any-pointer-coarse:min-h-hit"
                                                    wire:click="pick({{ Js::from($key) }}, {{ Js::from($choice) }})">{{ $choiceLabel }}</button>
                                        @endforeach
                                    </div>
                                @endif
                                <p class="m-0 flex flex-wrap items-center gap-2 text-caption text-text-muted">
                                    @if ($value === '')
                                        <x-bs-admin::badge :soft="true" color="info"><x-bs-admin::icon name="ri-arrow-go-back" class="size-3.5" />{{ __('admin.plans_default') }}</x-bs-admin::badge>
                                    @endif
                                    <span>{{ $isLimit ? __('admin.plans_default_limit', ['value' => PlanFeatureText::value($key, $default)]) : __('admin.plans_default_switch', ['value' => PlanFeatureText::value($key, $default)]) }}</span>
                                </p>
                            </div>
                            @if (isset($numErrors[$key]))
                                <p id="{{ $fid }}-e" class="{{ $err }} min-[720px]:col-span-2"><x-bs-admin::icon name="ri-error-warning" :class="$glyph" />{{ $numErrors[$key] }}</p>
                            @endif
                        </div>
                    @endforeach
                </section>
            </div>

            <div class="flex min-w-0 flex-col gap-4">
                <section class="{{ $card }}" aria-labelledby="plan-preview-h">
                    <div class="border-b border-border px-4 py-3"><h2 id="plan-preview-h" class="{{ $h3 }}">{{ __('admin.plans_preview_title') }}</h2></div>
                    <ul class="m-0 flex list-none flex-col gap-2.5 p-4">
                        @foreach ($effective as $key => $v)
                            @php
                                $on = is_bool($v) ? $v : $v > 0;
                                $isDefault = ($features[Edit::field($key)] ?? '') === '';
                                $note = trim((! $on ? ($invitation ? __('admin.plans_preview_locked_offer') : __('admin.plans_preview_locked')) : '').' '.($isDefault ? __('admin.plans_preview_default') : ''));
                            @endphp
                            <li class="flex items-start gap-2 text-body">
                                <x-bs-admin::icon :name="$on ? 'ri-checkbox-circle' : 'ri-close-circle'" @class(['mt-0.5 size-5 shrink-0', 'text-success-text' => $on, 'text-text-muted' => ! $on]) />
                                <span class="flex min-w-0 flex-col @if (! $on) text-text-muted @else text-text @endif">
                                    {{ PlanFeatureText::sees($key, $v) }}
                                    @if ($note !== '')<small class="text-caption text-text-muted">{{ $note }}</small>@endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </section>
                <section class="{{ $card }}" aria-labelledby="plan-price-h">
                    <div class="border-b border-border px-4 py-3"><h2 id="plan-price-h" class="{{ $h3 }}">{{ __('admin.plans_price_title') }}</h2></div>
                    <div class="flex flex-col gap-1.5 p-4">
                        <label for="plan-price" class="{{ $label }}">{{ __('admin.plans_price_label') }}</label>
                        <div class="flex items-center gap-2">
                            <input id="plan-price" type="text" class="field {{ $input }} cursor-not-allowed bg-surface-muted" value="{{ __('admin.plans_price_off') }}" disabled aria-describedby="plan-price-h2">
                            <span class="text-body text-text-muted">₫</span>
                        </div>
                        <p id="plan-price-h2" class="{{ $sub }}">{{ __('admin.plans_price_hint') }}</p>
                    </div>
                </section>
            </div>
        </div>

        <div class="sticky bottom-0 z-10 -mx-1 flex flex-wrap items-center justify-between gap-2.5 border-t border-border-strong bg-surface px-1 py-3" role="region" aria-label="{{ __('admin.plans_savebar') }}">
            <span class="text-caption">
                @if ($changes > 0)
                    <span class="inline-flex items-center gap-1.5 font-semibold text-warning-text"><x-bs-admin::icon name="ri-error-warning" :class="$glyph" />{{ __('admin.plans_changes', ['n' => $changes]) }}</span>
                @else
                    <span class="inline-flex items-center gap-1.5 text-text-muted"><x-bs-admin::icon name="ri-checkbox-circle" :class="$glyph" />{{ $isNew ? __('admin.plans_new_unsaved') : __('admin.plans_no_changes') }}</span>
                @endif
            </span>
            <div class="flex flex-wrap items-center gap-2.5">
                <x-bs-admin::button variant="outline" color="secondary" wire:click="resetChanges" wire:loading.attr="disabled" :disabled="$changes === 0">{{ __('admin.plans_discard') }}</x-bs-admin::button>
                <x-bs-admin::button color="primary" wire:click="save" wire:loading.attr="disabled" wire:target="save" :disabled="! $isNew && $changes === 0">
                    <x-bs-admin::icon name="ri-save-3" :class="$glyph" /><span>{{ $isNew ? __('admin.plans_add') : __('admin.plans_save') }}</span>
                </x-bs-admin::button>
            </div>
        </div>

        <x-bs-admin::unsaved-dialog :count="$changes" :label="$title" save="saveQuietly" discard="resetChanges" :body="$leave"
            :save-label="$isNew ? __('admin.plans_leave_save_new') : __('admin.plans_leave_save')" />
    @endif
</div>
