<x-admin::settings-shell active="catalog">
    @if (session('status'))
        <x-admin::alert color="success" icon="shield">{{ session('status') }}</x-admin::alert>
    @endif

    <x-admin::card :title="__('admin.catalog_settings')">
        <p class="mb-4 text-sm text-body-faint">{{ __('catalog.mode_intro') }}</p>

        <div class="space-y-3">
            <label class="flex cursor-pointer items-start gap-3 rounded border p-3 transition-colors {{ $mode === 'brochure' ? 'border-admin-primary bg-admin-primary/5' : 'border-border hover:border-border-strong' }}">
                <input type="radio" wire:model.live="mode" value="brochure" class="mt-1 accent-admin-primary">
                <span>
                    <span class="block text-sm font-medium text-body-text">{{ __('catalog.mode_brochure_label') }}</span>
                    <span class="mt-0.5 block text-sm text-body-faint">{{ __('catalog.mode_brochure_desc') }}</span>
                </span>
            </label>

            <label class="flex cursor-pointer items-start gap-3 rounded border p-3 transition-colors {{ $mode === 'commerce' ? 'border-admin-primary bg-admin-primary/5' : 'border-border hover:border-border-strong' }}">
                <input type="radio" wire:model.live="mode" value="commerce" class="mt-1 accent-admin-primary">
                <span>
                    <span class="block text-sm font-medium text-body-text">{{ __('catalog.mode_commerce_label') }}</span>
                    <span class="mt-0.5 block text-sm text-body-faint">{{ __('catalog.mode_commerce_desc') }}</span>
                </span>
            </label>
        </div>
        @error('mode') <p class="mt-2 text-sm text-admin-danger-text">{{ $message }}</p> @enderror

        <x-slot:footer>
            <div class="flex justify-end">
                <x-admin::button color="primary" wire:click="save" wire:loading.attr="disabled">{{ __('catalog.save') }}</x-admin::button>
            </div>
        </x-slot:footer>
    </x-admin::card>
</x-admin::settings-shell>
