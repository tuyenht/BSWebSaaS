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
                'other' => ['icon' => 'ri-server', 'color' => 'text-primary-text', 'meta' => $status['outbox'] === 'other' && $status['ok'] ? __('admin.em_meta_other_saved', ['name' => $storedRelay === 'smtp' ? __('admin.em_meta_other_smtp') : __('admin.em_rl_'.$storedRelay)]) : __('admin.em_meta_other'), 'tip' => __('admin.em_tip_bulk')],
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
                            @elseif ($quota !== null)
                                {{-- Today's mails left on the shared outbox, the bar filled by the share already used; hidden in a narrow card, as on the board --}}
                                <span class="inline-flex shrink-0 items-center gap-[7px] text-caption whitespace-nowrap text-text @max-[35rem]:hidden">
                                    {{ __('admin.em_quota_left', ['n' => \Illuminate\Support\Number::format($quota['left'], locale: app()->getLocale())]) }}
                                    <span class="inline-block h-[5px] w-11 overflow-hidden rounded-full bg-border-strong" aria-hidden="true"><span class="block h-full bg-success-base" style="width: {{ $quota['used_pct'] }}%"></span></span>
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
                                    @if ($quota !== null) {{ __('admin.em_info_platform_quota', ['limit' => \Illuminate\Support\Number::format($quota['limit'], locale: app()->getLocale())]) }} @endif
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

        @if ($provider === 'other')
            @include('admin::livewire.email-settings-relay')
        @endif
    @endif

    @if ($scope === 'platform')
    {{-- The console's «Email hệ thống»: the platform's own server and sender (a website's page draws the card below) --}}
    {{-- Enter in a field is the save bar's "Lưu" (it waits while a save runs and does nothing with nothing to save); the form itself never submits, so the page never reloads --}}
    <form x-data x-on:submit.prevent class="overflow-hidden rounded-md border border-border bg-surface shadow-card">
        <div class="border-b border-border px-5 py-3">
            {{-- The console's page says what it is in the warning above; a website's is who the mail is from (its own service is set up in «Chọn dịch vụ»). --}}
            <h2 class="text-[15px] font-semibold text-text-strong">{{ $scope === 'platform' ? __('admin.email_settings') : __('admin.em_sender_title') }}</h2>
        </div>
        <div class="space-y-5 p-5">
            @if ($scope === 'platform')
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
    @else
        {{-- «Tên và địa chỉ người gửi» beside «Nhận thông báo liên hệ» (one column on a narrow page), the
             test send under both. Enter in a field is the save bar's "Lưu"; the form itself never submits. --}}
        @php
            // An address may wrap only after its "@" and its dots (each piece kept whole).
            $mailParts = function (string $text): \Illuminate\Support\HtmlString {
                $pieces = preg_split('/(?<=[@.])/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

                return new \Illuminate\Support\HtmlString(implode('<wbr>', array_map(fn (string $p): string => '<span class="whitespace-nowrap">'.e($p).'</span>', $pieces)));
            };
            $lab = 'text-[calc(0.8125rem+var(--user-text-boost))] font-medium text-text-strong';
            $notifyErr = $errors->first('notifyTo');
            $replyWho = '<b class="font-semibold text-text-strong">'.e(__('admin.em_notify_reply_who')).'</b>';
        @endphp
        <form x-data x-on:submit.prevent class="rounded-md border border-border-strong bg-surface p-4 shadow-card">
            <div class="grid grid-cols-1 items-start gap-y-[18px] @min-[56.25rem]:grid-cols-2">
                <div class="min-w-0 [overflow-wrap:anywhere] @min-[56.25rem]:pe-6">
                    <h2 class="mb-2.5 text-caption font-bold tracking-[.04em] text-text-muted uppercase">{{ __('admin.em_sender_title') }}</h2>
                    {{-- The sender line as a customer's mailbox shows it --}}
                    <div class="mb-3.5 flex min-w-0 items-center gap-2 rounded border border-dashed border-border bg-surface-muted px-[11px] py-[9px] text-[calc(0.8125rem+var(--user-text-boost))] leading-normal" data-sender-preview>
                        <x-bs-admin::icon name="ri-mail" class="size-[min(1.5rem,calc(0.875rem+var(--user-text-boost)))] shrink-0 text-text-muted" />
                        @if ($sender['address'] !== '')
                            <span class="min-w-0 flex-auto text-text [overflow-wrap:normal]">{{ $sender['name'] }} {{ $mailParts('<'.$sender['address'].'>') }}</span>
                        @else
                            <span class="min-w-0 flex-auto text-text-muted">{{ $sender['empty'] }}</span>
                        @endif
                    </div>
                    <div class="flex flex-col gap-3.5">
                        <div class="flex flex-col gap-1.5">
                            <label for="em-from-name" class="{{ $lab }}">{{ __('admin.em_from_name') }}</label>
                            <x-bs-admin::input id="em-from-name" name="em_sender_name" autocomplete="organization" wire:model.live.blur="fromName" />
                        </div>
                        @if ($sender['edit'])
                            {{-- «Nhà cung cấp khác»: the part before @ is typed, the domain is the one it sends from --}}
                            <div class="flex flex-col gap-1.5">
                                <span class="flex items-center gap-1"><label for="em-from-local" class="{{ $lab }}">{{ __('admin.em_from_addr') }}</label><x-bs-admin::help-pop :label="__('admin.em_from_help')">{{ __('admin.em_from_help_edit') }}</x-bs-admin::help-pop></span>
                                <span class="flex items-stretch">
                                    <input id="em-from-local" type="text" name="em_from_local" autocomplete="off" spellcheck="false" wire:model.live.blur="fromLocal"
                                           x-on:input="$el.value = $el.value.replace(/[^a-zA-Z0-9._-]/g, '')" placeholder="{{ $sender['defaultLocal'] }}"
                                           aria-label="{{ __('admin.em_from_local') }}" @error('fromLocal') aria-invalid="true" aria-describedby="em-from-local-err" @else @if ($sender['keptNote'] !== '') aria-describedby="em-from-kept" @endif @enderror
                                           class="field block min-w-0 flex-1 rounded-s border border-e-0 border-border-input bg-surface px-3 text-text placeholder:text-placeholder transition-colors focus:border-primary-text aria-invalid:border-danger-text">
                                    <span class="inline-flex items-center rounded-e border border-border-input bg-surface-hover px-3 text-[calc(0.84375rem+var(--user-text-boost))] whitespace-nowrap text-text-muted">{{ '@'.($sender['suffix'] !== '' ? $sender['suffix'] : '—') }}</span>
                                </span>
                                @error('fromLocal')
                                    <x-bs-admin::field-error id="em-from-local-err">{{ $message }}</x-bs-admin::field-error>
                                @else
                                    @if ($sender['keptNote'] !== '')
                                        {{-- A stored address on another domain than the one it sends from now stays as it is until edited --}}
                                        <span id="em-from-kept" class="text-caption leading-normal text-text-muted" data-from-kept>{{ $sender['keptNote'] }}</span>
                                    @endif
                                @enderror
                            </div>
                        @elseif ($sender['locked'] !== '')
                            {{-- A signed-in Google / Microsoft / Yahoo mailbox, or «Email khác» on a public mailbox, sends as itself: shown, never typed --}}
                            <div class="flex flex-col gap-1.5">
                                <span class="flex items-center gap-1"><span id="em-from-ro-l" class="{{ $lab }}">{{ __('admin.em_from_addr') }}</span><x-bs-admin::help-pop :label="__('admin.em_from_help')">{{ $sender['lockedHelp'] }}</x-bs-admin::help-pop></span>
                                <span role="textbox" aria-readonly="true" aria-labelledby="em-from-ro-l" data-from-locked
                                      class="flex min-w-0 items-center gap-2 rounded border border-border-strong bg-surface-hover px-[11px] py-[9px] text-[calc(0.875rem+var(--user-text-boost))] text-text-muted">
                                    <x-bs-admin::icon name="ri-lock" class="size-[min(1.5rem,calc(0.875rem+var(--user-text-boost)))] shrink-0" />
                                    <span class="min-w-0 [overflow-wrap:anywhere]">{{ $sender['locked'] }}</span>
                                </span>
                            </div>
                        @endif
                        <div class="flex flex-col gap-1.5">
                            <span class="flex items-center gap-1"><label for="em-reply-to" class="{{ $lab }}">{{ __('admin.em_reply_label') }}</label><x-bs-admin::help-pop :label="__('admin.em_reply_help')">{{ __('admin.em_reply_help_text') }}</x-bs-admin::help-pop></span>
                            <x-bs-admin::input id="em-reply-to" type="email" name="em_reply_to" autocomplete="off" inputmode="email" wire:model.live.blur="replyTo" />
                        </div>
                    </div>
                </div>

                <div class="min-w-0 [overflow-wrap:anywhere] @min-[56.25rem]:border-s @min-[56.25rem]:border-border @min-[56.25rem]:ps-6">
                    <h2 class="mb-3.5 text-caption font-bold tracking-[.04em] text-text-muted uppercase">{{ __('admin.em_notify_title') }}</h2>
                    {{-- «Gửi thông báo tới»: Enter, a comma or Tab adds what is typed (leaving the box too);
                         Backspace in the empty box takes the last address off. The server checks each one. --}}
                    <div class="flex flex-col gap-1.5"
                         x-data="{ draft: '', add() { const v = this.draft.trim().replace(/,$/, ''); if (v === '') return; this.draft = ''; $wire.addNotify(v) } }">
                        <span class="flex items-center gap-1"><span id="em-notify-l" class="{{ $lab }}">{{ __('admin.em_notify_label') }}</span><x-bs-admin::help-pop :label="__('admin.em_notify_help')">{{ __('admin.em_notify_help_text') }}</x-bs-admin::help-pop></span>
                        <div @class([
                                'flex min-h-11 w-full flex-wrap items-center gap-1.5 rounded border bg-surface px-2 py-1.5 transition-colors has-[input:focus-visible]:outline-2 has-[input:focus-visible]:outline-offset-2 has-[input:focus-visible]:outline-primary-text',
                                'border-danger-text' => $notifyErr !== '',
                                'border-border-input has-[input:focus]:border-primary-text' => $notifyErr === '',
                            ]) role="group" aria-labelledby="em-notify-l">
                            @foreach ($notifyTo as $i => $email)
                                @php $ok = \App\Modules\Forms\Support\FormDraft::isEmail($email); @endphp
                                <span wire:key="em-nt-{{ $i }}-{{ md5($email) }}" data-notify-chip @unless ($ok) data-bad @endunless
                                      @class(['inline-flex max-w-full items-center gap-[5px] rounded-full py-[3px] ps-2.5 pe-1 text-[calc(0.8125rem+var(--user-text-boost))]',
                                              'bg-surface-muted text-primary-text' => $ok, 'bg-danger-base/12 text-danger-text' => ! $ok])>
                                    <span class="min-w-0 [overflow-wrap:normal]">{{ $mailParts($email) }}</span>
                                    <button type="button" wire:click="removeNotify({{ \Illuminate\Support\Js::from($email) }})" aria-label="{{ __('admin.em_notify_remove', ['email' => $email]) }}" title="{{ __('admin.em_notify_remove', ['email' => $email]) }}"
                                            class="hit-area-row inline-flex size-6 shrink-0 cursor-pointer items-center justify-center rounded-full [--hit-gap:0.625rem] hover:bg-surface-hover">
                                        <x-bs-admin::icon name="ri-close" class="size-3.5" />
                                    </button>
                                </span>
                            @endforeach
                            <input id="em-notify-in" type="text" inputmode="email" autocomplete="off" spellcheck="false" x-model="draft"
                                   x-on:keydown="if ($event.isComposing) return; if (['Enter', ',', 'Tab'].includes($event.key) && draft.trim() !== '') { $event.preventDefault(); add() } else if ($event.key === 'Backspace' && draft === '' && $wire.notifyTo.length > 0) { $wire.removeNotify($wire.notifyTo[$wire.notifyTo.length - 1]) }"
                                   x-on:blur="add()"
                                   aria-label="{{ __('admin.em_notify_input') }}" placeholder="{{ $notifyTo === [] ? __('admin.em_notify_ph', ['domain' => $sender['placeholderDomain']]) : '' }}"
                                   @if ($notifyErr !== '') aria-invalid="true" aria-describedby="em-notify-err" @endif
                                   class="min-h-control min-w-[7.5rem] flex-[1_1_7.5rem] border-0 bg-transparent px-0.5 text-[calc(0.875rem+var(--user-text-boost))] text-text placeholder:text-placeholder focus-visible:outline-none any-pointer-coarse:min-h-hit">
                        </div>
                        @if ($sender['suggestions'] !== [])
                            <div class="mt-[3px] flex flex-wrap gap-2">
                                @foreach ($sender['suggestions'] as $sg)
                                    <button type="button" wire:click="addNotify({{ \Illuminate\Support\Js::from($sg) }})" wire:key="em-sg-{{ md5($sg) }}"
                                            class="inline-flex min-h-control max-w-full cursor-pointer items-center rounded-full border border-dashed border-border-input bg-surface px-3 py-1 text-start text-caption text-primary-text transition-colors hover:bg-primary-soft any-pointer-coarse:min-h-hit">
                                        <span class="min-w-0 [overflow-wrap:normal]">{{ $mailParts('+'.$sg) }}</span>
                                    </button>
                                @endforeach
                            </div>
                        @endif
                        @if ($notifyErr !== '')
                            <x-bs-admin::field-error id="em-notify-err">{{ $notifyErr }}</x-bs-admin::field-error>
                        @endif
                    </div>
                    {{-- The board says the notices follow the member's profile language; a new-lead notice is sent
                         in the website's main language, so the line says that instead. --}}
                    @php($siteLang = app(\App\Modules\Tenancy\Context\SiteLocale::class)->default())
                    <div class="mt-2.5 flex items-start gap-[9px] text-[calc(0.78125rem+var(--user-text-boost))] leading-relaxed text-text-muted">
                        <x-bs-admin::icon name="ri-translate-2" class="mt-0.5 size-[min(1.5rem,calc(0.875rem+var(--user-text-boost)))] shrink-0 text-text-muted" />
                        <p class="min-w-0">{!! str_replace('%lang%', '<b class="font-semibold text-text">'.e(config('locales.labels.'.$siteLang) ?? strtoupper($siteLang)).'</b>', e(__('admin.em_notify_lang', ['lang' => '%lang%']))) !!}</p>
                    </div>
                    <div class="mt-3 flex items-start gap-[9px] rounded border border-info-base/28 bg-info-base/7 px-3 py-[11px]">
                        <x-bs-admin::icon name="ri-reply" class="mt-px size-[min(1.5rem,calc(0.9375rem+var(--user-text-boost)))] shrink-0 text-info-text" />
                        <p class="min-w-0 text-[calc(0.78125rem+var(--user-text-boost))] leading-relaxed text-text">{!! str_replace('%who%', $replyWho, e(__('admin.em_notify_reply', ['who' => '%who%']))) !!}</p>
                    </div>
                </div>

                {{-- The test send (to the member who presses it) --}}
                <div class="col-span-full mt-1.5 flex flex-wrap items-center gap-3 border-t border-border pt-4">
                    <x-bs-admin::button type="button" variant="outline" wire:click="sendTest" wire:loading.attr="disabled" wire:target="sendTest">{{ __('admin.send_test_email') }}</x-bs-admin::button>
                    @if ($testResult === 'ok')
                        <span class="text-caption text-success-text">{{ __('admin.test_email_sent') }}</span>
                    @elseif ($testResult === 'error')
                        <span class="text-caption text-danger-text">{{ __('admin.test_email_failed') }}</span>
                    @endif
                </div>
            </div>
        </form>
    @endif

    @if ($scope !== 'platform')
                <livewire:admin.email-recent-mail wire:key="em-recent-mail" />
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
