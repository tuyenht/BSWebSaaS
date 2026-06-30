<div>
    <div class="mb-4">
        <h1 class="text-lg font-semibold text-gray-800">{{ __('admin.platform_audit') }}</h1>
    </div>

    <div class="mb-4">
        <input type="search" wire:model.live.debounce.300ms="action" placeholder="{{ __('admin.filter_action') }}"
               class="w-full rounded border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700 placeholder:text-gray-400 transition-colors focus:border-admin-primary focus:bg-white focus:outline-none focus:ring-2 focus:ring-admin-primary/20 sm:max-w-xs">
    </div>

    <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white shadow-card">
        <table class="w-full text-sm">
            <thead class="border-b border-gray-200 bg-gray-50 text-left text-xs uppercase tracking-wider text-gray-500">
                <tr>
                    <th class="px-4 py-3 font-semibold">{{ __('admin.audit_when') }}</th>
                    <th class="px-4 py-3 font-semibold">{{ __('admin.audit_action') }}</th>
                    <th class="px-4 py-3 font-semibold">{{ __('admin.audit_actor') }}</th>
                    <th class="px-4 py-3 font-semibold">{{ __('admin.audit_site') }}</th>
                    <th class="px-4 py-3 font-semibold">{{ __('admin.audit_ip') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($entries as $entry)
                    <tr wire:key="audit-{{ $entry->id }}">
                        <td class="whitespace-nowrap px-4 py-3 text-gray-500">{{ $entry->created_at?->format('d/m/Y H:i') ?? '—' }}</td>
                        <td class="px-4 py-3 font-mono text-xs text-gray-800">{{ $entry->action }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $entry->actor_label ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $entry->site_id ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ $entry->ip_address ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-gray-400">{{ __('admin.no_audit') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $entries->links() }}
    </div>
</div>
