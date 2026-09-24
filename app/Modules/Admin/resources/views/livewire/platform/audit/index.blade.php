<div>
    <div class="mb-4">
        <h1 class="text-lg font-semibold text-heading">{{ __('admin.platform_audit') }}</h1>
    </div>

    <div class="mb-4">
        <input type="search" wire:model.live.debounce.300ms="action" placeholder="{{ __('admin.filter_action') }}"
               class="w-full rounded border border-border bg-surface-muted px-3 py-2 text-sm text-body-text placeholder:text-placeholder transition-colors focus:border-admin-primary focus:bg-surface focus:outline-none focus:ring-2 focus:ring-admin-primary/20 sm:max-w-xs">
    </div>

    <div class="overflow-x-auto rounded-lg border border-border bg-surface shadow-card">
        <table class="w-full text-sm">
            <thead class="border-b border-border bg-surface-muted text-left text-xs uppercase tracking-wider text-body-muted">
                <tr>
                    <th class="px-4 py-3 font-semibold">{{ __('admin.audit_when') }}</th>
                    <th class="px-4 py-3 font-semibold">{{ __('admin.audit_action') }}</th>
                    <th class="px-4 py-3 font-semibold">{{ __('admin.audit_actor') }}</th>
                    <th class="px-4 py-3 font-semibold">{{ __('admin.audit_site') }}</th>
                    <th class="px-4 py-3 font-semibold">{{ __('admin.audit_ip') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border-soft">
                @forelse ($entries as $entry)
                    <tr wire:key="audit-{{ $entry->id }}">
                        <td class="whitespace-nowrap px-4 py-3 text-body-muted">{{ $entry->created_at?->format('d/m/Y H:i') ?? '—' }}</td>
                        <td class="px-4 py-3 font-mono text-xs text-heading">{{ $entry->action }}</td>
                        <td class="px-4 py-3 text-body-muted">{{ $entry->actor_label ?? '—' }}</td>
                        <td class="px-4 py-3 text-body-muted">{{ $entry->site_id ?? '—' }}</td>
                        <td class="px-4 py-3 text-body-muted">{{ $entry->ip_address ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-placeholder">{{ __('admin.no_audit') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $entries->links() }}
    </div>
</div>
