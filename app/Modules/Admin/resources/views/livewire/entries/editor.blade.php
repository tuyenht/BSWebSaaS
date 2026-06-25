@php($noun = $type === 'post' ? 'bài viết' : 'trang')
<div class="entry-editor">
    <h1>{{ $ulid ? 'Sửa' : 'Tạo' }} {{ $noun }}</h1>

    @if ($status)
        <p class="entry-status">Trạng thái: <strong>{{ $status }}</strong></p>
    @endif

    @if (session('saved'))
        <p class="entry-saved">Đã lưu nháp.</p>
    @endif

    <label>Tiêu đề
        <input type="text" wire:model="title">
    </label>
    @error('title') <p class="error">{{ $message }}</p> @enderror

    <label>Đường dẫn (slug)
        <input type="text" wire:model="slug">
    </label>
    @error('slug') <p class="error">{{ $message }}</p> @enderror

    @if ($type === 'post')
        <label>Danh mục
            <select wire:model="categoryId">
                <option value="">— Chọn danh mục —</option>
                @foreach ($categories as $category)
                    <option value="{{ $category['id'] }}">{{ $category['name'] }}</option>
                @endforeach
            </select>
        </label>
        @error('categoryId') <p class="error">{{ $message }}</p> @enderror
    @endif

    <section class="blocks">
        <h2>Khối nội dung</h2>
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
                        <button type="button" wire:click="moveUp({{ $i }})" aria-label="Lên">↑</button>
                        <button type="button" wire:click="moveDown({{ $i }})" aria-label="Xuống">↓</button>
                        <button type="button" wire:click="removeBlock({{ $i }})">Xóa</button>
                    </header>
                    <div class="block-props">
                        @foreach (($schemas[$block['type']] ?? []) as $prop => $spec)
                            @if (($spec['type'] ?? 'text') === 'list')
                                <fieldset class="prop-list" wire:key="list-{{ $i }}-{{ $prop }}">
                                    <legend>{{ $prop }}</legend>
                                    @foreach (($block['props'][$prop] ?? []) as $j => $item)
                                        <div class="prop-list__item" wire:key="item-{{ $i }}-{{ $prop }}-{{ $j }}">
                                            @if (isset($spec['of']['type']))
                                                @include('admin::livewire.entries.partials.prop-input', ['fieldType' => $spec['of']['type'], 'model' => "blocks.$i.props.$prop.$j", 'fieldLabel' => $prop])
                                            @else
                                                @foreach (($spec['of'] ?? []) as $field => $fieldSpec)
                                                    @include('admin::livewire.entries.partials.prop-input', ['fieldType' => $fieldSpec['type'] ?? 'text', 'model' => "blocks.$i.props.$prop.$j.$field", 'fieldLabel' => $field])
                                                @endforeach
                                            @endif
                                            <button type="button" wire:click="moveItemUp({{ $i }}, '{{ $prop }}', {{ $j }})" aria-label="Lên">↑</button>
                                            <button type="button" wire:click="moveItemDown({{ $i }}, '{{ $prop }}', {{ $j }})" aria-label="Xuống">↓</button>
                                            <button type="button" wire:click="removeItem({{ $i }}, '{{ $prop }}', {{ $j }})">Xóa mục</button>
                                        </div>
                                    @endforeach
                                    <button type="button" wire:click="addItem({{ $i }}, '{{ $prop }}')">+ Thêm mục</button>
                                </fieldset>
                            @else
                                @include('admin::livewire.entries.partials.prop-input', ['fieldType' => $spec['type'] ?? 'text', 'model' => "blocks.$i.props.$prop", 'fieldLabel' => $prop])
                            @endif
                        @endforeach
                    </div>
                </li>
            @endforeach
        </ol>
    </section>

    <div class="editor-actions">
        <button type="button" wire:click="save">Lưu nháp</button>
        <button type="button" wire:click="publish">Đăng</button>
    </div>
</div>
