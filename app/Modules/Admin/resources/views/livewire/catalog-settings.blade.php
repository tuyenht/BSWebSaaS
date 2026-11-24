@php
    // Design board «Khung quản trị», section «Sản phẩm»: the frame's own title, then folding groups. «Cách khách xem
    // sản phẩm» picks the mode on two cards, each drawing the product card a visitor sees in that mode; «Trên website»
    // holds the phone menu's products tab. The board's other fields (reference price, stock count, out-of-stock rule,
    // contact button words) wait for their settings to exist.
    $modes = ['brochure', 'commerce'];
@endphp
<x-bs-admin::settings-shell active="catalog" :unsaved="$changes" :invalid="count($errors->keys())">
    @if (session('status'))
        <x-bs-admin::alert color="success" icon="ri-checkbox-circle">{{ session('status') }}</x-bs-admin::alert>
    @endif
    {{-- Enter in a field is the save bar's "Lưu"; the form itself never submits, so the page never reloads --}}
    <div x-data="{ open: 'view' }">
    <form x-data x-on:submit.prevent class="flex flex-col gap-3">
        <x-bs-admin::settings-group key="view" id="cs-g-view" :title="__('catalog.g_view')" :count="1" :bad="$errors->has('mode') ? 1 : 0" :first="true"
                                    class="flex flex-col gap-3">
            <div role="radiogroup" aria-labelledby="cs-mode-l" aria-describedby="cs-mode-d" class="flex flex-col gap-2">
                <span id="cs-mode-l" class="text-[calc(0.8125rem+var(--user-text-boost))] font-medium text-text-strong">{{ __('catalog.mode_label') }}</span>
                <div class="grid grid-cols-[repeat(auto-fit,minmax(min(100%,15rem),1fr))] gap-2.5">
                    @foreach ($modes as $m)
                        <label class="group flex min-h-control cursor-pointer flex-col gap-2 rounded-md border border-border-strong bg-surface px-3 py-3 transition-colors hover:border-primary-text has-[:checked]:border-primary-text has-[:checked]:bg-primary-soft/40 has-[:checked]:shadow-[0_0_0_1px_var(--color-primary-text)] has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-primary-text"
                               wire:key="cs-mode-{{ $m }}">
                            <input type="radio" name="cs-mode" value="{{ $m }}" wire:model.live="mode" class="sr-only">
                            <span class="flex items-center gap-2">
                                <span class="hidden text-primary-text group-has-[:checked]:inline-flex"><x-bs-admin::icon name="ri-checkbox-circle" class="size-[min(1.5rem,calc(1.125rem+var(--user-text-boost)))]" /></span>
                                <span class="inline-flex text-text-muted group-has-[:checked]:hidden"><x-bs-admin::icon name="ri-checkbox-blank-circle" class="size-[min(1.5rem,calc(1.125rem+var(--user-text-boost)))]" /></span>
                                <span class="text-[calc(0.875rem+var(--user-text-boost))] font-semibold text-text-strong">{{ __('catalog.mode_'.$m.'_label') }}</span>
                            </span>
                            {{-- the product card a visitor sees in this mode --}}
                            <span class="flex gap-2 rounded border border-border bg-surface-muted p-2" aria-hidden="true">
                                <span class="flex size-13 shrink-0 items-center justify-center rounded bg-primary-soft text-primary-text"><x-bs-admin::icon name="ri-image" class="size-[min(1.5rem,calc(1.25rem+var(--user-text-boost)))]" /></span>
                                <span class="flex min-w-0 flex-1 flex-col gap-1 text-caption leading-snug">
                                    <span class="font-semibold text-text-strong">{{ __('catalog.art_name') }}</span>
                                    @if ($m === 'commerce')
                                        <span class="font-bold text-primary-text">{{ __('catalog.art_price') }}</span>
                                        <span class="text-success-text">{{ __('catalog.art_stock') }}</span>
                                    @else
                                        <span class="text-text-muted">{{ __('catalog.art_short') }}</span>
                                        <span class="self-start rounded bg-primary px-2 font-semibold text-on-primary">{{ __('catalog.art_cta') }}</span>
                                    @endif
                                </span>
                            </span>
                            <span class="text-small leading-normal text-text-muted">{{ __('catalog.mode_'.$m.'_desc') }}</span>
                        </label>
                    @endforeach
                </div>
                <span id="cs-mode-d" class="text-caption text-text-muted">{{ __('catalog.mode_art_note') }}</span>
            </div>
            @error('mode')
                <x-bs-admin::field-error>{{ $message }}</x-bs-admin::field-error>
            @enderror
            @if ($mode === 'commerce')
                <x-bs-admin::alert color="info" icon="ri-information" role="note">{{ __('catalog.commerce_note') }}</x-bs-admin::alert>
            @endif
        </x-bs-admin::settings-group>

        @if ($productsTabAvailable)
            <x-bs-admin::settings-group key="site" id="cs-g-site" :title="__('catalog.on_site_title')" :count="1" :bad="$errors->has('drawerProducts') ? 1 : 0">
                <div class="flex items-start justify-between gap-4" x-data="{ info: false }">
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
                @error('drawerProducts')
                    <x-bs-admin::field-error class="mt-2">{{ $message }}</x-bs-admin::field-error>
                @enderror
            </x-bs-admin::settings-group>
        @endif
    </form>
    </div>
</x-bs-admin::settings-shell>
