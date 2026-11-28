@props([
    'name' => null,
    'src' => null,
    'size' => 'md',
])
@php
    // Mirrors the topbar user avatar (the same two letters, DisplayName::initials, on
    // admin-primary/10). Shows the image when given; the initials sit underneath so an image
    // that fails to load (onerror removes the <img>) reveals them — never a broken-image icon.
    $sizes = [
        'sm' => 'size-7 text-xs',
        'md' => 'size-8 text-sm',
        'lg' => 'size-10 text-base',
    ];
    $box = $sizes[$size] ?? $sizes['md'];
    $initial = \App\Modules\Admin\Support\DisplayName::initials((string) $name) ?: 'U';
@endphp
<span role="img" aria-label="{{ $name }}"
      {{ $attributes->merge(['class' => 'relative grid shrink-0 place-items-center overflow-hidden rounded-full bg-admin-primary/10 font-semibold text-accent '.$box]) }}>
    <span aria-hidden="true">{{ $initial }}</span>
    @if ($src)
        <img src="{{ $src }}" alt="" class="absolute inset-0 size-full object-cover" onerror="this.remove()">
    @endif
</span>
