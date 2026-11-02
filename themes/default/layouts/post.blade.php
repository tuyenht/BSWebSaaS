<!doctype html>
<html lang="{{ $lang ?? 'vi' }}">
<head>
@include('theme::partials.head')
</head>
<body>
    <a class="sf-skip" href="#noi-dung">{{ __('storefront.skip_to_content') }}</a>
    @include('theme::partials.header', ['menu' => $menu ?? [], 'brand' => $brand ?? null])
    <article class="post" id="noi-dung" tabindex="-1">
        @isset($category)
            <nav class="post__breadcrumb" aria-label="Breadcrumb">
                <a href="{{ $category['url'] }}">{{ $category['name'] }}</a>
            </nav>
        @endisset
@if (($leadBlock ?? null) !== 'page_header')
        <h1 class="post__title">{{ $title }}</h1>
@endif
        {{-- $sections is pre-rendered, per-block Blade-escaped HTML (BlockRenderer). Post
             bodies reuse the block-list Page-JSON (D1), so they render exactly like a page. --}}
        <div class="post__body">{!! $sections !!}</div>
    </article>
    @include('theme::partials.footer', ['menu' => $menu ?? [], 'brand' => $brand ?? null])
</body>
</html>
