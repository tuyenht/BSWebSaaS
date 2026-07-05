<div class="catalog-editor">
    <h1>{{ $ulid ? __('catalog.edit_title') : __('catalog.create_title') }}</h1>

    @if (session('saved'))
        <p class="entry-saved">{{ __('catalog.saved') }}</p>
    @endif

    <label>{{ __('catalog.name_label') }}
        <input type="text" wire:model="name">
    </label>
    @error('name') <p class="error">{{ $message }}</p> @enderror

    <label>{{ __('catalog.slug_label') }}
        <input type="text" wire:model="slug">
    </label>
    @error('slug') <p class="error">{{ $message }}</p> @enderror

    <label>{{ __('catalog.category_label') }}
        <select wire:model="categoryId">
            <option value="">{{ __('catalog.category_placeholder') }}</option>
            @foreach ($categories as $category)
                <option value="{{ $category['id'] }}">{{ $category['name'] }}</option>
            @endforeach
        </select>
    </label>
    @error('categoryId') <p class="error">{{ $message }}</p> @enderror

    <label>{{ __('catalog.description_label') }}
        <textarea wire:model="description" rows="10"></textarea>
    </label>
    @error('description') <p class="error">{{ $message }}</p> @enderror

    <label>{{ __('catalog.price_label') }}
        <input type="number" wire:model="price" min="0" step="1">
    </label>
    <p class="hint">{{ __('catalog.price_hint') }}</p>
    @error('price') <p class="error">{{ $message }}</p> @enderror

    <label>{{ __('catalog.sku_label') }}
        <input type="text" wire:model="sku">
    </label>
    @error('sku') <p class="error">{{ $message }}</p> @enderror

    <label>{{ __('catalog.status_label') }}
        <select wire:model="status">
            <option value="draft">{{ __('catalog.status_draft') }}</option>
            <option value="published">{{ __('catalog.status_published') }}</option>
            <option value="archived">{{ __('catalog.status_archived') }}</option>
        </select>
    </label>
    @error('status') <p class="error">{{ $message }}</p> @enderror

    <div class="editor-actions">
        <button type="button" wire:click="save">{{ __('catalog.save') }}</button>
    </div>
</div>
