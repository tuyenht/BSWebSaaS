{{--
    Shared storefront <head>: SSR SEO baseline — canonical, Open Graph, optional BreadcrumbList
    JSON-LD, hreflang alternates, design-token CSS variables, storefront stylesheet. Included
    inside each layout's <head>; inherits layout view data. Adds no DB queries.
--}}
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $seoTitle }}</title>
{{-- Tenant favicon from the brand setting; the platform file is only the no-brand fallback. --}}
<link rel="icon" href="{{ data_get($brand ?? null, 'favicon') ?: '/favicon.ico' }}" sizes="any">
@php
    // Brand description fallback: category-list and other pages without a per-page description
    // (seoDescription = null/empty) fall back to the tenant's brand short description so the
    // <meta name="description"> is never empty. $brand is injected via $chrome on every request
    // and adds no new query here.
    $effectiveDescription = ! empty($seoDescription)
        ? $seoDescription
        : data_get($brand ?? null, 'description', '');

    // Per-entry ogImage (set by StorefrontController for posts with a featured image) takes
    // priority over the site-wide brand og_image. Falls back to brand when not set.
    /** @var string|null $ogImage */
    $passedOgImage = $ogImage ?? null;
    $effectiveOgImage = ! empty($passedOgImage)
        ? $passedOgImage
        : data_get($brand ?? null, 'og_image', '');
@endphp
@if (! empty($effectiveDescription))
    <meta name="description" content="{{ $effectiveDescription }}">
@endif
@if (! empty($canonical))
    <link rel="canonical" href="{{ $canonical }}">
@endif

{{-- Open Graph — title/description/type/url/locale/image. --}}
<meta property="og:title" content="{{ $seoTitle }}">
@if (! empty($effectiveDescription))
    <meta property="og:description" content="{{ $effectiveDescription }}">
@endif
<meta property="og:type" content="{{ $ogType ?? 'website' }}">
@if (! empty($canonical))
    <meta property="og:url" content="{{ $canonical }}">
@endif
<meta property="og:locale" content="{{ $lang ?? 'vi' }}">
@if (! empty($effectiveOgImage))
    <meta property="og:image" content="{{ $effectiveOgImage }}">
@endif

@if (! empty($pagination['prevUrl']))
    <link rel="prev" href="{{ $pagination['prevUrl'] }}">
@endif
@if (! empty($pagination['nextUrl']))
    <link rel="next" href="{{ $pagination['nextUrl'] }}">
@endif
@foreach (($hreflang ?? []) as $alt)
    <link rel="alternate" hreflang="{{ $alt['hreflang'] }}" href="{{ $alt['href'] }}">
@endforeach

@if (! empty(data_get($brand ?? null, 'name')))
    @php
        // Organization JSON-LD — reads from $brand (already loaded by StorefrontController::brand();
        // no additional DB query here). Null/empty fields are omitted; the whole block is suppressed
        // when no brand name is set (fail-safe for tenants without a brand setting).
        $orgTelephone = (! empty($brand['phone'])) ? $brand['phone'] : ((! empty($brand['hotline'])) ? $brand['hotline'] : null);
        // address_line (new split key) takes priority; falls back to the combined address string
        // so existing tenants without split keys still get a populated streetAddress.
        $orgStreet    = (! empty($brand['address_line'])) ? $brand['address_line'] : ((! empty($brand['address'])) ? $brand['address'] : null);
        $orgLocality  = ! empty($brand['ward']) ? $brand['ward'] : null;
        $orgRegion    = ! empty($brand['province']) ? $brand['province'] : null;

        $orgPostal = null;
        if ($orgStreet !== null || $orgLocality !== null || $orgRegion !== null) {
            $orgPostal = array_filter(
                [
                    '@type'           => 'PostalAddress',
                    'streetAddress'   => $orgStreet,
                    'addressLocality' => $orgLocality,
                    'addressRegion'   => $orgRegion,
                    'addressCountry'  => 'VN',
                ],
                fn ($v) => $v !== null && $v !== '',
            );
        }

        // Build inside @php so Blade does not scan '@context' / '@type' as directives.
        // JSON_HEX_TAG escapes < > — safe for inline <script>.
        $orgLd = array_filter(
            [
                '@context'  => 'https://schema.org',
                '@type'     => 'Organization',
                'name'      => $brand['name'],
                'legalName' => ! empty($brand['legal_name']) ? $brand['legal_name'] : null,
                'url'       => url('/'),
                'logo'      => ! empty($brand['logo']) ? $brand['logo'] : null,
                'telephone' => $orgTelephone,
                'email'     => ! empty($brand['email']) ? $brand['email'] : null,
                'taxID'     => ! empty($brand['tax_id']) ? $brand['tax_id'] : null,
                'address'   => $orgPostal ?: null,
                // The site's own social profiles (site_profiles.social_links) — how search engines
                // tie the Facebook page / YouTube channel to this business.
                'sameAs'    => ! empty($brand['social_links']) && is_array($brand['social_links']) ? array_values($brand['social_links']) : null,
            ],
            fn ($v) => $v !== null && $v !== '' && $v !== [],
        );

        $orgJson = json_encode($orgLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
    @endphp
    <script type="application/ld+json">{!! $orgJson !!}</script>
@endif

@if (! empty($productLd))
    @php
        // Product schema for catalog detail pages (built by StorefrontController::renderProduct —
        // no offers: brochure catalog). Encoded inside @php, same escaping rules as the blocks below.
        $productJson = json_encode($productLd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
    @endphp
    <script type="application/ld+json">{!! $productJson !!}</script>
@endif

@if (! empty($breadcrumb))
    @php
        // Build inside @php so Blade does not scan '@context' / '@type' as directives.
        // JSON_HEX_TAG escapes < and > to < / > — safe for inline <script>.
        $breadcrumbLd = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => collect($breadcrumb)->values()->map(fn ($crumb, $i) => [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'name' => $crumb['name'],
                'item' => $crumb['url'],
            ])->all(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
    @endphp
    <script type="application/ld+json">{!! $breadcrumbLd !!}</script>
@endif

{{-- Per-site design tokens over the shared template (values are sanitized server-side). --}}
<style>:root{@foreach (($tokens ?? []) as $name => $value)--{{ $name }}:{{ $value }};@endforeach}</style>

{{-- Storefront stylesheet (Vite-managed; faked in tests via withoutVite()). --}}
@vite(['resources/css/storefront.css'])
