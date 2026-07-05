{{-- Velzon add-product layout (reference: apps-ecommerce-add-product) rebuilt from the admin
     component library: main column = product info + detail rich text + General/SEO tabs;
     sidebar = publish status, category, short summary. The two [data-rich-editor] textareas
     are mounted as Jodit islands (lazy chunk, see resources/js/app.js) inside wire:ignore so
     Livewire morphs never clobber the editor DOM; content syncs back DEFERRED via $wire.set. --}}
<div class="catalog-editor" data-catalog-editor>
    <x-admin::page-header :title="$ulid ? __('catalog.edit_title') : __('catalog.create_title')">
        <x-slot:actions>
            <x-admin::button color="primary" wire:click="save" wire:loading.attr="disabled">{{ __('catalog.save') }}</x-admin::button>
        </x-slot:actions>
    </x-admin::page-header>

    @if (session('saved'))
        <div class="mt-4">
            <x-admin::alert color="success" icon="shield">{{ __('catalog.saved') }}</x-admin::alert>
        </div>
    @endif

    <div class="mt-4 grid items-start gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <x-admin::card :title="__('catalog.card_product')">
                <div class="space-y-4">
                    <div>
                        <x-admin::input :label="__('catalog.name_label')" name="catalog-name" wire:model="name" />
                        @error('name') <p class="mt-1 text-sm text-admin-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <x-admin::input :label="__('catalog.slug_label')" name="catalog-slug" wire:model="slug" />
                        @error('slug') <p class="mt-1 text-sm text-admin-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-body-text" for="catalog-description">{{ __('catalog.description_label') }}</label>
                        {{-- wire:model = no-JS fallback only: Jodit never fires input events on the
                             hidden textarea, so while the island is alive the deferred $wire.set path
                             is the single writer; if the chunk fails to load, plain typing still syncs. --}}
                        <div wire:ignore>
                            <textarea id="catalog-description" data-rich-editor="full" data-rich-target="description" rows="10" wire:model="description">{{ $description }}</textarea>
                        </div>
                        @error('description') <p class="mt-1 text-sm text-admin-danger">{{ $message }}</p> @enderror
                    </div>
                </div>
            </x-admin::card>

            <x-admin::card :title="__('catalog.card_gallery')">
                <div class="space-y-3">
                    @if ($galleryImages === [])
                        <p class="text-sm text-body-faint">{{ __('catalog.gallery_empty') }}</p>
                    @else
                        <ul class="grid grid-cols-3 gap-3 sm:grid-cols-4" role="list">
                            @foreach ($galleryImages as $i => $image)
                                <li wire:key="gallery-{{ $image['id'] }}" class="overflow-hidden rounded border border-border">
                                    @if ($image['missing'])
                                        <div class="flex aspect-square items-center justify-center bg-surface-muted p-2 text-center text-xs text-body-faint">{{ __('catalog.gallery_missing') }}</div>
                                    @else
                                        <img src="{{ $image['url'] }}" alt="{{ $image['name'] ?? '' }}" loading="lazy" class="aspect-square w-full object-cover">
                                    @endif
                                    <div class="flex items-center justify-between gap-1 border-t border-border bg-surface-muted px-1.5 py-1">
                                        @if ($i === 0)
                                            <span class="text-xs font-medium text-admin-primary">{{ __('catalog.gallery_primary') }}</span>
                                        @else
                                            <span></span>
                                        @endif
                                        <span class="flex items-center">
                                            <button type="button" wire:click="moveImageUp({{ $i }})" aria-label="{{ __('catalog.gallery_move_up') }}" class="rounded px-1 text-sm text-body-faint hover:bg-surface-hover hover:text-body-text">↑</button>
                                            <button type="button" wire:click="moveImageDown({{ $i }})" aria-label="{{ __('catalog.gallery_move_down') }}" class="rounded px-1 text-sm text-body-faint hover:bg-surface-hover hover:text-body-text">↓</button>
                                            <button type="button" wire:click="removeImage({{ $i }})" class="rounded px-1 text-sm text-admin-danger hover:bg-surface-hover">{{ __('catalog.gallery_remove') }}</button>
                                        </span>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @can('media.view')
                        <x-admin::button variant="soft" color="primary" wire:click="openImagePicker">{{ __('catalog.gallery_add') }}</x-admin::button>
                    @endcan
                    <p class="text-sm text-body-faint">{{ __('catalog.gallery_hint') }}</p>
                    @error('imageMediaIds.*') <p class="mt-1 text-sm text-admin-danger">{{ $message }}</p> @enderror
                </div>
            </x-admin::card>

            <x-admin::card>
                <x-admin::tabs :tabs="['general' => __('catalog.tab_general'), 'seo' => __('catalog.tab_seo')]">
                    <div x-show="active === 'general'" role="tabpanel" class="space-y-4">
                        <div>
                            <x-admin::input :label="__('catalog.price_label')" name="catalog-price" type="number" min="0" step="1" inputmode="numeric" wire:model="price" />
                            <p class="mt-1 text-sm text-body-faint">{{ __('catalog.price_hint') }}</p>
                            @error('price') <p class="mt-1 text-sm text-admin-danger">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <x-admin::input :label="__('catalog.sku_label')" name="catalog-sku" wire:model="sku" />
                            @error('sku') <p class="mt-1 text-sm text-admin-danger">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div x-show="active === 'seo'" role="tabpanel" class="space-y-4" x-cloak>
                        <div>
                            <x-admin::input :label="__('catalog.seo_title_label')" name="catalog-seo-title" wire:model="seoTitle" />
                            @error('seoTitle') <p class="mt-1 text-sm text-admin-danger">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-body-text" for="catalog-seo-description">{{ __('catalog.seo_description_label') }}</label>
                            <textarea id="catalog-seo-description" rows="3" wire:model="seoDescription"
                                      class="block w-full rounded border border-border-strong bg-surface px-3 py-2 text-sm text-body-text placeholder:text-placeholder transition-colors focus:border-admin-primary focus:outline-none focus:ring-2 focus:ring-admin-primary/20"></textarea>
                            @error('seoDescription') <p class="mt-1 text-sm text-admin-danger">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </x-admin::tabs>
            </x-admin::card>
        </div>

        <div class="space-y-4">
            <x-admin::card :title="__('catalog.card_publish')">
                <label class="mb-1.5 block text-sm font-medium text-body-text" for="catalog-status">{{ __('catalog.status_label') }}</label>
                <select id="catalog-status" wire:model="status"
                        class="block w-full rounded border border-border-strong bg-surface px-3 py-2 text-sm text-body-text transition-colors focus:border-admin-primary focus:outline-none focus:ring-2 focus:ring-admin-primary/20">
                    <option value="draft">{{ __('catalog.status_draft') }}</option>
                    <option value="published">{{ __('catalog.status_published') }}</option>
                    <option value="archived">{{ __('catalog.status_archived') }}</option>
                </select>
                @error('status') <p class="mt-1 text-sm text-admin-danger">{{ $message }}</p> @enderror
            </x-admin::card>

            <x-admin::card :title="__('catalog.category_label')">
                <select id="catalog-category" wire:model="categoryId"
                        class="block w-full rounded border border-border-strong bg-surface px-3 py-2 text-sm text-body-text transition-colors focus:border-admin-primary focus:outline-none focus:ring-2 focus:ring-admin-primary/20">
                    <option value="">{{ __('catalog.category_placeholder') }}</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category['id'] }}">{{ $category['name'] }}</option>
                    @endforeach
                </select>
                @error('categoryId') <p class="mt-1 text-sm text-admin-danger">{{ $message }}</p> @enderror
            </x-admin::card>

            <x-admin::card :title="__('catalog.summary_label')">
                <div wire:ignore>
                    <textarea id="catalog-summary" data-rich-editor="basic" data-rich-target="summary" rows="4" wire:model="summary">{{ $summary }}</textarea>
                </div>
                <p class="mt-2 text-sm text-body-faint">{{ __('catalog.summary_hint') }}</p>
                @error('summary') <p class="mt-1 text-sm text-admin-danger">{{ $message }}</p> @enderror
            </x-admin::card>

            <x-admin::button color="primary" class="w-full" wire:click="save" wire:loading.attr="disabled">{{ __('catalog.save') }}</x-admin::button>
        </div>
    </div>

    {{-- Library picker (Livewire-controlled — stays open so several images can be picked). --}}
    @if ($showImagePicker)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true" aria-label="{{ __('catalog.picker_title') }}">
            <div class="max-h-[80vh] w-full max-w-2xl overflow-y-auto rounded-md border border-border bg-surface p-4 shadow-card">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <h5 class="text-[1rem] font-semibold text-body-text">{{ __('catalog.picker_title') }}</h5>
                    <x-admin::button size="sm" variant="soft" color="primary" wire:click="closeImagePicker">{{ __('admin.dismiss') }}</x-admin::button>
                </div>
                @if ($pickerMedia->isEmpty())
                    <p class="text-sm text-body-faint">{{ __('catalog.picker_empty') }}</p>
                @else
                    <div class="grid grid-cols-3 gap-2 sm:grid-cols-5">
                        @foreach ($pickerMedia as $m)
                            @if (str_starts_with($m->mime, 'image/'))
                                @php($picked = in_array($m->id, $imageMediaIds, true))
                                <button type="button" wire:key="pick-{{ $m->id }}" wire:click="pickImage({{ $m->id }})"
                                        @disabled($picked)
                                        class="relative overflow-hidden rounded border transition-colors {{ $picked ? 'border-admin-primary opacity-60' : 'border-border hover:border-admin-primary' }}"
                                        title="{{ $m->original_filename }}">
                                    <img src="{{ $urls->url($m->storage_key, 320) }}" alt="{{ $m->original_filename }}" loading="lazy" class="aspect-square w-full object-cover">
                                    @if ($picked)
                                        <span class="absolute right-1 top-1 rounded bg-admin-primary px-1.5 py-0.5 text-xs text-white">✓</span>
                                    @endif
                                </button>
                            @endif
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
