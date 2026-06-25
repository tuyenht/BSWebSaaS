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
        @default
            <input type="text" wire:model="{{ $model }}">
    @endswitch
</label>
