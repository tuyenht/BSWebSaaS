<div class="mx-auto max-w-4xl">
    <div class="mb-4">
        <a href="{{ route('platform.tenants.index') }}" wire:navigate
           class="text-sm font-medium text-admin-primary transition-colors hover:underline">&larr; {{ __('admin.back') }}</a>
    </div>

    <div class="mb-6 rounded-lg border border-gray-200 bg-white p-5 shadow-card">
        <h1 class="text-lg font-semibold text-gray-800">{{ $tenant->name }}</h1>
        <dl class="mt-3 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
            <div>
                <dt class="text-gray-500">{{ __('admin.status') }}</dt>
                <dd class="font-medium text-gray-800">{{ $tenant->status ? __('admin.status_'.$tenant->status) : '—' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">ULID</dt>
                <dd class="font-mono text-xs text-gray-700">{{ $tenant->ulid }}</dd>
            </div>
        </dl>
    </div>

    <h2 class="mb-3 text-sm font-semibold text-gray-700">{{ __('admin.tenant_sites') }}</h2>
    <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white shadow-card">
        <table class="w-full text-sm">
            <thead class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase tracking-wider text-gray-500">
                <tr>
                    <th class="px-4 py-3 font-semibold">{{ __('admin.name') }}</th>
                    <th class="px-4 py-3 font-semibold">{{ __('admin.primary_domain') }}</th>
                    <th class="px-4 py-3 font-semibold">{{ __('admin.status') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($sites as $site)
                    <tr wire:key="site-{{ $site->id }}">
                        <td class="px-4 py-3 font-medium text-gray-800">{{ $site->name }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $site->domains->firstWhere('is_primary', true)?->hostname ?? $site->domains->first()?->hostname ?? __('admin.no_domain') }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $site->status ? __('admin.status_'.$site->status) : '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="px-4 py-8 text-center text-gray-400">{{ __('admin.no_sites') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
