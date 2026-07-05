<div class="catalog-index">
    <div class="page-header">
        <h1>{{ __('catalog.title') }}</h1>
    </div>

    <table>
        <thead>
            <tr><th>{{ __('catalog.name_label') }}</th><th>{{ __('catalog.status_label') }}</th></tr>
        </thead>
        <tbody>
            @forelse ($products as $product)
                @php($translation = $product->translations->firstWhere('locale', $locale))
                <tr wire:key="product-{{ $product->ulid }}">
                    <td>{{ $translation?->name ?? __('catalog.untitled') }}</td>
                    <td>{{ $product->status }}</td>
                </tr>
            @empty
                <tr><td colspan="2">{{ __('catalog.empty') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
