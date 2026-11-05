{{--
    Glyphs of the rich header, footer and menus. `name` selects one of OUR literal SVG bodies
    (StorefrontIcons, never data from settings); `size` is the box in px. Outline glyphs follow
    currentColor; the social marks are filled. Unknown name → nothing.
--}}
@php($sfGlyph = \App\Modules\Theme\Chrome\StorefrontIcons::GLYPHS[$name] ?? null)
@if ($sfGlyph !== null)
@if ($sfGlyph[0] === 'f')
<svg class="sf-icon" width="{{ $size ?? 24 }}" height="{{ $size ?? 24 }}" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false">{!! $sfGlyph[1] !!}</svg>
@else
<svg class="sf-icon" width="{{ $size ?? 24 }}" height="{{ $size ?? 24 }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="{{ $stroke ?? 2 }}" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">{!! $sfGlyph[1] !!}</svg>
@endif
@endif
