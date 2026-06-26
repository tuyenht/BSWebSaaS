{{--
    Shared storefront <head>: the SSR SEO baseline for every layout — canonical + Open Graph,
    plus an optional BreadcrumbList JSON-LD. Included inside each layout's <head>, so it
    inherits the layout's view data. Every value comes from already-resolved request data, so
    the head adds no queries. JSON-LD is encoded server-side with tag-hex escaping to stay safe
    inside the <script> element.
--}}
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $seoTitle }}</title>
@if (! empty($seoDescription))
    <meta name="description" content="{{ $seoDescription }}">
@endif
@if (! empty($canonical))
    <link rel="canonical" href="{{ $canonical }}">
@endif

{{-- Open Graph — title/description/type/url/locale. og:image waits for the Media module. --}}
<meta property="og:title" content="{{ $seoTitle }}">
@if (! empty($seoDescription))
    <meta property="og:description" content="{{ $seoDescription }}">
@endif
<meta property="og:type" content="{{ $ogType ?? 'website' }}">
@if (! empty($canonical))
    <meta property="og:url" content="{{ $canonical }}">
@endif
<meta property="og:locale" content="{{ $lang ?? 'vi' }}">

@if (! empty($pagination['prevUrl']))
    <link rel="prev" href="{{ $pagination['prevUrl'] }}">
@endif
@if (! empty($pagination['nextUrl']))
    <link rel="next" href="{{ $pagination['nextUrl'] }}">
@endif
@foreach (($hreflang ?? []) as $alt)
    <link rel="alternate" hreflang="{{ $alt['hreflang'] }}" href="{{ $alt['href'] }}">
@endforeach

@if (! empty($breadcrumb))
    <script type="application/ld+json">{!! json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => collect($breadcrumb)->values()->map(fn ($crumb, $i) => [
            '@type' => 'ListItem',
            'position' => $i + 1,
            'name' => $crumb['name'],
            'item' => $crumb['url'],
        ])->all(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
@endif

{{-- Per-site design tokens over the shared template (values are sanitized server-side). --}}
<style>:root{@foreach (($tokens ?? []) as $name => $value)--{{ $name }}:{{ $value }};@endforeach}</style>
