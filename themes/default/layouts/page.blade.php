<!doctype html>
<html lang="{{ $lang ?? 'vi' }}">
<head>
@include('theme::partials.head')
</head>
<body>
    <a class="sf-skip" href="#noi-dung">{{ __('storefront.skip_to_content') }}</a>
    @include('theme::partials.header', ['menu' => $menu ?? [], 'brand' => $brand ?? null])
    {{--
        Single h1 per page (a11y + SEO invariant). For pages whose first section is a hero
        block, the CSS rule `main:has(.hero) .page__title` makes this h1 screen-reader-only
        so the hero's h2 visually leads the page without a duplicate heading. For inner pages
        (about, contact, partners) that have no hero, this h1 is the visible primary heading.
        A page led by a page_header band gets its h1 from the band, so none is printed here.
    --}}
    <main id="noi-dung" tabindex="-1">
@if (($leadBlock ?? null) !== 'page_header')
        <h1 class="page__title">{{ $title }}</h1>
@endif
        {{-- $sections is pre-rendered, per-block Blade-escaped HTML (BlockRenderer). --}}
        {!! $sections !!}
    </main>
    @include('theme::partials.footer', ['menu' => $menu ?? [], 'brand' => $brand ?? null])
</body>
</html>
