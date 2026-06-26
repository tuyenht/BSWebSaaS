{{--
    Curated inline-SVG glyph set (theme presentation). `name` = a glyph key from a block's `icon`
    text field. Unknown key → renders nothing. The SVG bodies are OUR literals (not user data — no
    upload, no XSS surface); `name` only selects one. Inline SVG = no webfont bytes, currentColor,
    CWV-friendly. The Media module later adds custom/uploaded icons via a picker.
--}}
@php($glyphs = [
    'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
    'check' => '<path d="M20 6 9 17l-5-5"/>',
    'box' => '<path d="M12 2 3 7v10l9 5 9-5V7z"/><path d="M3 7l9 5 9-5"/>',
    'users' => '<circle cx="9" cy="8" r="3"/><path d="M3 21a6 6 0 0 1 12 0"/><path d="M16 11a3 3 0 0 0 0-6"/><path d="M21 21a6 6 0 0 0-4-5.7"/>',
    'truck' => '<path d="M2 6h11v9H2z"/><path d="M13 9h4l4 3v3h-8z"/><circle cx="6" cy="18" r="1.6"/><circle cx="17" cy="18" r="1.6"/>',
    'cog' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/>',
])
@if (isset($glyphs[$name]))
    <svg class="icon" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $glyphs[$name] !!}</svg>
@endif
