<x-bs-admin::settings-shell active="catalog" :head="false">
    @if (session('status'))
        <x-bs-admin::alert color="success" icon="shield">{{ session('status') }}</x-bs-admin::alert>
    @endif

    <x-bs-admin::card :title="__('admin.catalog_settings')">
        <p class="mb-4 text-sm text-body-muted">{{ __('catalog.mode_intro') }}</p>

        <div class="space-y-3">
            <label class="flex cursor-pointer items-start gap-3 rounded border p-3 transition-colors {{ $mode === 'brochure' ? 'border-accent bg-admin-primary/5' : 'border-border hover:border-border-strong' }}">
                <input type="radio" wire:model.live="mode" value="brochure" class="mt-1 accent-admin-primary">
                <span>
                    <span class="block text-sm font-medium text-body-text">{{ __('catalog.mode_brochure_label') }}</span>
                    <span class="mt-0.5 block text-sm text-body-muted">{{ __('catalog.mode_brochure_desc') }}</span>
                </span>
            </label>

            <label class="flex cursor-pointer items-start gap-3 rounded border p-3 transition-colors {{ $mode === 'commerce' ? 'border-accent bg-admin-primary/5' : 'border-border hover:border-border-strong' }}">
                <input type="radio" wire:model.live="mode" value="commerce" class="mt-1 accent-admin-primary">
                <span>
                    <span class="block text-sm font-medium text-body-text">{{ __('catalog.mode_commerce_label') }}</span>
                    <span class="mt-0.5 block text-sm text-body-muted">{{ __('catalog.mode_commerce_desc') }}</span>
                </span>
            </label>
        </div>
        @error('mode') <p class="mt-2 text-sm text-admin-danger-text">{{ $message }}</p> @enderror

        @if ($productsTabAvailable)
            <div class="mt-6 border-t border-border pt-4" x-data="{ info: false }">
                <h6 class="mb-3 text-[calc(0.875rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('catalog.on_site_title') }}</h6>
                <div class="flex items-start justify-between gap-4">
                    <div class="flex min-w-0 flex-col gap-1">
                        <span id="cs-ptab-l" class="text-[calc(0.8125rem+var(--user-text-boost))] font-medium text-text-strong">{{ __('catalog.drawer_products_label') }}</span>
                        <button type="button" class="hit-area-row inline-flex items-center gap-1 self-start text-caption text-text-muted hover:text-primary-text"
                                x-on:click="info = !info" x-bind:aria-expanded="info ? 'true' : 'false'"
                                aria-controls="cs-ptab-info" aria-label="{{ __('admin.gs.detail_aria', ['label' => __('catalog.drawer_products_label')]) }}">
                            <x-bs-admin::icon name="ri-information" class="size-[min(1.5rem,calc(0.875rem+var(--user-text-boost)))]" />{{ __('admin.gs.detail') }}
                        </button>
                        <div id="cs-ptab-info" role="note" x-show="info" x-cloak
                             class="rounded-lg border border-border bg-surface px-3 py-2.5 text-caption leading-relaxed text-text [overflow-wrap:break-word]">{{ __('catalog.drawer_products_help') }}</div>
                    </div>
                    <button type="button" class="switch hit-area" role="switch" aria-checked="{{ $drawerProducts ? 'true' : 'false' }}" aria-labelledby="cs-ptab-l"
                            wire:click="$toggle('drawerProducts')"></button>
                </div>
                @error('drawerProducts') <p class="mt-2 text-sm text-danger-text">{{ $message }}</p> @enderror
            </div>
        @endif

        <x-slot:footer>
            <div class="flex justify-end">
                <x-bs-admin::button color="primary" wire:click="save" wire:loading.attr="disabled">{{ __('catalog.save') }}</x-bs-admin::button>
            </div>
        </x-slot:footer>
    </x-bs-admin::card>
</x-bs-admin::settings-shell>
