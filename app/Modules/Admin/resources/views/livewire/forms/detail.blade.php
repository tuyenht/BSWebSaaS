<div class="mx-auto max-w-3xl">
    <div class="mb-4">
        <a href="{{ route('admin.forms.index') }}" wire:navigate
           class="text-sm font-medium text-admin-primary transition-colors hover:underline">&larr; {{ __('forms.back_to_inbox') }}</a>
    </div>

    @if (session('status'))
        <p class="mb-4 rounded bg-green-50 px-4 py-2 text-sm text-green-700" role="status">{{ session('status') }}</p>
    @endif

    <div class="mb-6 rounded-lg border border-gray-200 bg-white p-5 shadow-card">
        <div class="mb-4 flex items-start justify-between gap-3">
            <div>
                <h1 class="text-lg font-semibold text-gray-800">{{ $submission->name ?: __('forms.lead_title') }}</h1>
                <p class="mt-1 text-sm text-gray-500">
                    {{ $submission->created_at?->format('d/m/Y H:i') ?? '—' }}
                </p>
            </div>
            <span class="rounded px-2 py-0.5 text-xs font-semibold
                @class([
                    'bg-blue-100 text-blue-700' => $submission->status === 'new',
                    'bg-gray-100 text-gray-600' => $submission->status === 'read',
                    'bg-amber-100 text-amber-700' => $submission->status === 'archived',
                    'bg-red-100 text-red-700' => $submission->status === 'spam',
                ])">{{ __('forms.status_'.$submission->status) }}</span>
        </div>

        <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
            @foreach ($submission->data as $key => $value)
                @if (is_scalar($value) && (string) $value !== '')
                    <div @class(['sm:col-span-2' => $key === 'message'])>
                        <dt class="text-gray-500">{{ $key }}</dt>
                        <dd class="whitespace-pre-line font-medium text-gray-800">{{ $value }}</dd>
                    </div>
                @endif
            @endforeach
        </dl>

        @if ($submission->source_url || $submission->ip_address)
            <dl class="mt-4 grid grid-cols-1 gap-2 border-t border-gray-100 pt-4 text-xs text-gray-400 sm:grid-cols-2">
                @if ($submission->source_url)
                    <div><dt class="inline">{{ __('forms.meta_source') }}: </dt><dd class="inline">{{ $submission->source_url }}</dd></div>
                @endif
                @if ($submission->ip_address)
                    <div><dt class="inline">IP: </dt><dd class="inline">{{ $submission->ip_address }}</dd></div>
                @endif
            </dl>
        @endif
    </div>

    @can('forms.manage')
        <div class="flex flex-wrap gap-2">
            @if ($submission->status !== 'read')
                <button type="button" wire:click="setStatus('read')"
                        class="rounded border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50">{{ __('forms.action_mark_read') }}</button>
            @endif
            @if ($submission->status !== 'archived')
                <button type="button" wire:click="setStatus('archived')"
                        class="rounded border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50">{{ __('forms.action_archive') }}</button>
            @endif
            @if ($submission->status !== 'spam')
                <button type="button" wire:click="setStatus('spam')"
                        class="rounded border border-red-200 bg-white px-3 py-2 text-sm font-medium text-red-600 transition-colors hover:bg-red-50">{{ __('forms.action_mark_spam') }}</button>
            @else
                <button type="button" wire:click="setStatus('new')"
                        class="rounded border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50">{{ __('forms.action_not_spam') }}</button>
            @endif
        </div>
    @endcan
</div>
