<x-bs-admin::settings-shell :active="$scope === 'platform' ? 'system-email' : 'email'" :head="false" :unsaved="$changes" :invalid="count($errors->keys())">
    {{-- Flash + OAuth-connect errors --}}
    @if (session('status'))
        <div class="rounded border border-admin-success/30 bg-admin-success/10 px-4 py-3 text-sm text-admin-success-text">{{ session('status') }}</div>
    @endif
    @error('oauth')
        <div class="rounded border border-danger-base/25 bg-danger-soft px-4 py-3 text-sm text-danger-text">{{ $message }}</div>
    @enderror
    @if ($oauthError = \App\Modules\Admin\Support\OAuthError::messageFor(request()->query('oauth_error')))
        <div class="rounded border border-danger-base/25 bg-danger-soft px-4 py-3 text-sm text-danger-text">{{ $oauthError }}</div>
    @endif
    @if (request('oauth') === 'connected')
        <div class="rounded border border-admin-success/30 bg-admin-success/10 px-4 py-3 text-sm text-admin-success-text">{{ __('admin.oauth_connected_ok') }}</div>
    @endif

    @if ($scope === 'platform')
        <div class="rounded border border-admin-warning/40 bg-admin-warning/10 px-4 py-3 text-sm text-admin-warning-text">{{ __('admin.system_default_warning') }}</div>
    @endif

    @if ($scope !== 'platform')
        {{-- A website's page has two tabs (the board's strip; «Mẫu thư» joins them with the mail
             templates). Switching shows the other at once; the server learns the tab with the next
             request, so a redraw keeps it, and the address keeps it for a reload. --}}
        @php
            $tabs = [
                'send' => ['icon' => 'ri-send-plane', 'label' => __('admin.em_tab_send'), 'short' => __('admin.em_tab_send')],
                'sign' => ['icon' => 'ri-quill-pen', 'label' => __('admin.em_tab_sign'), 'short' => __('admin.em_tab_sign_short')],
            ];
        @endphp
        <div class="@container flex flex-col gap-6"
             x-data="{ tab: @js($tab), go(t) { if (this.tab === t) return; this.tab = t; $wire.$set('tab', t, false); try { const u = new URL(location.href); t === 'send' ? u.searchParams.delete('tab') : u.searchParams.set('tab', t); history.replaceState(history.state, '', u) } catch (e) {} } }"
             x-on:email-tab.window="go($event.detail.tab)">
            <div role="tablist" aria-label="{{ __('admin.em_tabs_label') }}"
                 class="flex gap-1 overflow-x-auto overflow-y-hidden border-b border-border-strong [scrollbar-width:none] [&::-webkit-scrollbar]:h-0"
                 x-data="{ step(el, by) { const r = [...$root.querySelectorAll('[role=tab]')]; const n = r[(r.indexOf(el) + by + r.length) % r.length]; n.focus(); n.click() } }">
                @foreach ($tabs as $k => $t)
                    <button type="button" role="tab" id="em-tab-{{ $k }}" aria-controls="em-panel-{{ $k }}" title="{{ $t['label'] }}"
                            aria-selected="{{ $tab === $k ? 'true' : 'false' }}" tabindex="{{ $tab === $k ? 0 : -1 }}"
                            x-bind:aria-selected="tab === @js($k) ? 'true' : 'false'" x-bind:tabindex="tab === @js($k) ? 0 : -1"
                            x-on:click="go(@js($k))"
                            x-on:keydown.right.prevent="step($el, 1)" x-on:keydown.left.prevent="step($el, -1)"
                            class="-mb-px inline-flex min-h-11 shrink-0 cursor-pointer items-center gap-[7px] border-b-2 border-transparent px-4 pt-[11px] pb-[13px] text-[calc(0.84375rem+var(--user-text-boost))] font-medium whitespace-nowrap text-text-muted hover:text-text-strong focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary-text aria-selected:border-primary-text aria-selected:font-semibold aria-selected:text-primary-text @max-[37.5rem]:px-2.5">
                        <x-bs-admin::icon :name="$t['icon']" class="size-[min(1.25rem,calc(1rem+var(--user-text-boost)))] shrink-0 @max-[25rem]:hidden" />
                        <span class="@max-[37.5rem]:hidden">{{ $t['label'] }}</span><span class="hidden @max-[37.5rem]:inline">{{ $t['short'] }}</span>
                    </button>
                @endforeach
            </div>

            <div id="em-panel-send" role="tabpanel" aria-labelledby="em-tab-send" class="flex flex-col gap-6"
                 @if ($tab !== 'send') hidden @endif x-bind:hidden="tab !== 'send'">
        @php
            $obName = fn (string $k): string => __('admin.em_ob_'.$k);
            $statusName = $status['outbox'] === 'other' ? __('admin.em_ob_other_name') : $obName($status['outbox']);
            $rows = [
                'platform' => ['icon' => null, 'meta' => $platformFrom, 'badge' => __('admin.em_default'), 'tip' => null],
                'google' => ['icon' => 'ri-google-fill', 'color' => 'text-[#ea4335]', 'meta' => __('admin.em_meta_google'), 'tip' => __('admin.em_tip_fast')],
                'microsoft' => ['icon' => 'ri-microsoft-fill', 'color' => 'text-[#0078d4]', 'meta' => __('admin.em_meta_microsoft'), 'tip' => null],
                'other' => ['icon' => 'ri-server', 'color' => 'text-primary-text', 'meta' => $status['outbox'] === 'other' && $status['ok'] ? __('admin.em_meta_other_saved') : __('admin.em_meta_other'), 'tip' => __('admin.em_tip_bulk')],
            ];
            if ($this->yahooKept()) {
                $rows['yahoo'] = ['icon' => 'ri-mail', 'color' => 'text-[#6001d2]', 'meta' => __('admin.em_meta_yahoo'), 'tip' => null];
            }
        @endphp
        {{-- The delivery card: does the picked outbox send, and where mail goes meanwhile --}}
        <div @class([
                '@container flex flex-wrap items-center gap-x-3 gap-y-2 rounded-md border px-4 py-3.5',
                'border-success-base/35 bg-success-base/6' => $status['ok'],
                'border-warning-base/50 bg-warning-base/8' => ! $status['ok'],
            ])>
            <span @class([
                    'inline-flex size-[2.125rem] shrink-0 items-center justify-center rounded-full',
                    'bg-success-base/14 text-success-text' => $status['ok'],
                    'bg-warning-base/18 text-warning-text' => ! $status['ok'],
                ])>
                <x-bs-admin::icon :name="$status['ok'] ? 'ri-checkbox-circle-fill' : ($status['outbox'] === 'other' ? 'ri-error-warning-fill' : 'ri-time-fill')" class="size-[min(1.5rem,calc(1.125rem+var(--user-text-boost)))]" />
            </span>
            <div class="flex min-w-0 flex-1 basis-56 flex-col gap-0.5">
                <p class="text-body font-semibold text-text-strong">{{ $status['ok'] ? __('admin.em_status_ok', ['name' => $statusName]) : __('admin.em_status_wait', ['name' => $statusName]) }}</p>
                @unless ($status['ok'])
                    <p class="text-caption leading-normal text-text-muted">
                        {{ $status['outbox'] === 'platform' ? '' : __('admin.em_status_finish') }}
                        {{ $status['fallback'] ? __('admin.em_status_fallback') : ($platformConfigured ? '' : __('admin.em_status_none')) }}
                    </p>
                @endunless
            </div>
            <x-bs-admin::button type="button" variant="outline" class="ms-auto shrink-0 @max-[35rem]:ms-0 @max-[35rem]:w-full @max-[35rem]:justify-center" wire:click="sendTest" wire:loading.attr="disabled" wire:target="sendTest">
                <x-bs-admin::icon name="ri-send-plane" class="size-4" />{{ __('admin.em_test_short') }}
            </x-bs-admin::button>
        </div>

        {{-- The outbox picker: native-feel radios (arrows move between the four, Space picks); the (i) sits beside each, never inside --}}
        <div class="@container rounded-md border border-border-strong bg-surface p-4 shadow-card sm:p-5" x-data="{ info: null }">
            <h2 id="em-outbox-title" class="mb-3 text-caption font-bold tracking-[.04em] text-text-muted uppercase">{{ __('admin.em_outbox_title') }}</h2>
            <div role="radiogroup" aria-labelledby="em-outbox-title" class="flex flex-col gap-2.5"
                 x-data="{ step(el, by) { const r = [...$root.querySelectorAll('[role=radio]')]; r[(r.indexOf(el) + by + r.length) % r.length].focus() } }">
                @foreach ($rows as $k => $r)
                    @php
                        $sel = $provider === $k;
                        $linked = $oauthConnectedProvider === $k;
                        $mailbox = in_array($k, ['google', 'microsoft', 'yahoo'], true);
                        $done = $k === 'other' ? ($status['outbox'] === 'other' && $status['ok']) : $linked;
                    @endphp
                    <div wire:key="ob-{{ $k }}">
                        <div @class([
                                // a narrow card (the board's 560px) puts the state chip and (i) on a line of their own under the text
                                'flex items-center gap-x-3 border-[1.5px] pe-2 @max-[35rem]:flex-wrap',
                                'border-primary-text bg-[color-mix(in_srgb,var(--color-primary-text)_4.5%,var(--color-surface))]' => $sel,
                                'border-border bg-surface' => ! $sel,
                                'rounded-t-md' => $sel && $mailbox,
                                'rounded-md' => ! ($sel && $mailbox),
                            ])>
                            <button type="button" role="radio" aria-checked="{{ $sel ? 'true' : 'false' }}" tabindex="{{ $sel ? 0 : -1 }}"
                                    wire:click="$set('provider', @js($k))"
                                    x-on:keydown.down.prevent="step($el, 1)" x-on:keydown.right.prevent="step($el, 1)"
                                    x-on:keydown.up.prevent="step($el, -1)" x-on:keydown.left.prevent="step($el, -1)"
                                    class="flex min-h-12 min-w-0 flex-1 cursor-pointer items-center gap-3 rounded-md py-[11px] ps-[13px] text-start @max-[35rem]:basis-full @max-[35rem]:pb-1 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-text">
                                <span @class(['inline-flex size-[18px] shrink-0 items-center justify-center rounded-full border-2', 'border-primary-text' => $sel, 'border-border-input' => ! $sel])>
                                    @if ($sel)<span class="size-2 rounded-full bg-primary-text"></span>@endif
                                </span>
                                @if ($r['icon'])
                                    <x-bs-admin::icon :name="$r['icon']" @class(['size-[min(1.5rem,calc(1.25rem+var(--user-text-boost)))] shrink-0', $r['color'] ?? '']) />
                                @endif
                                <span class="flex min-w-0 flex-col gap-0.5">
                                    <span class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                        <b class="text-body font-semibold text-text-strong [overflow-wrap:anywhere]">{{ $obName($k) }}</b>
                                        @if (($r['badge'] ?? null) !== null)
                                            <span class="rounded bg-primary-text/12 px-2 py-px text-small font-bold text-primary-text">{{ $r['badge'] }}</span>
                                        @endif
                                        @if ($r['tip'] !== null)
                                            <span class="rounded bg-success-base/12 px-2 py-px text-small font-bold tracking-[.02em] whitespace-nowrap text-success-text">{{ $r['tip'] }}</span>
                                        @endif
                                    </span>
                                    <span class="text-caption text-text-muted [overflow-wrap:anywhere]">{{ $linked ? $oauthConnectedEmail : $r['meta'] }}</span>
                                </span>
                            </button>
                            <div class="flex shrink-0 items-center gap-2 @max-[35rem]:ms-[2.9375rem] @max-[35rem]:basis-[calc(100%-3.5rem)] @max-[35rem]:pb-1.5">
                            @if ($k !== 'platform')
                                <span @class([
                                        'inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-[3px] text-small font-semibold whitespace-nowrap',
                                        'bg-success-base/12 text-success-text' => $done,
                                        'bg-surface-muted text-text-muted' => ! $done,
                                    ])>
                                    <span @class(['size-[7px] shrink-0 rounded-full', 'bg-success-base' => $done, 'border-[1.5px] border-dashed border-text-muted' => ! $done])></span>
                                    {{ $k === 'other' ? __($done ? 'admin.em_set' : 'admin.em_not_set') : __($done ? 'admin.em_connected' : 'admin.em_not_connected') }}
                                </span>
                            @endif
                            <button type="button" x-on:click="info = info === @js($k) ? null : @js($k)"
                                    x-bind:aria-expanded="info === @js($k) ? 'true' : 'false'" aria-controls="em-info-{{ $k }}"
                                    aria-label="{{ __('admin.em_explain') }}" title="{{ __('admin.em_explain') }}"
                                    class="hit-area inline-flex size-[calc(1.75rem+var(--user-text-boost))] shrink-0 items-center justify-center rounded-full text-text-muted hover:bg-primary-soft hover:text-primary-text">
                                <x-bs-admin::icon name="ri-information" class="size-[min(1.5rem,calc(1.125rem+var(--user-text-boost)))]" />
                            </button>
                            </div>
                        </div>
                        @if ($sel && $mailbox)
                            {{-- Sign in to the mailbox. The website's own app at the provider, until the platform has one approved. --}}
                            <div role="group" aria-label="{{ __('admin.em_connect_with', ['name' => $obName($k)]) }}" class="space-y-4 rounded-b-md border-[1.5px] border-t-0 border-primary-text px-4 py-3.5">
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <x-bs-admin::input :label="__('admin.client_id')" wire:model.live.blur="oauthClientId" />
                                    <div>
                                        <x-bs-admin::input :label="__('admin.client_secret')" type="password" wire:model.live.blur="oauthClientSecret" autocomplete="new-password"
                                                        :placeholder="$oauthSecretSet ? __('admin.secret_stored') : ''" />
                                        @if ($oauthSecretSet)
                                            <p class="mt-1 text-caption text-text-muted">{{ __('admin.leave_blank_keep') }}</p>
                                        @endif
                                    </div>
                                </div>
                                <div class="space-y-2 rounded border border-info-base/30 bg-info-soft p-4 text-caption">
                                    <p class="font-semibold text-text-strong">{{ __('admin.oauth_setup_guide') }}
                                        <a href="{{ config('oauth.providers.'.$k.'.console_url') }}" target="_blank" rel="noopener noreferrer" class="ms-1 font-medium text-primary-text hover:underline">{{ __('admin.oauth_open_console', ['provider' => ucfirst($k)]) }} ↗</a>
                                    </p>
                                    <ol class="list-decimal space-y-1 ps-5 text-text-muted">
                                        <li>{{ __('admin.oauth_guide_step1') }}</li>
                                        <li>{{ __('admin.oauth_guide_step2') }}</li>
                                        <li>{{ __('admin.oauth_guide_step3') }}</li>
                                        <li>{{ __('admin.oauth_guide_connect') }}</li>
                                    </ol>
                                    @if ($k === 'google')
                                        <p class="text-text-muted">{{ __('admin.oauth_guide_google_tip') }}</p>
                                    @endif
                                    <label for="em-redirect-{{ $k }}" class="block pt-1 font-medium text-text-muted">{{ __('admin.redirect_uri') }}</label>
                                    <div class="flex items-center gap-2" x-data="{ copied: false }">
                                        <input id="em-redirect-{{ $k }}" type="text" readonly value="{{ \App\Modules\Admin\Support\OAuthFlow::callbackUrl() }}"
                                               class="field block w-full select-all rounded border border-border-strong bg-surface px-3 font-mono text-text">
                                        <x-bs-admin::button type="button" variant="soft" size="sm"
                                                         x-on:click="navigator.clipboard.writeText({{ \Illuminate\Support\Js::from(\App\Modules\Admin\Support\OAuthFlow::callbackUrl()) }}); copied = true; setTimeout(() => copied = false, 1500)">
                                            <span x-show="!copied">{{ __('admin.copy') }}</span><span x-show="copied" x-cloak>{{ __('admin.copied') }}</span>
                                        </x-bs-admin::button>
                                    </div>
                                    <p class="text-text-muted">{{ __('admin.oauth_redirect_uri_shared') }}</p>
                                </div>
                                <div class="flex flex-wrap items-center gap-2.5">
                                    @if ($linked)
                                        <x-bs-admin::button type="button" variant="outline" wire:click="sendTest" wire:loading.attr="disabled" wire:target="sendTest">
                                            <x-bs-admin::icon name="ri-send-plane" class="size-4" />{{ __('admin.em_resend_test') }}
                                        </x-bs-admin::button>
                                        <x-bs-admin::button type="button" variant="ghost" color="danger"
                                                         x-on:click="$bsConfirm({ text: {{ \Illuminate\Support\Js::from(__('admin.em_disconnect_confirm', ['email' => $oauthConnectedEmail])) }}, confirm: {{ \Illuminate\Support\Js::from(__('admin.em_disconnect')) }}, cancel: {{ \Illuminate\Support\Js::from(__('admin.cancel')) }} }).then(ok => ok && $wire.disconnect())">
                                            <x-bs-admin::icon name="ri-link-unlink-m" class="size-4" />{{ __('admin.em_disconnect') }}
                                        </x-bs-admin::button>
                                        <x-bs-admin::button type="button" variant="soft" wire:click="connect">{{ __('admin.oauth_reconnect') }}</x-bs-admin::button>
                                    @else
                                        <x-bs-admin::button type="button" wire:click="connect">
                                            <x-bs-admin::icon :name="$r['icon']" class="size-4" />{{ __('admin.em_connect_with', ['name' => ucfirst($k)]) }}
                                        </x-bs-admin::button>
                                        <span class="text-caption text-text-muted">{{ __('admin.oauth_save_first') }}</span>
                                    @endif
                                </div>
                            </div>
                        @endif
                        <div id="em-info-{{ $k }}" role="note" x-show="info === @js($k)" x-cloak
                             class="mt-1.5 rounded-md border border-border-strong bg-surface px-4 py-3 shadow-card"
                             x-on:keydown.escape.stop="info = null">
                            <div class="flex items-start justify-between gap-3">
                                <p class="text-body font-semibold text-text-strong">{{ __('admin.em_info_'.$k.'_t') }}</p>
                                <button type="button" x-on:click="info = null" aria-label="{{ __('admin.em_explain_close') }}" title="{{ __('admin.em_explain_close') }}"
                                        class="hit-area -me-1 -mt-0.5 inline-flex size-7 shrink-0 items-center justify-center rounded text-text-muted hover:bg-surface-hover">
                                    <x-bs-admin::icon name="ri-close" class="size-4" />
                                </button>
                            </div>
                            <p class="mt-1 text-caption leading-relaxed text-text-muted">
                                @if ($k === 'platform')
                                    {{ __('admin.em_info_platform', ['name' => $fromName !== '' ? $fromName : $siteName, 'address' => $platformFrom]) }}
                                    @if ($replyTo !== '') {{ __('admin.em_info_platform_reply', ['reply' => $replyTo]) }} @endif
                                @elseif ($k === 'other')
                                    {{ __('admin.em_info_other') }}
                                @else
                                    {{ __('admin.em_info_mailbox') }}
                                @endif
                            </p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Configuration form (a website's page: the end of «Gửi thư») --}}
    {{-- Enter in a field is the save bar's "Lưu" (it waits while a save runs and does nothing with nothing to save); the form itself never submits, so the page never reloads --}}
    <form x-data x-on:submit.prevent class="overflow-hidden rounded-md border border-border bg-surface shadow-card">
        <div class="border-b border-border px-5 py-3">
            {{-- The console's page says what it is in the warning above; a website's names what it holds now: its own server, or who the mail is from. --}}
            <h2 class="text-[15px] font-semibold text-text-strong">{{ $scope === 'platform' ? __('admin.email_settings') : ($provider === 'other' ? __('admin.em_info_other_t') : __('admin.em_sender_title')) }}</h2>
        </div>
        <div class="space-y-5 p-5">
            @if ($scope === 'platform' || $provider === 'other')
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-bs-admin::input :label="__('admin.smtp_host')" wire:model.live.blur="smtpHost" placeholder="smtp.gmail.com" />
                    <x-bs-admin::input :label="__('admin.smtp_port')" type="number" wire:model.live.blur="smtpPort" placeholder="587" />
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-body-text">{{ __('admin.smtp_encryption') }}</label>
                        <select wire:model.live="smtpEncryption" class="field block w-full rounded border border-border-strong bg-surface px-3 text-text focus:border-primary-text">
                            <option value="tls">{{ __('admin.encryption_tls') }}</option>
                            <option value="ssl">{{ __('admin.encryption_ssl') }}</option>
                            <option value="null">{{ __('admin.encryption_none') }}</option>
                        </select>
                    </div>
                    <x-bs-admin::input :label="__('admin.smtp_username')" wire:model.live.blur="smtpUsername" placeholder="info@example.com" />
                    <div class="sm:col-span-2">
                        <x-bs-admin::input :label="__('admin.smtp_password')" type="password" wire:model.live.blur="smtpPassword" autocomplete="new-password"
                                        :placeholder="$smtpPasswordSet ? __('admin.secret_stored') : ''" />
                        @if ($smtpPasswordSet)
                            <p class="mt-1 text-xs text-body-muted">{{ __('admin.leave_blank_keep') }}</p>
                        @endif
                    </div>
                </div>
            @endif

            {{-- Sender identity --}}
            <div class="grid gap-4 border-t border-border-soft pt-5 sm:grid-cols-2">
                <x-bs-admin::input :label="__('admin.from_address')" type="email" wire:model.live.blur="fromAddress" placeholder="no-reply@example.com" />
                <x-bs-admin::input :label="__('admin.from_name')" wire:model.live.blur="fromName" :placeholder="config('admin.brand.name')" />
                @if ($scope !== 'platform')
                    <div class="sm:col-span-2">
                        <x-bs-admin::input :label="__('admin.reply_to')" type="email" wire:model.live.blur="replyTo" placeholder="lienhe@congty-cua-ban.com" />
                        <p class="mt-1 text-xs text-body-muted">{{ __('admin.reply_to_hint') }}</p>
                    </div>
                @endif
            </div>

            {{-- Actions --}}
            <div class="flex flex-wrap items-center gap-3 border-t border-border-soft pt-5">
                <x-bs-admin::button type="button" variant="outline" wire:click="sendTest" wire:loading.attr="disabled" wire:target="sendTest">{{ __('admin.send_test_email') }}</x-bs-admin::button>
                @if ($testResult === 'ok')
                    <span class="text-sm text-admin-success-text">{{ __('admin.test_email_sent') }}</span>
                @elseif ($testResult === 'error')
                    <span class="text-sm text-admin-danger-text">{{ __('admin.test_email_failed') }}</span>
                @endif
            </div>
        </div>
    </form>

    @if ($scope !== 'platform')
            </div>{{-- #em-panel-send --}}

            <div id="em-panel-sign" role="tabpanel" aria-labelledby="em-tab-sign" class="flex flex-col gap-[18px]"
                 @if ($tab !== 'sign') hidden @endif x-bind:hidden="tab !== 'sign'">
                @include('admin::livewire.email-settings-sign', ['sign' => $sign])
            </div>
        </div>

        <x-bs-admin::toast :message="$toast" :tone="$toastTone" dismiss="dismissToast" :seq="$toastSeq" />
        @include('admin::partials.media-picker-modal', ['pickedIds' => []])
    @endif
</x-bs-admin::settings-shell>
