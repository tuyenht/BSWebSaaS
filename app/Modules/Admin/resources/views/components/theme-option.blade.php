{{--
    One Customizer choice as a card-radio with a miniature admin-layout preview
    (Velzon's card-radio pattern). Colors inside the thumbnail are DELIBERATE literals:
    each card illustrates one fixed choice, so it must NOT repaint with the live scheme.

    $axis:   scheme|sidebar|topbar     $value: the axis value this card selects
    $name:   radio group name          $label: visible caption
    Binding: pass store="1" for the launcher (Alpine store-bound), or forward
    wire:model from the Settings page (Livewire-bound).
--}}
@props(['axis', 'value', 'name', 'label', 'store' => false])
@php
    $brand = '#405189';
    $isDarkScheme = $axis === 'scheme' && $value === 'dark';
    $canvas = $isDarkScheme ? '#212529' : '#ffffff';
    $bar = $isDarkScheme ? 'rgb(255 255 255 / 0.14)' : '#eff2f7';
    $sidebar = match (true) {
        $axis === 'sidebar' && $value === 'light' => '#ffffff',
        $isDarkScheme => 'rgb(255 255 255 / 0.08)',
        default => $brand,
    };
    $topbar = $axis === 'topbar' && $value === 'dark' ? $brand : $canvas;
@endphp
<label class="group block cursor-pointer">
    <input type="radio" name="{{ $name }}" value="{{ $value }}" class="peer sr-only"
           @if ($store) :checked="$store.adminTheme.state.{{ $axis }} === '{{ $value }}'"
           @change="$store.adminTheme.set('{{ $axis }}', '{{ $value }}')" @endif
           {{ $attributes }}>
    <span class="flex h-14 overflow-hidden rounded border border-border transition-all peer-checked:border-accent peer-checked:ring-2 peer-checked:ring-accent/30 peer-focus-visible:ring-2 peer-focus-visible:ring-accent/60"
          style="background: {{ $canvas }}">
        <span class="h-full w-4 shrink-0 border-e" style="background: {{ $sidebar }}; border-color: {{ $bar }}"></span>
        <span class="flex min-w-0 flex-1 flex-col">
            <span class="h-3 w-full border-b" style="background: {{ $topbar }}; border-color: {{ $bar }}"></span>
            <span class="m-1.5 h-1.5 rounded-full" style="background: {{ $bar }}"></span>
            <span class="mx-1.5 h-1.5 w-2/3 rounded-full" style="background: {{ $bar }}"></span>
        </span>
    </span>
    <span class="mt-1.5 block text-center text-xs font-medium text-body-muted transition-colors group-has-[:checked]:text-accent">{{ $label }}</span>
</label>
