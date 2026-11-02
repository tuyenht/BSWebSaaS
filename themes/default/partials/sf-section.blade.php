{{--
    The look wrapper around one block (StyleProps::wrapper): `classes` come from fixed lookup tables,
    `id` matched the anchor pattern, `image` is the resolved `bg_image` (a photo behind the block under
    an overlay, painted as an <img> so it can be the page's main image). `section` is the block's
    already-rendered HTML.
--}}
<div class="{{ implode(' ', $wrapper['classes']) }}"@if ($wrapper['id'] !== null) id="{{ $wrapper['id'] }}"@endif>
@if ($wrapper['image'] !== null)
<div class="sf-s__bg" aria-hidden="true">
@include('theme::partials.sf-img', ['src' => $wrapper['image'], 'srcset' => $srcset, 'sizes' => '100vw', 'w' => 1600, 'h' => 900, 'priority' => $eager ? 'high' : 'lazy', 'class' => 'sf-s__img'])
</div>
@endif
{!! $section !!}
</div>
