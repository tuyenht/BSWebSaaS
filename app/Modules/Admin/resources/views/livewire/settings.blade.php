<x-admin::settings-shell active="security">
    {{-- Two-factor authentication --}}
    <div class="overflow-hidden rounded-md border border-border bg-surface shadow-card">
        <div class="border-b border-border px-5 py-3">
            <h2 class="text-[15px] font-semibold text-body-text">{{ __('auth.two_factor_title') }}</h2>
        </div>
        <div class="space-y-4 p-5">
            @php($user = auth()->user())

            @if ($user?->two_factor_confirmed_at)
                <span class="inline-flex items-center rounded bg-admin-success/10 px-2 py-0.5 text-xs font-semibold text-admin-success">{{ __('admin.two_factor_on') }}</span>
                <p class="text-sm text-body-muted">{{ __('admin.two_factor_enabled_desc') }}</p>
                <x-admin::button color="danger" variant="soft" wire:click="disableTwoFactor" wire:confirm="{{ __('admin.disable_2fa_confirm') }}">
                    {{ __('admin.disable_2fa') }}
                </x-admin::button>
            @elseif ($tfaQr)
                <p class="text-sm text-body-muted">{{ __('admin.scan_qr') }}</p>
                <div class="inline-block rounded border border-border bg-surface p-3">{!! $tfaQr !!}</div>
                <p class="text-xs text-body-faint">{{ __('admin.or_enter_key') }}: <span class="select-all font-mono text-body-text">{{ $tfaSecret }}</span></p>
                <form wire:submit="confirmTwoFactor" class="flex flex-col gap-2 sm:flex-row sm:items-start">
                    <div>
                        <input type="text" wire:model="tfaCode" inputmode="numeric" autocomplete="one-time-code" autofocus placeholder="000000"
                               class="w-40 rounded border border-border-strong bg-surface px-3 py-2 text-sm text-heading placeholder:text-body-faint focus:border-admin-primary focus:outline-none focus:ring-2 focus:ring-admin-primary/20">
                        @error('tfaCode') <p class="mt-1 text-xs text-admin-danger">{{ $message }}</p> @enderror
                    </div>
                    <x-admin::button type="submit" wire:loading.attr="disabled" wire:target="confirmTwoFactor">{{ __('admin.confirm') }}</x-admin::button>
                </form>
            @else
                <p class="text-sm text-body-muted">{{ __('admin.two_factor_off_desc') }}</p>
                <x-admin::button wire:click="startTwoFactor" wire:loading.attr="disabled" wire:target="startTwoFactor">{{ __('admin.enable_2fa') }}</x-admin::button>
            @endif

            @if ($showRecovery)
                <div class="rounded border border-admin-warning/40 bg-admin-warning/10 p-4">
                    <p class="mb-2 text-sm font-medium text-body-text">{{ __('admin.recovery_codes_hint') }}</p>
                    <ul class="grid grid-cols-2 gap-x-6 gap-y-1 font-mono text-sm text-heading">
                        @foreach ($recoveryCodes as $rc)<li>{{ $rc }}</li>@endforeach
                    </ul>
                </div>
            @endif
        </div>
    </div>

    {{-- Connected accounts (social sign-in) --}}
    <div class="mt-6 overflow-hidden rounded-md border border-border bg-surface shadow-card">
        <div class="border-b border-border px-5 py-3">
            <h2 class="text-[15px] font-semibold text-body-text">{{ __('admin.connected_accounts') }}</h2>
        </div>
        <div class="divide-y divide-border-soft">
            @foreach ($socialProviders as $key => $label)
                <div class="flex items-center justify-between px-5 py-3">
                    <div>
                        <p class="text-sm font-medium text-body-text">{{ $label }}</p>
                        @if ($connected->has($key))
                            <p class="text-xs text-body-faint">{{ __('admin.oauth_connected_as', ['email' => $connected[$key] ?: '—']) }}</p>
                        @else
                            <p class="text-xs text-placeholder">{{ __('admin.oauth_not_connected') }}</p>
                        @endif
                    </div>
                    @if ($connected->has($key))
                        <x-admin::button color="danger" variant="soft" wire:click="disconnect('{{ $key }}')" wire:confirm="{{ __('admin.social_disconnect_confirm') }}">
                            {{ __('admin.disconnect') }}
                        </x-admin::button>
                    @else
                        <a href="{{ route('admin.oauth.start', ['provider' => $key]) }}?purpose=connect"
                           class="rounded bg-admin-primary px-3 py-1.5 text-sm font-medium text-white transition-colors hover:bg-admin-primary/90">
                            {{ __('admin.oauth_connect') }}
                        </a>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</x-admin::settings-shell>
