{{--
    Curated inline-SVG glyph set (theme presentation). `name` = a glyph key from a block's `icon`
    text field. Unknown key → renders nothing. The SVG bodies are OUR literals (not user data — no
    upload, no XSS surface); `name` only selects one. Inline SVG = no webfont bytes, currentColor,
    CWV-friendly. The Media module later adds custom/uploaded icons via a picker. lock, adjust,
    graduation, coin, pin, group, wrench, machine and eye are Heroicons outline paths (MIT, LICENSE-heroicons.txt next to this
    file). `stroke` thins the line (default 2); `wrap` puts the glyph in a span with that class,
    so an unknown key leaves no empty box either.
--}}
@php($glyphs = [
    'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
    'check' => '<path d="M20 6 9 17l-5-5"/>',
    'box' => '<path d="M12 2 3 7v10l9 5 9-5V7z"/><path d="M3 7l9 5 9-5"/>',
    'users' => '<circle cx="9" cy="8" r="3"/><path d="M3 21a6 6 0 0 1 12 0"/><path d="M16 11a3 3 0 0 0 0-6"/><path d="M21 21a6 6 0 0 0-4-5.7"/>',
    'truck' => '<path d="M2 6h11v9H2z"/><path d="M13 9h4l4 3v3h-8z"/><circle cx="6" cy="18" r="1.6"/><circle cx="17" cy="18" r="1.6"/>',
    'cog' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/>',
    'lock' => '<path d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/>',
    'adjust' => '<path d="M3.75 13.5l-1.5-1.5m1.5 1.5l1.5-1.5m0 0l1.5 1.5m-1.5-1.5V3.75m0 9.75h.008v.008H3.75v-.008zm0 0H2.25m1.5 0H5.25m0 0l1.5-1.5m-1.5 1.5l-1.5-1.5m9 6l-1.5-1.5m1.5 1.5l1.5-1.5m0 0l1.5 1.5m-1.5-1.5V10.5m9 3.75h.008v.008H20.25v-.008zm0 0H18.75m1.5 0H21.75m-1.5 0l1.5-1.5m-1.5 1.5l-1.5-1.5"/>',
    'graduation' => '<path d="M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347m-15.482 0a50.57 50.57 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0112 13.489a50.702 50.702 0 017.74-3.342"/>',
    'coin' => '<path d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>',
    'pin' => '<path d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/><path d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/>',
    'group' => '<path d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z"/>',
    'wrench' => '<path d="M11.42 15.17L17.25 21A2.652 2.652 0 0021 17.25l-5.877-5.877M11.42 15.17l2.472-2.472a3.375 3.375 0 00-4.773-4.773L6.75 11.42m5.877 5.877l-5.877-5.877m0 0a3.375 3.375 0 014.773-4.773L15.17 11.42"/>',
    'machine' => '<path d="M21.75 17.25v-.228a4.5 4.5 0 00-.12-1.03l-2.268-9.64a3.375 3.375 0 00-3.285-2.6H8.25a3.375 3.375 0 00-3.285 2.6l-2.268 9.64a4.5 4.5 0 00-.12 1.03v.228m15.562 0a2.25 2.25 0 01-2.25 2.25h-11.124a2.25 2.25 0 01-2.25-2.25v-2.25a2.25 2.25 0 012.25-2.25h11.124a2.25 2.25 0 012.25 2.25v2.25z"/>',
    'eye' => '<path d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>',
])
@if (isset($glyphs[$name]))
@isset($wrap)<span class="{{ $wrap }}">@endisset
    <svg class="icon" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="{{ $stroke ?? 2 }}" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $glyphs[$name] !!}</svg>
@isset($wrap)</span>@endisset
@endif
