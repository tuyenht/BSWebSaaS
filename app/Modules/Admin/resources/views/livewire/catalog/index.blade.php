<div class="catalog-index">
    <div class="page-header">
        <h1>{{ $showTrash ? __('catalog.trash_tab') : __('catalog.title') }}</h1>
        <span>
            @if ($showTrash)
                <a href="{{ route('admin.catalog.index') }}" wire:navigate>{{ __('catalog.back_to_list') }}</a>
            @else
                @if ($canManage)
                    <a href="{{ route('admin.catalog.create') }}" wire:navigate>{{ __('catalog.create_new') }}</a>
                @endif
                <a href="{{ route('admin.catalog.index', ['trash' => 1]) }}" wire:navigate>{{ __('catalog.trash_tab') }} ({{ $trashCount }})</a>
            @endif
        </span>
    </div>

    <table>
        <thead>
            <tr><th>{{ __('catalog.name_label') }}</th><th>{{ __('catalog.status_label') }}</th><th></th></tr>
        </thead>
        <tbody>
            @forelse ($products as $product)
                @php($translation = $product->translations->firstWhere('locale', $locale))
                <tr wire:key="product-{{ $product->ulid }}">
                    <td>{{ $translation?->name ?? __('catalog.untitled') }}</td>
                    <td>{{ $product->status }}</td>
                    <td>
                        @if ($canManage)
                            @if ($showTrash)
                                <button type="button" wire:click="restore('{{ $product->ulid }}')">{{ __('catalog.restore_action') }}</button>
                                <button type="button" wire:click="forceDelete('{{ $product->ulid }}')" wire:confirm="{{ __('catalog.force_delete_confirm') }}" class="text-admin-danger">{{ __('catalog.force_delete_action') }}</button>
                            @else
                                <a href="{{ route('admin.catalog.edit', ['ulid' => $product->ulid]) }}" wire:navigate>{{ __('catalog.edit_action') }}</a>
                                <button type="button" wire:click="trash('{{ $product->ulid }}')" wire:confirm="{{ __('catalog.trash_confirm') }}" class="text-admin-danger">{{ __('catalog.trash_action') }}</button>
                            @endif
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="3">{{ $showTrash ? __('catalog.trash_empty') : __('catalog.empty') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
