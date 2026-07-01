<x-admin::settings-shell :active="$scope === 'platform' ? 'system-email' : 'email'">
    {{-- Flash + OAuth-connect errors --}}
    @if (session('status'))
        <div class="rounded border border-admin-success/30 bg-admin-success/10 px-4 py-3 text-sm text-admin-success">{{ session('status') }}</div>
    @endif
    @error('oauth')
        <div class="rounded border border-admin-danger/30 bg-admin-danger/10 px-4 py-3 text-sm text-admin-danger">{{ $message }}</div>
    @enderror
    @if (request('oauth_error'))
        <div class="rounded border border-admin-danger/30 bg-admin-danger/10 px-4 py-3 text-sm text-admin-danger">{{ request('oauth_error') }}</div>
    @endif
    @if (request('oauth') === 'connected')
        <div class="rounded border border-admin-success/30 bg-admin-success/10 px-4 py-3 text-sm text-admin-success">{{ __('admin.oauth_connected_ok') }}</div>
    @endif

    @if ($scope === 'platform')
        <div class="rounded border border-admin-warning/40 bg-admin-warning/10 px-4 py-3 text-sm text-admin-warning">{{ __('admin.system_default_warning') }}</div>
    @endif

    @if ($scope !== 'platform')
    {{-- Delivery status (hybrid: tenant override → platform default → log) --}}
    <div class="overflow-hidden rounded-md border border-gray-200 bg-white shadow-card">
        <div class="border-b border-gray-200 px-5 py-3">
            <h2 class="text-[15px] font-semibold text-gray-700">{{ __('admin.email_delivery') }}</h2>
        </div>
        <div class="space-y-3 p-5">
            @if ($hasOverride)
                <div class="flex flex-wrap items-center gap-3">
                    <span class="inline-flex items-center rounded bg-admin-success/10 px-2 py-0.5 text-xs font-semibold text-admin-success">{{ __('admin.tenant_override_active') }}</span>
                    <x-admin::button color="danger" variant="soft" size="sm" wire:click="clearOverride" wire:confirm="{{ __('admin.clear_override_confirm') }}">{{ __('admin.clear_override') }}</x-admin::button>
                </div>
            @elseif ($platformConfigured)
                <span class="inline-flex items-center rounded bg-admin-info/10 px-2 py-0.5 text-xs font-semibold text-admin-info">{{ __('admin.platform_default_active') }}</span>
                <p class="text-sm text-gray-600">{{ __('admin.platform_default_desc') }}</p>
            @else
                <span class="inline-flex items-center rounded bg-admin-warning/10 px-2 py-0.5 text-xs font-semibold text-admin-warning">{{ __('admin.no_email_configured') }}</span>
            @endif
        </div>
    </div>

    @endif

    {{-- Configuration form --}}
    <form wire:submit="save" class="overflow-hidden rounded-md border border-gray-200 bg-white shadow-card">
        <div class="border-b border-gray-200 px-5 py-3">
            <h2 class="text-[15px] font-semibold text-gray-700">{{ __('admin.email_settings') }}</h2>
            <p class="mt-0.5 text-xs text-gray-500">{{ __('admin.email_settings_desc') }}</p>
        </div>
        <div class="space-y-5 p-5">
            @if ($scope !== 'platform')
            {{-- Method selector --}}
            <div class="inline-flex rounded-md border border-gray-200 p-0.5">
                <label @class(['cursor-pointer rounded px-3 py-1.5 text-sm font-medium transition-colors', 'bg-admin-primary text-white' => $method === 'smtp_password', 'text-gray-600 hover:bg-gray-100' => $method !== 'smtp_password'])>
                    <input type="radio" wire:model.live="method" value="smtp_password" class="sr-only">{{ __('admin.email_method_smtp') }}
                </label>
                <label @class(['cursor-pointer rounded px-3 py-1.5 text-sm font-medium transition-colors', 'bg-admin-primary text-white' => $method === 'oauth2', 'text-gray-600 hover:bg-gray-100' => $method !== 'oauth2'])>
                    <input type="radio" wire:model.live="method" value="oauth2" class="sr-only">{{ __('admin.email_method_oauth2') }}
                </label>
            </div>

            @endif

            @if ($method === 'smtp_password')
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-admin::input :label="__('admin.smtp_host')" wire:model="smtpHost" placeholder="smtp.gmail.com" />
                    <x-admin::input :label="__('admin.smtp_port')" type="number" wire:model="smtpPort" placeholder="587" />
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-gray-700">{{ __('admin.smtp_encryption') }}</label>
                        <select wire:model="smtpEncryption" class="block w-full rounded border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 focus:border-admin-primary focus:outline-none focus:ring-2 focus:ring-admin-primary/20">
                            <option value="tls">{{ __('admin.encryption_tls') }}</option>
                            <option value="ssl">{{ __('admin.encryption_ssl') }}</option>
                            <option value="null">{{ __('admin.encryption_none') }}</option>
                        </select>
                    </div>
                    <x-admin::input :label="__('admin.smtp_username')" wire:model="smtpUsername" placeholder="info@example.com" />
                    <div class="sm:col-span-2">
                        <x-admin::input :label="__('admin.smtp_password')" type="password" wire:model="smtpPassword" autocomplete="new-password"
                                        :placeholder="$smtpPasswordSet ? __('admin.secret_stored') : ''" />
                        @if ($smtpPasswordSet)
                            <p class="mt-1 text-xs text-gray-500">{{ __('admin.leave_blank_keep') }}</p>
                        @endif
                    </div>
                </div>
            @else
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-gray-700">{{ __('admin.email_provider') }}</label>
                        <select wire:model.live="oauthProvider" class="block w-full rounded border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 focus:border-admin-primary focus:outline-none focus:ring-2 focus:ring-admin-primary/20">
                            <option value="google">Google</option>
                            <option value="microsoft">Microsoft</option>
                            <option value="yahoo">Yahoo</option>
                        </select>
                    </div>
                    <div class="hidden sm:block"></div>
                    <x-admin::input :label="__('admin.client_id')" wire:model="oauthClientId" />
                    <div>
                        <x-admin::input :label="__('admin.client_secret')" type="password" wire:model="oauthClientSecret" autocomplete="new-password"
                                        :placeholder="$oauthSecretSet ? __('admin.secret_stored') : ''" />
                        @if ($oauthSecretSet)
                            <p class="mt-1 text-xs text-gray-500">{{ __('admin.leave_blank_keep') }}</p>
                        @endif
                    </div>
                </div>

                {{-- Setup guide (provider console link reflects the selected provider) --}}
                <div class="space-y-2 rounded border border-admin-info/30 bg-admin-info/5 p-4 text-sm">
                    <p class="font-semibold text-gray-700">{{ __('admin.oauth_setup_guide') }}
                        <a href="{{ config('oauth.providers.'.$oauthProvider.'.console_url') }}" target="_blank" rel="noopener noreferrer" class="ms-1 font-medium text-admin-primary hover:underline">{{ __('admin.oauth_open_console', ['provider' => ucfirst($oauthProvider)]) }} ↗</a>
                    </p>
                    <ol class="list-decimal space-y-1 pl-5 text-gray-600">
                        <li>{{ __('admin.oauth_guide_step1') }}</li>
                        <li>{{ __('admin.oauth_guide_step2') }}</li>
                        <li>{{ __('admin.oauth_guide_step3') }}</li>
                        <li>{{ __('admin.oauth_guide_connect') }}</li>
                    </ol>
                    <label class="block pt-1 text-xs font-medium text-gray-600">{{ __('admin.redirect_uri') }}</label>
                    <div class="flex items-center gap-2" x-data="{ copied: false }">
                        <input type="text" readonly value="{{ \App\Modules\Admin\Support\OAuthFlow::callbackUrl() }}"
                               class="block w-full select-all rounded border border-gray-300 bg-white px-3 py-2 font-mono text-xs text-gray-700">
                        <x-admin::button type="button" variant="soft" size="sm"
                                         x-on:click="navigator.clipboard.writeText('{{ \App\Modules\Admin\Support\OAuthFlow::callbackUrl() }}'); copied = true; setTimeout(() => copied = false, 1500)">
                            <span x-show="!copied">{{ __('admin.copy') }}</span><span x-show="copied" x-cloak>{{ __('admin.copied') }}</span>
                        </x-admin::button>
                    </div>
                    <p class="text-xs text-gray-500">{{ __('admin.oauth_redirect_uri_shared') }}</p>
                </div>

                <div class="flex flex-wrap items-center gap-3 rounded border border-gray-200 bg-gray-50 px-4 py-3">
                    @if ($oauthConnectedEmail)
                        <span class="inline-flex items-center gap-1.5 text-sm text-admin-success">
                            <span class="inline-block h-2 w-2 rounded-full bg-admin-success"></span>
                            {{ __('admin.oauth_connected_as', ['email' => $oauthConnectedEmail]) }}
                        </span>
                        <x-admin::button type="button" variant="soft" size="sm" wire:click="connect">{{ __('admin.oauth_reconnect') }}</x-admin::button>
                    @else
                        <span class="text-sm text-gray-600">{{ __('admin.oauth_not_connected') }}</span>
                        <x-admin::button type="button" size="sm" wire:click="connect">{{ __('admin.oauth_connect') }}</x-admin::button>
                    @endif
                    <span class="text-xs text-gray-400">{{ __('admin.oauth_save_first') }}</span>
                </div>
            @endif

            {{-- Sender identity --}}
            <div class="grid gap-4 border-t border-gray-100 pt-5 sm:grid-cols-2">
                <x-admin::input :label="__('admin.from_address')" type="email" wire:model="fromAddress" placeholder="no-reply@example.com" />
                <x-admin::input :label="__('admin.from_name')" wire:model="fromName" :placeholder="config('admin.brand.name')" />
                @if ($scope !== 'platform')
                    <div class="sm:col-span-2">
                        <x-admin::input :label="__('admin.reply_to')" type="email" wire:model="replyTo" placeholder="lienhe@congty-cua-ban.com" />
                        <p class="mt-1 text-xs text-gray-500">{{ __('admin.reply_to_hint') }}</p>
                    </div>
                @endif
            </div>

            {{-- Actions --}}
            <div class="flex flex-wrap items-center gap-3 border-t border-gray-100 pt-5">
                <x-admin::button type="submit" wire:loading.attr="disabled" wire:target="save">{{ __('admin.save') }}</x-admin::button>
                <x-admin::button type="button" variant="outline" wire:click="sendTest" wire:loading.attr="disabled" wire:target="sendTest">{{ __('admin.send_test_email') }}</x-admin::button>
                @if ($testResult === 'ok')
                    <span class="text-sm text-admin-success">{{ __('admin.test_email_sent') }}</span>
                @elseif ($testResult === 'error')
                    <span class="text-sm text-admin-danger">{{ __('admin.test_email_failed') }}</span>
                @endif
            </div>
        </div>
    </form>
</x-admin::settings-shell>
