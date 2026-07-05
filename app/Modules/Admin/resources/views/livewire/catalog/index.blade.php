<div class="catalog-index">
    <div class="page-header">
        <h1>{{ __('catalog.title') }}</h1>
        @if ($canManage)
            <a href="{{ route('admin.catalog.create') }}" wire:navigate>{{ __('catalog.create_new') }}</a>
        @endif
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
                            <a href="{{ route('admin.catalog.edit', ['ulid' => $product->ulid]) }}" wire:navigate>{{ __('catalog.edit_action') }}</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="3">{{ __('catalog.empty') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
