{{-- The block rows of «Trình dựng» (design 07), shared by the content editor and the reusable block
     editor: the host draws them with EditsBlockList and gives $blocks, $kinds, $schemas, $looks,
     $blockImageUrls, $goneProducts, $openBlock, $rowButton, $askDelete, $error, $localeLabel and $unit, and
     may give $emptyText for an empty list. A shared block placed in the layout (reusable_ref, board 27) is
     drawn by shared-block-card from $refs (what its block says, by ulid; absent = deleted). --}}
@error('blocks') <p class="{{ $error }} m-0">{{ $message }}</p> @enderror
@if ($blocks === [])
    <p class="m-0 rounded-md border border-border bg-surface-muted p-4 text-center text-small text-text-muted">{{ $emptyText ?? __('entries.ed.builder_none') }}</p>
@endif
{{-- One row per block (design 07): glyph, name and a glimpse, kind chip, «Sửa nội dung»
     (its content form opens under the row, one at a time) and «Xóa khối». Order changes
     with the form's «Lên trên» / «Xuống dưới»: there is no drag reordering yet. --}}
<ol class="m-0 flex list-none flex-col gap-2 p-0">
    @foreach ($blocks as $i => $block)
        @if (\App\Modules\Theme\Components\BlockReference::is($block))
            @include('admin::livewire.entries.partials.shared-block-card', ['i' => (int) $i, 'last' => count($blocks) - 1])
            @continue
        @endif
        @php
            $i = (int) $i;
            $kind = $kinds[$block['type']] ?? \App\Modules\Admin\Support\BlockKinds::of($block['type']);
            $peek = \App\Modules\Admin\Support\BlockKinds::preview($block['props']);
        @endphp
        <li wire:key="block-{{ $i }}" class="min-w-0 rounded-lg border border-border bg-surface">
            <div class="flex min-w-0 items-center gap-3 px-3.5 py-2.5">
                <span class="inline-flex size-9 shrink-0 items-center justify-center rounded-md {{ $kind['tint'] }}" aria-hidden="true">
                    <x-bs-admin::icon :name="$kind['icon']" class="size-5" />
                </span>
                <span class="flex min-w-0 flex-1 flex-col">
                    <span class="text-small font-semibold text-text-strong">{{ $kind['label'] }}</span>
                    @if ($peek !== '')
                        <span class="truncate text-caption text-text-muted">{{ $peek }}</span>
                    @endif
                </span>
                <span class="hidden shrink-0 rounded-sm px-2 py-0.5 text-caption font-semibold whitespace-nowrap sm:inline {{ $kind['tint'] }}">{{ $kind['chip'] }}</span>
                <button type="button" class="{{ $rowButton }}" title="{{ __('entries.ed.block_edit') }}" aria-label="{{ __('entries.ed.block_edit') }}: {{ $kind['label'] }}"
                        aria-controls="block-form-{{ $i }}" aria-expanded="{{ $openBlock === $i ? 'true' : 'false' }}"
                        x-on:click="openBlock = openBlock === {{ $i }} ? null : {{ $i }}" x-bind:aria-expanded="openBlock === {{ $i }} ? 'true' : 'false'">
                    <x-bs-admin::icon name="ri-pencil" class="size-4" />
                </button>
                <button type="button" class="{{ $rowButton }} hover:border-danger-text hover:text-danger-text"
                        x-on:click="{{ $askDelete(__('entries.ed.del_block_text', ['lang' => $localeLabel, 'unit' => mb_strtolower($unit)])) }}.then(ok => ok && $wire.removeBlock({{ $i }}))"
                        title="{{ __('entries.ed.block_remove') }}" aria-label="{{ __('entries.ed.block_remove') }}: {{ $kind['label'] }}">
                    <x-bs-admin::icon name="ri-delete-bin" class="size-4" />
                </button>
            </div>
            <div id="block-form-{{ $i }}" class="flex min-w-0 flex-col gap-3 border-t border-border px-3.5 py-3"
                 x-show="openBlock === {{ $i }}" @if ($openBlock !== $i) style="display: none" @endif>
                <div class="flex flex-wrap gap-2">
                    <x-bs-admin::button variant="outline" color="secondary" size="sm" wire:click="moveUp({{ $i }})" :disabled="$i === 0">
                        <x-bs-admin::icon name="ri-arrow-up" class="size-4" /> {{ __('entries.ed.block_up') }}
                    </x-bs-admin::button>
                    <x-bs-admin::button variant="outline" color="secondary" size="sm" wire:click="moveDown({{ $i }})" :disabled="$i === count($blocks) - 1">
                        <x-bs-admin::icon name="ri-arrow-down" class="size-4" /> {{ __('entries.ed.block_down') }}
                    </x-bs-admin::button>
                </div>
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
                            @include('admin::livewire.entries.partials.prop-input', ['fieldType' => ($spec['type'] ?? 'text') === 'html' ? 'textarea' : ($spec['type'] ?? 'text'), 'model' => "blocks.$i.props.$prop", 'fieldLabel' => $prop, 'value' => $block['props'][$prop] ?? null, 'imageUrls' => $blockImageUrls, 'options' => $spec['options'] ?? [], 'min' => $spec['min'] ?? null, 'max' => $spec['max'] ?? null])
                        @endif
                    @endforeach
                </div>
                @include('admin::livewire.entries.partials.block-look', ['look' => $looks[$i]])
            </div>
        </li>
    @endforeach
</ol>
