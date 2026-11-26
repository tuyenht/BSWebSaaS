{{-- «Chọn dịch vụ» (board c-email-settings, under «Nhà cung cấp khác»): the eight services as tiles,
     the picked one's tip, its fields and «Kiểm tra & lưu kết nối». A service's server comes from
     MailRelay on the server; only «Email khác» takes a typed-in server. --}}
@use('App\Modules\Admin\Support\MailRelay')
@php
    // the services' own colours and initials (the board's badges); the letters are drawn, not read
    $badges = [
        'sendgrid' => ['SG', '#1a82e2', '#000000'],
        'postmark' => ['Pm', '#ffcb2b', '#212529'],
        'ses' => ['SES', '#ff9900', '#212529'],
        'mailgun' => ['Mg', '#c02428', '#ffffff'],
        'brevo' => ['Br', '#0b996e', '#000000'],
        'resend' => ['Re', '#111827', '#ffffff'],
        'mailtrap' => ['Mt', '#0d9488', '#000000'],
        'smtp' => ['SM', 'var(--color-admin-primary)', 'var(--color-on-solid, #ffffff)'],
    ];
    $isSmtp = $relay === 'smtp';
    $saved = $keySaved;
    $regions = MailRelay::regions($relay);
    $label = 'flex items-center gap-1.5 text-[calc(0.8125rem+var(--user-text-boost))] font-medium text-text-strong';
    $hint = 'text-caption leading-normal text-text-muted';
    $star = '<span aria-hidden="true" class="text-danger-text">*</span>';
    $keyLabel = __($isSmtp ? 'admin.em_rl_apppass' : 'admin.em_rl_key');
    $relayName = __('admin.em_rl_'.$relay);
    $emailHint = MailRelay::isMicrosoftMailbox($smtpUsername) ? __('admin.em_rl_outlook_hint') : __('admin.em_rl_email_hint');
@endphp
<div class="@container rounded-md border border-border-strong bg-surface p-4 shadow-card">
    <h2 id="em-prov-title" class="mb-3 text-caption font-bold tracking-[.04em] text-text-muted uppercase">{{ __('admin.em_prov_title') }}</h2>
    {{-- a radio group: the arrows move to the next service and pick it --}}
    <div role="radiogroup" aria-labelledby="em-prov-title" class="grid grid-cols-4 gap-2.5 @max-[51.25rem]:grid-cols-2"
         x-data="{ step(el, by) { const r = [...$root.querySelectorAll('[role=radio]')]; const to = r[(r.indexOf(el) + by + r.length) % r.length]; to.focus(); to.click() } }">
        @foreach (MailRelay::KEYS as $k)
            @php $on = $relay === $k; [$initials, $bg, $fg] = $badges[$k]; @endphp
            <button type="button" role="radio" aria-checked="{{ $on ? 'true' : 'false' }}" tabindex="{{ $on ? 0 : -1 }}" wire:key="rl-{{ $k }}"
                    wire:click="pickRelay(@js($k))"
                    x-on:keydown.down.prevent="step($el, 1)" x-on:keydown.right.prevent="step($el, 1)"
                    x-on:keydown.up.prevent="step($el, -1)" x-on:keydown.left.prevent="step($el, -1)"
                    @class([
                        'flex min-h-12 min-w-0 cursor-pointer items-center gap-2.5 rounded-md border-[1.5px] px-3 py-2.5 text-start transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-text',
                        'border-primary-text bg-[color-mix(in_srgb,var(--color-primary-text)_4.5%,var(--color-surface))]' => $on,
                        'border-border-strong bg-surface hover:border-border-input' => ! $on,
                    ])>
                <span aria-hidden="true" class="inline-flex size-[2.125rem] shrink-0 items-center justify-center rounded text-[calc(0.75rem+var(--user-text-boost))] font-extrabold"
                      style="background: {{ $bg }}; color: {{ $fg }}">{{ $initials }}</span>
                <span class="flex min-w-0 flex-col">
                    <span class="text-[calc(0.8125rem+var(--user-text-boost))] font-semibold text-text-strong [overflow-wrap:anywhere]">{{ __('admin.em_rl_'.$k) }}</span>
                    <span class="text-caption text-text-muted [overflow-wrap:anywhere]">{{ __('admin.em_rl_'.$k.'_sub') }}</span>
                </span>
            </button>
        @endforeach
    </div>
    <p class="mt-2.5 flex items-start gap-1.5 text-[calc(0.78125rem+var(--user-text-boost))] leading-[1.6] text-text-muted">
        <x-bs-admin::icon name="ri-lightbulb" class="mt-0.5 size-[min(1.5rem,calc(0.875rem+var(--user-text-boost)))] shrink-0 text-warning-text" />
        <span>{{ __('admin.em_rl_'.$relay.'_tip') }}</span>
    </p>

    <div class="mt-4 grid grid-cols-2 gap-x-[18px] gap-y-3.5 @max-[56.25rem]:grid-cols-1">
        @if ($isSmtp)
            <div class="flex flex-col gap-1.5">
                <label for="em-f-email" class="{{ $label }}">{{ __('admin.em_rl_email') }}{!! $star !!}</label>
                <x-bs-admin::input id="em-f-email" type="email" aria-required="true" wire:model.live.blur="smtpUsername" autocomplete="new-password" spellcheck="false" />
                @unless ($errors->has('smtpUsername'))
                    <span class="{{ $hint }}">{{ $emailHint }}</span>
                @endunless
            </div>
        @endif

        {{-- the key (or the app password) is write-only: a stored one shows «Đã lưu», and «Thay» opens an empty field --}}
        <div class="flex flex-col gap-1.5" wire:key="rl-key-{{ $relay }}"
             x-data="{ replace: @js($errors->has('smtpPassword')) }" x-on:em-key-saved.window="replace = false">
            @if ($saved)
                <span class="{{ $label }}"><label for="em-f-key" x-show="replace" x-cloak>{{ $keyLabel }}</label><span x-show="! replace">{{ $keyLabel }}</span></span>
                <span x-show="! replace" class="flex min-h-control flex-wrap items-center gap-2">
                    <span class="inline-flex items-center gap-1 rounded-full bg-success-soft px-2.5 py-0.5 text-caption font-semibold text-success-text">
                        <x-bs-admin::icon name="ri-lock" class="size-[min(1.5rem,calc(0.8125rem+var(--user-text-boost)))] shrink-0" />{{ __('admin.em_rl_saved') }}
                    </span>
                    <span class="min-w-0 flex-[1_1_10rem] text-caption text-text-muted">{{ __('admin.em_rl_saved_note') }}</span>
                    <x-bs-admin::button size="sm" variant="outline" color="secondary" :aria-label="__('admin.em_rl_replace_aria', ['label' => $keyLabel])"
                                        x-on:click="replace = true; $nextTick(() => document.getElementById('em-f-key')?.focus())">{{ __('admin.em_rl_replace') }}</x-bs-admin::button>
                </span>
                <span x-show="replace" x-cloak class="flex flex-col gap-1.5">
                    <x-bs-admin::input id="em-f-key" type="password" wire:model.live.blur="smtpPassword" autocomplete="new-password" spellcheck="false"
                                       :placeholder="$isSmtp ? 'abcd efgh ijkl mnop' : ($relay === 'sendgrid' ? 'SG.xxxxxxxx' : '')" />
                    @unless ($errors->has('smtpPassword'))
                        <span class="{{ $hint }}">{{ __('admin.em_rl_keep_blank') }}</span>
                    @endunless
                </span>
            @else
                <label for="em-f-key" class="{{ $label }}">{{ $keyLabel }}{!! $star !!}</label>
                <x-bs-admin::input id="em-f-key" type="password" aria-required="true" wire:model.live.blur="smtpPassword" autocomplete="new-password" spellcheck="false"
                                   :placeholder="$isSmtp ? 'abcd efgh ijkl mnop' : ($relay === 'sendgrid' ? 'SG.xxxxxxxx' : '')" />
                @unless ($errors->has('smtpPassword'))
                    <span class="{{ $hint }}">{{ $isSmtp ? __('admin.em_rl_apppass_hint') : __('admin.em_rl_key_hint', ['name' => $relayName]) }}</span>
                @endunless
            @endif
        </div>

        @if ($isSmtp)
            {{-- «Email khác» keeps the server fields (a Gmail or Yahoo address fills them in) --}}
            <x-bs-admin::input id="em-f-host" :label="__('admin.smtp_host')" wire:model.live.blur="smtpHost" placeholder="smtp.gmail.com" spellcheck="false" />
            <div class="grid grid-cols-2 gap-x-[18px]">
                <x-bs-admin::input id="em-f-port" :label="__('admin.smtp_port')" type="number" wire:model.live.blur="smtpPort" placeholder="587" />
                <div>
                    <label for="em-f-enc" class="mb-1.5 block text-sm font-medium text-body-text">{{ __('admin.smtp_encryption') }}</label>
                    <select id="em-f-enc" wire:model.live="smtpEncryption" class="field block w-full rounded border border-border-input bg-surface px-3 text-text focus:border-primary-text">
                        <option value="tls">{{ __('admin.encryption_tls') }}</option>
                        <option value="ssl">{{ __('admin.encryption_ssl') }}</option>
                        <option value="null">{{ __('admin.encryption_none') }}</option>
                    </select>
                </div>
            </div>
        @else
            <div class="flex flex-col gap-1.5">
                <label for="em-f-domain" class="{{ $label }}">{{ __('admin.em_rl_domain') }}{!! $star !!}</label>
                <x-bs-admin::input id="em-f-domain" aria-required="true" wire:model.live.blur="sendDomain" autocomplete="new-password" spellcheck="false" />
                @unless ($errors->has('sendDomain'))
                    <span class="{{ $hint }}">{{ __('admin.em_rl_domain_hint') }}</span>
                @endunless
            </div>
            @if (MailRelay::hasOwnLogin($relay))
                <div class="flex flex-col gap-1.5">
                    <label for="em-f-login" class="{{ $label }}">{{ __('admin.em_rl_login') }}{!! $star !!}</label>
                    <x-bs-admin::input id="em-f-login" aria-required="true" wire:model.live.blur="smtpUsername" autocomplete="new-password" spellcheck="false" />
                    @unless ($errors->has('smtpUsername'))
                        <span class="{{ $hint }}">{{ __('admin.em_rl_login_hint_'.$relay) }}</span>
                    @endunless
                </div>
            @endif
            @if ($regions !== [])
                <div class="flex flex-col gap-1.5">
                    <label for="em-f-region" class="{{ $label }}">{{ __('admin.em_rl_region') }}{!! $star !!}</label>
                    <select id="em-f-region" aria-required="true" wire:model.live="relayRegion" @error('relayRegion') aria-invalid="true" aria-describedby="em-f-region-error" @enderror
                            class="field block w-full rounded border border-border-input bg-surface px-3 text-text focus:border-primary-text aria-invalid:border-danger-text">
                        @if ($relay === 'ses')
                            <option value="">{{ __('admin.em_rl_region_pick') }}</option>
                        @endif
                        @foreach ($regions as $r)
                            <option value="{{ $r }}">{{ __('admin.em_rl_region_'.str_replace('-', '_', $r)) }}</option>
                        @endforeach
                    </select>
                    @error('relayRegion')
                        <p id="em-f-region-error" class="text-sm text-danger-text">{{ $message }}</p>
                    @enderror
                </div>
            @endif
        @endif
    </div>
    @error('relay')
        <x-bs-admin::field-error class="mt-3">{{ $message }}</x-bs-admin::field-error>
    @enderror

    <div class="mt-4 flex flex-wrap items-center gap-3">
        <x-bs-admin::button :variant="$relayChecked ? 'outline' : 'solid'" wire:click="checkRelay" wire:loading.attr="disabled" wire:target="checkRelay">
            <x-bs-admin::icon :name="$relayChecked ? 'ri-refresh' : 'ri-shield-check'" class="size-4" />{{ __($relayChecked ? 'admin.em_rl_recheck' : 'admin.em_rl_check') }}
        </x-bs-admin::button>
        <span role="status" aria-live="polite" @class([
                'inline-flex items-center gap-[7px] rounded-full px-[11px] py-[5px] text-[calc(0.78125rem+var(--user-text-boost))] font-semibold',
                'bg-success-base/14 text-success-text' => $relayChecked,
                'bg-warning-base/16 text-warning-text' => ! $relayChecked,
            ])>
            <span @class(['size-2 shrink-0 rounded-full', 'bg-success-base' => $relayChecked, 'border-[1.5px] border-dashed border-warning-text' => ! $relayChecked])></span>
            {{ __($relayChecked ? 'admin.em_rl_ok' : 'admin.em_rl_unchecked') }}
        </span>
    </div>
    @if (! $relayChecked && $testResult === 'error')
        <x-bs-admin::field-error class="mt-2.5">{{ __('admin.test_email_failed') }}</x-bs-admin::field-error>
    @endif
</div>
