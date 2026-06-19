<x-admin::settings-shell active="social">
    @if (session('status'))
        <div class="rounded border border-admin-success/30 bg-admin-success/10 px-4 py-3 text-sm text-admin-success">{{ session('status') }}</div>
    @endif

    <div class="overflow-hidden rounded-md border border-gray-200 bg-white shadow-card">
        <div class="border-b border-gray-200 px-5 py-3">
            <h2 class="text-[15px] font-semibold text-gray-700">{{ __('admin.social_login') }}</h2>
            <p class="mt-0.5 text-xs text-gray-500">{{ __('admin.social_login_desc') }}</p>
        </div>
        <div class="space-y-3 p-5">
            <label class="mb-1 block text-sm font-medium text-gray-700">{{ __('admin.redirect_uri') }}</label>
            <div class="flex items-center gap-2" x-data="{ copied: false }">
                <input type="text" readonly value="{{ $redirectUri }}"
                       class="block w-full select-all rounded border border-gray-300 bg-gray-50 px-3 py-2 font-mono text-xs text-gray-700">
                <x-admin::button type="button" variant="soft" size="sm"
                                 x-on:click="navigator.clipboard.writeText('{{ $redirectUri }}'); copied = true; setTimeout(() => copied = false, 1500)">
                    <span x-show="!copied">{{ __('admin.copy') }}</span>
                    <span x-show="copied" x-cloak>{{ __('admin.copied') }}</span>
                </x-admin::button>
            </div>
            <p class="text-xs text-gray-500">{{ __('admin.redirect_uri_hint') }}</p>
        </div>
    </div>

    @foreach ($providers as $provider)
        <form wire:submit="save('{{ $provider }}')" wire:key="social-{{ $provider }}"
              class="overflow-hidden rounded-md border border-gray-200 bg-white shadow-card">
            <div class="flex items-center justify-between border-b border-gray-200 px-5 py-3">
                <h3 class="text-sm font-semibold text-gray-700">{{ ucfirst($provider) }}</h3>
                <label class="inline-flex cursor-pointer items-center gap-2">
                    <input type="checkbox" wire:model="forms.{{ $provider }}.enabled" class="h-4 w-4 rounded border-gray-300 text-admin-primary focus:ring-admin-primary/30">
                    <span class="text-sm text-gray-600">{{ __('admin.enabled') }}</span>
                </label>
            </div>
            <div class="grid gap-4 p-5 sm:grid-cols-2">
                <x-admin::input :label="__('admin.client_id')" wire:model="forms.{{ $provider }}.client_id" />
                <div>
                    <x-admin::input :label="__('admin.client_secret')" type="password" wire:model="forms.{{ $provider }}.client_secret" autocomplete="new-password"
                                    :placeholder="($secretSet[$provider] ?? false) ? __('admin.secret_stored') : ''" />
                    @if ($secretSet[$provider] ?? false)
                        <p class="mt-1 text-xs text-gray-500">{{ __('admin.leave_blank_keep') }}</p>
                    @endif
                </div>
                <div class="sm:col-span-2">
                    <x-admin::button type="submit" size="sm" wire:loading.attr="disabled" wire:target="save('{{ $provider }}')">{{ __('admin.save') }}</x-admin::button>
                </div>
            </div>
        </form>
    @endforeach
</x-admin::settings-shell>
