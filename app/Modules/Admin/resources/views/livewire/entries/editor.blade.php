{{-- The post and page editor (design 07, one screen for both; `type` picks what differs). The crumb,
     the bar that stays under the admin's top bar (Quay lại · the title and the save state · the save
     buttons; when the editor's area is under 768px wide, its content under 720px, the buttons move to
     a bar along the bottom), the status band of live
     content, then two columns in the board's order. Main: Tiêu đề and Đường dẫn, Mô tả ngắn, the
     content card (a page's layout blocks under "Trình dựng"; a post's blocks until its writing frame
     comes), the shared "Hiện thế nào trên Google" card. Side: Đăng (status, the approval steps where a
     workflow governs the post), Chuyên mục (posts), Ảnh đại diện. The save state at rest is drawn
     here; typing, saving and a lost connection are drawn over it in the browser (bsEditorSave,
     resources/js/admin-editor.js; this editor's drafts do not save themselves). The block list is
     counted by the server (blocksChanged). Columns follow the editor's own width (flex basis
     520 / 300, as the board). --}}
@php
    use App\Modules\Admin\Livewire\Entries\Editor;
    use Illuminate\Support\Js;

    $isNew = $ulid === null;
    $isPage = $type === 'page';
    $h1 = $isNew ? __('entries.ed.h1_new', ['unit' => $unit]) : __('entries.ed.h1_edit', ['unit' => $unit]);
    $dirty = __('catalog.ed.sv_dirty');
    $saveConfig = ['fields' => Editor::TRACKED, 'autosave' => false, 'extra' => 'blocksChanged', 'words' => [
        'dirty' => $dirty,
        'dirtyDraft' => $dirty,
        'dirtyLive' => $dirty,
        'saving' => __('catalog.ed.sv_saving'),
        'offline' => __('catalog.ed.sv_offline'),
        'offlineToast' => __('catalog.ed.toast_offline'),
        'offlineToastIcon' => \Illuminate\Support\Facades\Blade::render('<x-bs-admin::icon name="ri-wifi-off" class="size-4" />'),
    ]];
    $field = 'field block w-full rounded-md border border-border-input bg-surface-muted px-3 text-text placeholder:text-placeholder transition-colors focus:border-primary-text aria-invalid:border-danger-text';
    $label = 'inline-flex min-w-0 max-w-full flex-wrap items-center gap-x-1.5 gap-y-1 text-small font-semibold text-text-strong';
    $error = 'mt-1 text-small text-danger-text';
    $card = 'rounded-md border border-border bg-surface shadow-card';
    $cardHead = 'flex flex-wrap items-center gap-2 border-b border-border px-4 py-3';
    $cardTitle = 'm-0 text-[calc(1rem+var(--user-text-boost))] font-semibold leading-tight text-text';
    $chip = $multiLocale ? strtoupper($locale) : null;
    $glyph = 'size-[1.15em] shrink-0';
@endphp
<div class="entry-editor @container" data-entry-editor data-kind="{{ $type }}">
    <div class="mx-auto flex max-w-[80rem] min-w-0 flex-col gap-4">
        <nav aria-label="{{ __('admin.breadcrumb') }}" class="text-caption text-text-muted @max-[35rem]:hidden">
            <div class="flex flex-wrap items-center gap-1.5">
                <a href="{{ route('admin.dashboard') }}" wire:navigate class="hit-area inline-flex items-center transition-colors hover:text-primary-text" title="{{ __('admin.breadcrumb_home') }}">
                    <x-bs-admin::icon name="ri-home-4" class="size-3.5" /><span class="sr-only">{{ __('admin.breadcrumb_home') }}</span>
                </a>
                <x-bs-admin::icon name="ri-arrow-right-s" class="size-3.5 text-placeholder" />
                <a href="{{ $listUrl }}" wire:navigate class="hit-area transition-colors hover:text-primary-text">{{ $listLabel }}</a>
                <x-bs-admin::icon name="ri-arrow-right-s" class="size-3.5 text-placeholder" />
                <span class="font-medium text-text" aria-current="page">{{ $h1 }}</span>
            </div>
        </nav>

        <div class="ped-bar flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-border bg-bg py-2 @max-[45rem]:flex-nowrap @max-[45rem]:gap-2"
             x-ignore wire:ignore.self data-bs-lazy="bsEditorSave" x-data="bsEditorSave({{ Js::from($saveConfig) }})" x-bind:data-sv-over="state() ? 'on' : false">
            <x-bs-admin::button variant="ghost" :href="$listUrl" class="shrink-0 @max-[35rem]:w-(--spacing-control) @max-[35rem]:px-0"
                                title="{{ __('entries.ed.back_title', ['list' => mb_strtolower($listLabel)]) }}" aria-label="{{ __('entries.ed.back_title', ['list' => mb_strtolower($listLabel)]) }}">
                <x-bs-admin::icon name="ri-arrow-left" :class="$glyph" /><span class="@max-[35rem]:hidden">{{ __('entries.ed.back') }}</span>
            </x-bs-admin::button>

            <div class="flex min-w-0 flex-col items-start gap-1 @max-[45rem]:flex-1">
                <h1 class="m-0 max-w-full truncate text-[calc(1.25rem+var(--user-text-boost))] font-semibold leading-tight text-text-strong" title="{{ $h1 }}">{{ $h1 }}</h1>
                <x-bs-admin::save-state :note="$note" class="max-w-full" />
            </div>
            <span wire:ignore class="contents">
                <x-bs-admin::button variant="outline" color="secondary" size="sm" class="shrink-0" x-show="state() === 'offline'" x-cloak x-on:click="retry()">
                    <x-bs-admin::icon name="ri-refresh" :class="$glyph" /> {{ __('catalog.ed.retry') }}
                </x-bs-admin::button>
            </span>

            <span class="flex-1 @max-[45rem]:hidden"></span>
            <div class="flex flex-wrap items-center justify-end gap-2 @max-[45rem]:hidden">
                @if ($actions['secondary'] !== null)
                    <x-bs-admin::button variant="outline" color="secondary" wire:click="save" wire:loading.attr="disabled" wire:target="save,publish,applyTransition" title="{{ __('entries.ed.save_tip') }}">
                        <x-bs-admin::icon name="ri-save-3" :class="$glyph" /> {{ $actions['secondary'] }}
                    </x-bs-admin::button>
                @endif
                @if ($actions['primary'] !== null)
                    <x-bs-admin::button color="primary" wire:click="{{ $actions['primaryAction'] }}" wire:loading.attr="disabled" wire:target="save,publish,applyTransition">
                        <x-bs-admin::icon :name="$actions['primaryIcon']" :class="$glyph" /> {{ $actions['primary'] }}
                    </x-bs-admin::button>
                @endif
            </div>
        </div>

        @if ($liveUrl !== null)
            <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 rounded-md bg-[color-mix(in_srgb,var(--color-success-text)_9%,var(--color-surface))] px-3.5 py-2.5 text-body font-semibold text-success-text">
                <x-bs-admin::icon name="ri-checkbox-circle" :class="$glyph" />
                <span>{{ __('entries.ed.pub_band') }}</span>
                <a href="{{ '//'.$liveUrl }}" target="_blank" rel="noopener" class="hit-area underline underline-offset-2" aria-label="{{ __('entries.ed.pub_link_aria', ['url' => $liveUrl]) }}">{{ __('entries.ed.pub_link') }}</a>
                <span class="min-w-0 font-normal [overflow-wrap:anywhere] @max-[35rem]:hidden">{{ $liveUrl }}</span>
            </div>
        @endif
        @error('workflow') <p class="{{ $error }} m-0">{{ $message }}</p> @enderror

        <div class="flex min-w-0 flex-wrap items-start gap-5">
            <div class="flex min-w-0 flex-[999_1_520px] flex-col gap-4">
                <section class="{{ $card }} px-5 py-4" aria-label="{{ $isPage ? __('entries.ed.title_page') : __('entries.ed.title_post') }}">
                    <div class="mb-1.5 flex flex-wrap items-center gap-2">
                        <label for="entry-title" class="{{ $label }}">
                            <span>{{ $isPage ? __('entries.ed.title_page') : __('entries.ed.title_post') }} <span class="text-danger-text" aria-hidden="true">*</span></span>
                            @if ($chip)
                                <span class="rounded-sm bg-primary-soft px-1.5 text-caption font-bold text-primary-text" title="{{ __('admin.seo.locale_chip_title') }}">{{ $chip }}</span>
                            @endif
                        </label>
                    </div>
                    <input type="text" id="entry-title" wire:model="title" lang="{{ $locale }}" aria-required="true"
                           @error('title') aria-invalid="true" aria-describedby="entry-title-e" @enderror class="{{ $field }}">
                    @error('title') <p id="entry-title-e" class="{{ $error }}">{{ $message }}</p> @enderror

                    <div class="mt-3.5">
                        <label for="entry-slug" class="{{ $label }} mb-1.5">{{ __('entries.ed.slug') }}</label>
                        <input type="text" id="entry-slug" wire:model="slug" dir="ltr" autocomplete="off" spellcheck="false"
                               @error('slug') aria-invalid="true" aria-describedby="entry-slug-e" @enderror class="{{ $field }}">
                        @error('slug') <p id="entry-slug-e" class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>
                </section>

                <x-bs-admin::seo-fields excerpt="excerpt" part="excerpt" :kind="$type" :address="$address" :locale="$locale" :multi-locale="$multiLocale" id="entry-seo" />

                <section class="{{ $card }} overflow-hidden" aria-labelledby="entry-body-h">
                    @if ($isPage)
                        <div class="flex min-w-0 items-center gap-3 border-b border-border px-4">
                            <h2 id="entry-body-h" class="m-0 inline-flex min-h-(--spacing-control) items-center gap-1.5 border-b-2 border-primary-text text-body font-semibold text-primary-text">
                                <x-bs-admin::icon name="ri-artboard-2" :class="$glyph" />{{ __('entries.ed.builder_tab') }}
                            </h2>
                        </div>
                        <div class="flex flex-col gap-3 px-5 pt-4 pb-5">
                            <p class="m-0 text-body font-semibold text-text-strong">{{ __('entries.ed.builder_head', ['n' => count($blocks)]) }}</p>
                    @else
                        <div class="{{ $cardHead }}">
                            <h2 id="entry-body-h" class="{{ $cardTitle }}">{{ __('entries.blocks_heading') }}</h2>
                            @if ($chip)
                                <span class="rounded-sm bg-primary-soft px-1.5 text-caption font-bold text-primary-text" title="{{ __('admin.seo.locale_chip_title') }}">{{ $chip }}</span>
                            @endif
                        </div>
                        <div class="flex flex-col gap-3 px-5 pt-4 pb-5">
                    @endif
                            @error('blocks') <p class="{{ $error }} m-0">{{ $message }}</p> @enderror
                            @if ($blocks === [])
                                <p class="m-0 rounded-md border border-border bg-surface-muted p-4 text-center text-small text-text-muted">{{ __('entries.ed.builder_empty') }}</p>
                            @endif
                            <div class="blocks">
        <ol class="block-list">
            @foreach ($blocks as $i => $block)
                <li wire:key="block-{{ $i }}" class="block">
                    <header>
                        <strong>{{ $block['type'] }}</strong>
                        <button type="button" wire:click="moveUp({{ $i }})" aria-label="{{ __('entries.move_up') }}">↑</button>
                        <button type="button" wire:click="moveDown({{ $i }})" aria-label="{{ __('entries.move_down') }}">↓</button>
                        <button type="button" wire:click="removeBlock({{ $i }})">{{ __('entries.remove_block') }}</button>
                    </header>
                    <div class="block-props">
                        @foreach (($schemas[$block['type']] ?? []) as $prop => $spec)
                            @continue(in_array($spec['group'] ?? null, ['style', 'display'], true))
                            {{-- A background video is chosen in the page builder's video field (not built in this editor yet); its value is kept as saved. --}}
                            @continue(($spec['type'] ?? null) === 'video')
                            @if (($spec['type'] ?? 'text') === 'list')
                                <fieldset class="prop-list" wire:key="list-{{ $i }}-{{ $prop }}">
                                    <legend>{{ $prop }}</legend>
                                    @foreach (($block['props'][$prop] ?? []) as $j => $item)
                                        <div class="prop-list__item" wire:key="item-{{ $i }}-{{ $prop }}-{{ $j }}">
                                            @if (isset($spec['of']['type']))
                                                @include('admin::livewire.entries.partials.prop-input', ['fieldType' => $spec['of']['type'], 'model' => "blocks.$i.props.$prop.$j", 'fieldLabel' => $prop, 'value' => $item, 'imageUrls' => $blockImageUrls, 'options' => $spec['of']['options'] ?? [], 'describedBy' => $prop === 'product_keys' && is_string($item) && in_array(strtoupper($item), $goneProducts, true) ? "gone-$i-$j" : null])
                                            @else
                                                @foreach (($spec['of'] ?? []) as $field => $fieldSpec)
                                                    @include('admin::livewire.entries.partials.prop-input', ['fieldType' => $fieldSpec['type'] ?? 'text', 'model' => "blocks.$i.props.$prop.$j.$field", 'fieldLabel' => $field, 'value' => $item[$field] ?? null, 'imageUrls' => $blockImageUrls, 'options' => $fieldSpec['options'] ?? []])
                                                @endforeach
                                            @endif
                                            @if ($prop === 'product_keys' && is_string($item) && in_array(strtoupper($item), $goneProducts, true))
                                                <span class="error" id="gone-{{ $i }}-{{ $j }}">{{ __('entries.product_pick_gone') }}</span>
                                            @endif
                                            <button type="button" wire:click="moveItemUp({{ $i }}, '{{ $prop }}', {{ $j }})" aria-label="{{ __('entries.move_up') }}">↑</button>
                                            <button type="button" wire:click="moveItemDown({{ $i }}, '{{ $prop }}', {{ $j }})" aria-label="{{ __('entries.move_down') }}">↓</button>
                                            <button type="button" wire:click="removeItem({{ $i }}, '{{ $prop }}', {{ $j }})">{{ __('entries.remove_item') }}</button>
                                        </div>
                                    @endforeach
                                    <button type="button" wire:click="addItem({{ $i }}, '{{ $prop }}')">{{ __('entries.add_item') }}</button>
                                </fieldset>
                            @else
                                @include('admin::livewire.entries.partials.prop-input', ['fieldType' => $spec['type'] ?? 'text', 'model' => "blocks.$i.props.$prop", 'fieldLabel' => $prop, 'value' => $block['props'][$prop] ?? null, 'imageUrls' => $blockImageUrls, 'options' => $spec['options'] ?? [], 'min' => $spec['min'] ?? null, 'max' => $spec['max'] ?? null])
                            @endif
                        @endforeach
                    </div>
                    @include('admin::livewire.entries.partials.block-look', ['look' => $looks[$i]])
                </li>
            @endforeach
        </ol>
                            </div>
                            <div class="flex flex-col gap-2 rounded-md border border-dashed border-border-input p-3">
                                <p class="m-0 inline-flex items-center gap-1.5 text-small font-semibold text-primary-text"><x-bs-admin::icon name="ri-add" :class="$glyph" />{{ __('entries.ed.add_block') }}</p>
                                <div class="block-palette flex flex-wrap gap-2">
                                    @foreach ($availableTypes as $availableType)
                                        <button type="button" wire:click="addBlock('{{ $availableType }}')" class="hit-area min-h-(--spacing-control) rounded-md border border-border bg-surface px-3 text-small text-text transition-colors hover:bg-surface-hover">+ {{ $availableType }}</button>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                </section>

                <x-bs-admin::seo-fields excerpt="excerpt" part="card" :kind="$type" :address="$address" :locale="$locale" :multi-locale="$multiLocale" id="entry-seo" />
            </div>

            <div class="flex min-w-0 flex-[1_1_300px] flex-col gap-4">
                <section class="{{ $card }} overflow-hidden" aria-labelledby="entry-pub-h">
                    <div class="flex items-center justify-between gap-2.5 border-b border-border px-4 py-3">
                        <h2 id="entry-pub-h" class="{{ $cardTitle }}">{{ __('entries.ed.card_publish') }}</h2>
                        <x-bs-admin::status-pill :status="$pill['status']">{{ $pill['label'] }}</x-bs-admin::status-pill>
                    </div>
                    @if ($workflow !== null)
                        <div class="p-4">
                            {{-- An active workflow governs this post: its steps and moves replace "Đăng". --}}
                            @include('admin::livewire.entries.partials.workflow-status')
                        </div>
                    @endif
                </section>

                @if ($type === 'post')
                    <section class="{{ $card }}" aria-labelledby="entry-cat-h">
                        <div class="{{ $cardHead }}"><h2 id="entry-cat-h" class="{{ $cardTitle }}"><label for="entry-category">{{ __('entries.ed.card_category') }}</label></h2></div>
                        <div class="p-4">
                            <select id="entry-category" wire:model.live="categoryId" @error('categoryId') aria-invalid="true" aria-describedby="entry-category-e" @enderror
                                    class="field block w-full rounded-md border border-border-input bg-surface px-3 text-text transition-colors focus:border-primary-text aria-invalid:border-danger-text">
                                <option value="">{{ __('entries.category_placeholder') }}</option>
                                @foreach ($categories as $category)
                                    <option value="{{ $category['id'] }}">{{ $category['name'] }}</option>
                                @endforeach
                            </select>
                            @error('categoryId') <p id="entry-category-e" class="{{ $error }}">{{ $message }}</p> @enderror
                        </div>
                    </section>
                @endif

                @can('media.view')
                    <section class="{{ $card }} flex flex-col gap-3 px-4 py-4" aria-labelledby="entry-cover-h">
                        <h2 id="entry-cover-h" class="{{ $cardTitle }}">{{ __('entries.ed.card_cover') }}</h2>
                        <div class="relative aspect-video w-full overflow-hidden rounded-md border border-border bg-surface-muted">
                            @if ($coverUrl)
                                <img src="{{ $coverUrl }}" alt="{{ __('entries.cover_alt') }}" class="entry-cover__preview size-full object-cover">
                                <button type="button" wire:click="clearCover" class="hit-area absolute end-2 top-2 inline-flex min-h-(--spacing-control) items-center gap-1 rounded-md bg-surface px-2.5 text-small font-medium text-danger-text shadow-card transition-colors hover:bg-surface-hover"
                                        title="{{ __('entries.ed.cover_remove_title', ['unit' => $unit]) }}" aria-label="{{ __('entries.ed.cover_remove_title', ['unit' => $unit]) }}">
                                    <x-bs-admin::icon name="ri-delete-bin" :class="$glyph" />{{ __('entries.cover_remove') }}
                                </button>
                            @else
                                <button type="button" wire:click="openCoverPicker" class="absolute inset-0 flex flex-col items-center justify-center gap-1.5 text-text-muted transition-colors hover:bg-surface-hover hover:text-primary-text">
                                    <x-bs-admin::icon name="ri-image-add" class="size-7" />
                                    <span class="text-small font-semibold">{{ __('entries.ed.cover_choose') }}</span>
                                </button>
                            @endif
                        </div>
                        @if ($coverUrl)
                            <div>
                                <x-bs-admin::button variant="outline" color="secondary" size="sm" wire:click="openCoverPicker">
                                    <x-bs-admin::icon name="ri-image-edit" :class="$glyph" /> {{ __('entries.ed.cover_change') }}
                                </x-bs-admin::button>
                            </div>
                        @endif
                    </section>
                @endcan
            </div>
        </div>

        <div class="ped-bbar flex gap-2 border-t border-border bg-bg pt-2.5 @min-[45rem]:hidden" x-data>
            @if ($actions['secondary'] !== null)
                <x-bs-admin::button variant="outline" color="secondary" class="min-w-0 flex-1" wire:click="save" wire:loading.attr="disabled" wire:target="save,publish,applyTransition" title="{{ __('entries.ed.save_tip') }}">
                    <x-bs-admin::icon name="ri-save-3" :class="$glyph" /> {{ $actions['secondary'] }}
                </x-bs-admin::button>
            @endif
            @if ($actions['primary'] !== null)
                <x-bs-admin::button color="primary" class="min-w-0 flex-1" wire:click="{{ $actions['primaryAction'] }}" wire:loading.attr="disabled" wire:target="save,publish,applyTransition">
                    <x-bs-admin::icon :name="$actions['primaryIcon']" :class="$glyph" /> {{ $actions['primary'] }}
                </x-bs-admin::button>
            @endif
        </div>
    </div>

    <x-bs-admin::unsaved-dialog :count="$changes" :label="$h1" :save="$leave['method']" discard="resetChanges" :body="$leave['body']" :save-label="$leave['save']" :discard-label="$leave['discard']" />

    @can('media.view')
        @if ($showMediaPicker)
            <div class="media-picker" role="dialog" aria-modal="true" aria-label="{{ __('entries.cover_pick') }}">
                <div class="media-picker__head">
                    <strong>{{ __('entries.cover_pick') }}</strong>
                    <button type="button" wire:click="closeCoverPicker">{{ __('entries.close') }}</button>
                </div>
                @if ($pickerMedia->isEmpty())
                    <p>{{ __('entries.library_empty') }}</p>
                @else
                    <div class="media-picker__grid">
                        @foreach ($pickerMedia as $m)
                            @if (str_starts_with($m->mime, 'image/'))
                                <button type="button" wire:key="pick-{{ $m->id }}"
                                        wire:click="selectCover('{{ $m->storage_key }}')"
                                        class="media-picker__item" title="{{ $m->original_filename }}">
                                    <img src="{{ $urls->url($m->storage_key, 320) }}" alt="{{ $m->original_filename }}" loading="lazy">
                                </button>
                            @endif
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

        @if ($showBlockImagePicker)
            <div class="media-picker" role="dialog" aria-modal="true" aria-label="{{ __('entries.image_pick') }}">
                <div class="media-picker__head">
                    <strong>{{ __('entries.image_pick') }}</strong>
                    <button type="button" wire:click="closeBlockImagePicker">{{ __('entries.close') }}</button>
                </div>
                @if ($pickerMedia->isEmpty())
                    <p>{{ __('entries.library_empty') }}</p>
                @else
                    <div class="media-picker__grid">
                        @foreach ($pickerMedia as $m)
                            @if (str_starts_with($m->mime, 'image/'))
                                <button type="button" wire:key="blockpick-{{ $m->id }}"
                                        wire:click="selectBlockImage({{ $m->id }})"
                                        class="media-picker__item" title="{{ $m->original_filename }}">
                                    <img src="{{ $urls->url($m->storage_key, 320) }}" alt="{{ $m->original_filename }}" loading="lazy">
                                </button>
                            @endif
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

        {{-- "Tải tệp": a document of the library for a button to hand out. --}}
        <x-bs-admin::modal model="showButtonFilePicker" :title="__('entries.btn_file_dialog_title')" :subtitle="__('entries.btn_file_dialog_sub')" :close-label="__('entries.btn_file_dialog_close')">
            @if ($buttonPanel['documents'] === [])
                <div class="btn-act__docs-empty">
                    <b>{{ __('entries.btn_file_dialog_empty_title') }}</b>
                    <span>{{ __('entries.btn_file_dialog_empty_how') }}</span>
                    <a class="btn-act__lib" href="{{ route('admin.media.index') }}" target="_blank" rel="noopener"><x-bs-admin::icon name="ri-folder-open" class="btn-act__btn-ic" />{{ __('entries.btn_file_dialog_library') }}<span class="sr-only"> {{ __('entries.btn_new_tab') }}</span></a>
                </div>
            @else
                <div class="btn-act__docs">
                    @foreach (array_map(fn (array $doc): array => $doc + ['current' => $doc['key'] === $buttonPanel['fileCurrent']], $buttonPanel['documents']) as $doc)
                        <button type="button" class="btn-act__doc" aria-pressed="{{ $doc['current'] ? 'true' : 'false' }}" wire:click="pickButtonFile({{ \Illuminate\Support\Js::from($doc['key']) }})" wire:key="btn-doc-{{ $doc['key'] }}" @if ($doc['current']) autofocus @endif>
                            <span class="btn-act__ic"><x-bs-admin::icon :name="$doc['icon']" class="btn-act__ic-glyph" /></span>
                            <span class="btn-act__kt"><span class="btn-act__name">{{ $doc['name'] }}</span><span class="btn-act__meta">{{ $doc['meta'] }}</span>
                                @if ($doc['current'])
                                    <span class="btn-act__doc-cur"><x-bs-admin::icon name="ri-check" class="btn-act__btn-ic" />{{ __('entries.btn_file_dialog_current') }}</span>
                                @endif
                            </span>
                        </button>
                    @endforeach
                </div>
            @endif
            <x-slot:footer>
                @if ($buttonPanel['documents'] !== [])
                    <a class="btn-act__lib me-auto" href="{{ route('admin.media.index') }}" target="_blank" rel="noopener"><x-bs-admin::icon name="ri-folder-open" class="btn-act__btn-ic" />{{ __('entries.btn_file_dialog_library') }}<span class="sr-only"> {{ __('entries.btn_new_tab') }}</span></a>
                @endif
                <x-bs-admin::button variant="outline" color="secondary" wire:click="closeButtonFilePicker">{{ __('entries.btn_file_dialog_cancel') }}</x-bs-admin::button>
            </x-slot:footer>
        </x-bs-admin::modal>
    @endcan
</div>
