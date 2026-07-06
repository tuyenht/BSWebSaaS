@php
    // Status → soft badge tone; labels come from the catalog.status_* keys.
    $statusColor = ['draft' => 'secondary', 'published' => 'success', 'archived' => 'warning'];
@endphp
<div class="catalog-index">
    <x-admin::page-header :title="$showTrash ? __('catalog.trash_tab') : __('catalog.title')">
        <x-slot:actions>
            @if ($showTrash)
                <x-admin::button size="sm" variant="soft" color="primary" :href="route('admin.catalog.index')">{{ __('catalog.back_to_list') }}</x-admin::button>
            @else
                <x-admin::button size="sm" variant="ghost" color="primary" :href="route('admin.catalog.index', ['trash' => 1])">{{ __('catalog.trash_tab') }} ({{ $trashCount }})</x-admin::button>
                @if ($canManage)
                    <x-admin::button size="sm" color="primary" :href="route('admin.catalog.create')">{{ __('catalog.create_new') }}</x-admin::button>
                @endif
            @endif
        </x-slot:actions>
    </x-admin::page-header>

    <x-admin::card class="mt-4">
        <div class="mb-4 max-w-xs">
            <label for="catalog-search" class="sr-only">{{ __('catalog.search_placeholder') }}</label>
            <input type="search" id="catalog-search" wire:model.live.debounce.300ms="search"
                   placeholder="{{ __('catalog.search_placeholder') }}"
                   class="block w-full rounded border border-border-strong bg-surface px-3 py-2 text-sm text-body-text placeholder:text-placeholder transition-colors focus:border-admin-primary focus:outline-none focus:ring-2 focus:ring-admin-primary/20">
        </div>

        <x-admin::table>
            <thead>
                <tr>
                    <th class="w-14"><span class="sr-only">{{ __('catalog.card_gallery') }}</span></th>
                    <th>{{ __('catalog.name_label') }}</th>
                    <th>{{ __('catalog.category_label') }}</th>
                    <th>{{ __('catalog.col_price') }}</th>
                    <th>{{ __('catalog.status_label') }}</th>
                    <th>{{ __('catalog.col_updated') }}</th>
                    <th><span class="sr-only">{{ __('admin.list') }}</span></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($products as $product)
                    @php($translation = $product->translations->firstWhere('locale', $locale))
                    @php($thumbnail = $product->images->first()?->media)
                    @php($categoryName = $product->taxonomies->first()?->translations->firstWhere('locale', $locale)?->name)
                    <tr wire:key="product-{{ $product->ulid }}">
                        <td>
                            @if ($thumbnail !== null)
                                <img src="{{ $urls->url($thumbnail->storage_key, 96) }}" alt="" loading="lazy" class="size-12 rounded border border-border object-cover">
                            @else
                                <span class="flex size-12 items-center justify-center rounded border border-border bg-surface-muted text-placeholder">
                                    <x-admin::icon name="image" class="size-5" />
                                </span>
                            @endif
                        </td>
                        <td>
                            <span class="block font-medium text-body-text">{{ $translation?->name ?? __('catalog.untitled') }}</span>
                            @if ($product->sku !== null)
                                <span class="text-xs text-body-faint">SKU: {{ $product->sku }}</span>
                            @endif
                        </td>
                        <td>{{ $categoryName ?? '—' }}</td>
                        <td class="whitespace-nowrap">{{ $product->price !== null ? number_format($product->price, 0, ',', '.').' ₫' : '—' }}</td>
                        <td>
                            <x-admin::badge soft :color="$statusColor[$product->status] ?? 'secondary'">{{ __('catalog.status_'.$product->status) }}</x-admin::badge>
                        </td>
                        <td class="whitespace-nowrap text-body-faint">{{ $product->updated_at?->locale(app()->getLocale())->diffForHumans() }}</td>
                        <td class="whitespace-nowrap text-right">
                            @if ($canManage)
                                @if ($showTrash)
                                    <button type="button" wire:click="restore('{{ $product->ulid }}')" class="text-admin-primary hover:underline">{{ __('catalog.restore_action') }}</button>
                                    <button type="button" x-on:click="$bsConfirm({ text: @js(__('catalog.force_delete_confirm')), confirm: @js(__('catalog.force_delete_action')), cancel: @js(__('admin.cancel')) }).then(ok => ok && $wire.forceDelete(@js($product->ulid)))" class="ml-2 text-admin-danger hover:underline">{{ __('catalog.force_delete_action') }}</button>
                                @else
                                    <a href="{{ route('admin.catalog.edit', ['ulid' => $product->ulid]) }}" wire:navigate class="text-admin-primary hover:underline">{{ __('catalog.edit_action') }}</a>
                                    <button type="button" x-on:click="$bsConfirm({ text: @js(__('catalog.trash_confirm')), confirm: @js(__('catalog.trash_action')), cancel: @js(__('admin.cancel')) }).then(ok => ok && $wire.trash(@js($product->ulid)))" class="ml-2 text-admin-danger hover:underline">{{ __('catalog.trash_action') }}</button>
                                @endif
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="py-10 text-center text-body-faint">
                            {{ $showTrash ? __('catalog.trash_empty') : (trim($search) !== '' ? __('catalog.search_empty') : __('catalog.empty')) }}
                            @if (! $showTrash && trim($search) === '' && $canManage)
                                <div class="mt-4">
                                    <x-admin::button size="sm" color="primary" :href="route('admin.catalog.create')">{{ __('catalog.create_new') }}</x-admin::button>
                                </div>
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </x-admin::table>

        <div class="mt-4 flex justify-end">
            <x-admin::pagination :paginator="$products" />
        </div>
    </x-admin::card>
</div>
