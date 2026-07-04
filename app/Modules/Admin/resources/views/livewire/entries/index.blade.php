<div class="entries-index">
    <div class="page-header">
        <h1>{{ $section?->label ?? __('entries.section_fallback') }}</h1>
        <a href="{{ route('admin.entries.create', $sectionKey ? ['section' => $sectionKey] : []) }}" wire:navigate>{{ __('entries.create_new') }}</a>
    </div>

    <table>
        <thead>
            <tr><th>{{ __('entries.title_label') }}</th><th>{{ __('entries.status_label') }}</th><th></th></tr>
        </thead>
        <tbody>
            @forelse ($entries as $entry)
                @php($translation = $entry->translations->firstWhere('locale', $locale))
                <tr wire:key="entry-{{ $entry->ulid }}">
                    <td>{{ $translation?->title ?? __('entries.untitled') }}</td>
                    <td>{{ $entry->status }}</td>
                    <td>
                        <a href="{{ route('admin.entries.edit', ['ulid' => $entry->ulid]) }}" wire:navigate>{{ __('entries.edit_action') }}</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="3">{{ __('entries.empty') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
