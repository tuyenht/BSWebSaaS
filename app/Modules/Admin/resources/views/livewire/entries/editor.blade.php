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
        <input type="text" wire:model="title" class="field">
    </label>
    @error('title') <p class="error">{{ $message }}</p> @enderror

    <label>{{ __('entries.slug_label') }}
        <input type="text" wire:model="slug" class="field">
    </label>
    @error('slug') <p class="error">{{ $message }}</p> @enderror

    @if ($type === 'post')
        <label>{{ __('entries.category_label') }}
            <select wire:model="categoryId" class="field">
                <option value="">{{ __('entries.category_placeholder') }}</option>
                @foreach ($categories as $category)
                    <option value="{{ $category['id'] }}">{{ $category['name'] }}</option>
                @endforeach
            </select>
        </label>
        @error('categoryId') <p class="error">{{ $message }}</p> @enderror
    @endif

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
                            @if (($spec['type'] ?? 'text') === 'list')
                                <fieldset class="prop-list" wire:key="list-{{ $i }}-{{ $prop }}">
                                    <legend>{{ $prop }}</legend>
                                    @foreach (($block['props'][$prop] ?? []) as $j => $item)
                                        <div class="prop-list__item" wire:key="item-{{ $i }}-{{ $prop }}-{{ $j }}">
                                            @if (isset($spec['of']['type']))
                                                @include('admin::livewire.entries.partials.prop-input', ['fieldType' => $spec['of']['type'], 'model' => "blocks.$i.props.$prop.$j", 'fieldLabel' => $prop, 'value' => $item, 'imageUrls' => $blockImageUrls])
                                            @else
                                                @foreach (($spec['of'] ?? []) as $field => $fieldSpec)
                                                    @include('admin::livewire.entries.partials.prop-input', ['fieldType' => $fieldSpec['type'] ?? 'text', 'model' => "blocks.$i.props.$prop.$j.$field", 'fieldLabel' => $field, 'value' => $item[$field] ?? null, 'imageUrls' => $blockImageUrls])
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
                                @include('admin::livewire.entries.partials.prop-input', ['fieldType' => $spec['type'] ?? 'text', 'model' => "blocks.$i.props.$prop", 'fieldLabel' => $prop, 'value' => $block['props'][$prop] ?? null, 'imageUrls' => $blockImageUrls])
                            @endif
                        @endforeach
                    </div>
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
