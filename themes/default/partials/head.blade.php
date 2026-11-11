{{--
    Shared storefront <head>: SSR SEO baseline — canonical, Open Graph, one JSON-LD
    @graph (Theme\Seo\StructuredData), hreflang alternates, design-token CSS variables, font preload, storefront stylesheet
    and the optional header scripts. Included inside each layout's <head>; inherits layout view
    data. Adds no DB queries.
    Inter's one Latin + Vietnamese file is preloaded only when the site's font is Inter (the
    Latin-extended subset loads on demand for other scripts; a site on another font downloads no font). The
    visitor's text size is applied before the first paint by a tiny inline script that the
    storefront CSP allows by its hash (no request, nothing else inline runs). The header script (submenus, menu panel, text size)
    loads only where there is something for it to do, the slider script only on a page with a
    slider to rotate (plus a preconnect to the media host when the slider leads the page), the
    background-video script only on a page whose hero has a video, the photo switcher only on a
    product page with more than one photo, and a
    robots line only where a page asks for one (the 404 page prints no share tags or JSON-LD). These lines sit in column 0 so a site
    without them renders the same bytes as before.
    The admin's preview of a page ($preview) prints no share tags and no JSON-LD (its caller sets no canonical
    or alternates either), and opens every link outside the preview frame (<base target="_blank">).
--}}
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $seoTitle }}</title>
{{-- Tenant favicon (Storefront BrandImages); the platform file is only the no-brand fallback. --}}
<link rel="icon" href="{{ data_get($brand ?? null, 'favicon') ?: '/favicon.ico' }}" sizes="any">
@if (! empty($robots))
<meta name="robots" content="{{ $robots }}">
@endif
@if (! empty($preview))
<base target="_blank">
@endif
@foreach (($verification ?? []) as $verify)
<meta name="{{ $verify['name'] }}" content="{{ $verify['content'] }}">
@endforeach
@php
    // Brand description fallback: category-list and other pages without a per-page description
    // (seoDescription = null/empty) fall back to the tenant's brand short description so the
    // <meta name="description"> is never empty. $brand is injected via $chrome on every request
    // and adds no new query here.
    $effectiveDescription = ! empty($seoDescription)
        ? $seoDescription
        : data_get($brand ?? null, 'description', '');

    // Per-entry ogImage (set by StorefrontController for posts with a featured image) takes
    // priority over the site-wide brand og_image; a site without a share image shares its logo.
    /** @var string|null $ogImage */
    $passedOgImage = $ogImage ?? null;
    $effectiveOgImage = ! empty($passedOgImage)
        ? $passedOgImage
        : (data_get($brand ?? null, 'og_image') ?: data_get($brand ?? null, 'logo', ''));

    // The share image's size is known only for the site's own library share image (BrandImages); the
    // page's featured image and the logo carry none, so no size is printed for them. The alt text is the
    // page's title for its own image, else the business name.
    $ogSharesBrand = empty($passedOgImage) && ! empty(data_get($brand ?? null, 'og_image'));
    $ogWidth = $ogSharesBrand ? (int) data_get($brand ?? null, 'og_image_width', 0) : 0;
    $ogHeight = $ogSharesBrand ? (int) data_get($brand ?? null, 'og_image_height', 0) : 0;
    $ogAlt = ! empty($passedOgImage) ? (string) $seoTitle : (string) data_get($brand ?? null, 'name', '');

    // The other languages this page is published in (the chrome's language list, empty on a one-language
    // site; an entry that only leads to another language's home page is not this page).
    $ogAlternates = [];
    foreach (($languages ?? []) as $language) {
        if (is_array($language) && ! empty($language['locale']) && empty($language['fallback']) && $language['locale'] !== ($lang ?? null)) {
            $ogAlternates[] = \App\Modules\Theme\Chrome\StorefrontChrome::ogLocale((string) $language['locale']);
        }
    }
    $ogAlternates = array_values(array_diff(array_unique($ogAlternates), [\App\Modules\Theme\Chrome\StorefrontChrome::ogLocale($lang ?? 'vi')]));

    // A post's publishing and change times (ISO 8601 with the zone), from the article data already built.
    $articlePublished = ($ogType ?? 'website') === 'article' ? data_get($articleLd ?? null, 'datePublished') : null;
    $articleModified = ($ogType ?? 'website') === 'article' ? data_get($articleLd ?? null, 'dateModified') : null;

    // The browser bar colour: the site's brand colour (a sanitized hex token).
    $themeColor = is_string($tokens['primary'] ?? null) && preg_match('/^#[0-9a-f]{3}(?:[0-9a-f]{3})?$/i', $tokens['primary']) === 1 ? $tokens['primary'] : null;
@endphp
@if ($themeColor !== null)
<meta name="theme-color" content="{{ $themeColor }}">
@endif
@if (! empty($effectiveDescription))
    <meta name="description" content="{{ $effectiveDescription }}">
@endif
@if (! empty($canonical))
    <link rel="canonical" href="{{ $canonical }}">
@endif

{{-- Open Graph — title/description/type/url/locale/image. A 404 page and a preview have nothing to share or describe. --}}
@if (empty($notFound) && empty($preview))
<meta property="og:title" content="{{ $seoTitle }}">
@if (! empty($effectiveDescription))
    <meta property="og:description" content="{{ $effectiveDescription }}">
@endif
<meta property="og:type" content="{{ $ogType ?? 'website' }}">
@if (! empty($canonical))
    <meta property="og:url" content="{{ $canonical }}">
@endif
@if (! empty(data_get($brand ?? null, 'name')))
<meta property="og:site_name" content="{{ $brand['name'] }}">
@endif
<meta property="og:locale" content="{{ \App\Modules\Theme\Chrome\StorefrontChrome::ogLocale($lang ?? 'vi') }}">
@foreach ($ogAlternates as $ogAlternate)
<meta property="og:locale:alternate" content="{{ $ogAlternate }}">
@endforeach
@if (! empty($articlePublished))
<meta property="article:published_time" content="{{ $articlePublished }}">
@endif
@if (! empty($articleModified))
<meta property="article:modified_time" content="{{ $articleModified }}">
@endif
@if (! empty($effectiveOgImage))
    <meta property="og:image" content="{{ $effectiveOgImage }}">
@if ($ogWidth > 0 && $ogHeight > 0)
    <meta property="og:image:width" content="{{ $ogWidth }}">
    <meta property="og:image:height" content="{{ $ogHeight }}">
@endif
@if ($ogAlt !== '')
    <meta property="og:image:alt" content="{{ $ogAlt }}">
@endif
@endif
{{-- Twitter / X card: a large image card when there is an image to show, else a plain summary. --}}
<meta name="twitter:card" content="{{ ! empty($effectiveOgImage) ? 'summary_large_image' : 'summary' }}">
<meta name="twitter:title" content="{{ $seoTitle }}">
@if (! empty($effectiveDescription))
    <meta name="twitter:description" content="{{ $effectiveDescription }}">
@endif
@if (! empty($effectiveOgImage))
    <meta name="twitter:image" content="{{ $effectiveOgImage }}">
@if ($ogAlt !== '')
    <meta name="twitter:image:alt" content="{{ $ogAlt }}">
@endif
@endif
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

@php
    // One schema.org @graph per page (organization, website, page, breadcrumb and the page's own
    // Article, Product, JobPosting or FAQPage), built from data the page already holds. Null on a
    // 404 and in the admin's preview. Built inside @php so Blade does not scan '@type' as a directive.
    $ldJson = \App\Modules\Theme\Seo\StructuredData::json([
        'brand' => $brand ?? null,
        'branchPlaces' => $branchPlaces ?? null,
        'canonical' => $canonical ?? null,
        'lang' => $lang ?? null,
        'title' => $title ?? null,
        'description' => $effectiveDescription,
        'ldPage' => $ldPage ?? null,
        'posts' => $posts ?? null,
        'cards' => $cards ?? null,
        'websiteLd' => $websiteLd ?? null,
        'articleLd' => $articleLd ?? null,
        'productLd' => $productLd ?? null,
        'jobLd' => $jobLd ?? null,
        'faqLd' => $faqLd ?? null,
        'breadcrumb' => $breadcrumb ?? null,
        'notFound' => $notFound ?? null,
        'preview' => $preview ?? null,
    ]);
@endphp
@if ($ldJson !== null)
    <script type="application/ld+json">{!! $ldJson !!}</script>
@endif

{{-- Per-site design tokens over the shared template (values are sanitized server-side). --}}
<style>:root{@foreach (($tokens ?? []) as $name => $value)--{{ $name }}:{{ $value }};@endforeach}</style>

@if (str_starts_with((string) ($tokens['font'] ?? ''), 'Inter'))
<link rel="preload" href="/fonts/inter-latin-vi.woff2" as="font" type="font/woff2" crossorigin>
@endif
@if (! empty($mediaOrigin))
<link rel="preconnect" href="{{ $mediaOrigin }}">
@endif
@if (! empty($header['text_size']))
<script>{!! \App\Modules\Theme\Chrome\StorefrontChrome::TEXT_SIZE_BOOT !!}</script>
@endif
{{-- Storefront stylesheet (Vite-managed; faked in tests via withoutVite()). --}}
@vite(['resources/css/storefront.css'])
@if (! empty($header) || \App\Modules\Theme\Chrome\StorefrontChrome::hasSubmenus($menu ?? []))
@vite(['resources/js/storefront.js'])
@endif
@if (! empty($sliderScript))
@vite(['resources/js/sf-slider.js'])
@endif
@if (! empty($sectionsScript))
@vite(['resources/js/sections.js'])
@endif
@if (! empty($videoScript))
@vite(['resources/js/sf-video.js'])
@endif
@if (! empty($statsScript))
@vite(['resources/js/sf-stats.js'])
@endif
@if (! empty($bgVideoScript))
@vite(['resources/js/sf-bgvideo.js'])
@endif
@if (! empty($galleryScript))
@vite(['resources/js/sf-gallery.js'])
@endif
@if (! empty($tabsScript))
@vite(['resources/js/sf-tabs.js'])
@endif
@if (! empty($branchMapScript))
@vite(['resources/js/sf-branch-map.js'])
@endif
@if (! empty($postScript))
@vite(['resources/js/sf-post.js'])
@endif
@if (! empty($tocScript))
@vite(['resources/js/sf-toc.js'])
@endif
@if (! empty($viewerScript))
@vite(['resources/js/sf-viewer.js'])
{{-- The photo viewer's frame, translated here; sf-viewer.js copies it into the page on first use. --}}
<template id="sf-viewer"><dialog class="sf-viewer" aria-label="{{ __('storefront.photo_viewer') }}"><button type="button" class="sf-viewer__close" aria-label="{{ __('storefront.close_viewer') }}">×</button><figure class="sf-viewer__figure"><img class="sf-viewer__img" alt=""><figcaption class="sf-viewer__caption" aria-live="polite"><span class="sf-viewer__text"></span> <span class="sf-viewer__count"></span></figcaption></figure><button type="button" class="sf-viewer__nav sf-viewer__prev" aria-label="{{ __('storefront.prev_photo') }}"><svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M15 19l-7-7 7-7"/></svg></button><button type="button" class="sf-viewer__nav sf-viewer__next" aria-label="{{ __('storefront.next_photo') }}"><svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 5l7 7-7 7"/></svg></button></dialog></template>
@endif
