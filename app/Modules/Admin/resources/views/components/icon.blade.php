@props(['name' => 'doc'])
@php
    // Minimal inline-SVG icon set (Heroicons outline, zero-dependency). Velzon ships an
    // icon font (Remix); we keep dependencies lean and inline SVGs instead. Unknown names
    // fall back to a generic document glyph so a missing content_section.icon never breaks.
    $paths = [
        'dashboard' => 'M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6Zm9.75 0A2.25 2.25 0 0 1 15.75 3.75H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6Zm-9.75 9.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25Zm9.75 0a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z',
        'menu' => 'M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5',
        'search' => 'M21 21l-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z',
        'logout' => 'M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15M12 9l-3 3m0 0 3 3m-3-3h12.75',
        'doc' => 'M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 12h3m-7.5 0h.008v.008H8.25V18.75Zm.375-9.75h4.5M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z',
        // Additive (increment-3): existing names above are untouched. 'users'/'settings'
        // are migrated 1:1 from the previously-hardcoded sidebar SVGs (settings = gear +
        // centre dot as a second sub-path) so the catalog-driven nav keeps the same glyphs.
        'users' => 'M16 19v-1.5a3.5 3.5 0 0 0-3.5-3.5h-5A3.5 3.5 0 0 0 4 17.5V19M21 19v-1.5a3.5 3.5 0 0 0-2.6-3.4M14.5 4.4a3.5 3.5 0 0 1 0 6.2M10 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z',
        'settings' => 'M10.4 3.5h3.2l.5 2.4 1.9.8 2.1-1.3 2.2 2.2-1.3 2.1.8 1.9 2.4.5v3.2l-2.4.5-.8 1.9 1.3 2.1-2.2 2.2-2.1-1.3-1.9.8-.5 2.4h-3.2l-.5-2.4-1.9-.8-2.1 1.3-2.2-2.2 1.3-2.1-.8-1.9L2 13.6v-3.2l2.4-.5.8-1.9-1.3-2.1 2.2-2.2 2.1 1.3 1.9-.8z M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0z',
        'file' => 'M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z M14 2v5a1 1 0 0 0 1 1h5',
        'file-text' => 'M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z M14 2v5a1 1 0 0 0 1 1h5 M16 13H8 M16 17H8 M10 9H8',
    ];
    $d = $paths[$name] ?? $paths['doc'];
@endphp
<svg {{ $attributes->merge(['class' => 'size-5']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor"
     stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <path d="{{ $d }}" />
</svg>
