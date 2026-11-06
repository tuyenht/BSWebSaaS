@php($noun = $type === 'post' ? __('entries.noun_post') : __('entries.noun_page'))
<div class="entry-editor">
    <h1>{{ $ulid ? __('entries.edit_title', ['noun' => $noun]) : __('entries.create_title', ['noun' => $noun]) }}</h1>

    @if ($status)
        <p class="entry-status">{{ __('entries.status_label') }}: <strong>{{ $status }}</strong></p>
    @endif

    @if (session('saved'))
        <p class="entry-saved">{{ __('entries.draft_saved') }}</p>
    @endif

    <label>{{ __('entries.title_label') }}
        <input type="text" wire:model="title" class="field block w-full">
    </label>
    @error('title') <p class="error">{{ $message }}</p> @enderror

    <label>{{ __('entries.slug_label') }}
        <input type="text" wire:model="slug" class="field block w-full">
    </label>
    @error('slug') <p class="error">{{ $message }}</p> @enderror

    @if ($type === 'post')
        <label>{{ __('entries.category_label') }}
            <select wire:model.live="categoryId" class="field">
                <option value="">{{ __('entries.category_placeholder') }}</option>
                @foreach ($categories as $category)
                    <option value="{{ $category['id'] }}">{{ $category['name'] }}</option>
                @endforeach
            </select>
        </label>
        @error('categoryId') <p class="error">{{ $message }}</p> @enderror
    @endif

    <x-bs-admin::seo-fields excerpt="excerpt" :kind="$type" :address="$address" :locale="$locale" :multi-locale="$multiLocale" id="entry-seo" />

    @can('media.view')
        <section class="entry-cover">
            <h2>{{ __('entries.cover_heading') }}</h2>
            @if ($coverUrl)
                <img src="{{ $coverUrl }}" alt="{{ __('entries.cover_alt') }}" class="entry-cover__preview" style="max-width:240px;height:auto;display:block">
                <button type="button" wire:click="clearCover">{{ __('entries.cover_remove') }}</button>
            @else
                <p>{{ __('entries.cover_empty') }}</p>
            @endif
            <button type="button" wire:click="openCoverPicker">{{ __('entries.cover_pick') }}</button>
        </section>

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

    <section class="blocks">
        <h2>{{ __('entries.blocks_heading') }}</h2>
        @error('blocks') <p class="error">{{ $message }}</p> @enderror

        <div class="block-palette">
            @foreach ($availableTypes as $availableType)
                <button type="button" wire:click="addBlock('{{ $availableType }}')">+ {{ $availableType }}</button>
            @endforeach
        </div>

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
                                                @include('admin::livewire.entries.partials.prop-input', ['fieldType' => $spec['of']['type'], 'model' => "blocks.$i.props.$prop.$j", 'fieldLabel' => $prop, 'value' => $item, 'imageUrls' => $blockImageUrls, 'options' => $spec['of']['options'] ?? []])
                                            @else
                                                @foreach (($spec['of'] ?? []) as $field => $fieldSpec)
                                                    @include('admin::livewire.entries.partials.prop-input', ['fieldType' => $fieldSpec['type'] ?? 'text', 'model' => "blocks.$i.props.$prop.$j.$field", 'fieldLabel' => $field, 'value' => $item[$field] ?? null, 'imageUrls' => $blockImageUrls, 'options' => $fieldSpec['options'] ?? []])
                                                @endforeach
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
    </section>

    @if ($workflow !== null)
        {{-- An active workflow governs this section: the approval block replaces Publish. --}}
        @include('admin::livewire.entries.partials.workflow-status')
        <div class="editor-actions">
            <button type="button" wire:click="save">{{ __('entries.save_draft') }}</button>
        </div>
    @else
        @error('workflow') <p class="error">{{ $message }}</p> @enderror
        <div class="editor-actions">
            <button type="button" wire:click="save">{{ __('entries.save_draft') }}</button>
            <button type="button" wire:click="publish">{{ __('entries.publish') }}</button>
        </div>
    @endif
</div>
