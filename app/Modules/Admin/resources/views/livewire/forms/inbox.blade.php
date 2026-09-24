<div>
    <div class="mb-4">
        <h1 class="text-lg font-semibold text-heading">{{ __('forms.inbox_title') }}</h1>
        <p class="mt-1 text-sm text-body-faint">{{ __('forms.inbox_subtitle') }}</p>
    </div>

    {{-- Status filter --}}
    <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center">
        <select wire:model.live="status"
                class="w-full rounded border border-border bg-surface-muted px-3 py-2 text-sm text-body-text transition-colors focus:border-admin-primary focus:bg-surface focus:outline-none focus:ring-2 focus:ring-admin-primary/20 sm:max-w-[220px]">
            <option value="">{{ __('forms.status_all') }}</option>
            <option value="new">{{ __('forms.status_new') }}</option>
            <option value="read">{{ __('forms.status_read') }}</option>
            <option value="archived">{{ __('forms.status_archived') }}</option>
            <option value="spam">{{ __('forms.status_spam') }}</option>
        </select>
    </div>

    <div class="overflow-x-auto rounded-lg border border-border bg-surface shadow-card">
        <table class="w-full text-sm">
            <thead class="border-b border-border bg-surface-muted text-left text-xs uppercase tracking-wider text-body-muted">
                <tr>
                    <th class="px-4 py-3 font-semibold">{{ __('forms.col_name') }}</th>
                    <th class="px-4 py-3 font-semibold">{{ __('forms.col_contact') }}</th>
                    <th class="px-4 py-3 font-semibold">{{ __('forms.col_received') }}</th>
                    <th class="px-4 py-3 font-semibold">{{ __('admin.status') }}</th>
                    <th class="px-4 py-3 text-end font-semibold">{{ __('admin.actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border-soft">
                @forelse ($submissions as $submission)
                    <tr wire:key="lead-{{ $submission->id }}" @class(['bg-admin-primary/5' => $submission->status === 'new'])>
                        <td class="px-4 py-3 font-medium text-heading">{{ $submission->name ?: '—' }}</td>
                        <td class="px-4 py-3 text-body-muted">{{ $submission->email ?: $submission->phone ?: '—' }}</td>
                        <td class="px-4 py-3 text-body-muted">{{ $submission->created_at?->format('d/m/Y H:i') ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <span class="rounded px-2 py-0.5 text-xs font-semibold
                                @class([
                                    'bg-blue-100 text-blue-700' => $submission->status === 'new',
                                    'bg-surface-hover text-body-muted' => $submission->status === 'read',
                                    'bg-amber-100 text-amber-700' => $submission->status === 'archived',
                                    'bg-red-100 text-red-700' => $submission->status === 'spam',
                                ])">{{ __('forms.status_'.$submission->status) }}</span>
                        </td>
                        <td class="px-4 py-3 text-end">
                            <a href="{{ route('admin.forms.show', $submission->ulid) }}" wire:navigate
                               class="font-medium text-admin-primary transition-colors hover:underline">{{ __('admin.view') }}</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-placeholder">{{ __('forms.inbox_empty') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $submissions->links() }}
    </div>
</div>
