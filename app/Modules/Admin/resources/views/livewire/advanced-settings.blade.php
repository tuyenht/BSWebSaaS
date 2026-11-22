@php
    use App\Modules\Storefront\Support\SiteMaintenance;

    // "Bảo mật & Nâng cao" (board 31): the «Chế độ bảo trì» card. The chip and the line under the
    // card's title follow what is SAVED (what visitors see now), the switch follows the draft. A
    // member who may only read gets the «Chỉ xem» line (phones) or note (wider), a switch that does
    // not move, fields they cannot type in and no save bar.
    $glyph = 'size-[min(1.5rem,calc(1rem+var(--user-text-boost)))] shrink-0';
    $small = 'size-[min(1.5rem,calc(0.75rem+var(--user-text-boost)))] shrink-0';
    $chip = 'inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-caption font-semibold';
    $multi = count($locales) > 1;
    $whenBad = $errors->has('reopen');
    $fieldLook = 'w-full min-w-0 rounded-md border bg-surface px-2.5 text-text placeholder:text-placeholder';
@endphp
<x-bs-admin::settings-shell active="advanced" :unsaved="$changes" :invalid="count($errors->keys())" live-errors first-error="adv-first-error">
    <x-slot:aside>
        {{-- «Hướng dẫn»: the screen's questions and answers in a dialog (board 31's help). --}}
        <div x-data="{ help: false }">
            <button type="button" x-on:click="help = true" aria-haspopup="dialog" x-bind:aria-expanded="help ? 'true' : 'false'" data-adv-help
                    aria-label="{{ __('guide.ui.open_aria') }}" title="{{ __('guide.ui.open_aria') }}"
                    class="hit-area relative inline-flex min-h-hit min-w-hit items-center justify-center gap-1.5 rounded-md px-3 align-middle text-body font-semibold whitespace-nowrap text-primary-text transition-colors hover:bg-surface-hover max-md:px-0">
                <span class="inline-flex size-7 shrink-0 items-center justify-center rounded-full border-[1.5px] border-border-input bg-surface" aria-hidden="true"><x-bs-admin::icon name="ri-question-mark" class="size-4" /></span><span class="max-md:hidden">{{ __('guide.ui.open') }}</span>
            </button>
            <x-bs-admin::modal state="help" close="help = false" :title="__('admin.adv.help.title')" :close-label="__('admin.adv.help.close_aria')">
                <dl class="m-0 flex flex-col gap-3">
                    @foreach ([1, 2] as $i)
                        <div>
                            <dt class="font-semibold text-text-strong">{{ __('admin.adv.help.q'.$i) }}</dt>
                            <dd class="m-0 mt-0.5 text-text">{{ __('admin.adv.help.a'.$i) }}</dd>
                        </div>
                    @endforeach
                </dl>
                <x-slot:footer>
                    <x-bs-admin::button variant="outline" color="secondary" x-on:click="help = false">{{ __('admin.close') }}</x-bs-admin::button>
                </x-slot:footer>
            </x-bs-admin::modal>
        </div>
    </x-slot:aside>

    <div class="flex flex-col gap-4" data-adv
         x-data x-on:adv-first-error.window="$nextTick(() => $el.querySelector('[aria-invalid=true]')?.focus())">

        @if ($readOnly)
            <p class="m-0 text-[calc(0.8125rem+var(--user-text-boost))] text-text md:hidden" data-adv-ro-line>
                <span class="{{ $chip }} me-1 bg-info-soft text-info-text"><x-bs-admin::icon name="ri-eye" class="{{ $small }}" />{{ __('admin.adv.ro_chip') }}</span>
                {{ __('admin.adv.ro_line') }}
            </p>
            <div class="max-md:hidden">
                <x-bs-admin::alert color="info" icon="ri-eye" role="status">{{ $role !== null ? __('admin.adv.ro_alert', ['role' => $role]) : __('admin.adv.ro_alert_anon') }}</x-bs-admin::alert>
            </div>
        @endif

        @if ($stamp !== null)
            <p class="m-0 text-caption text-text-muted" data-adv-stamp>{{ $stamp }}</p>
        @endif

        <section class="@container/adv flex min-w-0 flex-col gap-4 rounded-md border border-border bg-surface p-[1.375rem] shadow-card max-md:rounded-none max-md:border-0 max-md:bg-transparent max-md:px-0 max-md:pt-2 max-md:pb-5 max-md:shadow-none"
                 aria-labelledby="adv-mt-h" id="adv-mt">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                <h2 id="adv-mt-h" class="min-w-0 flex-[1_1_12em] text-[min(1.375rem,calc(1rem+var(--user-text-boost)))] leading-[1.4] font-semibold break-words text-text-strong">{{ __('admin.adv.mt.title') }}</h2>
                @if ($savedOn)
                    <span class="{{ $chip }} bg-warning-soft text-warning-text" data-adv-chip="on"><x-bs-admin::icon name="ri-tools" class="{{ $small }}" />{{ __('admin.adv.mt.chip_on') }}</span>
                @else
                    <span class="{{ $chip }} bg-surface-muted text-text-muted" data-adv-chip="off"><x-bs-admin::icon name="ri-checkbox-blank-circle" class="{{ $small }}" />{{ __('admin.adv.mt.chip_off') }}</span>
                @endif
                <span class="inline-flex min-h-hit shrink-0 items-center">
                    <button type="button" class="switch hit-area" role="switch" aria-checked="{{ $maintenanceOn ? 'true' : 'false' }}" aria-label="{{ __('admin.adv.mt.switch') }}"
                            @if ($readOnly) disabled aria-disabled="true" @else wire:click="toggleMaintenance" @endif></button>
                </span>
            </div>

            <p class="m-0 max-w-[46rem] text-text [text-wrap:pretty]">{{ $savedOn ? __('admin.adv.mt.lead_on') : __('admin.adv.mt.lead_off') }} <b class="font-semibold text-text-strong">{{ __('admin.adv.mt.lead_members') }}</b></p>

            <div class="grid grid-cols-1 gap-4 @min-[36rem]/adv:grid-cols-2">
                @foreach ($locales as $lc)
                    @php
                        $fid = 'adv-msg-'.$lc;
                        $bag = 'messages.'.$lc;
                        $bad = $errors->has($bag);
                        $isDefault = $lc === $defaultLocale;
                        $name = $names[$lc];
                        $ph = $readOnly ? '' : ($isDefault ? __('admin.adv.mt.msg_ph') : __('admin.adv.mt.msg_ph_other'));
                    @endphp
                    <div class="flex min-w-0 flex-col" wire:key="adv-msg-{{ $lc }}">
                        <label for="{{ $fid }}" class="mb-1.5 block text-[calc(0.8125rem+var(--user-text-boost))] leading-[1.45] font-semibold text-text-strong">{{ $multi ? __('admin.adv.mt.msg_label', ['name' => $name]) : __('admin.adv.mt.msg_label_one') }}</label>
                        <textarea id="{{ $fid }}" rows="3" lang="{{ $lc }}" wire:model.live.blur="{{ $bag }}" placeholder="{{ $ph }}" maxlength="{{ SiteMaintenance::MAX_MESSAGE }}"
                                  @if ($readOnly) readonly @endif aria-invalid="{{ $bad ? 'true' : 'false' }}" aria-describedby="{{ $bad ? $fid.'-err ' : '' }}{{ $fid }}-h"
                                  class="field {{ $fieldLook }} min-h-[5.5rem] resize-y py-2 {{ $bad ? 'border-danger-text' : 'border-border-input' }}"></textarea>
                        @if ($bad)
                            <x-bs-admin::field-error id="{{ $fid }}-err" class="mt-1.5">{{ $errors->first($bag) }}</x-bs-admin::field-error>
                        @endif
                        <p id="{{ $fid }}-h" class="m-0 mt-1.5 max-w-[46rem] text-caption leading-relaxed text-text-muted">{{ $isDefault ? __('admin.adv.mt.msg_hint') : __('admin.adv.mt.msg_hint_other', ['name' => $name]) }}</p>
                    </div>
                @endforeach
            </div>

            <div class="flex min-w-0 flex-col" role="group" aria-labelledby="adv-when-l">
                <span id="adv-when-l" class="mb-1.5 block text-[calc(0.8125rem+var(--user-text-boost))] leading-[1.45] font-semibold text-text-strong">{{ __('admin.adv.mt.when_label') }} <span class="font-normal text-text-muted">{{ __('admin.adv.mt.when_optional') }}</span></span>
                <div class="flex flex-wrap gap-2">
                    <input id="adv-when-d" type="date" wire:model.live.blur="reopenDate" aria-label="{{ __('admin.adv.mt.when_date') }}" @if ($readOnly) readonly @endif
                           aria-invalid="{{ $whenBad ? 'true' : 'false' }}" aria-describedby="{{ $whenBad ? 'adv-when-err ' : '' }}adv-when-h"
                           class="field {{ $fieldLook }} max-w-56 flex-[1_1_10rem] {{ $whenBad ? 'border-danger-text' : 'border-border-input' }}">
                    <input id="adv-when-t" type="time" wire:model.live.blur="reopenTime" aria-label="{{ __('admin.adv.mt.when_time') }}" @if ($readOnly) readonly @endif
                           aria-invalid="{{ $whenBad ? 'true' : 'false' }}" aria-describedby="{{ $whenBad ? 'adv-when-err ' : '' }}adv-when-h"
                           class="field {{ $fieldLook }} max-w-56 flex-[1_1_10rem] {{ $whenBad ? 'border-danger-text' : 'border-border-input' }}">
                </div>
                @if ($whenBad)
                    <x-bs-admin::field-error id="adv-when-err" class="mt-1.5 max-w-[46rem]">{{ $errors->first('reopen') }}</x-bs-admin::field-error>
                @endif
                <p id="adv-when-h" class="m-0 mt-1.5 max-w-[46rem] text-caption leading-relaxed text-text-muted">{{ $whenText !== null && ! $whenBad ? __('admin.adv.mt.when_hint_set', ['time' => $whenText]) : __('admin.adv.mt.when_hint') }}</p>
            </div>

            <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                <x-bs-admin::button variant="outline" color="secondary" :href="route('admin.settings.advanced.preview')" :navigate="false" target="_blank" rel="noopener">
                    <x-bs-admin::icon name="ri-external-link" class="{{ $glyph }}" />{{ __('admin.adv.mt.preview') }}
                </x-bs-admin::button>
            </div>
        </section>
    </div>

    <x-bs-admin::toast :message="$toast" :tone="$toastTone" dismiss="dismissToast" :seq="$toastSeq" />
</x-bs-admin::settings-shell>
