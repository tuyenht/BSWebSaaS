{{-- One labelled input for a typed prop (§E7): text | textarea | url | int | image | enum (a choice
     from `options`, labelled entries.opt_{prop}_{value}). A url the schema marks as a button's
     link draws the button-action control instead. --}}
@php $buttonView = $fieldType === 'url' ? (($buttons ?? [])[\Illuminate\Support\Str::after($model, 'blocks.')] ?? null) : null; @endphp
@if ($buttonView !== null)
    {{-- A block button: what it does when pressed (kind + its value), not a bare address. --}}
    @include('admin::livewire.entries.partials.button-action', ['button' => $buttonView])
@else
<label>{{ $fieldLabel }}
    @switch($fieldType)
        @case('textarea')
            <textarea wire:model="{{ $model }}"></textarea>
            @break
        @case('int')
            <input type="number" wire:model="{{ $model }}" class="field">
            @break
        @case('enum')
            <select wire:model="{{ $model }}" class="field">
                @foreach (($options ?? []) as $option)
                    <option value="{{ $option }}">{{ __('entries.opt_'.$fieldLabel.'_'.$option) }}</option>
                @endforeach
            </select>
            @break
        @case('url')
            <input type="url" wire:model="{{ $model }}" class="field">
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
            <input type="text" wire:model="{{ $model }}" class="field">
    @endswitch
</label>
@endif
