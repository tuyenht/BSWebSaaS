{{--
    One responsive image. `src` passes SafeLink (a web link or a site path, else nothing renders);
    `srcset` is server-built from a storage key (never author data) and comes with its `sizes`.
    `priority`: high (the page's main image: eager + fetchpriority), eager, or lazy (default).
    `alt`: optional text (default "", decorative: the heading or link beside it carries the text);
    pass one only for a picture that is the only carrier of its meaning. `w`/`h` set
    the intrinsic ratio; the frame around it fixes the shown ratio (aspect-ratio + object-fit).
--}}
@php
    $sfImgSrc = \App\Modules\Shared\Support\SafeLink::href($src ?? null);
    $sfImgPriority = in_array($priority ?? 'lazy', ['high', 'eager', 'lazy'], true) ? ($priority ?? 'lazy') : 'lazy';
@endphp
@if ($sfImgSrc !== null)
<img class="{{ $class ?? 'sf-img' }}" src="{{ $sfImgSrc }}"@if (! empty($srcset)) srcset="{{ $srcset }}" sizes="{{ $sizes ?? '100vw' }}"@endif alt="{{ $alt ?? '' }}" width="{{ $w ?? 1600 }}" height="{{ $h ?? 900 }}" loading="{{ $sfImgPriority === 'lazy' ? 'lazy' : 'eager' }}"@if ($sfImgPriority === 'high') fetchpriority="high"@else decoding="async"@endif>
@endif
