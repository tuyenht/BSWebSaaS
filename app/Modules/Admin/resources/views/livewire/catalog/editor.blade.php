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
</div>
