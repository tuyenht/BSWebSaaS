<div class="entries-index">
    <div class="page-header">
        <h1>Trang</h1>
        <a href="{{ route('admin.entries.create') }}" wire:navigate>Tạo trang mới</a>
    </div>

    <table>
        <thead>
            <tr><th>Tiêu đề</th><th>Trạng thái</th><th></th></tr>
        </thead>
        <tbody>
            @forelse ($pages as $page)
                @php($translation = $page->translations->firstWhere('locale', $locale))
                <tr wire:key="entry-{{ $page->ulid }}">
                    <td>{{ $translation?->title ?? '(chưa có tiêu đề)' }}</td>
                    <td>{{ $page->status }}</td>
                    <td>
                        <a href="{{ route('admin.entries.edit', ['ulid' => $page->ulid]) }}" wire:navigate>Sửa</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="3">Chưa có trang nào.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
