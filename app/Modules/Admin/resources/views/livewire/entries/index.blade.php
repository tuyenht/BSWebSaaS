<div class="entries-index">
    <div class="page-header">
        <h1>{{ $section?->label ?? 'Trang' }}</h1>
        <a href="{{ route('admin.entries.create', $sectionKey ? ['section' => $sectionKey] : []) }}" wire:navigate>Tạo mới</a>
    </div>

    <table>
        <thead>
            <tr><th>Tiêu đề</th><th>Trạng thái</th><th></th></tr>
        </thead>
        <tbody>
            @forelse ($entries as $entry)
                @php($translation = $entry->translations->firstWhere('locale', $locale))
                <tr wire:key="entry-{{ $entry->ulid }}">
                    <td>{{ $translation?->title ?? '(chưa có tiêu đề)' }}</td>
                    <td>{{ $entry->status }}</td>
                    <td>
                        <a href="{{ route('admin.entries.edit', ['ulid' => $entry->ulid]) }}" wire:navigate>Sửa</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="3">Chưa có nội dung nào.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
