{{--
    One partner of the partners block: its logo, or its name when it has none. A library logo carries its
    stored width/height and its ratio (--logo-r), so the stylesheet gives every logo about the same area;
    a logo from elsewhere (no stored size) keeps the 160×80 box and the default ratio.
--}}
@php
    $name = \App\Modules\Theme\Components\SectionProps::text($item, 'name');
    $src = is_string($item['image_url'] ?? null) && $item['image_url'] !== '' ? $item['image_url'] : null;
    $size = $src !== null ? ($dims[$src] ?? null) : null;
    $size = is_array($size) && ($size[0] ?? 0) > 0 && ($size[1] ?? 0) > 0 ? $size : null;
@endphp
@if ($src !== null)
<img class="partners__logo" src="{{ $src }}" alt="{{ $name }}" width="{{ $size[0] ?? 160 }}" height="{{ $size[1] ?? 80 }}"@if ($size !== null) style="--logo-r: {{ round($size[0] / $size[1], 3) }}"@endif loading="lazy">
@else
<span class="partners__name{{ mb_strlen($name) > 24 ? ' partners__name--long' : '' }}">{{ $name }}</span>
@endif
