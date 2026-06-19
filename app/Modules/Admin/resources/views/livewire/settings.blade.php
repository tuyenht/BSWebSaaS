<x-admin::settings-shell active="security">
    {{-- Two-factor authentication --}}
    <div class="overflow-hidden rounded-md border border-gray-200 bg-white shadow-card">
        <div class="border-b border-gray-200 px-5 py-3">
            <h2 class="text-[15px] font-semibold text-gray-700">{{ __('auth.two_factor_title') }}</h2>
        </div>
        <div class="space-y-4 p-5">
            @php($user = auth()->user())

            @if ($user?->two_factor_confirmed_at)
                <span class="inline-flex items-center rounded bg-admin-success/10 px-2 py-0.5 text-xs font-semibold text-admin-success">{{ __('admin.two_factor_on') }}</span>
                <p class="text-sm text-gray-600">{{ __('admin.two_factor_enabled_desc') }}</p>
                <x-admin::button color="danger" variant="soft" wire:click="disableTwoFactor" wire:confirm="{{ __('admin.disable_2fa_confirm') }}">
                    {{ __('admin.disable_2fa') }}
                </x-admin::button>
            @elseif ($tfaQr)
                <p class="text-sm text-gray-600">{{ __('admin.scan_qr') }}</p>
                <div class="inline-block rounded border border-gray-200 bg-white p-3">{!! $tfaQr !!}</div>
                <p class="text-xs text-gray-500">{{ __('admin.or_enter_key') }}: <span class="select-all font-mono text-gray-700">{{ $tfaSecret }}</span></p>
                <form wire:submit="confirmTwoFactor" class="flex flex-col gap-2 sm:flex-row sm:items-start">
                    <div>
                        <input type="text" wire:model="tfaCode" inputmode="numeric" autocomplete="one-time-code" autofocus placeholder="000000"
                               class="w-40 rounded border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 placeholder:text-gray-500 focus:border-admin-primary focus:outline-none focus:ring-2 focus:ring-admin-primary/20">
                        @error('tfaCode') <p class="mt-1 text-xs text-admin-danger">{{ $message }}</p> @enderror
                    </div>
                    <x-admin::button type="submit" wire:loading.attr="disabled" wire:target="confirmTwoFactor">{{ __('admin.confirm') }}</x-admin::button>
                </form>
            @else
                <p class="text-sm text-gray-600">{{ __('admin.two_factor_off_desc') }}</p>
                <x-admin::button wire:click="startTwoFactor" wire:loading.attr="disabled" wire:target="startTwoFactor">{{ __('admin.enable_2fa') }}</x-admin::button>
            @endif

            @if ($showRecovery)
                <div class="rounded border border-admin-warning/40 bg-admin-warning/10 p-4">
                    <p class="mb-2 text-sm font-medium text-gray-700">{{ __('admin.recovery_codes_hint') }}</p>
                    <ul class="grid grid-cols-2 gap-x-6 gap-y-1 font-mono text-sm text-gray-800">
                        @foreach ($recoveryCodes as $rc)<li>{{ $rc }}</li>@endforeach
                    </ul>
                </div>
            @endif
        </div>
    </div>
</x-admin::settings-shell>
