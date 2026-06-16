<div class="entry-editor">
    <h1>{{ $ulid ? 'Sửa trang' : 'Tạo trang' }}</h1>

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

    <section class="blocks">
        <h2>Khối nội dung</h2>
        @error('blocks') <p class="error">{{ $message }}</p> @enderror

        <div class="block-palette">
            @foreach ($availableTypes as $type)
                <button type="button" wire:click="addBlock('{{ $type }}')">+ {{ $type }}</button>
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
                        @foreach (($block['props'] ?? []) as $key => $value)
                            <label>{{ $key }}
                                <input type="text" wire:model="blocks.{{ $i }}.props.{{ $key }}">
                            </label>
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
