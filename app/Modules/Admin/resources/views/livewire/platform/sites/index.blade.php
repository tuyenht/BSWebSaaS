<div>
    <div class="mb-4">
        <h1 class="text-lg font-semibold text-gray-800">{{ __('admin.platform_sites') }}</h1>
    </div>

    {{-- Filters --}}
    <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center">
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('admin.search_placeholder') }}"
               class="w-full rounded border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700 placeholder:text-gray-400 transition-colors focus:border-admin-primary focus:bg-white focus:outline-none focus:ring-2 focus:ring-admin-primary/20 sm:max-w-xs">
        <select wire:model.live="status"
                class="w-full rounded border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700 transition-colors focus:border-admin-primary focus:bg-white focus:outline-none focus:ring-2 focus:ring-admin-primary/20 sm:max-w-[200px]">
            <option value="">{{ __('admin.all_statuses') }}</option>
            <option value="active">{{ __('admin.status_active') }}</option>
            <option value="provisioning">{{ __('admin.status_provisioning') }}</option>
            <option value="suspended">{{ __('admin.status_suspended') }}</option>
            <option value="archived">{{ __('admin.status_archived') }}</option>
        </select>
    </div>

    <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white shadow-card">
        <table class="w-full text-sm">
            <thead class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase tracking-wider text-gray-500">
                <tr>
                    <th class="px-4 py-3 font-semibold">{{ __('admin.name') }}</th>
                    <th class="px-4 py-3 font-semibold">{{ __('admin.primary_domain') }}</th>
                    <th class="px-4 py-3 font-semibold">{{ __('admin.tenant') }}</th>
                    <th class="px-4 py-3 font-semibold">{{ __('admin.site_type') }}</th>
                    <th class="px-4 py-3 font-semibold">{{ __('admin.status') }}</th>
                    <th class="px-4 py-3 text-end font-semibold">{{ __('admin.actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($sites as $site)
                    <tr wire:key="site-{{ $site->id }}">
                        <td class="px-4 py-3 font-medium text-gray-800">{{ $site->name }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $site->domains->firstWhere('is_primary', true)?->hostname ?? $site->domains->first()?->hostname ?? __('admin.no_domain') }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $site->tenant?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $site->siteType?->key ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $site->status ? __('admin.status_'.$site->status) : '—' }}</td>
                        <td class="px-4 py-3 text-end">
                            <a href="{{ route('platform.sites.show', $site->ulid) }}" wire:navigate
                               class="font-medium text-admin-primary transition-colors hover:underline">{{ __('admin.view') }}</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-gray-400">{{ __('admin.no_sites') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $sites->links() }}
    </div>
</div>
