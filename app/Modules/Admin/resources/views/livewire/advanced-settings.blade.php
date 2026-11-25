@php
    use App\Modules\Admin\Support\AdminAddressLimit;
    use App\Modules\Storefront\Support\SiteEmbedCode;
    use App\Modules\Storefront\Support\SiteMaintenance;

    // "Bảo mật & Nâng cao" (board 31): the «Chế độ bảo trì» card. The chip and the line under the
    // card's title follow what is SAVED (what visitors see now), the switch follows the draft. A
    // member who may only read gets the «Chỉ xem» line (phones) or note (wider), a switch that does
    // not move, fields they cannot type in and no save bar.
    //
    // The «Mã nhúng thêm» card: the chip follows the saved code (running = on with some code), the
    // four boxes show while the drafted switch is on (or a box is in error). The first save that turns
    // code on opens the «Bật mã nhúng thêm cho website?» dialog, whose «Bật và lưu» stays unavailable
    // until «Tôi hiểu…» is ticked; the server asks for that confirmation (save(true)) itself.
    //
    // The «Giới hạn nơi được vào trang quản trị» card: the chip follows the saved limit, the rows show
    // while the drafted switch is on. A row's fault shows once it was left, the empty list and the
    // lock-out band once a save was tried (the server decides both, against the request's own address).
    $glyph = 'size-[min(1.5rem,calc(1rem+var(--user-text-boost)))] shrink-0';
    $small = 'size-[min(1.5rem,calc(0.75rem+var(--user-text-boost)))] shrink-0';
    $chip = 'inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-caption font-semibold';
    $multi = count($locales) > 1;
    $whenBad = $errors->has('reopen');
    $fieldLook = 'w-full min-w-0 rounded-md border bg-surface px-2.5 text-text placeholder:text-placeholder';
@endphp
<x-bs-admin::settings-shell active="advanced" card="none" :unsaved="$changes" :invalid="count($errors->keys())" live-errors first-error="adv-first-error">
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
                    @foreach ([1, 2, 3, 4] as $i)
                        <div>
                            <dt class="font-semibold text-text-strong">{{ __('admin.adv.help.q'.$i) }}</dt>
                            <dd class="m-0 mt-0.5 text-text">{{ __('admin.adv.help.a'.$i, ['platform' => $platform]) }}</dd>
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
         x-data x-on:adv-first-error.window="$nextTick(() => $el.querySelector('[aria-invalid=true], [data-adv-err]')?.focus())">

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

        <section class="@container/adv flex min-w-0 flex-col gap-4 rounded-md border border-border bg-surface p-[1.375rem] shadow-card max-md:rounded-none max-md:border-0 max-md:border-t max-md:border-border max-md:bg-transparent max-md:px-0 max-md:pt-5 max-md:pb-5 max-md:shadow-none"
                 aria-labelledby="adv-ip-h" id="adv-ip" data-adv-ip
                 x-on:adv-ip-focus.window="$nextTick(() => document.getElementById('adv-ip-' + $event.detail.id)?.focus())">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                <h2 id="adv-ip-h" class="min-w-0 flex-[1_1_12em] text-[min(1.375rem,calc(1rem+var(--user-text-boost)))] leading-[1.4] font-semibold break-words text-text-strong">{{ __('admin.adv.ip.title') }}</h2>
                @if ($ipSavedOn)
                    <span class="{{ $chip }} bg-success-soft text-success-text" data-adv-ip-chip="on"><x-bs-admin::icon name="ri-checkbox-circle" class="{{ $small }}" />{{ __('admin.adv.ip.chip_on') }}</span>
                @else
                    <span class="{{ $chip }} bg-surface-muted text-text-muted" data-adv-ip-chip="off"><x-bs-admin::icon name="ri-checkbox-blank-circle" class="{{ $small }}" />{{ __('admin.adv.ip.chip_off') }}</span>
                @endif
                <span class="inline-flex min-h-hit shrink-0 items-center">
                    <button type="button" class="switch hit-area" role="switch" aria-checked="{{ $ipOn ? 'true' : 'false' }}" aria-label="{{ __('admin.adv.ip.switch') }}"
                            @if ($readOnly) disabled aria-disabled="true" @else wire:click="toggleIp" @endif></button>
                </span>
            </div>

            <p class="m-0 max-w-[46rem] text-text [text-wrap:pretty]">{{ __('admin.adv.ip.lead') }}</p>

            <div class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded border border-border bg-surface-muted px-4 py-3" data-adv-ip-me>
                <span class="min-w-0 flex-[1_1_14em] text-text">{{ __('admin.adv.ip.me') }} <b class="font-semibold break-all text-text-strong tabular-nums">{{ $myIp ?? '—' }}</b></span>
                @if ($inSupport)
                    <span class="min-w-0 flex-[1_1_100%] text-caption text-text-muted" data-adv-ip-support>{{ __('admin.adv.ip.me_support') }}</span>
                @elseif (! $readOnly && $ipOn && ! $meIn && $myIp !== null)
                    <x-bs-admin::button variant="outline" color="secondary" size="sm" wire:click="addMyIp" data-adv-ip-add-me>
                        <x-bs-admin::icon name="ri-add" class="{{ $small }}" />{{ __('admin.adv.ip.add_me') }}
                    </x-bs-admin::button>
                @elseif ($ipOn && $meIn)
                    <span class="{{ $chip }} bg-success-soft text-success-text" data-adv-ip-me-in><x-bs-admin::icon name="ri-check" class="{{ $small }}" />{{ __('admin.adv.ip.me_in') }}</span>
                @endif
            </div>

            @if ($errors->has('ip.lock'))
                <x-bs-admin::alert color="danger" icon="ri-error-warning" role="alert" id="adv-ip-lock" tabindex="-1" data-adv-err="ip-lock">
                    <p class="m-0"><b class="font-semibold">{{ __('admin.adv.ip.lock_b') }}</b> {{ $errors->first('ip.lock') }}</p>
                    <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-2">
                        <x-bs-admin::button variant="outline" color="secondary" size="sm" wire:click="addMyIp" data-adv-ip-lock-add>
                            <x-bs-admin::icon name="ri-add" class="{{ $small }}" />{{ __('admin.adv.ip.lock_add') }}
                        </x-bs-admin::button>
                    </div>
                </x-bs-admin::alert>
            @endif

            @if ($ipOn)
                @php
                    $ipLabel = 'text-[calc(0.8125rem+var(--user-text-boost))] leading-[1.45] font-semibold text-text-strong';
                @endphp
                {{-- one grid on wider cards: the header and every row share its three columns (subgrid) --}}
                <div class="flex min-w-0 flex-col @min-[36rem]/adv:grid @min-[36rem]/adv:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] @min-[36rem]/adv:gap-x-3">
                    <div class="mb-1.5 hidden {{ $ipLabel }} @min-[36rem]/adv:col-span-3 @min-[36rem]/adv:grid @min-[36rem]/adv:grid-cols-subgrid" aria-hidden="true">
                        <span>{{ __('admin.adv.ip.col_address') }}</span><span>{{ __('admin.adv.ip.col_note') }}</span><span></span>
                    </div>
                    <ul class="m-0 flex list-none flex-col gap-3 p-0 @min-[36rem]/adv:col-span-3 @min-[36rem]/adv:grid @min-[36rem]/adv:grid-cols-subgrid" aria-label="{{ __('admin.adv.ip.list_aria') }}">
                        @foreach ($ipRows as $i => $row)
                            @php
                                $fid = 'adv-ip-'.$row['id'];
                                $bad = $row['error'] !== null;
                            @endphp
                            <li class="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-x-3 gap-y-1 border-b border-border pb-3 @min-[36rem]/adv:col-span-3 @min-[36rem]/adv:grid-cols-subgrid @min-[36rem]/adv:border-b-0 @min-[36rem]/adv:pb-0"
                                wire:key="adv-ip-row-{{ $row['id'] }}" data-adv-ip-row="{{ $row['no'] }}">
                                <span class="col-span-2 row-start-1 {{ $ipLabel }} @min-[36rem]/adv:hidden" aria-hidden="true">{{ __('admin.adv.ip.row_label', ['n' => $row['no']]) }}</span>
                                <input id="{{ $fid }}" type="text" wire:model="ips.{{ $i }}.address" x-on:blur="$wire.touchIp({{ $row['id'] }})"
                                       autocomplete="off" autocapitalize="off" spellcheck="false" dir="ltr" maxlength="64"
                                       placeholder="{{ $readOnly ? '' : __('admin.adv.ip.address_ph') }}" aria-label="{{ __('admin.adv.ip.row_label', ['n' => $row['no']]) }}"
                                       @if ($readOnly) readonly @endif aria-invalid="{{ $bad ? 'true' : 'false' }}" aria-describedby="{{ $bad ? $fid.'-err ' : '' }}adv-ip-hint"
                                       class="field {{ $fieldLook }} col-start-1 row-start-2 tabular-nums @min-[36rem]/adv:row-start-1 {{ $bad ? 'border-danger-text' : 'border-border-input' }}">
                                <span class="col-span-2 row-start-3 mt-1 {{ $ipLabel }} @min-[36rem]/adv:hidden" aria-hidden="true">{{ __('admin.adv.ip.row_note') }}</span>
                                <input type="text" wire:model.live.blur="ips.{{ $i }}.note" maxlength="{{ AdminAddressLimit::MAX_NOTE }}"
                                       placeholder="{{ $readOnly ? '' : __('admin.adv.ip.note_ph') }}" aria-label="{{ __('admin.adv.ip.note_aria', ['n' => $row['no']]) }}" @if ($readOnly) readonly @endif
                                       class="field {{ $fieldLook }} col-span-2 row-start-4 border-border-input @min-[36rem]/adv:col-span-1 @min-[36rem]/adv:col-start-2 @min-[36rem]/adv:row-start-1">
                                @unless ($readOnly)
                                    <x-bs-admin::button variant="ghost" color="secondary" wire:click="removeIpRow({{ $row['id'] }})"
                                                        :aria-label="trim($row['address']) !== '' ? __('admin.adv.ip.remove_aria_value', ['n' => $row['no'], 'value' => trim($row['address'])]) : __('admin.adv.ip.remove_aria', ['n' => $row['no']])"
                                                        class="col-start-2 row-start-2 @min-[36rem]/adv:col-start-3 @min-[36rem]/adv:row-start-1" data-adv-ip-remove>
                                        <x-bs-admin::icon name="ri-delete-bin" class="{{ $glyph }}" />{{ __('admin.adv.ip.remove') }}
                                    </x-bs-admin::button>
                                @endunless
                                @if ($bad)
                                    <x-bs-admin::field-error id="{{ $fid }}-err" class="col-span-full">{{ $row['error'] }}</x-bs-admin::field-error>
                                @elseif ($row['isMe'])
                                    <p class="col-span-full m-0 flex items-center gap-1 text-caption text-success-text" data-adv-ip-row-me><x-bs-admin::icon name="ri-check" class="{{ $small }}" />{{ __('admin.adv.ip.row_me') }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    @if ($errors->has('ip.list'))
                        <x-bs-admin::field-error id="adv-ip-list-err" class="mt-3 @min-[36rem]/adv:col-span-3" tabindex="-1" data-adv-err="ip-list">{{ $errors->first('ip.list') }}</x-bs-admin::field-error>
                    @endif
                    @unless ($readOnly)
                        <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-2 @min-[36rem]/adv:col-span-3">
                            <x-bs-admin::button variant="outline" color="secondary" wire:click="addIpRow" data-adv-ip-add-row>
                                <x-bs-admin::icon name="ri-add" class="{{ $glyph }}" />{{ __('admin.adv.ip.add_row') }}
                            </x-bs-admin::button>
                        </div>
                    @endunless
                    <p id="adv-ip-hint" class="m-0 mt-1.5 max-w-[46rem] @min-[36rem]/adv:col-span-3 text-caption leading-relaxed text-text-muted">{{ __('admin.adv.ip.hint') }}</p>
                </div>
            @endif

            <x-bs-admin::alert color="info" icon="ri-customer-service-2" role="note">
                <p class="m-0"><b class="font-semibold">{{ __('admin.adv.ip.help_b') }}</b> {{ __('admin.adv.ip.help', ['platform' => $platform]) }}</p>
            </x-bs-admin::alert>
        </section>

        <section class="@container/adv flex min-w-0 flex-col gap-4 rounded-md border border-border bg-surface p-[1.375rem] shadow-card max-md:rounded-none max-md:border-0 max-md:border-t max-md:border-border max-md:bg-transparent max-md:px-0 max-md:pt-5 max-md:pb-5 max-md:shadow-none"
                 aria-labelledby="adv-cd-h" id="adv-cd" data-adv-code>
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                <h2 id="adv-cd-h" class="min-w-0 flex-[1_1_12em] text-[min(1.375rem,calc(1rem+var(--user-text-boost)))] leading-[1.4] font-semibold break-words text-text-strong">{{ __('admin.adv.cd.title') }}</h2>
                @if ($codeRunning)
                    <span class="{{ $chip }} bg-warning-soft text-warning-text" data-adv-code-chip="on"><x-bs-admin::icon name="ri-tools" class="{{ $small }}" />{{ __('admin.adv.cd.chip_on') }}</span>
                @else
                    <span class="{{ $chip }} bg-surface-muted text-text-muted" data-adv-code-chip="off"><x-bs-admin::icon name="ri-checkbox-blank-circle" class="{{ $small }}" />{{ __('admin.adv.cd.chip_off') }}</span>
                @endif
                <span class="inline-flex min-h-hit shrink-0 items-center">
                    <button type="button" class="switch hit-area" role="switch" aria-checked="{{ $codeOn ? 'true' : 'false' }}" aria-label="{{ __('admin.adv.cd.switch') }}"
                            @if ($readOnly) disabled aria-disabled="true" @else wire:click="toggleCode" @endif></button>
                </span>
            </div>

            <x-bs-admin::alert color="info" icon="ri-plug-2" role="note">
                <div class="flex flex-col gap-2">
                    <p class="m-0"><b class="font-semibold">{{ __('admin.adv.cd.tip_lead') }}</b> {!! __('admin.adv.cd.tip', ['place' => '<b class="font-semibold">'.e(__('admin.int.title')).'</b>']) !!}</p>
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                        <x-bs-admin::button variant="outline" color="secondary" size="sm" :href="route('admin.settings.integrations')">
                            {{ __('admin.adv.cd.tip_open') }}<x-bs-admin::icon name="ri-arrow-right" class="{{ $small }}" />
                        </x-bs-admin::button>
                    </div>
                </div>
            </x-bs-admin::alert>

            <x-bs-admin::alert color="warning" icon="ri-alert" role="note">
                <p class="m-0"><b class="font-semibold">{{ __('admin.adv.cd.warn_title') }}</b> {{ __('admin.adv.cd.warn_intro') }}</p>
                <ul class="m-0 mt-2 flex list-disc flex-col gap-1 ps-[1.125rem]">
                    <li><b class="font-semibold">{{ __('admin.adv.cd.warn_slow_b') }}</b> {{ __('admin.adv.cd.warn_slow') }}</li>
                    <li><b class="font-semibold">{{ __('admin.adv.cd.warn_safe_b') }}</b> {{ __('admin.adv.cd.warn_safe') }}</li>
                    <li><b class="font-semibold">{{ __('admin.adv.cd.warn_break_b') }}</b> {{ __('admin.adv.cd.warn_break') }}</li>
                    <li>{{ __('admin.adv.cd.warn_trust') }}</li>
                </ul>
            </x-bs-admin::alert>

            <p class="m-0 max-w-[46rem] text-caption leading-relaxed text-text-muted" data-adv-code-signed-in>{{ __('admin.adv.cd.signed_in') }}</p>

            @if ($codeKept)
                <p class="m-0 max-w-[46rem] text-text-muted [text-wrap:pretty]" data-adv-code-kept>{{ __('admin.adv.cd.kept') }}</p>
            @endif

            @if ($codeOn || $errors->has('code.*'))
                <div class="flex flex-col gap-4">
                    @foreach (SiteEmbedCode::SLOTS as $slot)
                        @php
                            $fid = 'adv-code-'.$slot;
                            $bag = 'code.'.$slot;
                            $bad = $errors->has($bag);
                            $tone = ['head' => 'bg-danger-soft text-danger-text', 'foot' => 'bg-success-soft text-success-text'][$slot] ?? 'bg-info-soft text-info-text';
                            $toneIcon = ['head' => 'ri-error-warning', 'foot' => 'ri-thumb-up'][$slot] ?? 'ri-information';
                            $toneText = ['head' => 'admin.adv.cd.chip_head', 'foot' => 'admin.adv.cd.chip_foot'][$slot] ?? 'admin.adv.cd.chip_asked';
                        @endphp
                        <div @class(['flex min-w-0 flex-col gap-1.5', 'rounded border border-dashed border-danger-text bg-surface-muted p-3' => $slot === 'head']) wire:key="adv-code-{{ $slot }}" data-adv-code-box="{{ $slot }}">
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                <label for="{{ $fid }}" @class(['block text-[calc(0.8125rem+var(--user-text-boost))] leading-[1.45] font-semibold', 'text-text' => $slot === 'head', 'text-text-strong' => $slot !== 'head'])>{{ __('admin.adv.cd.'.$slot) }}</label>
                                <span class="{{ $chip }} {{ $tone }}"><x-bs-admin::icon :name="$toneIcon" class="{{ $small }}" />{{ __($toneText) }}</span>
                            </div>
                            <p id="{{ $fid }}-h" class="m-0 max-w-[46rem] text-caption leading-relaxed text-text-muted">{{ __('admin.adv.cd.'.$slot.'_hint') }}</p>
                            <textarea id="{{ $fid }}" rows="{{ $slot === 'head' ? 3 : 4 }}" dir="ltr" spellcheck="false" autocomplete="off" autocapitalize="off" wire:model.live.blur="{{ $bag }}"
                                      placeholder="{{ $readOnly ? '' : __($slot === 'head' ? 'admin.adv.cd.head_ph' : 'admin.adv.cd.ph') }}"
                                      @if ($readOnly) readonly @endif aria-invalid="{{ $bad ? 'true' : 'false' }}" aria-describedby="{{ $bad ? $fid.'-err ' : '' }}{{ $fid }}-h"
                                      class="field {{ $fieldLook }} {{ $slot === 'head' ? 'min-h-[4.5rem]' : 'min-h-[6.5rem]' }} resize-y py-2 font-mono text-[calc(0.8125rem+var(--user-text-boost))] leading-normal [tab-size:2] {{ $bad ? 'border-danger-text' : 'border-border-input' }}"></textarea>
                            @if ($bad)
                                <x-bs-admin::field-error id="{{ $fid }}-err">{{ $errors->first($bag) }}</x-bs-admin::field-error>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    </div>

    {{-- «Bật mã nhúng thêm cho website?»: asked before the first save that turns code on, never after. --}}
    <div x-data="{ ack: false }" wire:key="adv-ask-{{ $askSeq }}">
        <x-bs-admin::modal model="askCode" alert :close-label="__('admin.adv.cd.ask_close_aria')">
            <x-slot:heading><span class="inline-flex items-start gap-3"><x-bs-admin::icon name="ri-alert" class="mt-0.5 size-[calc(1.375rem+var(--user-text-boost))] shrink-0 text-warning-text" /><span>{{ __('admin.adv.cd.ask_title') }}</span></span></x-slot:heading>
            <p class="m-0" data-adv-ask-lead>{{ __('admin.adv.cd.ask_lead') }}</p>
            <ul class="m-0 flex list-disc flex-col gap-1.5 ps-[1.125rem]">
                <li>{{ __('admin.adv.cd.ask_slow') }}</li>
                <li>{{ __('admin.adv.cd.ask_safe') }}</li>
                <li>{{ __('admin.adv.cd.ask_break') }}</li>
            </ul>
            <label class="flex min-h-hit cursor-pointer items-start gap-2.5 py-2 font-medium text-text-strong">
                <input type="checkbox" x-model="ack" class="mt-0.5 size-[1.125rem] shrink-0 accent-[var(--color-primary)]" data-adv-ack>
                <span>{{ __('admin.adv.cd.ask_ack') }}</span>
            </label>
            <x-slot:footer>
                <x-bs-admin::button variant="outline" color="secondary" x-on:click="hide()">{{ __('admin.cancel') }}</x-bs-admin::button>
                <x-bs-admin::button color="primary" data-adv-ask-ok aria-disabled="true" x-bind:aria-disabled="ack ? 'false' : 'true'"
                                    :title="__('admin.adv.cd.ask_ok_need')" x-bind:title="ack ? {{ \Illuminate\Support\Js::from(__('admin.adv.cd.ask_ok_title')) }} : {{ \Illuminate\Support\Js::from(__('admin.adv.cd.ask_ok_need')) }}"
                                    class="aria-disabled:cursor-not-allowed aria-disabled:opacity-60" x-on:click="if (ack) { $wire.save(true); }"
                                    wire:loading.attr="aria-busy" wire:target="save">
                    <x-bs-admin::icon name="ri-save-3" class="{{ $glyph }}" />{{ __('admin.adv.cd.ask_ok') }}
                </x-bs-admin::button>
            </x-slot:footer>
        </x-bs-admin::modal>
    </div>

    <x-bs-admin::toast :message="$toast" :tone="$toastTone" dismiss="dismissToast" :seq="$toastSeq" />
</x-bs-admin::settings-shell>
