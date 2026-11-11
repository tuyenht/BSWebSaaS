<!doctype html>
<html lang="{{ $lang ?? 'vi' }}"{!! ($dir ?? 'ltr') === 'rtl' ? ' dir="rtl"' : '' !!}>
<head>
@include('theme::partials.head')
</head>
<body>
    <a class="sf-skip" href="#noi-dung">{{ __('storefront.skip_to_content') }}</a>
    @include('theme::partials.header', ['menu' => $menu ?? [], 'brand' => $brand ?? null])
    {{--
        A page with a numbered table of contents (a policy, terms of use): the page layout, with the
        title band that leads the page kept full width (`$band`, split off by PageRenderer) and the
        rest drawn beside a contents list built from its text headings (`$toc`, two or more; the
        anchors and numbers were added at render time, ArticleToc::numbered). Plain links to
        anchors: no script. On a phone the list sits above the text; from 1024px it is a column that
        stays in view while the text scrolls. One h1, as on the page layout.
    --}}
    <main id="noi-dung" tabindex="-1">
@if (($leadBlock ?? null) !== 'page_header')
@if (! empty($breadcrumb))
        <div class="page__crumbs">@include('theme::partials.sf-breadcrumb', ['crumbs' => $breadcrumb])</div>
@endif
        <h1 class="page__title">{{ $title }}</h1>
@endif
        {{-- $band and $sections are pre-rendered, per-block Blade-escaped HTML (BlockRenderer). --}}
        {!! $band ?? '' !!}
@if (($toc ?? []) !== [])
        <div class="sf-doc">
            <nav class="sf-doc__toc" aria-label="{{ __('storefront.toc_page') }}">
                <div class="sf-doc__toc-box">
                    <p class="sf-doc__toc-title">{{ __('storefront.toc_page') }}</p>
                    {{-- Headings the author numbered keep their own numbers, so the list adds none; the
                         role keeps the list announced as one where list markers are switched off. --}}
                    <ol @class(['sf-doc__toc-list', 'sf-doc__toc-list--own' => $tocOwn ?? false])@if ($tocOwn ?? false) role="list"@endif>
@foreach ($toc as $item)
                        <li><a class="sf-doc__toc-link" href="#{{ $item['id'] }}">{{ $item['label'] }}</a></li>
@endforeach
                    </ol>
                </div>
            </nav>
            <div class="sf-doc__body">
                {!! $sections !!}
            </div>
        </div>
@else
        {!! $sections !!}
@endif
    </main>
    @include('theme::partials.footer', ['menu' => $menu ?? [], 'brand' => $brand ?? null])
</body>
</html>
