<div class="mx-auto max-w-4xl">
    <div class="mb-4">
        <a href="{{ route('platform.sites.index') }}" wire:navigate
           class="text-sm font-medium text-admin-primary transition-colors hover:underline">&larr; {{ __('admin.back') }}</a>
    </div>

    <div class="mb-6 rounded-lg border border-gray-200 bg-white p-5 shadow-card">
        <h1 class="text-lg font-semibold text-gray-800">{{ $site->name }}</h1>
        <dl class="mt-3 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
            <div>
                <dt class="text-gray-500">{{ __('admin.status') }}</dt>
                <dd class="font-medium text-gray-800">{{ $site->status ? __('admin.status_'.$site->status) : '—' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">{{ __('admin.tenant') }}</dt>
                <dd class="font-medium text-gray-800">{{ $site->tenant?->name ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">{{ __('admin.site_type') }}</dt>
                <dd class="font-medium text-gray-800">{{ $site->siteType?->key ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">ULID</dt>
                <dd class="font-mono text-xs text-gray-700">{{ $site->ulid }}</dd>
            </div>
        </dl>
    </div>

    <h2 class="mb-3 text-sm font-semibold text-gray-700">{{ __('admin.domains') }}</h2>
    <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white shadow-card">
        <table class="w-full text-sm">
            <thead class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase tracking-wider text-gray-500">
                <tr>
                    <th class="px-4 py-3 font-semibold">{{ __('admin.primary_domain') }}</th>
                    <th class="px-4 py-3 font-semibold">Kind</th>
                    <th class="px-4 py-3 font-semibold">{{ __('admin.status') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($site->domains as $domain)
                    <tr wire:key="domain-{{ $domain->id }}">
                        <td class="px-4 py-3 font-medium text-gray-800">
                            {{ $domain->hostname }}
                            @if ($domain->is_primary)
                                <span class="ms-1 rounded bg-admin-primary/10 px-1.5 py-0.5 text-[10px] font-semibold text-admin-primary">★</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-gray-600">{{ $domain->kind }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $domain->ssl_status ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="px-4 py-8 text-center text-gray-400">{{ __('admin.no_domains') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
