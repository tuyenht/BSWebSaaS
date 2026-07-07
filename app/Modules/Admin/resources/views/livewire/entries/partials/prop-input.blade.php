{{-- One labelled input for a typed prop (§E7): text | textarea | url | int. --}}
<label>{{ $fieldLabel }}
    @switch($fieldType)
        @case('textarea')
            <textarea wire:model="{{ $model }}"></textarea>
            @break
        @case('int')
            <input type="number" wire:model="{{ $model }}">
            @break
        @case('url')
            <input type="url" wire:model="{{ $model }}">
            @break
        @case('image')
            @php $imagePath = \Illuminate\Support\Str::after($model, 'blocks.'); @endphp
            <span class="image-prop">
                @if (is_int($value ?? null) && ! empty(($imageUrls ?? [])[$value]))
                    <img src="{{ $imageUrls[$value] }}" alt="" class="image-prop__preview" style="max-width:160px;height:auto;display:block">
                @endif
                <button type="button" wire:click="openBlockImagePicker('{{ $imagePath }}')">{{ __('entries.image_pick') }}</button>
                @if (! empty($value))
                    <button type="button" wire:click="clearBlockImage('{{ $imagePath }}')">{{ __('entries.image_clear') }}</button>
                @endif
            </span>
            @break
        @default
            <input type="text" wire:model="{{ $model }}">
    @endswitch
</label>
