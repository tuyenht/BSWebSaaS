@php
    use App\Modules\Admin\Livewire\Platform\Tenants\ChangePlanDialog;

    $num = fn (int $n): string => \Illuminate\Support\Number::format($n, locale: app()->getLocale());
    $curName = $current?->name ?? __('admin.customers_no_plan');
    $changed = array_values(array_filter($diff, fn (array $r): bool => $r['change'] !== 'same'));
    $sameCount = count($diff) - count($changed);
    $options = $plans->map(fn ($p): array => [
        'value' => $p->key,
        'label' => $p->name,
        'tag' => $current !== null && $p->id === $current->id ? __('admin.customers_dlg_current_tag') : null,
        'checked' => $chosen !== null && $p->id === $chosen->id,
        'disabled' => $current !== null && $p->id === $current->id,
    ])->values()->all();
    $planMissing = $tried && $chosen === null;
    $reasonMissing = $tried && trim($reason) === '';
@endphp
{{-- One dialog for both customer pages. A page opens it with $dispatch('open-change-plan', { ulid }). --}}
<div x-on:open-change-plan.window="$wire.openFor($event.detail.ulid)"
     x-data="{ dirty() { return this.$wire.to !== '' || (this.$wire.reason ?? '').trim() !== ''; } }"
     x-on:beforeunload.window="if ($wire.open && dirty()) { $event.preventDefault(); $event.returnValue = ''; }">
    <x-bs-admin::modal model="open" size="wide"
        :title="$tenant ? __('admin.customers_dlg_title', ['name' => $tenant->name]) : __('admin.customers_dlg_submit')"
        :subtitle="$tenant ? __('admin.customers_dlg_sub', ['cur' => $curName]) : null"
        :close-label="__('admin.customers_dlg_close_aria')">
        @if ($tenant !== null)
            @if ($failure !== '')
                <div role="alert" class="flex items-start gap-2 rounded bg-danger-soft px-3.5 py-3 text-body text-danger-text">
                    <x-bs-admin::icon name="ri-close-circle" class="mt-0.5 size-5 shrink-0" />
                    <span>{{ __($failure === 'not_sold' ? 'admin.customers_dlg_not_sold' : 'admin.customers_dlg_failed') }}</span>
                </div>
            @endif

            <div>
                <div id="kh-dlg-plans" class="mb-1.5 text-body font-medium text-text-strong">
                    {{ __('admin.customers_dlg_new_plan') }} <span class="text-danger-text" aria-hidden="true">*</span><span class="sr-only">{{ __('admin.customers_dlg_required') }}</span>
                </div>
                {{-- opening focuses the first plan that can be chosen (the modal moves focus in first) --}}
                <x-bs-admin::choice-cards :options="$options" pick="choose" labelledby="kh-dlg-plans"
                    :aria-describedby="$planMissing ? 'kh-dlg-plan-err' : null"
                    x-effect="open && setTimeout(() => (document.activeElement?.closest('[role=radiogroup]') ? null : $el.querySelector('[role=radio]:not([aria-disabled=true])')?.focus()), 60)" />
                @if ($planMissing)
                    <p id="kh-dlg-plan-err" class="m-0 mt-1.5 flex items-start gap-1.5 text-small text-danger-text">
                        <x-bs-admin::icon name="ri-error-warning" class="mt-0.5 size-4 shrink-0" />{{ __('admin.customers_dlg_plan_required') }}
                    </p>
                @endif
            </div>

            @if ($chosen !== null)
                <div>
                    <p class="m-0 mb-1.5 text-small font-semibold text-text-muted">{{ __('admin.customers_dlg_compare', ['cur' => $curName]) }}</p>
                    <x-bs-admin::compare-list :rows="$changed" :same="$sameCount > 0 ? __('admin.customers_dlg_same', ['n' => $num($sameCount)]) : null" />
                </div>
                <div class="flex items-start gap-2 rounded bg-info-soft px-3.5 py-3 text-body text-info-text">
                    <x-bs-admin::icon name="ri-window" class="mt-0.5 size-5 shrink-0" />
                    <span class="[overflow-wrap:anywhere]">{{ __('admin.customers_dlg_apply', ['n' => $num($liveSites->count()), 'list' => $liveSites->isEmpty() ? '' : ': '.$liveSites->pluck('name')->implode(' · ')]) }}</span>
                </div>
                @if ($overages !== [])
                    <div role="note" class="flex items-start gap-2 rounded bg-warning-soft px-3.5 py-3 text-body text-warning-text">
                        <x-bs-admin::icon name="ri-error-warning" class="mt-0.5 size-5 shrink-0" />
                        <div class="min-w-0">
                            <b>{{ __('admin.customers_dlg_over_title') }}</b>
                            <ul class="m-0 mt-1 ps-4.5">
                                @foreach ($overages as $line)
                                    <li class="[overflow-wrap:anywhere]">{{ $line }}</li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                @endif
            @endif

            {{-- The error shows once the field is left empty, or after a save was tried. --}}
            <div x-data="{ left: false, empty: {{ trim($reason) === '' ? 'true' : 'false' }} }">
                <label for="kh-reason" class="mb-1.5 block text-body font-medium text-text-strong">
                    {{ __('admin.customers_dlg_reason') }} <span class="text-danger-text" aria-hidden="true">*</span><span class="sr-only">{{ __('admin.customers_dlg_required') }}</span>
                </label>
                <textarea id="kh-reason" wire:model="reason" maxlength="{{ ChangePlanDialog::REASON_MAX }}" rows="3"
                    placeholder="{{ __('admin.customers_dlg_reason_placeholder') }}" aria-describedby="kh-reason-h"
                    x-on:blur="left = true" x-on:input="empty = $el.value.trim() === ''"
                    :aria-invalid="({{ $reasonMissing ? 'true' : 'false' }} || left) && empty ? 'true' : 'false'"
                    class="field block min-h-22 w-full resize-y rounded border border-border-input bg-surface px-3 py-2 text-body text-text placeholder:text-placeholder transition-colors focus:border-primary-text aria-invalid:border-danger-text"></textarea>
                <div id="kh-reason-h" class="mt-1.5 text-small">
                    <p x-show="({{ $reasonMissing ? 'true' : 'false' }} || left) && empty" @unless ($reasonMissing) x-cloak @endunless class="m-0 flex items-start gap-1.5 text-danger-text">
                        <x-bs-admin::icon name="ri-error-warning" class="mt-0.5 size-4 shrink-0" />{{ __('admin.customers_dlg_reason_error') }}
                    </p>
                    <p x-show="! (({{ $reasonMissing ? 'true' : 'false' }} || left) && empty)" @if ($reasonMissing) x-cloak @endif class="m-0 text-text-muted">{{ __('admin.customers_dlg_reason_hint') }}</p>
                </div>
            </div>
        @endif

        <x-slot:footer>
            <x-bs-admin::button variant="outline" color="secondary" x-on:click="hide()">{{ __('admin.cancel') }}</x-bs-admin::button>
            <x-bs-admin::button color="primary" wire:click="save" wire:loading.attr="disabled" wire:target="save">
                <x-bs-admin::icon name="ri-exchange" class="size-4" wire:loading.remove wire:target="save" />
                <x-bs-admin::icon name="ri-loader-4" class="size-4 animate-spin motion-reduce:animate-none" wire:loading wire:target="save" />
                <span wire:loading.remove wire:target="save">{{ $chosen ? __('admin.customers_dlg_submit_to', ['plan' => $chosen->name]) : __('admin.customers_dlg_submit') }}</span>
                <span wire:loading wire:target="save">{{ __('admin.customers_dlg_saving') }}</span>
            </x-bs-admin::button>
        </x-slot:footer>
    </x-bs-admin::modal>

    <x-bs-admin::toast :message="$toast" dismiss="dismissToast" />
</div>
