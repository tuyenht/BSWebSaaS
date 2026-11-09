{{-- The product editor (design 09): the crumb and title, the bar that stays under the admin's top
     bar (Quay lại · the save state · Thử lại · the save buttons; below 768px wide the buttons move
     to a bar along the bottom), then the cards in the board's order. Main column: Thông tin sản
     phẩm (name; slug and summary in the "Thêm tùy chọn" group), Ảnh sản phẩm, Giá, Mô tả, the
     shared "Hiện thế nào trên Google" card, the "Thêm tùy chọn" button. Side column: Đăng (status,
     first publication, last save, what visitors see), then in the group Chuyên mục and Mã SKU. The
     group stays open or closed per browser (localStorage 'bs-editor'), and opens when a field in it
     has an error. The save state at rest is drawn here; typing, saving and a lost connection are
     drawn over it in the browser (resources/js/admin-editor.js). The description is a Jodit island
     (lazy chunk) inside wire:ignore; its HTML syncs back deferred. Sizes come from the control
     tokens; the layout follows the editor's own width (container queries). --}}
@php
    use App\Modules\Admin\Livewire\Catalog\Editor;
    use App\Modules\Admin\Support\SeoFields;
    use Illuminate\Support\Js;

    $isNew = $ulid === null;
    $title = $isNew ? __('catalog.create_title') : __('catalog.edit_title');
    $bandIcons = ['empty' => 'ri-edit', 'short' => 'ri-arrow-up', 'near' => 'ri-arrow-right-up', 'good' => 'ri-check', 'long' => 'ri-error-warning', 'over' => 'ri-close-circle'];
    // The name and summary meters are the shared part's (bsSeoFields): the name measured like a title, the summary like a short description.
    $infoConfig = SeoFields::clientConfig(['excerpt' => 'summary', 'title' => 'name', 'description' => null, 'pageTitle' => null, 'slug' => 'slug'], $address);
    $saveConfig = ['fields' => Editor::TRACKED, 'words' => [
        'dirty' => __('catalog.ed.sv_dirty'),
        'dirtyDraft' => __('catalog.ed.sv_dirty_draft'),
        'dirtyLive' => __('catalog.ed.sv_dirty_live'),
        'saving' => __('catalog.ed.sv_saving'),
        'offline' => __('catalog.ed.sv_offline'),
    ]];
    $field = 'field block w-full rounded-md border border-border-input bg-surface-muted px-3 text-text placeholder:text-placeholder transition-colors focus:border-primary-text aria-invalid:border-danger-text';
    $label = 'inline-flex min-w-0 max-w-full flex-wrap items-center gap-x-1.5 gap-y-1 text-small font-semibold text-text-strong';
    $labelRow = 'mb-1.5 flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1';
    $error = 'mt-1 text-small text-danger-text';
    $hint = 'mt-1.5 text-caption text-text-muted';
    $cardHead = 'flex flex-wrap items-center gap-2 border-b border-border px-4 py-3';
    $cardTitle = 'm-0 text-[calc(1rem+var(--user-text-boost))] font-semibold leading-tight text-text';
    $chip = $multiLocale ? strtoupper($locale) : null;
    $glyph = 'size-[1.15em] shrink-0';
    $more = ['slug', 'summary', 'sku', 'categoryId'];
@endphp
<div class="catalog-editor @container" data-catalog-editor>
    <div class="mx-auto flex max-w-[80rem] min-w-0 flex-col gap-4">
        <div>
            <nav aria-label="{{ __('admin.breadcrumb') }}" class="hidden text-caption text-text-muted min-[600px]:block">
                <div class="flex flex-wrap items-center gap-1.5">
                    <x-bs-admin::icon name="ri-home-4" class="size-3.5" />
                    <span>{{ __('admin.breadcrumb_home') }}</span>
                    <x-bs-admin::icon name="ri-arrow-right-s" class="size-3.5 text-placeholder" />
                    <a href="{{ route('admin.catalog.index') }}" wire:navigate class="hit-area transition-colors hover:text-primary-text">{{ __('catalog.ed.crumb_list') }}</a>
                    <x-bs-admin::icon name="ri-arrow-right-s" class="size-3.5 text-placeholder" />
                    <span class="font-medium" aria-current="page">{{ $isNew ? __('catalog.ed.crumb_new') : __('catalog.ed.crumb_edit') }}</span>
                </div>
            </nav>
            <h1 class="m-0 mt-0.5 text-[calc(1.25rem+var(--user-text-boost))] font-semibold leading-snug text-text-strong [overflow-wrap:anywhere]">{{ $title }}</h1>
        </div>

        <div class="ped-bar flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-border bg-bg py-2 @max-[48rem]:flex-nowrap @max-[48rem]:gap-2"
             x-ignore wire:ignore.self data-bs-lazy="bsEditorSave" x-data="bsEditorSave({{ Js::from($saveConfig) }})" x-bind:data-sv-over="state() ? 'on' : false">
            <x-bs-admin::button variant="ghost" :href="route('admin.catalog.index')" class="shrink-0 @max-[37.5rem]:w-(--spacing-control) @max-[37.5rem]:px-0"
                                title="{{ __('catalog.ed.back_title') }}" aria-label="{{ __('catalog.ed.back_title') }}">
                <x-bs-admin::icon name="ri-arrow-left" :class="$glyph" /><span class="@max-[37.5rem]:hidden">{{ __('catalog.ed.back') }}</span>
            </x-bs-admin::button>

            <x-bs-admin::save-state :note="$note" class="@max-[48rem]:flex-1" />
            <span wire:ignore class="contents">
                <x-bs-admin::button variant="outline" color="secondary" size="sm" class="shrink-0" x-show="state() === 'offline'" x-cloak x-on:click="retry()">
                    <x-bs-admin::icon name="ri-refresh" :class="$glyph" /> {{ __('catalog.ed.retry') }}
                </x-bs-admin::button>
            </span>

            <span class="flex-1 @max-[48rem]:hidden"></span>
            <div class="flex flex-wrap items-center justify-end gap-2 @max-[48rem]:hidden">
                @if ($actions['secondary'] !== null)
                    <x-bs-admin::button variant="outline" color="secondary" wire:click="save" wire:loading.attr="disabled" wire:target="save,publish">
                        <x-bs-admin::icon name="ri-save-3" :class="$glyph" /> {{ $actions['secondary'] }}
                    </x-bs-admin::button>
                @endif
                <x-bs-admin::button color="primary" wire:click="publish" wire:loading.attr="disabled" wire:target="save,publish">
                    <x-bs-admin::icon :name="$actions['primaryIcon']" :class="$glyph" /> {{ $actions['primary'] }}
                </x-bs-admin::button>
            </div>
        </div>

        <div class="grid min-w-0 items-start gap-4 @min-[64rem]:grid-cols-3"
             x-data="{ more: false, init() { try { this.more = JSON.parse(localStorage.getItem('bs-editor') || '{}').more === true } catch (e) {} }, setMore(on) { this.more = on; try { const o = JSON.parse(localStorage.getItem('bs-editor') || '{}') || {}; o.more = on; localStorage.setItem('bs-editor', JSON.stringify(o)) } catch (e) {} } }">
            <div class="flex min-w-0 flex-col gap-4 @min-[64rem]:col-span-2">
                <section class="rounded-md border border-border bg-surface shadow-card" aria-labelledby="ped-h-info" x-data="bsSeoFields({{ Js::from($infoConfig) }})">
                    <div class="{{ $cardHead }}"><h2 id="ped-h-info" class="{{ $cardTitle }}">{{ __('catalog.card_product') }}</h2></div>
                    <div class="flex flex-col gap-4 p-4">
                        <div>
                            <div class="{{ $labelRow }}">
                                <label for="catalog-name" class="{{ $label }}">
                                    <span>{{ __('catalog.name_label') }} <span class="text-danger-text" aria-hidden="true">*</span></span>
                                    @if ($chip)
                                        <span class="rounded-sm bg-primary-soft px-1.5 text-caption font-bold text-primary-text" title="{{ __('admin.seo.locale_chip_title') }}">{{ $chip }}</span>
                                    @endif
                                </label>
                            </div>
                            <textarea id="catalog-name" wire:model="name" rows="1" maxlength="{{ \App\Modules\Catalog\Actions\RenameProduct::MAX }}" lang="{{ $locale }}" aria-required="true"
                                      aria-describedby="catalog-name-c @error('name') catalog-name-e @enderror" @error('name') aria-invalid="true" @enderror
                                      x-on:keydown.enter="if (! $event.isComposing && $event.keyCode !== 229) $event.preventDefault()"
                                      class="{{ $field }} resize-none py-2 [field-sizing:content]"></textarea>
                            <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1">
                                <span class="seo-meter min-w-24 flex-1" :data-b="titleMeter.band" aria-hidden="true">
                                    <span class="seo-meter__zone" :style="{ left: titleMeter.lo + '%', width: titleMeter.zw + '%' }"></span>
                                    <span class="seo-meter__fill" :style="{ width: titleMeter.pct + '%' }"></span>
                                    <span class="seo-meter__tick" :style="{ left: titleMeter.tick + '%' }"></span>
                                </span>
                                <span id="catalog-name-c" class="seo-count" :data-b="titleMeter.band" data-b="empty">
                                    @foreach ($bandIcons as $band => $icon)
                                        <x-bs-admin::icon :name="$icon" x-show="titleMeter.band === '{{ $band }}'" x-cloak />
                                    @endforeach
                                    <span x-text="titleMeter.text"></span>
                                </span>
                            </div>
                            @error('name') <p id="catalog-name-e" class="{{ $error }}">{{ $message }}</p> @enderror
                        </div>

                        <div x-show="more" x-cloak>
                            <label for="catalog-slug" class="{{ $label }} mb-1.5">
                                <span>{{ __('catalog.card_opt_slug') }}</span>
                                @if ($chip)
                                    <span class="rounded-sm bg-primary-soft px-1.5 text-caption font-bold text-primary-text" title="{{ __('admin.seo.locale_chip_title') }}">{{ $chip }}</span>
                                @endif
                            </label>
                            <div class="flex min-w-0 items-center rounded-md border border-border-input bg-surface-muted px-3 focus-within:border-primary-text has-aria-invalid:border-danger-text">
                                <span class="shrink-0 text-small text-text-muted" dir="ltr">/</span>
                                <input type="text" id="catalog-slug" wire:model="slug" dir="ltr" spellcheck="false" autocomplete="off"
                                       aria-describedby="catalog-slug-h @error('slug') catalog-slug-e @enderror" @error('slug') aria-invalid="true" @enderror
                                       class="field min-w-0 flex-1 border-0 bg-transparent px-1 font-mono text-text">
                            </div>
                            @error('slug') <p id="catalog-slug-e" class="{{ $error }}" x-init="setMore(true)">{{ $message }}</p> @enderror
                            @if ($liveUrl !== null && ($saved['slug'] ?? '') !== '')
                                <p class="mt-1.5 flex items-start gap-1.5 text-small text-warning-text" x-show="$wire.slug !== @js($saved['slug'])" x-cloak>
                                    <x-bs-admin::icon name="ri-route" class="mt-0.5 {{ $glyph }}" />
                                    <span>{{ __('catalog.ed.slug_moves', ['old' => $liveUrl]) }}</span>
                                </p>
                            @endif
                            <div id="catalog-slug-h" class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1">
                                <span class="min-w-0 text-caption text-text-muted [overflow-wrap:anywhere]" dir="ltr" x-text="previewAddress">{{ $address['base'].$slug }}</span>
                                @if ($liveUrl !== null)
                                    <x-bs-admin::button variant="ghost" size="sm" :href="'//'.$liveUrl" :navigate="false" target="_blank" rel="noopener">
                                        <x-bs-admin::icon name="ri-external-link" :class="$glyph" /> {{ __('catalog.view_on_site') }}
                                    </x-bs-admin::button>
                                @endif
                            </div>
                        </div>

                        <div x-show="more" x-cloak>
                            <div class="{{ $labelRow }}">
                                <label for="catalog-summary" class="{{ $label }}">
                                    <span>{{ __('catalog.ed.summary_label') }}</span>
                                    @if ($chip)
                                        <span class="rounded-sm bg-primary-soft px-1.5 text-caption font-bold text-primary-text" title="{{ __('admin.seo.locale_chip_title') }}">{{ $chip }}</span>
                                    @endif
                                </label>
                                <span id="catalog-summary-c" class="seo-count" :data-b="excerptMeter.band" data-b="empty">
                                    @foreach ($bandIcons as $band => $icon)
                                        <x-bs-admin::icon :name="$icon" x-show="excerptMeter.band === '{{ $band }}'" x-cloak />
                                    @endforeach
                                    <span x-text="excerptMeter.text"></span>
                                </span>
                            </div>
                            <textarea id="catalog-summary" wire:model="summary" rows="3" maxlength="{{ Editor::SUMMARY_MAX }}" lang="{{ $locale }}"
                                      aria-describedby="catalog-summary-c catalog-summary-h @if ($summaryWarning) catalog-summary-w @endif @error('summary') catalog-summary-e @enderror" @error('summary') aria-invalid="true" @enderror
                                      class="{{ $field }} resize-y py-2.5"></textarea>
                            <span class="seo-meter" :data-b="excerptMeter.band" aria-hidden="true">
                                <span class="seo-meter__zone" :style="{ left: excerptMeter.lo + '%', width: excerptMeter.zw + '%' }"></span>
                                <span class="seo-meter__fill" :style="{ width: excerptMeter.pct + '%' }"></span>
                                <span class="seo-meter__tick" :style="{ left: excerptMeter.tick + '%' }"></span>
                            </span>
                            @error('summary') <p id="catalog-summary-e" class="{{ $error }}" x-init="setMore(true)">{{ $message }}</p> @enderror
                            @if ($summaryWarning)
                                <p id="catalog-summary-w" class="mt-1.5 flex items-start gap-1.5 text-small text-warning-text" x-init="setMore(true)">
                                    <x-bs-admin::icon name="ri-error-warning" class="mt-0.5 {{ $glyph }}" /><span>{{ $summaryWarning }}</span>
                                </p>
                            @endif
                            <p id="catalog-summary-h" class="{{ $hint }}">{{ __('catalog.ed.summary_hint') }}</p>
                        </div>
                    </div>
                </section>

                <section class="rounded-md border border-border bg-surface shadow-card" aria-labelledby="ped-h-img">
                    <div class="{{ $cardHead }}"><h2 id="ped-h-img" class="{{ $cardTitle }}">{{ __('catalog.card_gallery') }}</h2></div>
                    <div class="space-y-3 p-4">
                        @if ($galleryImages === [])
                            <p class="text-sm text-text-muted">{{ __('catalog.gallery_empty') }}</p>
                        @else
                            <ul class="grid grid-cols-3 gap-3 sm:grid-cols-4" role="list">
                                @foreach ($galleryImages as $i => $image)
                                    <li wire:key="gallery-{{ $image['id'] }}" class="overflow-hidden rounded border border-border">
                                        @if ($image['missing'])
                                            <div class="flex aspect-square items-center justify-center bg-surface-muted p-2 text-center text-xs text-text-muted">{{ __('catalog.gallery_missing') }}</div>
                                        @else
                                            <img src="{{ $image['url'] }}" alt="{{ $image['name'] ?? '' }}" loading="lazy" class="aspect-square w-full object-cover">
                                        @endif
                                        <div class="flex items-center justify-between gap-1 border-t border-border bg-surface-muted px-1.5 py-1">
                                            @if ($i === 0)
                                                <span class="text-xs font-medium text-primary-text">{{ __('catalog.gallery_primary') }}</span>
                                            @else
                                                <span></span>
                                            @endif
                                            <span class="flex items-center">
                                                <button type="button" wire:click="moveImageUp({{ $i }})" aria-label="{{ __('catalog.gallery_move_up') }}" class="rounded px-1 text-sm text-text-muted hover:bg-surface-hover hover:text-text">↑</button>
                                                <button type="button" wire:click="moveImageDown({{ $i }})" aria-label="{{ __('catalog.gallery_move_down') }}" class="rounded px-1 text-sm text-text-muted hover:bg-surface-hover hover:text-text">↓</button>
                                                <button type="button" wire:click="removeImage({{ $i }})" class="rounded px-1 text-sm text-danger-text hover:bg-surface-hover">{{ __('catalog.gallery_remove') }}</button>
                                            </span>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        @can('media.view')
                            <x-bs-admin::button variant="soft" color="primary" wire:click="openImagePicker">{{ __('catalog.gallery_add') }}</x-bs-admin::button>
                        @endcan
                        <p class="text-sm text-text-muted">{{ __('catalog.gallery_hint') }}</p>
                        @error('imageMediaIds.*') <p class="mt-1 text-sm text-danger-text">{{ $message }}</p> @enderror
                    </div>
                </section>

                <section class="rounded-md border border-border bg-surface shadow-card" aria-labelledby="ped-h-price">
                    <div class="{{ $cardHead }}"><h2 id="ped-h-price" class="{{ $cardTitle }}">{{ __('catalog.card_price') }}</h2></div>
                    <div class="p-4">
                        <x-bs-admin::input :label="__('catalog.price_label')" name="catalog-price" type="number" min="0" step="1" inputmode="numeric" wire:model="price" aria-describedby="catalog-price-h" />
                        <p id="catalog-price-h" class="mt-1 text-sm text-text-muted">{{ __('catalog.price_hint') }}</p>
                    </div>
                </section>

                <section class="rounded-md border border-border bg-surface shadow-card" aria-labelledby="ped-h-desc">
                    <div class="{{ $cardHead }}">
                        <h2 id="ped-h-desc" class="{{ $cardTitle }}"><label for="rich-editor-description">{{ __('catalog.ed.card_desc') }}</label></h2>
                        @if ($chip)
                            <span class="rounded-sm bg-primary-soft px-1.5 text-caption font-bold text-primary-text" title="{{ __('admin.seo.locale_chip_title') }}">{{ $chip }}</span>
                        @endif
                    </div>
                    <div class="p-4">
                        <x-bs-admin::rich-editor target="description" profile="full" :value="$description" rows="10" />
                        @error('description') <p class="mt-1 text-sm text-danger-text">{{ $message }}</p> @enderror
                    </div>
                </section>

                <x-bs-admin::seo-fields page-title-model="name" kind="product" :address="$address" :locale="$locale" :multi-locale="$multiLocale" id="catalog-seo" />

                <button type="button" x-on:click="setMore(! more)" x-bind:aria-expanded="more ? 'true' : 'false'" aria-expanded="false"
                        class="grid w-full grid-cols-[auto_minmax(0,1fr)] items-center gap-x-2.5 gap-y-1 rounded-md border border-dashed border-border-input px-4 py-2.5 text-left text-body font-semibold text-primary-text transition-colors min-h-12 hover:bg-[color-mix(in_srgb,var(--color-primary-text)_6%,var(--color-surface))]">
                    <x-bs-admin::icon name="ri-add" :class="$glyph" x-show="! more" />
                    <x-bs-admin::icon name="ri-subtract" :class="$glyph" x-show="more" x-cloak />
                    <span x-text="more ? @js(__('catalog.ed.less')) : @js(__('catalog.ed.more'))">{{ __('catalog.ed.more') }}</span>
                    <span class="col-start-2 text-small font-normal text-text-muted" x-text="more ? @js(__('catalog.ed.less_sub')) : @js(__('catalog.ed.more_sub'))">{{ __('catalog.ed.more_sub') }}</span>
                </button>
            </div>

            <div class="flex min-w-0 flex-col gap-4">
                <section class="rounded-md border border-border bg-surface shadow-card" aria-labelledby="ped-h-pub">
                    <div class="{{ $cardHead }}">
                        <h2 id="ped-h-pub" class="{{ $cardTitle }}">{{ __('catalog.card_publish') }}</h2>
                        <span class="flex-1"></span>
                        <x-bs-admin::status-pill :status="$publishCard['status']">{{ $publishCard['chip'] }}</x-bs-admin::status-pill>
                    </div>
                    <div class="flex flex-col gap-3 p-4">
                        <dl class="m-0 grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-2 text-small">
                            <dt class="text-text-muted">{{ __('catalog.ed.pub_label') }}</dt><dd class="m-0 text-text [overflow-wrap:anywhere]">{{ $publishCard['published'] }}</dd>
                            <dt class="text-text-muted">{{ __('catalog.ed.upd_label') }}</dt><dd class="m-0 text-text [overflow-wrap:anywhere]">{{ $publishCard['updated'] }}</dd>
                            <dt class="text-text-muted">{{ __('catalog.ed.seen_label') }}</dt><dd class="m-0 text-text [overflow-wrap:anywhere]">{{ $publishCard['seen'] }}</dd>
                        </dl>
                        @if ($liveUrl !== null)
                            <div>
                                <x-bs-admin::button variant="outline" color="secondary" size="sm" :href="'//'.$liveUrl" :navigate="false" target="_blank" rel="noopener">
                                    <x-bs-admin::icon name="ri-external-link" :class="$glyph" /> {{ __('catalog.view_on_site') }}
                                </x-bs-admin::button>
                            </div>
                        @endif
                    </div>
                </section>

                <section class="rounded-md border border-border bg-surface shadow-card" aria-labelledby="ped-h-cat" x-show="more" x-cloak>
                    <div class="{{ $cardHead }}"><h2 id="ped-h-cat" class="{{ $cardTitle }}"><label for="catalog-category">{{ __('catalog.card_opt_cat') }}</label></h2></div>
                    <div class="p-4">
                        <select id="catalog-category" wire:model.live="categoryId" @error('categoryId') aria-invalid="true" aria-describedby="catalog-category-e" @enderror
                                class="field block w-full rounded border border-border-strong bg-surface px-3 text-text transition-colors focus:border-primary-text">
                            <option value="">{{ __('catalog.category_placeholder') }}</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category['id'] }}">{{ $category['name'] }}</option>
                            @endforeach
                        </select>
                        @error('categoryId') <p id="catalog-category-e" class="{{ $error }}" x-init="setMore(true)">{{ $message }}</p> @enderror
                    </div>
                </section>

                <section class="rounded-md border border-border bg-surface shadow-card" aria-labelledby="ped-h-sku" x-show="more" x-cloak>
                    <div class="{{ $cardHead }}"><h2 id="ped-h-sku" class="{{ $cardTitle }}">{{ __('catalog.card_opt_sku') }}</h2></div>
                    <div class="p-4">
                        <label for="catalog-sku" class="{{ $label }} mb-1.5">{{ __('catalog.card_opt_sku') }}</label>
                        <input type="text" id="catalog-sku" wire:model="sku" dir="ltr" autocomplete="off" spellcheck="false"
                               aria-describedby="catalog-sku-h @error('sku') catalog-sku-e @enderror" @error('sku') aria-invalid="true" @enderror
                               class="field block w-full rounded-md border border-border-input bg-surface-muted px-3 font-mono text-text placeholder:text-placeholder transition-colors focus:border-primary-text aria-invalid:border-danger-text">
                        @error('sku') <p id="catalog-sku-e" class="{{ $error }}" x-init="setMore(true)">{{ $message }}</p> @enderror
                        <p id="catalog-sku-h" class="{{ $hint }}">{{ __('catalog.ed.sku_hint') }}</p>
                    </div>
                </section>
            </div>
        </div>

        <div class="ped-bbar flex gap-2 border-t border-border bg-bg pt-2.5 @min-[48rem]:hidden" x-data>
            @if ($actions['secondary'] !== null)
                <x-bs-admin::button variant="outline" color="secondary" class="min-w-0 flex-1" wire:click="save" wire:loading.attr="disabled" wire:target="save,publish">
                    <x-bs-admin::icon name="ri-save-3" :class="$glyph" /> {{ $actions['secondary'] }}
                </x-bs-admin::button>
            @endif
            <x-bs-admin::button color="primary" class="min-w-0 flex-1" wire:click="publish" wire:loading.attr="disabled" wire:target="save,publish">
                <x-bs-admin::icon :name="$actions['primaryIcon']" :class="$glyph" /> {{ $actions['primary'] }}
            </x-bs-admin::button>
        </div>
    </div>

    <x-bs-admin::unsaved-dialog :count="$changes" :label="$title" save="save" discard="resetChanges" :body="$leave['body']" :save-label="$leave['save']" />

    {{-- Shared library picker (WithMediaLibraryPicker): gallery mode marks already-attached
         images and stays open for multi-pick; editor mode inserts at the caret and closes. --}}
    @include('admin::partials.media-picker-modal', ['pickedIds' => $imagePickerMode === 'gallery' ? $imageMediaIds : []])
    @include('admin::partials.video-embed-modal')
</div>
