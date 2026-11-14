{{--
    My account (Tài khoản của tôi) — ported 1:1 from design board 23 and its linked-accounts child.
    Sections: profile, security (password + two-factor), linked sign-in accounts, preferences
    (language, light/dark, text size, motion, notifications). Layout and type: resources/css/admin-account.css,
    its own Vite entry linked below (@assets puts it in the head once, on a full load and after wire:navigate).
    During a support session every control is disabled and the server refuses each change.
--}}
@php
    $t = fn (string $key, array $replace = []): string => __('admin.account.'.$key, $replace);
    $tabs = [
        'profile' => ['ri-user-3', $t('tab_profile')],
        'security' => ['ri-shield-keyhole', $t('tab_security')],
        'linked' => ['ri-links', $t('tab_linked')],
        'prefs' => ['ri-settings-3', $t('tab_prefs')],
    ];
    $lockId = $readOnly ? 'ac-lockwhy' : null;
    $profileDirty = '$wire.profileName.trim() !== $wire.savedName || $wire.profileTitle.trim() !== $wire.savedTitle || $wire.profilePhone.trim() !== $wire.savedPhone';
    $glyph = 'size-[calc(1.0625rem+var(--user-text-boost))] shrink-0';
    $small = 'size-[1.15em] shrink-0';
    $providerIcons = ['google' => 'ri-google', 'microsoft' => 'ri-microsoft', 'facebook' => 'ri-facebook-circle', 'yahoo' => 'mail'];
    $groupIcons = ['workflow' => 'ri-git-pull-request', 'lead' => 'ri-mail', 'security' => 'ri-shield-keyhole', 'system' => 'ri-settings-3'];
@endphp
<div class="ac-c" data-screen="account">
    @assets
        @vite('resources/css/admin-account.css')
    @endassets
    <div class="mx-auto flex max-w-[1120px] flex-col gap-4">
        <header>
            <h1 class="ac-h1">{{ $t('title') }}</h1>
            <p class="ac-desc">{{ $t('desc') }}</p>
        </header>

        <div class="ac-card ac-id">
            <span class="ac-av" aria-hidden="true">{{ $initials }}</span>
            <div class="flex min-w-0 flex-[1_1_220px] flex-col gap-0.5">
                <span class="ac-nm">{{ $user->name }}</span>
                <span class="text-text-muted [overflow-wrap:anywhere]">{{ $user->email }}</span>
            </div>
            <div class="flex max-w-full flex-none flex-wrap gap-1.5">
                @if ($roleLabel)
                    <span class="ac-chip" data-t="brand"><x-bs-admin::icon name="ri-shield-user" class="{{ $small }}" />{{ $roleLabel }}</span>
                @endif
                @if ($siteName !== '')
                    <span class="ac-chip"><x-bs-admin::icon name="ri-store-2" class="{{ $small }}" />{{ $t('plane_site', ['site' => $siteName]) }}</span>
                @endif
            </div>
        </div>

        @if ($readOnly)
            <div class="ac-al" data-k="warning" role="note" id="ac-lockwhy">
                <x-bs-admin::icon name="ri-lock" class="mt-0.5 {{ $glyph }}" />
                <span><strong>{{ $t('ro_title') }}</strong> {{ $t('ro_body') }}</span>
            </div>
        @endif

        <div class="ac-body">
            <nav class="ac-card ac-nav" role="tablist" aria-orientation="vertical" aria-label="{{ $t('tabs_label') }}"
                 x-data
                 @keydown.arrow-down.prevent="$focus.wrap().next()" @keydown.arrow-up.prevent="$focus.wrap().previous()">
                @foreach ($tabs as $key => [$icon, $label])
                    <button type="button" role="tab" id="ac-tab-{{ $key }}" aria-controls="ac-panel"
                            aria-selected="{{ $tab === $key ? 'true' : 'false' }}" tabindex="{{ $tab === $key ? '0' : '-1' }}"
                            wire:click="$set('tab', '{{ $key }}')">
                        <x-bs-admin::icon :name="$icon" class="{{ $glyph }}" />
                        <span>{{ $label }}</span>
                        @if ($key === 'profile')
                            <span class="ac-udot" x-cloak x-show="{{ $profileDirty }}">{{ $t('unsaved') }}</span>
                        @endif
                    </button>
                @endforeach
            </nav>

            <div class="flex min-w-0 flex-col gap-4">
                <label class="ac-sel">
                    <span class="ac-lab">{{ $t('tabs_select') }}</span>
                    <select class="field ac-select" wire:model.live="tab" aria-label="{{ $t('tabs_label') }}">
                        @foreach ($tabs as $key => [$icon, $label])
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <div id="ac-panel" role="tabpanel" aria-labelledby="ac-tab-{{ $tab }}" class="flex min-w-0 flex-col gap-4">
                    {{-- ─────────────── profile ─────────────── --}}
                    @if ($tab === 'profile')
                        <section class="ac-card" aria-labelledby="ac-h-prof">
                            <div class="ac-ch"><h2 class="ac-h3" id="ac-h-prof">{{ $t('tab_profile') }}</h2></div>
                            <div class="ac-cb">
                                <div class="flex flex-wrap items-center gap-x-4 gap-y-3">
                                    <span class="ac-av lg" aria-hidden="true">{{ $initials }}</span>
                                    <div class="min-w-0 flex-[1_1_220px]">
                                        <div class="ac-sub flex flex-wrap items-center gap-1.5">{{ $t('avatar') }} <span class="ac-chip"><x-bs-admin::icon name="ri-time" class="{{ $small }}" />{{ $t('later') }}</span></div>
                                        <p class="ac-rule">{{ $t('avatar_hint') }}</p>
                                    </div>
                                </div>

                                <div class="ac-f2">
                                    <div class="ac-span" x-data="{ max: {{ \App\Modules\Admin\Livewire\Account::NAME_MAX }} }">
                                        <label class="ac-lab" for="ac-name">{{ $t('name') }} <span class="font-bold text-danger-text" aria-hidden="true">*</span><span class="sr-only">{{ $t('required') }}</span></label>
                                        <input id="ac-name" class="field ac-in" autocomplete="name" maxlength="140" placeholder="Nguyễn Ngọc Anh"
                                               wire:model.blur="profileName" @disabled($readOnly)
                                               @error('profileName') aria-invalid="true" @enderror aria-describedby="ac-name-h">
                                        @error('profileName')
                                            <p class="ac-err" id="ac-name-h"><x-bs-admin::icon name="ri-error-warning" class="mt-0.5 {{ $small }}" />{{ $message }}</p>
                                        @else
                                            <p class="ac-hint" id="ac-name-h">{{ $t('name_hint') }} <span x-text="@js($t('name_count', ['count' => '__N__'])).replace('__N__', $wire.profileName.length)"></span></p>
                                        @enderror
                                    </div>
                                    <div>
                                        <label class="ac-lab" for="ac-title">{{ $t('job_title') }}</label>
                                        <input id="ac-title" class="field ac-in" autocomplete="organization-title" placeholder="{{ $t('job_title_placeholder') }}"
                                               wire:model.blur="profileTitle" @disabled($readOnly)
                                               @error('profileTitle') aria-invalid="true" @enderror aria-describedby="ac-title-h">
                                        @error('profileTitle')
                                            <p class="ac-err" id="ac-title-h"><x-bs-admin::icon name="ri-error-warning" class="mt-0.5 {{ $small }}" />{{ $message }}</p>
                                        @else
                                            <p class="ac-hint" id="ac-title-h">{{ $t('job_title_hint') }}</p>
                                        @enderror
                                    </div>
                                    <div>
                                        <label class="ac-lab" for="ac-phone">{{ $t('phone') }}</label>
                                        <input id="ac-phone" class="field ac-in" type="tel" inputmode="tel" autocomplete="tel" placeholder="{{ $t('phone_placeholder') }}"
                                               wire:model.blur="profilePhone" @disabled($readOnly)
                                               @error('profilePhone') aria-invalid="true" @enderror aria-describedby="ac-phone-h">
                                        @error('profilePhone')
                                            <p class="ac-err" id="ac-phone-h"><x-bs-admin::icon name="ri-error-warning" class="mt-0.5 {{ $small }}" />{{ $message }}</p>
                                        @else
                                            <p class="ac-hint" id="ac-phone-h">{{ $t('phone_hint') }}</p>
                                        @enderror
                                    </div>
                                </div>

                                <div class="m-0 h-px bg-border"></div>
                                <div>
                                    <div class="ac-lab" id="ac-email-l">{{ $t('email') }}</div>
                                    <span class="ac-mono block tracking-normal" aria-labelledby="ac-email-l">{{ $user->email }}</span>
                                    <p class="ac-hint">{{ $t('email_hint') }}</p>
                                </div>
                            </div>
                            <div class="ac-foot">
                                <x-bs-admin::button wire:click="saveProfile" wire:loading.attr="disabled" wire:target="saveProfile" :disabled="$readOnly" :aria-describedby="$lockId">
                                    <x-bs-admin::icon name="ri-save-3" class="{{ $small }}" />
                                    <span wire:loading.remove wire:target="saveProfile">{{ $t('save_profile') }}</span>
                                    <span wire:loading wire:target="saveProfile">{{ $t('saving') }}</span>
                                </x-bs-admin::button>
                                <span x-cloak x-show="{{ $profileDirty }}">
                                    <x-bs-admin::button variant="ghost" wire:click="resetProfile">
                                        <x-bs-admin::icon name="ri-arrow-go-back" class="{{ $small }}" />{{ $t('discard') }}
                                    </x-bs-admin::button>
                                </span>
                                <x-bs-admin::saved-note>{{ $profileSavedAt !== '' ? $t('saved_at', ['time' => $profileSavedAt]) : '' }}</x-bs-admin::saved-note>
                                @if ($readOnly)
                                    <span class="ac-why"><x-bs-admin::icon name="ri-lock" class="mt-0.5 {{ $small }}" />{{ $t('ro_body') }}</span>
                                @endif
                            </div>
                        </section>
                    @endif

                    {{-- ─────────────── security ─────────────── --}}
                    @if ($tab === 'security')
                        <section class="ac-card" aria-labelledby="ac-h-pw"
                                 x-data="{
                                     show: { cur: false, nw: false, cf: false },
                                     strength(p) {
                                         if (! p) return 0;
                                         if (p.length < {{ \App\Modules\Admin\Livewire\Account::PASSWORD_MIN }}) return 1;
                                         let s = 1;
                                         if (p.length >= 14) s++;
                                         if (/\d/.test(p) && /[^\d]/.test(p)) s++;
                                         if (/[^\w\s]|_/.test(p) || (/[A-Z]/.test(p) && /[a-z]/.test(p))) s++;
                                         return Math.min(4, s);
                                     },
                                     labels: @js([1 => [$t('st_1'), $t('st_1_tip')], 2 => [$t('st_2'), $t('st_2_tip')], 3 => [$t('st_3'), $t('st_3_tip')], 4 => [$t('st_4'), $t('st_4_tip')]]),
                                 }">
                            <div class="ac-ch"><h2 class="ac-h3" id="ac-h-pw">{{ $t('pw_title') }}</h2></div>
                            <div class="ac-cb">
                                @if ($passwordChanged)
                                    <x-bs-admin::alert color="success" icon="ri-checkbox-circle">{{ $t('pw_done') }}</x-bs-admin::alert>
                                @endif
                                <div>
                                    <label class="ac-lab" for="ac-pw-cur">{{ $t('pw_current') }} <span class="font-bold text-danger-text" aria-hidden="true">*</span></label>
                                    <div class="ac-pw">
                                        <input id="ac-pw-cur" class="field" :type="show.cur ? 'text' : 'password'" type="password" autocomplete="current-password"
                                               wire:model="current_password" @disabled($readOnly)
                                               @error('current_password') aria-invalid="true" aria-describedby="ac-pw-cur-h" @enderror>
                                        <button type="button" @click="show.cur = ! show.cur" :aria-pressed="show.cur" @disabled($readOnly)
                                                aria-label="{{ $t('pw_show') }}" :title="show.cur ? @js($t('pw_hide')) : @js($t('pw_show'))">
                                            <x-bs-admin::icon name="ri-eye" class="{{ $glyph }}" x-show="! show.cur" />
                                            <x-bs-admin::icon name="ri-eye-off" class="{{ $glyph }}" x-show="show.cur" x-cloak />
                                        </button>
                                    </div>
                                    @error('current_password')
                                        <p class="ac-err" id="ac-pw-cur-h"><x-bs-admin::icon name="ri-error-warning" class="mt-0.5 {{ $small }}" />{{ $message }}</p>
                                    @enderror
                                </div>
                                <div class="ac-f2">
                                    <div>
                                        <label class="ac-lab" for="ac-pw-new">{{ $t('pw_new') }} <span class="font-bold text-danger-text" aria-hidden="true">*</span></label>
                                        <div class="ac-pw">
                                            <input id="ac-pw-new" class="field" :type="show.nw ? 'text' : 'password'" type="password" autocomplete="new-password"
                                                   wire:model="password" @disabled($readOnly)
                                                   @error('password') aria-invalid="true" @enderror aria-describedby="ac-pw-new-h ac-pw-new-s">
                                            <button type="button" @click="show.nw = ! show.nw" :aria-pressed="show.nw" @disabled($readOnly)
                                                    aria-label="{{ $t('pw_show') }}" :title="show.nw ? @js($t('pw_hide')) : @js($t('pw_show'))">
                                                <x-bs-admin::icon name="ri-eye" class="{{ $glyph }}" x-show="! show.nw" />
                                                <x-bs-admin::icon name="ri-eye-off" class="{{ $glyph }}" x-show="show.nw" x-cloak />
                                            </button>
                                        </div>
                                        <template x-if="$wire.password">
                                            <div>
                                                <div class="ac-meter" :data-s="strength($wire.password)" aria-hidden="true"><i></i><i></i><i></i><i></i></div>
                                                <p class="ac-stx" id="ac-pw-new-s" :data-s="strength($wire.password)">
                                                    <span><strong x-text="labels[strength($wire.password)][0]"></strong> — <span x-text="labels[strength($wire.password)][1]"></span></span>
                                                </p>
                                            </div>
                                        </template>
                                        @error('password')
                                            <p class="ac-err" id="ac-pw-new-h"><x-bs-admin::icon name="ri-error-warning" class="mt-0.5 {{ $small }}" />{{ $message }}</p>
                                        @else
                                            <p class="ac-hint" id="ac-pw-new-h">{{ $t('pw_new_hint') }}</p>
                                        @enderror
                                    </div>
                                    <div>
                                        <label class="ac-lab" for="ac-pw-cf">{{ $t('pw_confirm') }} <span class="font-bold text-danger-text" aria-hidden="true">*</span></label>
                                        <div class="ac-pw">
                                            <input id="ac-pw-cf" class="field" :type="show.cf ? 'text' : 'password'" type="password" autocomplete="new-password"
                                                   wire:model="password_confirmation" @disabled($readOnly)
                                                   @error('password_confirmation') aria-invalid="true" aria-describedby="ac-pw-cf-h" @enderror>
                                            <button type="button" @click="show.cf = ! show.cf" :aria-pressed="show.cf" @disabled($readOnly)
                                                    aria-label="{{ $t('pw_show') }}" :title="show.cf ? @js($t('pw_hide')) : @js($t('pw_show'))">
                                                <x-bs-admin::icon name="ri-eye" class="{{ $glyph }}" x-show="! show.cf" />
                                                <x-bs-admin::icon name="ri-eye-off" class="{{ $glyph }}" x-show="show.cf" x-cloak />
                                            </button>
                                        </div>
                                        @error('password_confirmation')
                                            <p class="ac-err" id="ac-pw-cf-h"><x-bs-admin::icon name="ri-error-warning" class="mt-0.5 {{ $small }}" />{{ $message }}</p>
                                        @enderror
                                    </div>
                                </div>
                                <p class="ac-rule flex items-start gap-1.5"><x-bs-admin::icon name="ri-information" class="mt-0.5 {{ $small }}" /><span>{{ $t('pw_others') }}</span></p>
                            </div>
                            <div class="ac-foot">
                                <x-bs-admin::button wire:click="changePassword" wire:loading.attr="disabled" wire:target="changePassword" :disabled="$readOnly" :aria-describedby="$lockId">
                                    <x-bs-admin::icon name="ri-lock-password" class="{{ $small }}" />
                                    <span wire:loading.remove wire:target="changePassword">{{ $t('pw_submit') }}</span>
                                    <span wire:loading wire:target="changePassword">{{ $t('pw_submitting') }}</span>
                                </x-bs-admin::button>
                                @if ($readOnly)
                                    <span class="ac-why"><x-bs-admin::icon name="ri-lock" class="mt-0.5 {{ $small }}" />{{ $t('ro_body') }}</span>
                                @endif
                            </div>
                        </section>

                        <section class="ac-card" aria-labelledby="ac-h-tfa">
                            <div class="ac-ch">
                                <h2 class="ac-h3" id="ac-h-tfa">{{ $t('tfa_title') }}</h2>
                                @if ($twoFactorOn)
                                    <span class="ac-chip" data-t="ok"><x-bs-admin::icon name="ri-shield-check" class="{{ $small }}" />{{ $t('tfa_on') }}</span>
                                @else
                                    <span class="ac-chip" data-t="warn"><x-bs-admin::icon name="ri-shield" class="{{ $small }}" />{{ $t('tfa_off') }}</span>
                                @endif
                            </div>
                            <div class="ac-cb">
                                <p class="ac-rule text-text">{{ $t('tfa_intro') }}</p>

                                @if (! $twoFactorOn && $tfaStep === 0)
                                    <div class="ac-tfa">
                                        <div><div class="ac-sub">{{ $t('tfa_need') }}</div><p class="ac-rule">{{ $t('tfa_need_body') }}</p></div>
                                        <x-bs-admin::button wire:click="openDialog('enable')" :disabled="$readOnly" :aria-describedby="$lockId">
                                            <x-bs-admin::icon name="ri-shield-keyhole" class="{{ $small }}" />{{ $t('tfa_start') }}
                                        </x-bs-admin::button>
                                    </div>
                                @elseif (! $twoFactorOn && $tfaStep === 1 && $tfaQr)
                                    <div class="flex flex-col gap-3" x-data="{ copied: false }">
                                        <span class="ac-chip self-start" data-t="brand">{{ $t('tfa_step1') }}</span>
                                        <div class="flex flex-wrap items-start gap-4">
                                            <div class="ac-qr" role="img" aria-label="{{ $t('tfa_qr_label') }}">{!! $tfaQr !!}</div>
                                            <div class="flex min-w-0 flex-[1_1_240px] flex-col gap-2">
                                                <ol class="m-0 flex list-decimal flex-col gap-1 pl-5">
                                                    <li>{{ $t('tfa_scan_1') }}</li>
                                                    <li>{{ $t('tfa_scan_2') }}</li>
                                                    <li>{{ $t('tfa_scan_3') }}</li>
                                                </ol>
                                                <div class="ac-sub">{{ $t('tfa_key_label') }}</div>
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <span class="ac-mono">{{ $tfaKey }}</span>
                                                    <x-bs-admin::button variant="outline" color="secondary" size="sm"
                                                                        @click="navigator.clipboard?.writeText({{ \Illuminate\Support\Js::from($tfaRawKey) }}).then(() => copied = true)">
                                                        <x-bs-admin::icon name="ri-file-copy" class="{{ $small }}" />
                                                        <span x-text="copied ? @js($t('copied')) : @js($t('tfa_copy_key'))">{{ $t('tfa_copy_key') }}</span>
                                                    </x-bs-admin::button>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <x-bs-admin::button wire:click="twoFactorNext">{{ $t('next') }}<x-bs-admin::icon name="ri-arrow-right" class="{{ $small }}" /></x-bs-admin::button>
                                            <x-bs-admin::button variant="ghost" wire:click="cancelTwoFactor">{{ __('admin.cancel') }}</x-bs-admin::button>
                                        </div>
                                    </div>
                                @elseif (! $twoFactorOn && $tfaStep === 2)
                                    <div class="flex flex-col gap-3">
                                        <span class="ac-chip self-start" data-t="brand">{{ $t('tfa_step2') }}</span>
                                        <div class="max-w-80">
                                            <label class="ac-lab" for="ac-tfa-code">{{ $t('tfa_code_label') }}</label>
                                            <input id="ac-tfa-code" class="field ac-in ac-mono" inputmode="numeric" autocomplete="one-time-code" maxlength="7" placeholder="123 456"
                                                   wire:model="tfaCode" wire:keydown.enter="confirmTwoFactor" autofocus
                                                   @error('tfaCode') aria-invalid="true" @enderror aria-describedby="ac-tfa-h">
                                            @error('tfaCode')
                                                <p class="ac-err" id="ac-tfa-h"><x-bs-admin::icon name="ri-error-warning" class="mt-0.5 {{ $small }}" />{{ $message }}</p>
                                            @else
                                                <p class="ac-hint" id="ac-tfa-h">{{ $t('tfa_code_hint') }}</p>
                                            @enderror
                                        </div>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <x-bs-admin::button wire:click="confirmTwoFactor" wire:loading.attr="disabled" wire:target="confirmTwoFactor">
                                                <span wire:loading.remove wire:target="confirmTwoFactor">{{ $t('tfa_confirm') }}</span>
                                                <span wire:loading wire:target="confirmTwoFactor">{{ $t('checking') }}</span>
                                            </x-bs-admin::button>
                                            <x-bs-admin::button variant="ghost" wire:click="twoFactorBack"><x-bs-admin::icon name="ri-arrow-left" class="{{ $small }}" />{{ $t('back') }}</x-bs-admin::button>
                                        </div>
                                    </div>
                                @elseif ($twoFactorOn && $tfaStep === 3)
                                    <div class="flex flex-col gap-3" x-data="{ kept: false }">
                                        <span class="ac-chip self-start" data-t="ok"><x-bs-admin::icon name="ri-shield-check" class="{{ $small }}" />{{ $t('tfa_step3') }}</span>
                                        <div class="ac-al" data-k="warning"><x-bs-admin::icon name="ri-alert" class="mt-0.5 {{ $glyph }}" /><span>{{ $t('tfa_codes_warn') }}</span></div>
                                        @include('admin::partials.account-codes', ['codes' => $recoveryCodes, 'label' => $t('codes_label')])
                                        <label class="ac-check"><input type="checkbox" x-model="kept"><span>{{ $t('codes_kept') }}</span></label>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <x-bs-admin::button wire:click="finishTwoFactor" x-bind:disabled="! kept" aria-describedby="ac-done-h">{{ $t('done') }}</x-bs-admin::button>
                                            <span class="ac-hint m-0" id="ac-done-h" x-show="! kept">{{ $t('codes_done_hint') }}</span>
                                        </div>
                                    </div>
                                @elseif ($twoFactorOn)
                                    <div class="ac-tfa">
                                        <div><div class="ac-sub">{{ $t('tfa_since', ['date' => $twoFactorSince]) }}</div><p class="ac-rule">{{ $t('tfa_codes_left', ['count' => $codesLeft]) }}</p></div>
                                        <x-bs-admin::button variant="outline" color="secondary" wire:click="openDialog('regen')" :disabled="$readOnly" :aria-describedby="$lockId">
                                            <x-bs-admin::icon name="ri-refresh" class="{{ $small }}" />{{ $t('regen') }}
                                        </x-bs-admin::button>
                                    </div>
                                    <div class="m-0 h-px bg-border"></div>
                                    <div class="ac-tfa">
                                        <div><div class="ac-sub">{{ $t('tfa_disable') }}</div><p class="ac-rule">{{ $t('tfa_disable_body') }}</p></div>
                                        <x-bs-admin::button variant="outline" color="danger" wire:click="openDialog('disable')" :disabled="$readOnly" :aria-describedby="$lockId">
                                            <x-bs-admin::icon name="ri-shield-cross" class="{{ $small }}" />{{ $t('tfa_disable') }}
                                        </x-bs-admin::button>
                                    </div>
                                @endif
                                @if ($readOnly)
                                    <span class="ac-why"><x-bs-admin::icon name="ri-lock" class="mt-0.5 {{ $small }}" />{{ $t('ro_body') }}</span>
                                @endif
                            </div>
                        </section>
                    @endif

                    {{-- ─────────────── linked sign-in accounts ─────────────── --}}
                    @if ($tab === 'linked')
                        <section class="ac-card" aria-labelledby="ac-h-lk">
                            <div class="ac-ch"><h2 class="ac-h3" id="ac-h-lk">{{ $t('tab_linked') }}</h2></div>
                            <div class="ac-cb">
                                <p class="ac-rule">{{ $t('linked_intro') }}</p>
                                @if (request()->query('oauth') === 'connected')
                                    <x-bs-admin::alert color="success" icon="ri-checkbox-circle">{{ $t('linked_done') }}</x-bs-admin::alert>
                                @elseif ($oauthError = $errors->first('oauth') ?: \App\Modules\Admin\Support\OAuthError::messageFor(request()->query('oauth_error')))
                                    <x-bs-admin::alert color="warning" icon="ri-error-warning">{{ $oauthError }}</x-bs-admin::alert>
                                @endif
                                @if ($providers === [])
                                    <p class="ac-rule">{{ $t('linked_none') }}</p>
                                @else
                                    <div class="ac-card overflow-hidden" aria-describedby="{{ $lockId }}">
                                        <div class="px-4 py-1.5">
                                            @foreach ($providers as $p)
                                                <div class="ac-lrow">
                                                    <x-bs-admin::icon :name="$providerIcons[$p['key']] ?? 'ri-links'" class="size-6 text-text-strong" />
                                                    <div class="flex min-w-0 flex-col gap-0.5">
                                                        <div class="flex flex-wrap items-center gap-2">
                                                            <span class="font-semibold text-text-strong">{{ $p['label'] }}</span>
                                                            <span class="ac-chip" @if ($p['linked']) data-t="ok" @endif>{{ $p['linked'] ? $t('linked_yes') : $t('linked_no') }}</span>
                                                        </div>
                                                        <span class="ac-rule">
                                                            {{ $p['linked'] ? $t('linked_meta', ['email' => $p['email'], 'since' => $p['since']]) : $t('linked_meta_no', ['provider' => $p['label']]) }}
                                                        </span>
                                                    </div>
                                                    <div>
                                                        @if ($p['linked'])
                                                            <x-bs-admin::button variant="outline" color="danger" size="sm"
                                                                                wire:click="openDialog('unlink', '{{ $p['key'] }}')"
                                                                                :disabled="$readOnly || $p['lastWay']" :title="$p['lastWay'] ? $t('linked_last_way') : null">
                                                                <x-bs-admin::icon name="ri-link-unlink-m" class="{{ $small }}" />{{ $t('unlink') }}
                                                            </x-bs-admin::button>
                                                        @elseif ($readOnly)
                                                            <x-bs-admin::button variant="outline" color="secondary" size="sm" disabled>
                                                                <x-bs-admin::icon name="ri-link" class="{{ $small }}" />{{ $t('link') }}
                                                            </x-bs-admin::button>
                                                        @else
                                                            <x-bs-admin::button variant="outline" color="secondary" size="sm" :navigate="false"
                                                                                :href="route('admin.oauth.start', ['provider' => $p['key'], 'purpose' => 'connect'])">
                                                                <x-bs-admin::icon name="ri-link" class="{{ $small }}" />{{ $t('link') }}
                                                            </x-bs-admin::button>
                                                        @endif
                                                    </div>
                                                </div>
                                                @if ($p['lastWay'])
                                                    <p class="ac-hint mb-2 ml-10">{{ $t('linked_last_way') }}</p>
                                                @endif
                                            @endforeach
                                        </div>
                                        <div class="ac-lnote"><x-bs-admin::icon name="ri-magic" class="mt-0.5 {{ $small }}" /><span>{{ $t('linked_auto') }}</span></div>
                                    </div>
                                @endif
                            </div>
                        </section>
                    @endif

                    {{-- ─────────────── preferences ─────────────── --}}
                    @if ($tab === 'prefs')
                        <section class="ac-card" aria-labelledby="ac-h-ui">
                            <div class="ac-ch">
                                <h2 class="ac-h3" id="ac-h-ui">{{ $t('ui_title') }}</h2>
                                <x-bs-admin::saved-note wire:loading.class="invisible" wire:target="setScheme, setTextSize, resetTextSize, setMotion">{{ $savedNote === 'ui' ? $t('applied') : '' }}</x-bs-admin::saved-note>
                            </div>
                            <div class="ac-cb">
                                <p class="ac-rule">{{ $t('ui_intro') }}</p>
                                <fieldset class="m-0 min-w-0 border-0 p-0">
                                    <legend class="ac-lab p-0">{{ $t('lang') }}</legend>
                                    <div class="ac-langs">
                                        @foreach ($locales as $locale)
                                            <label class="ac-opt">
                                                <input type="radio" name="ac-uil" value="{{ $locale['code'] }}" @checked($prefs['locale'] === $locale['code'])
                                                       wire:click="setLocale('{{ $locale['code'] }}')" @disabled($readOnly)>
                                                <img src="{{ asset('images/admin/flags/'.$locale['code'].'.svg') }}" alt="" width="22" height="22">
                                                <span lang="{{ $locale['code'] }}" class="font-medium text-text-strong">{{ $locale['label'] }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                    <p class="ac-hint">{{ $t('lang_hint') }}</p>
                                </fieldset>
                                <div class="ac-f2">
                                    <div class="flex min-w-0 flex-col gap-2">
                                        <span class="ac-lab m-0" id="ac-l-sch">{{ $t('scheme') }}</span>
                                        <div class="seg" role="group" aria-labelledby="ac-l-sch">
                                            @foreach (['light' => ['ri-sun', $t('scheme_light')], 'dark' => ['ri-moon', $t('scheme_dark')], 'auto' => ['ri-computer', $t('scheme_auto')]] as $value => [$icon, $label])
                                                <button type="button" aria-pressed="{{ $prefs['scheme'] === $value ? 'true' : 'false' }}"
                                                        class="inline-flex items-center justify-center gap-1.5" wire:click="setScheme('{{ $value }}')" @disabled($readOnly)>
                                                    <x-bs-admin::icon :name="$icon" class="{{ $small }}" />{{ $label }}
                                                </button>
                                            @endforeach
                                        </div>
                                    </div>
                                    <div class="flex min-w-0 flex-col gap-2">
                                        <span class="ac-lab m-0" id="ac-l-fs">{{ $t('text_size') }}</span>
                                        <div class="seg" role="group" aria-labelledby="ac-l-fs" aria-describedby="ac-fs-h">
                                            @foreach (['md' => [$t('fs_md'), '0.875rem'], 'lg' => [$t('fs_lg'), '1rem'], 'xl' => [$t('fs_xl'), '1.125rem'], 'xxl' => [$t('fs_xxl'), '1.25rem']] as $value => [$label, $size])
                                                <button type="button" aria-pressed="{{ $prefs['textSize'] === $value ? 'true' : 'false' }}"
                                                        style="font-size: {{ $size }}" wire:click="setTextSize('{{ $value }}')" @disabled($readOnly)>
                                                    {{ $label }}@if (! $prefs['ownTextSize'] && $prefs['siteTextSize'] === $value)<span class="whitespace-nowrap">&nbsp;{{ $t('fs_site') }}</span>@endif
                                                </button>
                                            @endforeach
                                        </div>
                                        <p class="ac-hint m-0" id="ac-fs-h">{{ $t('fs_hint') }}</p>
                                        @if ($prefs['ownTextSize'])
                                            <div>
                                                <x-bs-admin::button variant="ghost" wire:click="resetTextSize" :disabled="$readOnly">
                                                    <x-bs-admin::icon name="ri-arrow-go-back" class="{{ $small }}" />{{ $t('fs_reset') }}
                                                </x-bs-admin::button>
                                            </div>
                                        @endif
                                    </div>
                                    <div class="flex min-w-0 flex-col gap-2">
                                        <span class="ac-lab m-0" id="ac-l-mo">{{ $t('motion') }}</span>
                                        <div class="seg" role="group" aria-labelledby="ac-l-mo" aria-describedby="ac-mo-h">
                                            @foreach (['auto' => $t('motion_auto'), 'reduce' => $t('motion_reduce')] as $value => $label)
                                                <button type="button" aria-pressed="{{ $prefs['motion'] === $value ? 'true' : 'false' }}"
                                                        wire:click="setMotion('{{ $value }}')" @disabled($readOnly)>{{ $label }}</button>
                                            @endforeach
                                        </div>
                                        <p class="ac-hint m-0" id="ac-mo-h">{{ $t('motion_hint') }}</p>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <section class="ac-card" aria-labelledby="ac-h-ntf">
                            <div class="ac-ch">
                                <h2 class="ac-h3" id="ac-h-ntf">{{ $t('ntf_title') }}</h2>
                                <x-bs-admin::saved-note wire:loading.class="invisible" wire:target="setNotification">{{ $savedNote === 'ntf' ? $t('applied') : '' }}</x-bs-admin::saved-note>
                            </div>
                            <div class="ac-cb">
                                <p class="ac-rule">{{ $t('ntf_intro') }}</p>
                                @if ($readOnly)
                                    <p class="ac-why"><x-bs-admin::icon name="ri-user-shared" class="mt-0.5 {{ $small }}" /><span>{{ $t('ntf_ro') }}</span></p>
                                @endif
                                <div class="ac-nt" role="table" aria-label="{{ $t('ntf_table') }}">
                                    <div class="ac-nt-hd" role="row">
                                        <span role="columnheader">{{ $t('ntf_kind') }}</span>
                                        <span role="columnheader">{{ $t('ntf_in_app') }}</span>
                                        <span role="columnheader">{{ $t('ntf_mail') }}</span>
                                        <span role="columnheader">Zalo</span>
                                    </div>
                                    @foreach ($noticeGroups as $group)
                                        <div class="ac-nt-g" role="row">
                                            <span role="rowheader"><x-bs-admin::icon :name="$groupIcons[$group['group']]" class="{{ $small }}" />{{ $t('ntf_group_'.$group['group']) }}</span>
                                        </div>
                                        @foreach ($group['rows'] as $row)
                                            @php($label = $t('ntf_k_'.str_replace('.', '_', $row['kind'])))
                                            <div class="ac-nt-r" role="row" wire:key="ntf-{{ $row['kind'] }}">
                                                <div class="flex min-w-0 flex-col gap-0.5" role="cell">
                                                    <span class="ac-nt-l">{{ $label }}</span>
                                                    <span class="ac-nt-d">{{ $t('ntf_d_'.str_replace('.', '_', $row['kind'])) }}</span>
                                                    @if ($row['locked'])
                                                        <span class="ac-nt-lk"><x-bs-admin::icon name="ri-lock" class="mt-0.5 {{ $small }}" />{{ $t('ntf_locked') }}</span>
                                                    @endif
                                                </div>
                                                @foreach (['in_app' => [$row['inApp'], $t('ntf_in_app')], 'mail' => [$row['mail'], $t('ntf_mail')]] as $channel => [$on, $channelLabel])
                                                    <div class="ac-nt-c" role="cell">
                                                        @if ($on === null)
                                                            <span class="ac-nt-s"><span class="ac-nt-cl">{{ $channelLabel }} · </span>{{ $t('later') }}</span>
                                                        @else
                                                            <span class="ac-nt-s"><span class="ac-nt-cl">{{ $channelLabel }} · </span>{{ $row['locked'] ? $t('ntf_always') : ($on ? $t('ntf_state_on') : $t('ntf_state_off')) }}</span>
                                                            <button type="button" class="switch hit-area" role="switch" aria-checked="{{ $on ? 'true' : 'false' }}"
                                                                    aria-label="{{ $channelLabel }}: {{ $label }}"
                                                                    @if ($row['locked']) disabled
                                                                    @else wire:click="setNotification('{{ $row['kind'] }}', '{{ $channel }}', {{ $on ? 'false' : 'true' }})" @disabled($readOnly)
                                                                    @endif></button>
                                                        @endif
                                                    </div>
                                                @endforeach
                                                <div class="ac-nt-z" role="cell">{{ $t('later') }}</div>
                                            </div>
                                        @endforeach
                                    @endforeach
                                </div>
                                <p class="ac-nt-zm"><x-bs-admin::icon name="ri-chat-3" class="{{ $small }}" />{{ $t('ntf_zalo_later') }}</p>
                            </div>
                        </section>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- turn two-factor on · regenerate codes · turn it off · unlink a provider --}}
    <x-bs-admin::modal model="dialogOpen" size="md" :close-label="__('admin.close')">
        <x-slot:heading>
            @if ($dialog === 'enable')
                {{ $t('dlg_enable_title') }}
            @elseif ($dialog === 'disable')
                {{ $t('dlg_disable_title') }}
            @elseif ($dialog === 'regen')
                {{ $t('dlg_regen_title') }}
            @elseif ($dialog === 'codes')
                {{ $t('dlg_codes_title') }}
            @elseif ($dialog === 'unlink')
                {{ $t('dlg_unlink_title', ['provider' => collect($providers)->firstWhere('key', $dialogProvider)['label'] ?? $dialogProvider]) }}
            @endif
        </x-slot:heading>

        @if ($dialog === 'enable' || $dialog === 'disable' || $dialog === 'regen')
            @php($dlgAction = ['enable' => 'startTwoFactor', 'disable' => 'disableTwoFactor', 'regen' => 'regenerateRecoveryCodes'][$dialog])
            <div class="flex items-start gap-3">
                <span class="ac-dic" @if ($dialog === 'disable') data-k="danger" @endif>
                    <x-bs-admin::icon :name="['enable' => 'ri-shield-keyhole', 'disable' => 'ri-shield-cross', 'regen' => 'ri-refresh'][$dialog]" class="size-5" />
                </span>
                <p class="ac-rule">{{ $t('dlg_'.$dialog.'_body') }}</p>
            </div>
            <div>
                <label class="ac-lab" for="ac-dlg-pw">{{ $t('dlg_pw') }}</label>
                <div class="ac-pw">
                    <input id="ac-dlg-pw" class="field" type="password" autocomplete="current-password" autofocus
                           wire:model="confirmPassword" wire:keydown.enter="{{ $dlgAction }}"
                           @error('confirmPassword') aria-invalid="true" aria-describedby="ac-dlg-h" @enderror>
                </div>
                @error('confirmPassword')
                    <p class="ac-err" id="ac-dlg-h"><x-bs-admin::icon name="ri-error-warning" class="mt-0.5 {{ $small }}" />{{ $message }}</p>
                @enderror
            </div>
        @elseif ($dialog === 'codes')
            <p class="ac-rule">{{ $t('dlg_codes_body') }}</p>
            <div class="ac-al" data-k="warning">{{ $t('dlg_codes_warn') }}</div>
            @include('admin::partials.account-codes', ['codes' => $recoveryCodes, 'label' => $t('codes_label_new')])
        @elseif ($dialog === 'unlink')
            @php($providerLabel = collect($providers)->firstWhere('key', $dialogProvider)['label'] ?? $dialogProvider)
            <div class="flex items-start gap-3">
                <span class="ac-dic" data-k="danger"><x-bs-admin::icon name="ri-link-unlink-m" class="size-5" /></span>
                <div class="flex flex-col gap-2">
                    <p class="ac-rule">{{ $t('dlg_unlink_body', ['provider' => $providerLabel]) }}</p>
                    <p class="ac-hint m-0">{{ $t('dlg_unlink_note') }}</p>
                </div>
            </div>
        @endif

        <x-slot:footer>
            @if ($dialog === 'codes')
                <x-bs-admin::button wire:click="closeDialog">{{ $t('done') }}</x-bs-admin::button>
            @else
                <x-bs-admin::button variant="outline" color="secondary" wire:click="closeDialog">{{ __('admin.cancel') }}</x-bs-admin::button>
                @if ($dialog === 'enable')
                    <x-bs-admin::button wire:click="startTwoFactor" wire:loading.attr="disabled" wire:target="startTwoFactor">
                        {{ $t('next') }}<x-bs-admin::icon name="ri-arrow-right" class="{{ $small }}" />
                    </x-bs-admin::button>
                @elseif ($dialog === 'disable')
                    <x-bs-admin::button color="danger" wire:click="disableTwoFactor" wire:loading.attr="disabled" wire:target="disableTwoFactor">
                        <x-bs-admin::icon name="ri-shield-cross" class="{{ $small }}" />{{ $t('tfa_disable') }}
                    </x-bs-admin::button>
                @elseif ($dialog === 'regen')
                    <x-bs-admin::button wire:click="regenerateRecoveryCodes" wire:loading.attr="disabled" wire:target="regenerateRecoveryCodes">
                        <x-bs-admin::icon name="ri-refresh" class="{{ $small }}" />{{ $t('dlg_regen_go') }}
                    </x-bs-admin::button>
                @elseif ($dialog === 'unlink')
                    <x-bs-admin::button color="danger" wire:click="unlinkProvider" wire:loading.attr="disabled" wire:target="unlinkProvider">
                        <x-bs-admin::icon name="ri-link-unlink-m" class="{{ $small }}" />{{ $t('unlink') }}
                    </x-bs-admin::button>
                @endif
            @endif
        </x-slot:footer>
    </x-bs-admin::modal>
</div>
